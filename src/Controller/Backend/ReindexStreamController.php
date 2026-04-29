<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\DocumentIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\SearchDocument;
use VenneMedia\VenneSearchContaoBundle\Service\Pdf\PdfExtractor;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;
use VenneMedia\VenneSearchContaoBundle\Service\Text\TextNormalizer;

/**
 * Bulletproof-Reindex-Stream — paginiert via ?offset=N&limit=200.
 *
 * Hintergrund: Plesk PHP-FPM hat ein hartes max_execution_time = 30. Das
 * kann set_time_limit(0) NICHT überschreiben. Statt zu kämpfen, schneiden
 * wir den Lauf in Batches à 200 Items, die jeweils << 30 s brauchen, und
 * lassen das Backend-JS automatisch den nächsten Batch holen.
 *
 * Reihenfolge ist stabil sortiert:
 *   index 0 .. P-1     → Pages (sortiert nach id ASC)
 *   index P .. P+F-1   → Files (sortiert nach uuid HEX ASC)
 *
 * Frontend-Events:
 *   start    {total, alreadyIndexed, offset, limit}
 *   progress {current, total, label, error?, skipped?}
 *   heartbeat {ts}
 *   done     {total, indexed, skipped, nextOffset|null}
 *   fatal    {message}
 */
final class ReindexStreamController extends AbstractController
{
    private const INDEXABLE_FILE_EXTENSIONS = ['pdf', 'txt', 'md', 'docx', 'odt', 'rtf'];

    /**
     * Heartbeat alle N Sekunden, damit Reverse-Proxies (nginx, Plesk) nicht
     * idle-killen. Plesk hat default proxy_read_timeout 60s, aber bei
     * Streaming-Endpunkten greifen oft auch FastCGI-Timeouts. 5s ist sicher
     * unter beiden — kostet im Stream-Body fast nichts (ein paar Bytes pro Tick).
     */
    private const HEARTBEAT_INTERVAL_SECONDS = 5;

    /** Default-Batch-Größe pro Request — passt komfortabel in 30 s PHP-Limit. */
    private const DEFAULT_BATCH_LIMIT = 200;

    /** Hard-Cap: niemand darf mit ?limit=99999 das Plesk-Timeout sprengen. */
    private const MAX_BATCH_LIMIT = 500;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $db,
        private readonly DocumentIndexer $indexer,
        private readonly TextNormalizer $normalizer,
        private readonly PdfExtractor $pdfExtractor,
        private readonly SettingsRepository $settings,
        private readonly Client $meilisearch,
    ) {
    }

    public function __invoke(Request $request): StreamedResponse
    {
        if (!$this->isGranted('ROLE_USER') || !$this->getUser() instanceof BackendUser) {
            return $this->unauthorizedStreamResponse();
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        if (\function_exists('apache_setenv')) {
            @\apache_setenv('no-gzip', '1');
        }

        $offset = max(0, (int) $request->query->get('offset', '0'));
        $limit = (int) $request->query->get('limit', (string) self::DEFAULT_BATCH_LIMIT);
        if ($limit < 1) {
            $limit = self::DEFAULT_BATCH_LIMIT;
        }
        if ($limit > self::MAX_BATCH_LIMIT) {
            $limit = self::MAX_BATCH_LIMIT;
        }

        // Session JETZT starten (vor dem Stream-Callback), bevor Header rausgehen.
        if ($request->hasSession()) {
            $session = $request->getSession();
            if (!$session->isStarted()) {
                $session->start();
            }
            $session->save();
        }

        $this->framework->initialize();

        $response = new StreamedResponse(function () use ($offset, $limit) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            try {
                $this->runReindexBatch($offset, $limit);
            } catch (\Throwable $e) {
                $this->emit('fatal', ['message' => substr($e->getMessage(), 0, 200)]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('X-Output-Buffering', 'no');

        return $response;
    }

    private function runReindexBatch(int $offset, int $limit): void
    {
        if (!$this->settings->isConfigured()) {
            $this->emit('fatal', ['message' => 'API-Key nicht konfiguriert.']);
            return;
        }

        try {
            $config = $this->settings->load();
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveAuthException) {
            $this->emit('fatal', ['message' => 'Plattform-Key ungültig oder widerrufen — bitte im venne-search.de-Dashboard prüfen.']);
            return;
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveSubscriptionException) {
            $this->emit('fatal', ['message' => 'Venne-Search-Abo nicht aktiv — Suche aktuell deaktiviert.']);
            return;
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveProvisioningException) {
            $this->emit('fatal', ['message' => 'Bundle wartet auf Provisionierung — Plattform-Admin kontaktieren.']);
            return;
        } catch (\Throwable $e) {
            $this->emit('fatal', ['message' => 'Plattform nicht erreichbar: '.substr($e->getMessage(), 0, 160)]);
            return;
        }

        $locale = $config->enabledLocales[0] ?? 'de';

        // Stabile, sortierte Listen — damit offset/limit deterministisch bleibt
        // auch wenn neue Rows zwischen zwei Batches dazukommen (neue Rows liegen
        // dann am Ende und werden im aktuellen Lauf einfach mit aufgenommen).
        $pageRows = $this->db->fetchAllAssociative(
            "SELECT id FROM tl_page WHERE type IN ('regular', 'forward', 'redirect') AND published = '1' ORDER BY id ASC"
        );
        // ORDER BY path statt HEX(uuid) — HEX() ist MySQL-spezifisch und
        // crasht auf SQLite/PostgreSQL. Pfad-Sortierung ist außerdem
        // deterministisch + vom User nachvollziehbar.
        $fileRows = $this->db->fetchAllAssociative(
            "SELECT id, uuid, path, extension FROM tl_files WHERE type = 'file' ORDER BY path ASC"
        );

        // Master-Toggle: wenn Datei-Indexierung aus ist, gar keine Files indexieren
        // (nicht nur PDFs ausschließen).
        if (!$config->indexPdfs) {
            $fileRows = [];
        }

        $files = [];
        foreach ($fileRows as $row) {
            $ext = strtolower((string) ($row['extension'] ?? ''));
            if (!\in_array($ext, self::INDEXABLE_FILE_EXTENSIONS, true)) {
                continue;
            }
            $files[] = $row;
        }

        $pageCount = \count($pageRows);
        $fileCount = \count($files);
        $total = $pageCount + $fileCount;

        // Beim ersten Batch (Klick auf "Jetzt indexieren"): den Stand aus
        // Meilisearch als Initial-Done-Liste setzen. So werden alle bereits
        // im Index befindlichen Docs sofort als "schon erledigt" markiert
        // und im Loop ueberspringt — der Lauf indexiert nur die echten
        // Neuzugaenge. "Index leeren" wuerde den Index UND die Done-Liste
        // resetten, damit dann ein vollstaendiger Frisch-Indexlauf durchgeht.
        if ($offset === 0) {
            $existingMeiliIds = $this->loadIndexedIds($config->indexPrefix.'_'.$locale);
            $initialDoneJson = json_encode(array_keys($existingMeiliIds), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!\is_string($initialDoneJson)) {
                $initialDoneJson = '[]';
            }
            try {
                $this->db->executeStatement(
                    'UPDATE tl_venne_search_settings SET reindex_total = ?, reindex_started_at = ?, reindex_done_ids = ? WHERE id = 1',
                    [$total, time(), $initialDoneJson],
                );
            } catch (\Throwable) {
            }
        }

        // Resume-Set aus DB-Spalte lesen — persistent ueber Reconnects, instant.
        // Beim allerersten Batch ist hier schon der aktuelle Meilisearch-
        // Stand drin (siehe oben), bei spaeteren Batches/Reconnects das was
        // im aktuellen Run schon erledigt wurde.
        $alreadyIndexedIds = $this->loadDoneIdsFromDb();

        $batchEnd = min($offset + $limit, $total);

        $this->emit('start', [
            'total' => $total,
            'alreadyIndexed' => \count($alreadyIndexedIds),
            'offset' => $offset,
            'limit' => $limit,
            'batchEnd' => $batchEnd,
        ]);

        $indexed = 0;
        $skipped = 0;
        $lastHeartbeat = time();
        $batchStartTime = microtime(true);
        $projectDir = $this->getParameter('kernel.project_dir');
        // Sammelt alle docIds die in diesem Batch erfolgreich indexiert wurden.
        // Am Ende werden sie in tl_venne_search_settings.reindex_done_ids
        // persistiert, damit der naechste Batch (oder Reconnect) sie skippen kann.
        $newlyDoneIds = [];

        // Index-Setup einmal pro Locale pro Batch — nicht pro Item.
        try {
            $this->indexer->ensureIndex($locale);
        } catch (\Throwable $e) {
            $this->emit('fatal', ['message' => 'Index-Setup fehlgeschlagen: '.substr($e->getMessage(), 0, 160)]);
            return;
        }

        // 1) Pages-Slice
        $pageStart = max(0, $offset);
        $pageStop = min($pageCount, $batchEnd);
        if ($pageStart < $pageStop) {
            for ($i = $pageStart; $i < $pageStop; $i++) {
                $row = $pageRows[$i];
                $current = $i + 1;
                $pageId = (int) $row['id'];
                $docId = 'page-'.$pageId;

                if (isset($alreadyIndexedIds[$docId])) {
                    ++$skipped;
                    $this->emit('progress', [
                        'current' => $current,
                        'total' => $total,
                        'label' => 'Seite #'.$pageId.' bereits indexiert — übersprungen',
                        'skipped' => true,
                    ]);
                    $this->maybeHeartbeat($lastHeartbeat);
                    continue;
                }

                $this->forceHeartbeat($lastHeartbeat);

                try {
                    $pageRow = $this->db->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$pageId]);
                    if (!$pageRow) {
                        $this->emit('progress', [
                            'current' => $current,
                            'total' => $total,
                            'label' => 'Seite #'.$pageId.' nicht gefunden',
                            'error' => true,
                        ]);
                        $this->maybeHeartbeat($lastHeartbeat);
                        continue;
                    }

                    $doc = $this->buildPageDocumentFromRow($pageRow);
                    if ($doc !== null) {
                        $this->indexer->upsert($doc);
                        ++$indexed;
                        $newlyDoneIds[] = $docId;
                        // Alle 25 Items sofort in DB persistieren — falls
                        // der Stream stirbt, sind sie nicht verloren.
                        if (\count($newlyDoneIds) >= 25) {
                            $this->mergeDoneIdsToDb($newlyDoneIds);
                            $newlyDoneIds = [];
                        }
                    }

                    $pageLabel = (string) ($pageRow['title'] ?: $pageRow['alias'] ?: 'ID '.$pageId);
                    $pageAlias = (string) ($pageRow['alias'] ?? '');
                    $pageInfo = $pageAlias !== '' ? ' [/'.$pageAlias.', ID '.$pageId.']' : ' [ID '.$pageId.']';
                    $this->emit('progress', [
                        'current' => $current,
                        'total' => $total,
                        'label' => 'Seite: '.$pageLabel.$pageInfo,
                        'eta' => $this->calculateEta($current - $offset, $batchEnd - $offset, $batchStartTime),
                    ]);
                } catch (\Throwable $e) {
                    $this->emit('progress', [
                        'current' => $current,
                        'total' => $total,
                        'label' => 'Fehler bei Seite '.$pageId.': '.substr($e->getMessage(), 0, 80),
                        'error' => true,
                    ]);
                } finally {
                    unset($pageRow, $doc);
                    if ($i % 25 === 0) {
                        \gc_collect_cycles();
                    }
                }

                $this->maybeHeartbeat($lastHeartbeat);
            }
        }

        // 2) Files-Slice
        $fileStart = max(0, $offset - $pageCount);
        $fileStop = max(0, $batchEnd - $pageCount);
        if ($fileStart < $fileStop) {
            for ($i = $fileStart; $i < $fileStop; $i++) {
                $row = $files[$i];
                $current = $pageCount + $i + 1;

                $relativePath = (string) $row['path'];
                $uuidBin = $row['uuid'] ?? null;
                if ($uuidBin !== null && $uuidBin !== '') {
                    $docId = 'file-'.bin2hex((string) $uuidBin);
                } else {
                    $docId = 'file-path-'.md5($relativePath);
                }
                $absolute = rtrim((string) $projectDir, '/').'/'.$relativePath;
                $ext = strtolower((string) ($row['extension'] ?? ''));
                $basename = basename($relativePath);

                if (isset($alreadyIndexedIds[$docId])) {
                    ++$skipped;
                    $this->emit('progress', [
                        'current' => $current,
                        'total' => $total,
                        'label' => 'Datei bereits indexiert: '.$basename,
                        'skipped' => true,
                    ]);
                    $this->maybeHeartbeat($lastHeartbeat);
                    continue;
                }

                if (!is_file($absolute) || !is_readable($absolute)) {
                    $this->emit('progress', [
                        'current' => $current,
                        'total' => $total,
                        'label' => 'Datei nicht lesbar: '.$basename,
                        'error' => true,
                    ]);
                    $this->maybeHeartbeat($lastHeartbeat);
                    continue;
                }

                // ──── KRITISCH: PRE-ITEM HEARTBEAT ─────────────────────────
                // Bevor wir möglicherweise 20+ Sekunden mit einem PDF
                // verbringen, schicken wir einen Heartbeat — sonst killt
                // der Reverse-Proxy die Verbindung.
                $this->forceHeartbeat($lastHeartbeat);

                try {
                    $extractStart = microtime(true);
                    $extractResult = $this->extractFileText($absolute, $ext, $config->maxFileSizeMb);
                    $text = $extractResult['text'];
                    $skipReason = $extractResult['reason'];
                    $extractDuration = (int) ((microtime(true) - $extractStart) * 1000);

                    if ($text === '') {
                        $humanReason = $this->humanizeSkipReason($skipReason);
                        $sizeKb = (int) round(((int) ($row['filesize'] ?? @filesize($absolute) ?: 0)) / 1024);
                        $sizePart = $sizeKb > 0 ? sprintf(' [%s KB]', number_format($sizeKb, 0, ',', '.')) : '';
                        $this->emit('progress', [
                            'current' => $current,
                            'total' => $total,
                            'label' => sprintf('%s%s — %s', $basename, $sizePart, $humanReason !== '' ? $humanReason : 'kein Text'),
                            'skipped' => true,
                            'eta' => $this->calculateEta($current - $offset, $batchEnd - $offset, $batchStartTime),
                        ]);
                        ++$skipped;
                    } else {
                        $doc = new SearchDocument(
                            id: $docId,
                            type: 'file',
                            locale: $locale,
                            title: $this->humanizeFilename(pathinfo($relativePath, PATHINFO_FILENAME)),
                            url: '/'.$relativePath,
                            content: $this->normalizer->normalize($text),
                            tags: [$ext],
                        );
                        $this->indexer->upsert($doc);
                        ++$indexed;
                        $newlyDoneIds[] = $docId;
                        if (\count($newlyDoneIds) >= 25) {
                            $this->mergeDoneIdsToDb($newlyDoneIds);
                            $newlyDoneIds = [];
                        }

                        $this->emit('progress', [
                            'current' => $current,
                            'total' => $total,
                            'label' => sprintf(
                                '%s — %s Zeichen, %dms',
                                $basename,
                                number_format(mb_strlen($text), 0, ',', '.'),
                                $extractDuration,
                            ),
                            'eta' => $this->calculateEta($current - $offset, $batchEnd - $offset, $batchStartTime),
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->emit('progress', [
                        'current' => $current,
                        'total' => $total,
                        'label' => 'Fehler bei '.$basename.': '.substr($e->getMessage(), 0, 80),
                        'error' => true,
                    ]);
                } finally {
                    // Memory-Cleanup nach jedem File — pdfparser kann je nach
                    // Datei mehrere hundert MB peak halten. Ohne aktiven gc_collect
                    // wachsen wir bis zum FPM-Memory-Limit und sterben mitten im Run.
                    unset($text, $extractResult, $skipReason, $doc);
                    \gc_collect_cycles();
                }

                $this->maybeHeartbeat($lastHeartbeat);
            }
        }

        // Done-Ids aus diesem Batch in DB persistieren — damit Reconnect /
        // naechster Batch sie als "schon erledigt" sieht.
        if ($newlyDoneIds !== []) {
            $this->mergeDoneIdsToDb($newlyDoneIds);
        }

        gc_collect_cycles();

        $nextOffset = $batchEnd < $total ? $batchEnd : null;

        $this->emit('done', [
            'total' => $total,
            'indexed' => $indexed,
            'skipped' => $skipped,
            'nextOffset' => $nextOffset,
        ]);
    }

    /**
     * Liest die persistente Resume-Liste aus tl_venne_search_settings.
     * Format: JSON-Array von docIds (z.B. ["page-475","file-abc123",...]).
     *
     * @return array<string,true>  Map docId => true fuer O(1)-Lookup
     */
    private function loadDoneIdsFromDb(): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT reindex_done_ids FROM tl_venne_search_settings WHERE id = 1');
            if (!\is_array($row)) {
                return [];
            }
            $raw = (string) ($row['reindex_done_ids'] ?? '');
            if ($raw === '') {
                return [];
            }
            $arr = json_decode($raw, true);
            if (!\is_array($arr)) {
                return [];
            }
            $map = [];
            foreach ($arr as $id) {
                if (\is_string($id) && $id !== '') {
                    $map[$id] = true;
                }
            }
            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Schreibt neue docIds in die persistente Resume-Liste — merged mit
     * was schon drinsteht. Wird am Ende jedes Batches aufgerufen.
     *
     * @param list<string> $newIds
     */
    private function mergeDoneIdsToDb(array $newIds): void
    {
        if ($newIds === []) {
            return;
        }
        try {
            $existing = $this->loadDoneIdsFromDb();
            foreach ($newIds as $id) {
                $existing[$id] = true;
            }
            $jsonList = array_keys($existing);
            $json = json_encode($jsonList, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!\is_string($json)) {
                return;
            }
            $this->db->executeStatement(
                'UPDATE tl_venne_search_settings SET reindex_done_ids = ? WHERE id = 1',
                [$json],
            );
        } catch (\Throwable) {
            // Persistenz schief — egal, bei naechstem Batch wird es nochmal
            // versucht. Lieber Doppelt-Indexierung als Crash.
        }
    }

    /**
     * Holt alle Doc-IDs aus dem Meilisearch-Index, damit wir wissen was schon
     * drin ist und beim Resume nicht wieder versuchen.
     *
     * @return array<string,true>
     */
    private function loadIndexedIds(string $indexUid): array
    {
        $ids = [];
        try {
            $offset = 0;
            $limit = 1000;
            for ($i = 0; $i < 200; $i++) {
                $query = (new \Meilisearch\Contracts\DocumentsQuery())
                    ->setFields(['id'])
                    ->setLimit($limit)
                    ->setOffset($offset);
                $resp = $this->meilisearch->index($indexUid)->getDocuments($query)->getResults();
                if ($resp === []) {
                    break;
                }
                foreach ($resp as $hit) {
                    $arr = is_array($hit) ? $hit : (array) $hit;
                    if (isset($arr['id'])) {
                        $ids[(string) $arr['id']] = true;
                    }
                }
                $offset += $limit;
                if (\count($resp) < $limit) {
                    break;
                }
            }
        } catch (\Throwable) {
        }

        return $ids;
    }

    /**
     * Generiert die Frontend-URL einer Page **rein aus DB-Daten** —
     * KEINE Contao-Framework-Aufrufe (PageModel, UrlGenerator) im Stream-
     * Kontext. Die crashen mit Fatal weil das Routing nicht voll initialisiert
     * ist, und try/catch faengt das nicht.
     *
     * Format: /<alias><urlSuffix> mit Suffix aus Root-Page-DB-Spalte oder
     * Container-Parameter contao.url_suffix.
     */
    private function generatePageUrl(int $pageId, string $aliasFallback): string
    {
        $alias = ltrim($aliasFallback, '/');
        if ($alias === '' || $alias === 'index') {
            return '/';
        }
        return '/'.$alias.$this->resolveUrlSuffix($pageId);
    }

    /**
     * Liest den URL-Suffix:
     *   1. tl_page.urlSuffix der Root-Page (wenn nicht leer)
     *   2. Container-Parameter contao.url_suffix (Contao default '.html')
     */
    private function resolveUrlSuffix(int $pageId): string
    {
        try {
            $current = $pageId;
            for ($i = 0; $i < 50 && $current > 0; $i++) {
                $row = $this->db->fetchAssociative('SELECT pid, type, urlSuffix FROM tl_page WHERE id = ?', [$current]);
                if (!\is_array($row)) {
                    break;
                }
                if (($row['type'] ?? '') === 'root') {
                    $rootSuffix = (string) ($row['urlSuffix'] ?? '');
                    if ($rootSuffix !== '') {
                        return $rootSuffix;
                    }
                    break; // Root-Page hat keinen eigenen Suffix -> globalen verwenden
                }
                $current = (int) ($row['pid'] ?? 0);
            }
        } catch (\Throwable) {
        }

        // Fallback: globaler Contao-URL-Suffix (Container-Parameter).
        // Default in Contao 4: '.html'.
        try {
            $container = \Contao\System::getContainer();
            if ($container->hasParameter('contao.url_suffix')) {
                return (string) $container->getParameter('contao.url_suffix');
            }
        } catch (\Throwable) {
        }
        return '.html';
    }

    private function maybeHeartbeat(int &$lastHeartbeat): void
    {
        $now = time();
        if ($now - $lastHeartbeat >= self::HEARTBEAT_INTERVAL_SECONDS) {
            $this->emit('heartbeat', ['ts' => $now]);
            $lastHeartbeat = $now;
        }
    }

    /**
     * Erzwungener Heartbeat — VOR potenziell langsamen Items, damit die
     * Verbindung selbst dann nicht idle-stirbt, wenn das Item dann doch
     * lange braucht (großes PDF, langsamer Meili-Upsert).
     */
    private function forceHeartbeat(int &$lastHeartbeat): void
    {
        $this->emit('heartbeat', ['ts' => time()]);
        $lastHeartbeat = time();
    }

    /**
     * Übersetzt Skip-Reasons aus dem Extractor-Code in für Menschen lesbare
     * deutsche Texte. Damit der Admin im Reindex-Log SOFORT sieht, warum eine
     * Datei übersprungen wurde, und nicht erst maschinen-lesbare Codes
     * dechiffrieren muss.
     */
    private function humanizeSkipReason(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return '';
        }
        return match (true) {
            str_starts_with($reason, 'file_too_large_') => sprintf(
                'Datei zu groß (~%s MB > Limit) — Limit in den Einstellungen anpassen.',
                substr($reason, strlen('file_too_large_'), -3),
            ),
            str_starts_with($reason, 'parse_timeout_after_') => sprintf(
                'PDF-Parser-Timeout nach %s — Datei zu komplex/kaputt, übersprungen.',
                substr($reason, strlen('parse_timeout_after_')),
            ),
            str_contains($reason, 'Secured pdf') => 'PDF ist passwortgeschützt — kein Zugriff auf Text.',
            $reason === 'empty_textlayer_image_only' => 'PDF enthält nur Bilder (kein durchsuchbarer Text).',
            $reason === 'file_not_readable' => 'Datei nicht lesbar (Berechtigungsproblem oder Pfad falsch).',
            $reason === 'file_size_unknown' => 'Dateigröße nicht ermittelbar.',
            $reason === 'docx_no_ziparchive', $reason === 'odt_no_ziparchive' => 'Server hat keine ZIP-Unterstützung — Office-Datei kann nicht gelesen werden.',
            $reason === 'docx_zip_open_failed' => 'DOCX-Datei kaputt (ZIP-Öffnen fehlgeschlagen).',
            $reason === 'odt_zip_open_failed' => 'ODT-Datei kaputt (ZIP-Öffnen fehlgeschlagen).',
            $reason === 'docx_empty', $reason === 'odt_empty', $reason === 'rtf_empty_after_strip' => 'Datei enthält keinen extrahierbaren Text.',
            $reason === 'rtf_empty_file' => 'RTF-Datei ist leer.',
            $reason === 'odt_no_content' => 'ODT enthält kein content.xml — Datei kaputt.',
            str_starts_with($reason, 'unsupported_extension_') => sprintf(
                'Dateityp .%s wird nicht unterstützt.',
                substr($reason, strlen('unsupported_extension_')),
            ),
            str_starts_with($reason, 'parse_failed:') => sprintf('Parse-Fehler: %s', substr($reason, strlen('parse_failed:'))),
            default => $reason,
        };
    }

    /**
     * Berechnet ETA aus Items/Sekunde und verbleibenden Items. Liefert einen
     * Klartext-String den das Frontend direkt einblenden kann.
     */
    private function calculateEta(int $current, int $total, float $batchStartTime): string
    {
        $elapsed = microtime(true) - $batchStartTime;
        if ($elapsed < 0.5 || $current <= 0) {
            return '';
        }
        $itemsPerSec = $current / $elapsed;
        if ($itemsPerSec < 0.01) {
            return '';
        }
        $remaining = max(0, $total - $current);
        $etaSec = (int) ceil($remaining / $itemsPerSec);
        if ($etaSec < 60) {
            return sprintf('%.1f/s · ETA %ds', $itemsPerSec, $etaSec);
        }
        if ($etaSec < 3600) {
            return sprintf('%.1f/s · ETA %dm %ds', $itemsPerSec, intdiv($etaSec, 60), $etaSec % 60);
        }
        return sprintf('%.1f/s · ETA %dh %dm', $itemsPerSec, intdiv($etaSec, 3600), intdiv($etaSec % 3600, 60));
    }

    private function emit(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
        @ob_flush();
        @flush();
    }

    /**
     * @param array<string,mixed> $pageRow
     */
    private function buildPageDocumentFromRow(array $pageRow): ?SearchDocument
    {
        $pageId = (int) ($pageRow['id'] ?? 0);
        if ($pageId <= 0) {
            return null;
        }

        $contentParts = [
            (string) ($pageRow['pageTitle'] ?: ($pageRow['title'] ?? '')),
            (string) ($pageRow['description'] ?? ''),
            (string) ($pageRow['keywords'] ?? ''),
        ];

        $articles = $this->db->fetchAllAssociative(
            "SELECT id, title, teaser FROM tl_article WHERE pid = ? AND inColumn = 'main' AND published = '1'",
            [$pageId]
        );

        foreach ($articles as $article) {
            $contentParts[] = (string) ($article['title'] ?? '');
            $contentParts[] = (string) ($article['teaser'] ?? '');
            $elements = $this->db->fetchAllAssociative(
                "SELECT headline, text FROM tl_content WHERE pid = ? AND ptable = 'tl_article' AND invisible = '' ORDER BY sorting",
                [(int) $article['id']]
            );
            foreach ($elements as $el) {
                $contentParts[] = $this->extractHeadlineText((string) ($el['headline'] ?? ''));
                $contentParts[] = strip_tags((string) ($el['text'] ?? ''));
            }
        }

        $rawContent = implode(' ', array_filter($contentParts));
        $normalizedContent = $this->normalizer->normalize($rawContent);

        // URL via Contao-Framework — generiert die echte Frontend-URL inklusive
        // URL-Suffix (.html), Sprach-Prefix, Tree-Pfad. Fallback: einfache
        // alias-URL wenn das Framework im Stream-Kontext nicht voll initialisiert.
        $url = $this->generatePageUrl($pageId, (string) ($pageRow['alias'] ?? ''));

        $tags = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($pageRow['keywords'] ?? ''))
        )));

        return new SearchDocument(
            id: 'page-'.$pageId,
            type: 'page',
            locale: (string) ($pageRow['language'] ?: 'de'),
            title: (string) ($pageRow['pageTitle'] ?: ($pageRow['title'] ?? '')),
            url: $url,
            content: $normalizedContent,
            tags: $tags,
            publishedAt: !empty($pageRow['start']) ? (int) $pageRow['start'] : (int) ($pageRow['tstamp'] ?? 0),
            weight: 1.0,
        );
    }

    /**
     * @return array{text:string, reason:?string}
     */
    private function extractFileText(string $absolutePath, string $extension, int $maxFileSizeMb): array
    {
        if ($extension === 'pdf') {
            $result = $this->pdfExtractor->extract($absolutePath, $maxFileSizeMb);
            return ['text' => $result->text, 'reason' => $result->skipReason];
        }
        if ($extension === 'txt' || $extension === 'md') {
            $content = (string) file_get_contents($absolutePath);
            return ['text' => $content, 'reason' => $content === '' ? 'empty_file' : null];
        }
        if ($extension === 'docx') {
            return $this->extractDocx($absolutePath);
        }
        if ($extension === 'odt') {
            return $this->extractOdt($absolutePath);
        }
        if ($extension === 'rtf') {
            return $this->extractRtf($absolutePath);
        }

        return ['text' => '', 'reason' => 'unsupported_extension_'.$extension];
    }

    /**
     * DOCX = ZIP mit word/document.xml. Wir lesen die XML, strippen alle
     * Tags und sammeln den reinen Text. Kein extra Composer-Package noetig.
     *
     * @return array{text:string, reason:?string}
     */
    private function extractDocx(string $absolutePath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['text' => '', 'reason' => 'docx_no_ziparchive'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return ['text' => '', 'reason' => 'docx_zip_open_failed'];
        }
        // Hauptdokument + ggf. Header/Footer einsammeln
        $xmlPaths = ['word/document.xml'];
        for ($i = 1; $i <= 3; $i++) {
            $xmlPaths[] = 'word/header'.$i.'.xml';
            $xmlPaths[] = 'word/footer'.$i.'.xml';
        }
        $text = '';
        foreach ($xmlPaths as $entry) {
            $raw = $zip->getFromName($entry);
            if ($raw === false || $raw === '') {
                continue;
            }
            // </w:p> -> Newline (Absaetze), Rest stripped
            $withBreaks = str_replace(['</w:p>', '</w:tab>', '<w:br/>', '<w:br />'], "\n", $raw);
            $stripped = trim(strip_tags($withBreaks));
            if ($stripped !== '') {
                $text .= $stripped."\n";
            }
        }
        $zip->close();
        $text = trim($text);
        if ($text === '') {
            return ['text' => '', 'reason' => 'docx_empty'];
        }
        return ['text' => $text, 'reason' => null];
    }

    /**
     * ODT = ZIP mit content.xml. Selbe Logik wie DOCX, andere Pfade.
     *
     * @return array{text:string, reason:?string}
     */
    private function extractOdt(string $absolutePath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['text' => '', 'reason' => 'odt_no_ziparchive'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return ['text' => '', 'reason' => 'odt_zip_open_failed'];
        }
        $raw = $zip->getFromName('content.xml');
        $zip->close();
        if (!\is_string($raw) || $raw === '') {
            return ['text' => '', 'reason' => 'odt_no_content'];
        }
        $withBreaks = str_replace(['</text:p>', '</text:h>', '<text:tab/>', '<text:line-break/>'], "\n", $raw);
        $text = trim(strip_tags($withBreaks));
        if ($text === '') {
            return ['text' => '', 'reason' => 'odt_empty'];
        }
        return ['text' => $text, 'reason' => null];
    }

    /**
     * RTF: Control-Codes (\xx) raus, geschweifte Klammern raus,
     * was uebrig bleibt ist im Wesentlichen der Text. Reicht fuer
     * Suche, ist nicht perfekt.
     *
     * @return array{text:string, reason:?string}
     */
    private function extractRtf(string $absolutePath): array
    {
        $raw = (string) file_get_contents($absolutePath);
        if ($raw === '') {
            return ['text' => '', 'reason' => 'rtf_empty_file'];
        }
        // Hex-Sequenzen \'xx -> richtiges Zeichen
        $raw = preg_replace_callback(
            '/\\\\\'([0-9a-fA-F]{2})/',
            static fn ($m) => chr(hexdec($m[1])),
            $raw,
        ) ?? $raw;
        // Unicode-Sequenzen \uNNNN
        $raw = preg_replace_callback(
            '/\\\\u(-?\d+)\??/',
            static fn ($m) => mb_chr((int) $m[1] & 0xFFFF, 'UTF-8'),
            $raw,
        ) ?? $raw;
        // Restliche Control-Words \word + optional Param + optional Space
        $raw = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', ' ', $raw) ?? $raw;
        // Geschweifte Klammern weg
        $raw = str_replace(['{', '}', '\\*', '\\\\'], ['', '', '', ''], $raw);
        // Whitespace normalisieren
        $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($text === '') {
            return ['text' => '', 'reason' => 'rtf_empty_after_strip'];
        }
        return ['text' => $text, 'reason' => null];
    }

    private function humanizeFilename(string $filename): string
    {
        $cleaned = preg_replace('/[-_]+/', ' ', $filename) ?? $filename;
        return ucwords(mb_strtolower(trim($cleaned)));
    }

    /**
     * Extrahiert den reinen Headline-Text. In Contao ist `tl_content.headline`
     * oft serialized als `a:2:{s:5:"value";s:24:"…";s:4:"unit";s:2:"h2";}`.
     */
    private function extractHeadlineText(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, 'a:')) {
            $data = @unserialize($raw, ['allowed_classes' => false]);
            if (\is_array($data) && isset($data['value'])) {
                return strip_tags((string) $data['value']);
            }
        }

        return strip_tags($raw);
    }

    private function unauthorizedStreamResponse(): StreamedResponse
    {
        $response = new StreamedResponse(static function (): void {
            echo "event: fatal\n";
            echo 'data: {"message":"unauthorized"}' . "\n\n";
        }, 403);
        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
