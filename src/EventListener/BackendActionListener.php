<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;
use Meilisearch\Client;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\DocumentIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\SiteCrawler;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Verarbeitet die "Komplett-Reindex" und "Status"-Buttons aus der DCA-Toolbar.
 *
 * Contao 5 erkennt Operationen mit `key=…` und ruft die zugehörige Callback
 * via `config.onload_callback` auf — wir hängen uns dort ein und routen je
 * nach `Input::get('key')` an die richtige Methode.
 */
final class BackendActionListener
{
    /** UI-Icons (currentColor SVG, vertical-aligned für Inline-Use). */
    private const SVG_ICON_PUBLIC = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;display:inline-block;"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
    private const SVG_ICON_LOCK = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;display:inline-block;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    private const SVG_ICON_REFRESH = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;display:inline-block;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';
    private const SVG_ICON_TRASH = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;display:inline-block;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';
    private const SVG_ICON_BAN = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;display:inline-block;"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>';
    private const SVG_ICON_EYE = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;display:inline-block;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    private const SVG_ICON_CHECK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;display:inline-block;"><polyline points="20 6 9 17 4 12"/></svg>';
    private const SVG_ICON_X = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;display:inline-block;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    private const SVG_ICON_CLOCK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;display:inline-block;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';

    public function __construct(
        private readonly SiteCrawler $crawler,
        private readonly Client $meilisearch,
        private readonly SettingsRepository $settings,
        private readonly Connection $db,
        private readonly DocumentIndexer $indexer,
    ) {
    }

    /**
     * Sorgt dafür dass der Index die neuen v0.4.0-Filter (is_protected,
     * allowed_groups) als FILTERABLE registriert hat. DocumentIndexer macht
     * das idempotent — mehrfacher Aufruf ist harmlos.
     */
    private function ensureIndexerSchema(string $locale): void
    {
        $this->indexer->ensureIndex($locale);
    }

    /**
     * Liest "zuletzt aktualisiert" aus dem Meilisearch-Stats-Response.
     * Verschiedene Versionen liefern den Key unterschiedlich (lastUpdate,
     * last_update, updatedAt). Wir versuchen alle.
     *
     * @param array<string, mixed> $stats
     */
    private function resolveLastUpdate(string $indexUid, array $stats): ?int
    {
        // Manche Meilisearch-Versionen liefern den Timestamp direkt im
        // /indexes/<uid>/stats-Response — die akzeptieren wir natürlich.
        foreach (['lastUpdate', 'last_update', 'updatedAt'] as $key) {
            if (!empty($stats[$key])) {
                $ts = is_numeric($stats[$key]) ? (int) $stats[$key] : strtotime((string) $stats[$key]);
                if ($ts !== false && $ts > 0) {
                    return $ts;
                }
            }
        }
        // Meilisearch v1.x liefert das updatedAt nicht in /stats sondern nur
        // im /indexes/<uid>-Endpoint. fetchRawInfo() holt das direkt vom
        // Server, fetchInfo()->getUpdatedAt() würde reichen, ist aber
        // unnötig wenn wir den Raw-Response sowieso parsen können.
        try {
            $idx = $this->meilisearch->index($indexUid);
            if (method_exists($idx, 'fetchRawInfo')) {
                $info = $idx->fetchRawInfo();
                if (is_array($info) && !empty($info['updatedAt'])) {
                    $ts = strtotime((string) $info['updatedAt']);
                    if ($ts !== false && $ts > 0) {
                        return $ts;
                    }
                }
            }
            if (method_exists($idx, 'getUpdatedAt')) {
                $val = $idx->getUpdatedAt();
                if ($val instanceof \DateTimeInterface) {
                    return $val->getTimestamp();
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    #[AsCallback(table: 'tl_venne_search_settings', target: 'config.onload')]
    public function onLoad(): void
    {
        $key = (string) ($_GET['key'] ?? '');
        $act = (string) ($_GET['act'] ?? '');

        // Direkt zur Edit-Maske wenn weder Action noch Key gesetzt sind.
        // Spart den Klick durch die ein-Eintrag-Listenansicht — das Bundle
        // ist ein Singleton (id=1), die Liste hat keinen Mehrwert.
        //
        // Wir generieren über Contaos Token-Manager einen frischen Request-
        // Token für die act=edit-URL. Sonst landet der User auf der "Ungültiges
        // Token"-Confirm-Page, weil act=edit als State-Change gilt und einen
        // passenden Token verlangt — der Token aus der Listing-View (do=venne_search)
        // gilt nicht für die Edit-URL.
        if ($key === '' && $act === '') {
            $token = '';
            try {
                $token = (string) \Contao\System::getContainer()
                    ->get('contao.csrf.token_manager')
                    ->getDefaultTokenValue();
            } catch (\Throwable) {
            }
            $url = '/contao?do=venne_search&act=edit&id=1' . ($token !== '' ? '&rt=' . urlencode($token) : '');
            header('Location: ' . $url);
            exit;
        }

        if ($key === '') {
            return;
        }

        if ($key === 'reindex') {
            $this->handleReindex();
        } elseif ($key === 'status') {
            $this->handleStatus();
        } elseif ($key === 'purge') {
            $this->handlePurge();
        } elseif ($key === 'debug-diff') {
            $this->handleDebugDiff();
        }
    }

    /**
     * READ-ONLY Debug-Button: vergleicht was die Site hat (Pages + Files) mit
     * dem was im Meilisearch-Index UND der DB-Resume-Liste drin ist. Schreibt
     * NIX in den Index, fasst NIX an. Output:
     *   - in der Backend-Message-Box (Zusammenfassung)
     *   - detailliertes Log nach var/logs/venne-search-debug.log
     */
    private function handleDebugDiff(): void
    {
        if (!$this->settings->isConfigured()) {
            $this->renderMessage('Debug nicht möglich', 'API-Key fehlt.', 'error');
            return;
        }

        try {
            $config = $this->settings->load();
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveException $e) {
            $this->renderMessage('Plattform-Verbindung fehlgeschlagen', $e->getMessage(), 'error');
            return;
        }

        $locale = $config->enabledLocales[0] ?? 'de';
        $indexUid = $config->indexPrefix.'_'.$locale;

        // Log-Datei vorbereiten
        $logDir = \dirname(__DIR__, 2).'/../../../var/logs';
        $kernelProjectDir = \Contao\System::getContainer()->getParameter('kernel.project_dir');
        $logDir = rtrim((string) $kernelProjectDir, '/').'/var/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir.'/venne-search-debug.log';
        $logHandle = @fopen($logFile, 'a');
        $log = function (string $msg) use ($logHandle): void {
            $line = '['.date('Y-m-d H:i:s').'] '.$msg."\n";
            if ($logHandle) {
                @fwrite($logHandle, $line);
            }
        };

        $log('====================================================');
        $log('DEBUG-DIFF gestartet (read-only, schreibt nichts).');
        $log('Index: '.$indexUid);

        // 1) Was hat die Site? (Pages + Files)
        $pageRows = $this->db->fetchAllAssociative(
            "SELECT id, alias, title FROM tl_page WHERE type IN ('regular', 'forward', 'redirect') AND published = '1' ORDER BY id ASC"
        );
        $fileRows = $this->db->fetchAllAssociative(
            "SELECT id, uuid, path, extension FROM tl_files WHERE type = 'file' ORDER BY path ASC"
        );
        $indexableExts = ['pdf', 'txt', 'md', 'docx', 'odt', 'rtf'];
        $sitePageIds = [];
        foreach ($pageRows as $row) {
            $sitePageIds['page-'.(int) $row['id']] = ['kind' => 'page', 'title' => (string) ($row['title'] ?? ''), 'alias' => (string) ($row['alias'] ?? '')];
        }
        $siteFileIds = [];
        foreach ($fileRows as $row) {
            $ext = strtolower((string) ($row['extension'] ?? ''));
            if (!\in_array($ext, $indexableExts, true)) {
                continue;
            }
            if ($ext === 'pdf' && !$config->indexPdfs) {
                continue;
            }
            $uuidBin = $row['uuid'] ?? null;
            $docId = ($uuidBin !== null && $uuidBin !== '') ? 'file-'.bin2hex((string) $uuidBin) : 'file-path-'.md5((string) $row['path']);
            $siteFileIds[$docId] = ['kind' => 'file', 'path' => (string) $row['path'], 'ext' => $ext];
        }
        $siteAllIds = $sitePageIds + $siteFileIds;

        $log('Site-Pages erfasst: '.\count($sitePageIds));
        $log('Site-Files erfasst (filterabel '.implode('/', $indexableExts).'): '.\count($siteFileIds));
        $log('Site-Total: '.\count($siteAllIds));

        // 2) Was ist im Meilisearch?
        $meiliIds = [];
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
                        $meiliIds[(string) $arr['id']] = true;
                    }
                }
                $offset += $limit;
                if (\count($resp) < $limit) {
                    break;
                }
            }
            $log('Meilisearch-Docs gelesen: '.\count($meiliIds));
        } catch (\Throwable $e) {
            $log('FEHLER beim Lesen von Meilisearch: '.$e->getMessage());
            $this->renderMessage('Debug-Fehler', 'Meilisearch nicht erreichbar: '.$e->getMessage(), 'error');
        }

        // 3) Was ist in der DB-Resume-Liste?
        $dbDoneIds = [];
        try {
            $row = $this->db->fetchAssociative('SELECT reindex_done_ids FROM tl_venne_search_settings WHERE id = 1');
            $raw = is_array($row) ? (string) ($row['reindex_done_ids'] ?? '') : '';
            if ($raw !== '') {
                $arr = json_decode($raw, true);
                if (is_array($arr)) {
                    foreach ($arr as $id) {
                        if (is_string($id)) {
                            $dbDoneIds[$id] = true;
                        }
                    }
                }
            }
            $log('DB-Resume-Liste (reindex_done_ids): '.\count($dbDoneIds));
        } catch (\Throwable $e) {
            $log('FEHLER beim Lesen der DB-Resume-Liste: '.$e->getMessage());
        }

        // 4) Diff: was würde der Reindex tun?
        $wouldSkip = [];
        $wouldIndex = [];
        $orphanInIndex = [];
        foreach ($siteAllIds as $docId => $info) {
            // Was der Stream-Controller checkt: $alreadyIndexedIds (= dbDoneIds nach v0.17+).
            // Aelterer Code (v0.16-) checkt dagegen Meilisearch direkt.
            $inDb = isset($dbDoneIds[$docId]);
            $inMeili = isset($meiliIds[$docId]);
            if ($inDb || $inMeili) {
                $wouldSkip[$docId] = ['inDb' => $inDb, 'inMeili' => $inMeili, 'info' => $info];
            } else {
                $wouldIndex[$docId] = $info;
            }
        }
        // Index-Orphans: docs im Meilisearch die nicht mehr auf der Site sind
        foreach ($meiliIds as $docId => $_) {
            if (!isset($siteAllIds[$docId])) {
                $orphanInIndex[$docId] = true;
            }
        }

        $log('--- Diff-Ergebnis ---');
        $log('Wuerden uebersprungen (in DB ODER Index): '.\count($wouldSkip));
        $log('Wuerden NEU indexiert: '.\count($wouldIndex));
        $log('Orphans (im Index aber nicht mehr auf Site): '.\count($orphanInIndex));

        // 5) Sample der ersten 50 wouldIndex (das ist der Verdaechtigen-Pool)
        $log('--- Erste 50 die NEU indexiert wuerden ---');
        $count = 0;
        foreach ($wouldIndex as $docId => $info) {
            if (++$count > 50) break;
            $detail = $info['kind'] === 'page'
                ? sprintf('PAGE id=%s alias=%s title=%s', substr($docId, 5), $info['alias'], $info['title'])
                : sprintf('FILE path=%s ext=%s', $info['path'], $info['ext']);
            $log('  '.$docId.'  '.$detail);
        }

        // 6) Sample der ersten 20 wouldSkip wo inDb=false aber inMeili=true (Resume-Bug-Verdacht)
        $log('--- Erste 20 die in Meilisearch aber NICHT in DB-Resume sind (= Bug bei v0.17+) ---');
        $count = 0;
        foreach ($wouldSkip as $docId => $row) {
            if ($row['inDb'] === false && $row['inMeili'] === true) {
                if (++$count > 20) break;
                $log('  '.$docId);
            }
        }

        // 7) Stichprobe DB-DoneIds: erste 5
        $log('--- Stichprobe DB-DoneIds (erste 5) ---');
        $count = 0;
        foreach ($dbDoneIds as $id => $_) {
            if (++$count > 5) break;
            $log('  '.$id);
        }

        // 8) Stichprobe MeiliIds: erste 5
        $log('--- Stichprobe Meili-IDs (erste 5) ---');
        $count = 0;
        foreach ($meiliIds as $id => $_) {
            if (++$count > 5) break;
            $log('  '.$id);
        }

        $log('DEBUG-DIFF fertig.');
        $log('====================================================');
        if ($logHandle) {
            @fclose($logHandle);
        }

        // Sample: erste 30 wouldIndex docIds direkt in die Message-Box
        $sampleNew = [];
        $count = 0;
        foreach ($wouldIndex as $docId => $info) {
            if (++$count > 30) break;
            $detail = $info['kind'] === 'page'
                ? sprintf('PAGE id=%s alias=%s', substr($docId, 5), $info['alias'])
                : sprintf('FILE %s', $info['path']);
            $sampleNew[] = $docId.'  '.$detail;
        }

        // Sample: 10 IDs in Meili die NICHT in DB sind (= Resume-Bug)
        $sampleMeiliNotInDb = [];
        $count = 0;
        foreach ($meiliIds as $id => $_) {
            if (!isset($dbDoneIds[$id])) {
                if (++$count > 10) break;
                $sampleMeiliNotInDb[] = $id;
            }
        }

        // Sample-DB-IDs vs Sample-Meili-IDs (Format-Vergleich)
        $sampleDb = array_slice(array_keys($dbDoneIds), 0, 5);
        $sampleMeili = array_slice(array_keys($meiliIds), 0, 5);

        $msg = sprintf(
            "Site-Total: %d (%d Pages + %d Files)\n".
            "Meilisearch hat: %d Docs\n".
            "DB-Resume-Liste hat: %d Docs ← sollte gleich Meilisearch sein!\n".
            "Diff Meili-DB: %d Docs sind im Index aber nicht in DB-Liste (= Resume-Bug)\n".
            "Wuerden uebersprungen: %d\n".
            "Wuerden NEU indexiert: %d\n".
            "Orphans im Index (auf Site weg): %d\n".
            "\n--- Erste 30 die NEU indexiert wuerden ---\n%s\n".
            "\n--- 10 IDs in Meili aber NICHT in DB-Resume (= bestaetigt Resume-Bug) ---\n%s\n".
            "\n--- Format-Check Stichprobe DB-IDs ---\n%s\n".
            "\n--- Format-Check Stichprobe Meili-IDs ---\n%s",
            \count($siteAllIds), \count($sitePageIds), \count($siteFileIds),
            \count($meiliIds),
            \count($dbDoneIds),
            \count($meiliIds) - \count(array_intersect_key($meiliIds, $dbDoneIds)),
            \count($wouldSkip),
            \count($wouldIndex),
            \count($orphanInIndex),
            implode("\n", $sampleNew) ?: '(keine)',
            implode("\n", $sampleMeiliNotInDb) ?: '(keine)',
            implode("\n", $sampleDb) ?: '(keine)',
            implode("\n", $sampleMeili) ?: '(keine)',
        );
        $this->renderMessage('Debug-Diff (read-only)', $msg, 'info');
    }

    private function handlePurge(): void
    {
        if (!$this->settings->isConfigured()) {
            $this->renderMessage('Nicht möglich', 'API-Key fehlt.', 'error');
            return;
        }

        try {
            $config = $this->settings->load();
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveException $e) {
            $this->renderMessage('Plattform-Verbindung fehlgeschlagen', $e->getMessage(), 'error');
            return;
        }

        $deletedIndexes = 0;

        foreach ($config->enabledLocales as $locale) {
            $indexUid = $config->indexPrefix.'_'.$locale;
            try {
                // Komplettes Löschen aller Dokumente.
                $this->meilisearch->index($indexUid)->deleteAllDocuments();
                ++$deletedIndexes;
            } catch (\Throwable $e) {
                // Index existiert evtl. nicht — egal, ist ja eh leer.
            }
            // WICHTIG: nach dem Leeren das Schema neu setzen, damit auch
            // alte Indexe die neuen Filterable-Attribute (is_protected,
            // allowed_groups) kennen. Sonst zeigt der Documents-Panel
            // "attribute not filterable"-Error sobald jemand reinguckt.
            try {
                $this->indexer->ensureIndex($locale);
            } catch (\Throwable) {
            }
        }

        // Reindex-Status + Resume-Liste zurücksetzen
        try {
            $this->db->executeStatement(
                'UPDATE tl_venne_search_settings SET reindex_total = 0, reindex_started_at = 0, reindex_done_ids = NULL WHERE id = 1'
            );
        } catch (\Throwable) {
        }

        $this->renderMessage(
            'Index geleert',
            sprintf('Alle Dokumente wurden aus %d Sprachindex(en) entfernt. Klick „Jetzt indexieren" für eine frische Komplett-Indexierung.', $deletedIndexes),
            'success',
        );
    }

    private function handleReindex(): void
    {
        if (!$this->settings->isConfigured()) {
            $this->renderMessage(
                'Indexierung nicht möglich',
                'Trage zuerst deinen API-Key unter „Bearbeiten" ein.',
                'error',
            );
            return;
        }

        // Plattform-Verbindung testen, bevor wir Reindex starten
        try {
            $this->settings->load();
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveException $e) {
            $this->renderMessage('Plattform-Verbindung fehlgeschlagen', $e->getMessage(), 'error');
            return;
        }

        $stats = $this->crawler->reindexAll();
        $total = $stats['pages'] + $stats['files'];

        // Total + Startzeit in DB schreiben für die Progress-Bar.
        try {
            $this->db->executeStatement(
                'UPDATE tl_venne_search_settings SET reindex_total = ?, reindex_started_at = ? WHERE id = 1',
                [$total, time()],
            );
        } catch (\Throwable) {
            // Spalten existieren noch nicht (Migration noch nicht gelaufen) — egal, Bar fällt back.
        }

        $this->renderMessage(
            'Reindex gestartet',
            sprintf(
                '%d Seiten und %d Dateien wurden eingereiht. Die Indexierung läuft im Hintergrund — die Fortschrittsanzeige aktualisiert sich automatisch.',
                $stats['pages'],
                $stats['files'],
            ),
            'success',
        );
    }

    private function handleStatus(): void
    {
        if (!$this->settings->isConfigured()) {
            $this->renderMessage('Status nicht möglich', 'API-Key fehlt — bitte erst konfigurieren.', 'error');
            return;
        }
        try {
            $config = $this->settings->load();
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveException $e) {
            $this->renderMessage('Plattform-Verbindung fehlgeschlagen', $e->getMessage(), 'error');
            return;
        }
        if (!$this->settings->isConfigured()) {
            $this->renderMessage(
                'Status',
                'Noch nicht konfiguriert — kein API-Key hinterlegt.',
                'info',
            );
        }

        $rows = ['<p><strong>Aktive Sprachen:</strong> '.implode(', ', $config->enabledLocales).'</p>'];

        foreach ($config->enabledLocales as $locale) {
            $indexUid = $config->indexPrefix.'_'.$locale;
            try {
                $stats = $this->meilisearch->index($indexUid)->stats();
                $rows[] = sprintf(
                    '<p><strong>%s</strong>: %s Dokumente · zuletzt aktualisiert: %s</p>',
                    htmlspecialchars($locale),
                    number_format((int) ($stats['numberOfDocuments'] ?? 0), 0, ',', '.'),
                    htmlspecialchars((string) ($stats['lastUpdate'] ?? '—')),
                );
            } catch (\Throwable $e) {
                $rows[] = sprintf(
                    '<p><strong>%s</strong>: noch keine Daten (%s)</p>',
                    htmlspecialchars($locale),
                    htmlspecialchars($e->getMessage()),
                );
            }
        }

        $this->renderMessage('Index-Status', implode('', $rows), 'info', false);
    }

    /**
     * Statt direkt mit echo+exit aus dem Contao-Backend rauszufallen, hängen
     * wir die Confirmation-Message in $_SESSION['CONFIRM_MESSAGES'] (Contao's
     * eigene Message-API) und redirecten zurück zur Listenansicht. So bleibt
     * das komplette Backend-Layout (Sidebar, Header, Footer) erhalten.
     */
    private function renderMessage(string $title, string $body, string $type, bool $exitAfter = true): void
    {
        $bucket = match ($type) {
            'error' => 'TL_ERROR',
            'info' => 'TL_INFO',
            default => 'TL_CONFIRM',
        };

        $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $body));
        \Contao\Message::add(\sprintf("%s\n%s", $title, trim($plain)), $bucket);

        if ($exitAfter) {
            $token = (string) ($_GET['rt'] ?? '');
            $url = '/contao?do=venne_search'.($token !== '' ? '&rt='.urlencode($token) : '');
            header('Location: '.$url);
            exit;
        }
    }

    /**
     * DCA-input_field_callback: rendert den großen Reindex-Button mit
     * Beschreibung, was passiert. Klick führt zur ?key=reindex Action,
     * die in onLoad() abgefangen wird.
     */
    public static function renderReindexPanel(): string
    {
        /** @var self $self */
        $self = \Contao\System::getContainer()->get(self::class);

        return $self->buildReindexHtml();
    }

    /**
     * Gemeinsamer Stylesheet-Block für alle Bundle-Backend-Panels.
     * Überschreibt unsere Light-Mode-Inline-Styles wenn Contao auf Dark-Mode
     * umschaltet (`html[data-color-scheme=dark]` oder `prefers-color-scheme`),
     * damit weiße Karten und schwarze Schrift nicht in Augen knallen.
     *
     * Wir nutzen Contao-eigene Backend-Variablen (`--content-bg`, `--text`,
     * `--border` etc.) — die switchen selbst zwischen Light + Dark.
     */
    private function darkModeStyleBlock(): string
    {
        return '<style>'
            . '.vsearch-panel{color:var(--text,#1f2937);}'
            // Karten + Container: weißer Hintergrund → Contao-Content-BG
            . '.vsearch-panel [style*="background:#fff"],'
            . '.vsearch-panel [style*="background: #fff"],'
            . '.vsearch-panel [style*="background:#ffffff"]{background:var(--content-bg,#fff)!important;color:var(--text,#1f2937)!important;}'
            // Soft-Greys (#f9fafb, #f3f4f6) → leichte Dark-Variante
            . '.vsearch-panel [style*="background:#f9fafb"],'
            . '.vsearch-panel [style*="background: #f9fafb"]{background:var(--table-even,#f9fafb)!important;}'
            . '.vsearch-panel [style*="background:#f3f4f6"]{background:var(--code-bg,#f3f4f6)!important;color:var(--text,#374151)!important;}'
            // Border-Greys
            . '.vsearch-panel [style*="border:1px solid #d1d5db"],'
            . '.vsearch-panel [style*="border:1px solid #e5e7eb"]{border-color:var(--border,#d1d5db)!important;}'
            // Text-Farben (dunkel auf hellem BG → hell auf dunklem BG)
            . '.vsearch-panel [style*="color:#1f2937"],'
            . '.vsearch-panel [style*="color: #1f2937"],'
            . '.vsearch-panel [style*="color:#111827"]{color:var(--text,#1f2937)!important;}'
            . '.vsearch-panel [style*="color:#374151"],'
            . '.vsearch-panel [style*="color:#4b5563"],'
            . '.vsearch-panel [style*="color:#6b7280"],'
            . '.vsearch-panel [style*="color:#9ca3af"]{color:var(--info,#6b7280)!important;}'
            // Form-Inputs/Selects/Buttons
            . '.vsearch-panel input[type=text],'
            . '.vsearch-panel input[type=search],'
            . '.vsearch-panel select,'
            . '.vsearch-panel textarea{background:var(--form-bg,#fff)!important;color:var(--text,#1f2937)!important;border-color:var(--form-border,#d1d5db)!important;}'
            // Status-/Stat-Boxen mit „bunten" Hintergründen (warn-yellow,
            // error-red, info-blue) bleiben farblich erkennbar — die Contao-
            // Dark-Theme-Variablen schalten sie automatisch auf gedämpfte Töne
            // wenn vorhanden, sonst greift unser Fallback.
            . '@media (prefers-color-scheme: dark){'
            . '  .vsearch-panel [style*="background:#fffbeb"]{background:rgba(202,138,4,0.12)!important;}'
            . '  .vsearch-panel [style*="background:#fef2f2"]{background:rgba(220,38,38,0.12)!important;}'
            . '  .vsearch-panel [style*="background:rgba(255,255,255,0.05)"]{background:rgba(255,255,255,0.04)!important;}'
            . '}'
            . 'html[data-color-scheme=dark] .vsearch-panel [style*="background:#fffbeb"]{background:rgba(202,138,4,0.15)!important;}'
            . 'html[data-color-scheme=dark] .vsearch-panel [style*="background:#fef2f2"]{background:rgba(220,38,38,0.12)!important;}'
            . '</style>';
    }

    public function buildReindexHtml(): string
    {
        if (!$this->settings->isConfigured()) {
            return '<div class="tl_help tl_tip" style="padding:12px;color:#94a3b8;">Trag erst deinen API-Key oben ein und speichere.</div>';
        }

        // v0.3.0: Plan-First-Flow. Browser-JS ruft erst /reindex/plan auf,
        // zeigt dem User die komplette Vorschau (was neu, was schon im Index,
        // welche Karteileichen). Dann läuft eine JS-Schleife pro Item gegen
        // /reindex/item — jeder Request ist isoliert, max 10s, keine SSE-
        // Drama mehr. Am Ende: /reindex/finalize.
        $planUrl = '/contao/venne-search/reindex-plan';
        $itemUrl = '/contao/venne-search/reindex-item';
        $finalizeUrl = '/contao/venne-search/reindex-finalize';

        // PDF-Flag direkt aus DB lesen — SettingsRepository::load() koennte
        // gecacht sein und stale-Wert zurueckgeben. Direct-Read = Wahrheit.
        $pdfEnabled = true;
        try {
            $pdfRow = $this->db->fetchOne('SELECT index_pdfs FROM tl_venne_search_settings WHERE id = 1');
            $pdfEnabled = ($pdfRow === '1' || $pdfRow === 1 || $pdfRow === true);
        } catch (\Throwable) {
        }
        $fileTypeNote = $pdfEnabled
            ? 'PDF, DOCX, TXT, MD, RTF, ODT'
            : 'DOCX, TXT, MD, RTF, ODT (PDF deaktiviert)';

        // Purge-URL — Token aus aktuellem Request, Pfad absolut auf /contao
        $token = (string) ($_GET['rt'] ?? '');
        $purgeUrl = '/contao?do=venne_search&key=purge'.($token !== '' ? '&rt='.urlencode($token) : '');

        $bundleVersion = $this->resolveBundleVersion();

        // Inline-SVG-Icons
        $iconBox = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
        $iconReindex = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';
        $iconTrash = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';

        return sprintf(
            $this->darkModeStyleBlock().
            '<div class="vsearch-panel" style="padding:14px 18px;">'.
            '<div style="padding:18px 22px;border:1px solid #4a8087;border-radius:10px;background:#fff;color:#1f2937;">'.
            '<div style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap;">'.
            '<div style="flex:1;min-width:280px;display:flex;gap:12px;align-items:flex-start;">'.
            '<span style="display:inline-flex;width:38px;height:38px;align-items:center;justify-content:center;border-radius:8px;background:rgba(74,128,135,0.1);color:#3a7178;flex-shrink:0;">'.$iconBox.'</span>'.
            '<div style="padding-right:1rem;">'.
            '<h3 style="margin:0 0 4px;color:#1f2937;font-size:1rem;font-weight:600;line-height:1.3;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">'.
                '<span>Komplett-Indexierung deiner Site</span>'.
                '<span style="font-size:.7rem;font-weight:500;padding:2px 8px;border-radius:999px;background:rgba(74,128,135,0.1);color:#3a7178;letter-spacing:.02em;">v'.htmlspecialchars($bundleVersion).'</span>'.
            '</h3>'.
            '<p style="margin:0;color:#4b5563;font-size:.9rem;line-height:1.5;padding-right:.4rem;">Crawlt <strong style="color:#1f2937;">alle aktiven Seiten</strong>, Artikel und Dateien aus dem Datei-Bereich (%s). Erst Vorschau, dann live pro Datei — du siehst <strong>vorab</strong>, was indexiert wird.</p>'.
            '</div>'.
            '</div>'.
            '<div style="display:flex;flex-direction:column;gap:.55rem;">'.
            '<button type="button" id="vsearch-reindex-btn" data-plan-url="%s" data-item-url="%s" data-finalize-url="%s" data-pdf-enabled="%s" style="display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.75rem 1.4rem;background:#3a7178;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer;font-size:.95rem;white-space:nowrap;">'.
            $iconReindex.'<span>Vorschau & Indexieren</span>'.
            '</button>'.
            '<a href="%s" id="vsearch-purge-btn" style="display:inline-flex;align-items:center;justify-content:center;gap:.45rem;padding:.55rem 1.1rem;background:#fff;color:#b91c1c;border:1px solid #fca5a5;border-radius:8px;font-weight:500;font-size:.85rem;text-decoration:none;white-space:nowrap;">'.
            $iconTrash.'<span>Index leeren</span>'.
            '</a>'.
            '</div>'.
            '</div>'.
            // Plan-Übersicht (zeigt: total/new/existing/orphans)
            '<div id="vsearch-plan-wrap" style="margin-top:18px;display:none;"></div>'.
            // Progress-Bar + Log
            '<div id="vsearch-progress-wrap" style="margin-top:18px;display:none;">'.
            '<div style="display:flex;justify-content:space-between;font-size:.88rem;color:#4b5563;margin-bottom:6px;flex-wrap:wrap;gap:.5rem;">'.
            '<span><strong id="vsearch-progress-label" style="color:#1f2937;">Starte…</strong></span>'.
            '<span style="display:flex;gap:.8rem;align-items:center;">'.
                '<button type="button" id="vsearch-pause-btn" style="background:#fff;color:#4b5563;border:1px solid #d1d5db;padding:.25rem .65rem;border-radius:5px;font-size:.78rem;cursor:pointer;font-weight:500;">Pause</button>'.
                '<span id="vsearch-progress-percent" style="color:#1f2937;font-weight:700;">0 %%</span>'.
            '</span>'.
            '</div>'.
            '<div style="height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden;">'.
            '<div id="vsearch-progress-bar" style="height:100%%;width:0%%;background:linear-gradient(90deg,#3a7178,#16a34a);transition:width .25s ease;"></div>'.
            '</div>'.
            '<div id="vsearch-progress-log" style="margin-top:12px;max-height:240px;overflow-y:auto;font-size:.82rem;color:#374151;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;line-height:1.6;background:#f9fafb;padding:10px 14px;border-radius:6px;border:1px solid #e5e7eb;"></div>'.
            '</div>'.
            '</div>'.
            '<script>(function(){'.
            // Inline SVG-Icons für Permissions (statt Emojis Globus 🌍 / Schloss 🔒)
            'var SVG_PUBLIC=\'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;display:inline-block;"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>\';'.
            'var SVG_LOCK=\'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;display:inline-block;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>\';'.
            'var btn=document.getElementById("vsearch-reindex-btn");'.
            'if(!btn||btn.dataset.bound)return;'.
            'btn.dataset.bound="1";'.
            'var planWrap=document.getElementById("vsearch-plan-wrap");'.
            'var wrap=document.getElementById("vsearch-progress-wrap");'.
            'var bar=document.getElementById("vsearch-progress-bar");'.
            'var lbl=document.getElementById("vsearch-progress-label");'.
            'var pct=document.getElementById("vsearch-progress-percent");'.
            'var log=document.getElementById("vsearch-progress-log");'.
            'var pauseBtn=document.getElementById("vsearch-pause-btn");'.
            // Auto-Concurrency: Standard 2 (sicher auf shared hosting).
            // Bei Error-Storm (3 Errors innerhalb 10 Items) automatisch auf
            // 1 runterregeln — Server überlastet, lieber langsam als gar nicht.
            'var CONCURRENCY=2;var recentErrors=[];'.
            'var planUrl=btn.dataset.planUrl;'.
            'var itemUrl=btn.dataset.itemUrl;'.
            'var finalizeUrl=btn.dataset.finalizeUrl;'.
            // State
            'var state={runId:"",queue:[],orphans:[],inFlight:0,indexed:0,skipped:0,errors:0,total:0,plannedTotal:0,startedAt:0,paused:false,done:false};'.
            'function appendLog(msg,kind){var line=document.createElement("div");line.textContent=msg;'.
            'if(kind==="error")line.style.color="#b91c1c";'.
            'else if(kind==="skip")line.style.color="#9ca3af";'.
            'else if(kind==="info")line.style.color="#1d4ed8";'.
            'else if(kind==="ok")line.style.color="#059669";'.
            'else line.style.color="#374151";'.
            'log.appendChild(line);log.scrollTop=log.scrollHeight;'.
            'while(log.childNodes.length>500)log.removeChild(log.firstChild);}'.
            'function fmtEta(){'.
            ' if(state.startedAt===0||state.indexed+state.skipped===0)return "";'.
            ' var elapsed=(Date.now()-state.startedAt)/1000;'.
            ' var done=state.indexed+state.skipped;'.
            ' var rate=done/elapsed;if(rate<.01)return "";'.
            ' var remaining=Math.max(0,state.plannedTotal-done);'.
            ' var eta=Math.ceil(remaining/rate);'.
            ' if(eta<60)return rate.toFixed(1)+"/s · ETA "+eta+"s";'.
            ' if(eta<3600)return rate.toFixed(1)+"/s · ETA "+Math.floor(eta/60)+"m "+(eta%%60)+"s";'.
            ' return rate.toFixed(1)+"/s · ETA "+Math.floor(eta/3600)+"h "+Math.floor((eta%%3600)/60)+"m";'.
            '}'.
            'function refreshUi(){'.
            ' var done=state.indexed+state.skipped+state.errors;'.
            ' var p=state.plannedTotal>0?Math.round(done/state.plannedTotal*100):0;'.
            ' bar.style.width=p+"%%";'.
            ' pct.textContent=p+" %%";'.
            ' var eta=fmtEta();'.
            ' lbl.textContent=done+" / "+state.plannedTotal+" — "+state.indexed+" neu, "+state.skipped+" übersprungen"+(state.errors>0?", "+state.errors+" Fehler":"")+(eta?" · "+eta:"");'.
            '}'.
            // === MODAL-Builder ===
            'function showModal(opts){'.
            ' var bg=document.createElement("div");'.
            ' bg.style.cssText="position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:1rem;animation:vsfade .15s ease-out;";'.
            ' var box=document.createElement("div");'.
            ' box.style.cssText="max-width:560px;width:100%%;background:#fff;border-radius:12px;box-shadow:0 24px 64px -12px rgba(0,0,0,.4);padding:1.6rem 1.7rem;color:#1f2937;font-family:inherit;max-height:90vh;overflow-y:auto;";'.
            ' box.innerHTML=opts.html;'.
            ' bg.appendChild(box);document.body.appendChild(bg);'.
            ' var close=function(){bg.remove();};'.
            ' if(opts.onMount)opts.onMount(box,close);'.
            ' bg.addEventListener("click",function(e){if(e.target===bg&&!opts.persistent)close();});'.
            ' document.addEventListener("keydown",function esc(e){if(e.key==="Escape"&&!opts.persistent){close();document.removeEventListener("keydown",esc);}});'.
            ' return close;'.
            '}'.
            'if(!document.getElementById("vs-modal-style")){var st=document.createElement("style");st.id="vs-modal-style";st.textContent="@keyframes vsfade{from{opacity:0;transform:scale(.97)}to{opacity:1;transform:scale(1)}}";document.head.appendChild(st);}'.
            // === ITEM-LOOP ===
            'function indexItem(item,retry){'.
            ' state.inFlight++;'.
            ' return fetch(itemUrl,{method:"POST",headers:{"Content-Type":"application/json","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin",body:JSON.stringify({runId:state.runId,item:item})})'.
            // Statt blindem .json() lesen wir erst Text, dann parsen wir.
            // Bei Crash kriegen wir den echten Body (HTML-Fehlerseite, PHP-
            // Fatal o.ä.) und können das im Log anzeigen — sonst sieht der
            // User nur "invalid_json" und weiß nicht warum.
            '  .then(function(r){return r.text().then(function(t){var d;try{d=JSON.parse(t);}catch(e){d={ok:false,error:"invalid_json:HTTP_"+r.status+":"+(t||"").substring(0,120).replace(/\\s+/g," ").trim()};}return{status:r.status,data:d};});})'.
            '  .then(function(res){'.
            '   state.inFlight--;'.
            '   var d=res.data;'.
            '   if(d&&d.ok&&d.skipped){'.
            '    state.skipped++;'.
            '    var humanReason=(function(r){if(!r)return "unbekannt";'.
            '     if(r==="pdf_encrypted_or_restricted"||r==="pdf_secured_skipped_fast"||r==="parse_failed:Secured pdf file are currently not supported.")return "PDF verschlüsselt oder mit Lese-Einschränkung";'.
            '     if(r==="empty_textlayer_image_only")return "PDF nur Bilder, kein Text";'.
            '     if(r==="pdf_header_missing")return "Keine gültige PDF-Datei";'.
            '     if(r==="pdf_eof_missing_corrupt")return "PDF beschädigt/abgeschnitten";'.
            '     if(r==="pdf_too_complex_skipped")return "PDF zu komplex (Scan/Bilder)";'.
            '     if(r.indexOf("pdf_too_big_for_memory_")===0)return "PDF zu groß für Memory ("+r.split("_").pop()+" MB)";'.
            '     if(r==="file_unreadable"||r==="file_not_readable")return "Datei nicht lesbar";'.
            '     if(r==="permission_excluded")return "durch Berechtigung ausgeschlossen";'.
            '     if(r==="page_noindex_robots")return "Page hat noindex-Robots-Tag";'.
            '     if(r==="page_no_search_flag")return "Page hat noSearch-Flag";'.
            '     if(r.indexOf("file_too_large_")===0)return "Datei zu groß";'.
            '     if(r.indexOf("parse_timeout")===0)return "PDF-Parser-Timeout";'.
            '     if(r.indexOf("parse_failed:")===0)return "Parse-Fehler: "+r.substring(13,80);'.
            '     return r;'.
            '    })(d.reason);'.
            '    appendLog("⊘ "+item.label+" — übersprungen ("+humanReason+")","skip");'.
            '   }else if(d&&d.ok){'.
            '    state.indexed++;'.
            '    var len=d.contentLen?(", "+(d.contentLen+"").replace(/\\B(?=(\\d{3})+(?!\\d))/g,".")+" Zeichen"):"";'.
            '    var dur=d.durationMs?(", "+d.durationMs+"ms"):"";'.
            '    appendLog("[OK] "+item.label+len+dur,"ok");'.
            '   }else{'.
            '    var rt=retry||0;'.
            '    var err=(d&&d.error)||"fehler";'.
            // PHP-Fatal / Memory / Server-500: Retry hat keinen Sinn —
            // wird wieder gleich crashen. Nur Netzwerk-Fehler retryen.
            '    var noRetry=err.indexOf("invalid_json:HTTP_5")===0||err.indexOf("php_fatal")===0||err.indexOf("item_crash")===0;'.
            '    if(rt<2&&!noRetry){'.
            '     appendLog("[Retry] "+item.label+" — "+(rt+1)+"/2 ("+err+")","info");'.
            '     return new Promise(function(res){setTimeout(function(){res(indexItem(item,rt+1));},800*(rt+1));});'.
            '    }'.
            '    state.errors++;trackError();'.
            '    appendLog("[FAIL] "+item.label+" — "+err,"error");'.
            '   }'.
            '   refreshUi();pump();'.
            '  }).catch(function(e){'.
            '   state.inFlight--;'.
            '   var rt=retry||0;'.
            '   if(rt<2){'.
            '    appendLog("[Retry] "+item.label+" — Netzwerk "+(rt+1)+"/2","info");'.
            '    return new Promise(function(res){setTimeout(function(){res(indexItem(item,rt+1));},1500*(rt+1));});'.
            '   }'.
            '   state.errors++;trackError();'.
            '   appendLog("[FAIL] "+item.label+" — Netzwerk-Fehler: "+(e&&e.message||"unbekannt"),"error");'.
            '   refreshUi();pump();'.
            '  });'.
            '}'.
            'function pump(){'.
            ' if(state.done)return;'.
            ' while(!state.paused&&state.queue.length>0&&state.inFlight<CONCURRENCY){'.
            '  var item=state.queue.shift();'.
            '  indexItem(item,0);'.
            ' }'.
            ' if(state.queue.length===0&&state.inFlight===0){finalize();}'.
            '}'.
            // Adaptive Downgrade: zähle Errors der letzten 10 Items.
            // 3+ Errors in den letzten 10 → CONCURRENCY auf 1 runter,
            // Server ist offenbar überlastet.
            'function trackError(){recentErrors.push(state.indexed+state.skipped+state.errors);'.
            ' var done=state.indexed+state.skipped+state.errors;'.
            ' recentErrors=recentErrors.filter(function(idx){return done-idx<=10;});'.
            ' if(recentErrors.length>=3&&CONCURRENCY>1){CONCURRENCY=1;appendLog("[!] Server-Überlastung erkannt — schalte auf 1 parallel runter","info");}'.
            '}'.
            'function finalize(){'.
            ' if(state.done)return;state.done=true;'.
            ' appendLog("Finalize wird gesendet …","info");'.
            ' fetch(finalizeUrl,{method:"POST",headers:{"Content-Type":"application/json","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin",body:JSON.stringify({runId:state.runId,indexed:state.indexed,skipped:state.skipped,errors:state.errors,removeOrphans:true,orphans:state.orphans})})'.
            '  .then(function(r){return r.json();})'.
            '  .then(function(d){'.
            '   var orph=d&&d.orphansRemoved?d.orphansRemoved:0;'.
            '   appendLog("[Fertig] "+state.indexed+" indexiert, "+state.skipped+" übersprungen"+(state.errors>0?", "+state.errors+" Fehler":"")+(orph>0?", "+orph+" Karteileichen entfernt":""),"info");'.
            '   bar.style.width="100%%";pct.textContent="100 %%";'.
            '   lbl.textContent="Fertig — "+state.indexed+"/"+state.plannedTotal+" indexiert";'.
            '   btn.disabled=false;btn.innerHTML=btn.dataset.originalHtml;'.
            '   try{localStorage.removeItem("vsearchRun");}catch(e){}'.
            '  }).catch(function(){'.
            '   appendLog("[!] Finalize-Call fehlgeschlagen — Daten sind aber im Index.","info");'.
            '   btn.disabled=false;btn.innerHTML=btn.dataset.originalHtml;'.
            '  });'.
            '}'.
            'pauseBtn.addEventListener("click",function(){'.
            ' state.paused=!state.paused;'.
            ' pauseBtn.textContent=state.paused?"Weiter":"Pause";'.
            ' if(!state.paused)pump();'.
            '});'.
            'btn.dataset.originalHtml=btn.innerHTML;'.
            // === START: Plan abrufen, Vorschau zeigen ===
            'btn.addEventListener("click",function(){'.
            ' btn.disabled=true;btn.textContent="Lade Vorschau…";'.
            ' planWrap.style.display="none";wrap.style.display="none";'.
            ' fetch(planUrl,{method:"POST",headers:{"Content-Type":"application/json","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin",body:"{}"})'.
            '  .then(function(r){return r.json();})'.
            '  .then(function(d){'.
            '   if(!d||!d.ok){throw new Error(d&&d.error||"Plan-Call fehlgeschlagen");}'.
            '   showPlan(d);'.
            '  }).catch(function(e){'.
            '   alert("Fehler beim Lesen der Plan-Vorschau: "+(e&&e.message||"unbekannt"));'.
            '   btn.disabled=false;btn.innerHTML=btn.dataset.originalHtml;'.
            '  });'.
            '});'.
            'function showPlan(plan){'.
            ' state.runId=plan.runId;'.
            ' state.orphans=plan.orphans||[];'.
            ' var s=plan.stats||{total:0,new:0,existing:0,excluded:0,orphans:0,public:0,protected:0};'.
            // "excluded"-Items werden NIE indexiert (Modus oder Pattern) — die fliegen aus der Item-Loop raus
            ' var newItems=(plan.items||[]).filter(function(it){return it.status==="new";});'.
            ' var allItems=(plan.items||[]).filter(function(it){return it.status!=="excluded";});'.
            // Plain-Text-Liste (im pre-Block, kann kein SVG) — knapper Marker
            ' var listPreview=newItems.slice(0,12).map(function(it){var marker=it.permission==="protected"?"[geschützt]":"[öffentl.]";return "  "+marker+" "+it.label+(it.sizeKb>0?" ("+it.sizeKb+" KB)":"");}).join("\\n");'.
            ' var moreNote=newItems.length>12?"\\n  ... und "+(newItems.length-12)+" weitere":"";'.
            ' var html=""+'.
            '  "<h3 style=\\"margin:0 0 .8rem;font-size:1.15rem;font-weight:600;\\">📋 Reindex-Vorschau</h3>"+'.
            '  "<div style=\\"display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:.6rem;margin:0 0 1rem;\\">"+'.
            '   "<div style=\\"padding:.7rem .9rem;border:1px solid #e5e7eb;border-radius:7px;background:#f9fafb;\\"><div style=\\"font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;\\">Total</div><div style=\\"font-size:1.4rem;font-weight:700;color:#1f2937;\\">"+s.total+"</div></div>"+'.
            '   "<div style=\\"padding:.7rem .9rem;border:1px solid #86efac;border-radius:7px;background:#f0fdf4;\\"><div style=\\"font-size:.7rem;color:#15803d;text-transform:uppercase;letter-spacing:.04em;\\">Neu</div><div style=\\"font-size:1.4rem;font-weight:700;color:#15803d;\\">"+s.new+"</div></div>"+'.
            '   "<div style=\\"padding:.7rem .9rem;border:1px solid #d1d5db;border-radius:7px;background:#f9fafb;\\"><div style=\\"font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;\\">Schon im Index</div><div style=\\"font-size:1.4rem;font-weight:700;color:#6b7280;\\">"+s.existing+"</div></div>"+'.
            '   ((s.excluded||0)>0?"<div style=\\"padding:.7rem .9rem;border:1px solid #fcd34d;border-radius:7px;background:#fffbeb;\\"><div style=\\"font-size:.7rem;color:#a16207;text-transform:uppercase;letter-spacing:.04em;\\">Ausgeschlossen</div><div style=\\"font-size:1.4rem;font-weight:700;color:#a16207;\\">"+s.excluded+"</div></div>":"")+'.
            '   ((s.orphans||0)>0?"<div style=\\"padding:.7rem .9rem;border:1px solid #fca5a5;border-radius:7px;background:#fef2f2;\\"><div style=\\"font-size:.7rem;color:#b91c1c;text-transform:uppercase;letter-spacing:.04em;\\">Karteileichen</div><div style=\\"font-size:1.4rem;font-weight:700;color:#b91c1c;\\">"+s.orphans+"</div></div>":"")+'.
            '  "</div>"+'.
            // Permission-Sub-Stats — SVG-Icons statt Emojis
            '  "<div style=\\"display:flex;gap:.8rem;margin:0 0 1.2rem;font-size:.83rem;color:#4b5563;flex-wrap:wrap;align-items:center;\\">"+'.
            '   "<span style=\\"color:#3a7178;display:inline-flex;align-items:center;gap:.3rem;\\">"+SVG_PUBLIC+" <strong>"+(s.public||0)+"</strong> öffentlich</span>"+'.
            '   "<span style=\\"color:#a16207;display:inline-flex;align-items:center;gap:.3rem;\\">"+SVG_LOCK+" <strong>"+(s.protected||0)+"</strong> geschützt</span>"+'.
            '  "</div>"+'.
            '  (s.new>0?"<p style=\\"margin:0 0 .6rem;color:#4b5563;font-size:.88rem;\\">Diese "+s.new+" Dokumente werden indexiert:</p><pre style=\\"background:#0f172a;color:#cbd5e1;padding:.8rem 1rem;border-radius:6px;font-size:.78rem;line-height:1.55;max-height:200px;overflow-y:auto;margin:0 0 1.2rem;font-family:ui-monospace,monospace;\\">"+escapeHtml(listPreview+moreNote||"(keine)")+"</pre>":"<p style=\\"margin:0 0 1.2rem;color:#4b5563;font-size:.9rem;\\">"+(s.total===0?"Keine indexierbaren Inhalte gefunden.":"Alle "+s.total+" Dokumente sind bereits im Index — du kannst trotzdem alles neu indexieren um Inhalte zu aktualisieren.")+"</p>")+'.
            '  "<div style=\\"display:flex;gap:.5rem;justify-content:flex-end;flex-wrap:wrap;\\">"+'.
            '   "<button type=\\"button\\" data-vs-cancel style=\\"padding:.55rem 1.1rem;background:#fff;color:#4b5563;border:1px solid #d1d5db;border-radius:8px;font-weight:500;cursor:pointer;\\">Abbrechen</button>"+'.
            '   (s.total>0?"<button type=\\"button\\" data-vs-all style=\\"padding:.55rem 1.1rem;background:#fff;color:#3a7178;border:1px solid #4a8087;border-radius:8px;font-weight:500;cursor:pointer;\\">Alle "+s.total+" neu indexieren</button>":"")+'.
            '   (s.new>0?"<button type=\\"button\\" data-vs-new style=\\"padding:.55rem 1.3rem;background:#3a7178;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer;\\">Nur Neue indexieren ("+s.new+")</button>":"")+'.
            '  "</div>";'.
            ' var close=showModal({html:html,persistent:true,onMount:function(box,close){'.
            '  box.querySelector("[data-vs-cancel]").addEventListener("click",function(){close();btn.disabled=false;btn.innerHTML=btn.dataset.originalHtml;});'.
            '  var newBtn=box.querySelector("[data-vs-new]");if(newBtn)newBtn.addEventListener("click",function(){close();startRun(newItems);});'.
            '  var allBtn=box.querySelector("[data-vs-all]");if(allBtn)allBtn.addEventListener("click",function(){close();startRun(allItems);});'.
            ' }});'.
            '}'.
            'function escapeHtml(s){return String(s).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c];});}'.
            'function startRun(items){'.
            ' state.queue=items.slice();'.
            ' state.plannedTotal=items.length;'.
            ' state.startedAt=Date.now();'.
            ' state.indexed=0;state.skipped=0;state.errors=0;state.done=false;state.paused=false;state.inFlight=0;'.
            ' CONCURRENCY=2;recentErrors=[];'.
            ' wrap.style.display="block";log.innerHTML="";'.
            ' bar.style.width="0%%";pct.textContent="0 %%";'.
            ' lbl.textContent="0 / "+state.plannedTotal+" — Starte…";'.
            ' appendLog("Plan: "+state.plannedTotal+" Items · Auto-Concurrency 2 (passt sich bei Fehlern an) · RunID "+state.runId,"info");'.
            ' btn.textContent="Indexierung läuft…";'.
            ' try{localStorage.setItem("vsearchRun",JSON.stringify({runId:state.runId,total:state.plannedTotal,startedAt:state.startedAt}));}catch(e){}'.
            ' pump();'.
            '}'.
            // Purge-Button
            'var purgeBtn=document.getElementById("vsearch-purge-btn");'.
            'if(purgeBtn&&!purgeBtn.dataset.bound){'.
            ' purgeBtn.dataset.bound="1";'.
            ' var purgeHref=purgeBtn.getAttribute("href");'.
            ' purgeBtn.addEventListener("click",function(e){'.
            '  e.preventDefault();'.
            '  showModal({html:"<h3 style=\\"margin:0 0 .8rem;font-size:1.15rem;font-weight:600;\\">Index wirklich leeren?</h3><p style=\\"margin:0 0 1.4rem;color:#4b5563;line-height:1.55;font-size:.95rem;\\">Alle indexierten Dokumente werden aus dem Suchindex entfernt. Die Suche liefert danach <strong>keine Treffer mehr</strong>, bis du erneut indexierst.</p><div style=\\"display:flex;gap:.6rem;justify-content:flex-end;\\"><button type=\\"button\\" data-vs-cancel style=\\"padding:.55rem 1.1rem;background:#fff;color:#4b5563;border:1px solid #d1d5db;border-radius:8px;font-weight:500;cursor:pointer;\\">Abbrechen</button><button type=\\"button\\" data-vs-ok style=\\"padding:.55rem 1.3rem;background:#b91c1c;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer;\\">Ja, alles löschen</button></div>",onMount:function(box,close){'.
            '   box.querySelector("[data-vs-cancel]").addEventListener("click",close);'.
            '   box.querySelector("[data-vs-ok]").addEventListener("click",function(){close();window.location.href=purgeHref;});'.
            '  }});'.
            ' });'.
            '}'.
            '})();</script>'.
            '</div>',
            htmlspecialchars($fileTypeNote),
            htmlspecialchars($planUrl),
            htmlspecialchars($itemUrl),
            htmlspecialchars($finalizeUrl),
            $pdfEnabled ? '1' : '0',
            htmlspecialchars($purgeUrl),
        );
    }

    /**
     * DCA-input_field_callback: rendert die Live-Status-Box direkt im Edit-Form.
     * Wird von Contao statisch aufgerufen — wir holen den Service über den
     * Symfony-Container.
     */
    public static function renderStatusPanel(): string
    {
        /** @var self $self */
        $self = \Contao\System::getContainer()->get(self::class);

        return $self->buildStatusHtml();
    }

    /**
     * DCA-input_field_callback: rendert die Index-Tabelle direkt im Edit-Form.
     */
    public static function renderDocumentsPanel(): string
    {
        /** @var self $self */
        $self = \Contao\System::getContainer()->get(self::class);

        return $self->buildDocumentsHtml();
    }

    /**
     * v2.0.0 — Tag-Tree-Picker. Delegiert an den dedizierten Tag-Listener
     * (in Phase 4 geliefert), der TagRepository injiziert bekommt.
     */
    public static function renderTagTreePanel(): string
    {
        try {
            $listener = \Contao\System::getContainer()->get(
                'VenneMedia\\VenneSearchContaoBundle\\EventListener\\TagBackendListener'
            );
            return $listener?->renderTreePanel() ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * v2.0.0 — Tabellarische Tag-Übersicht.
     */
    public static function renderTagsOverviewPanel(): string
    {
        try {
            $listener = \Contao\System::getContainer()->get(
                'VenneMedia\\VenneSearchContaoBundle\\EventListener\\TagBackendListener'
            );
            return $listener?->renderOverviewPanel() ?? '';
        } catch (\Throwable) {
            return '';
        }
    }


    /**
     * Einheitliche, klar lesbare Fehler-Box für Resolve-Probleme.
     * Wird im Status-Panel und in der Dokument-Tabelle gleich aussehen.
     */
    private function renderErrorBox(string $title, string $hint): string
    {
        return sprintf(
            '<div style="padding:18px 20px;border:1px solid #fca5a5;border-radius:8px;background:#fef2f2;color:#991b1b;margin:14px 18px;">'
            .'<strong style="font-size:1rem;">%s</strong>'
            .'<div style="margin-top:6px;font-size:.9rem;color:#7f1d1d;">%s</div>'
            .'</div>',
            htmlspecialchars($title),
            $hint, // bewusst nicht escaped — Aufrufer escaped selbst falls nötig (für Multi-Line)
        );
    }

    public function buildStatusHtml(): string
    {
        if (!$this->settings->isConfigured()) {
            return '<div class="tl_help tl_tip" style="padding:12px;color:#94a3b8;">Trag zuerst deinen API-Key oben ein und speichere die Verbindung.</div>';
        }

        try {
            $config = $this->settings->load();
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveAuthException) {
            return $this->renderErrorBox('Plattform-API-Key ungültig oder widerrufen', 'Trag einen frischen Key aus deinem venne-search.de-Dashboard ein.');
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveSubscriptionException) {
            return $this->renderErrorBox('Abo nicht aktiv', 'Reaktiviere dein Search-Abo unter venne-search.de/billing — danach Suche sofort wieder verfügbar.');
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveProvisioningException) {
            return $this->renderErrorBox('Bundle wartet auf Provisionierung', 'Der Plattform-Admin muss deinen Key einem Such-Server zuweisen. Bitte support@venne-media.de kontaktieren.');
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveTransportException $e) {
            return $this->renderErrorBox('Plattform aktuell nicht erreichbar', 'venne-search.de antwortet nicht. Bundle nutzt zwischengespeicherte Verbindung wenn vorhanden. Details: '.htmlspecialchars(substr($e->getMessage(), 0, 160)));
        } catch (\Throwable $e) {
            return $this->renderErrorBox('Unerwarteter Fehler', htmlspecialchars(substr($e->getMessage(), 0, 200)));
        }

        $boxes = [];

        foreach ($config->enabledLocales as $locale) {
            $indexUid = $config->indexPrefix.'_'.$locale;
            try {
                $stats = $this->meilisearch->index($indexUid)->stats();
                $count = (int) ($stats['numberOfDocuments'] ?? 0);
                // Meilisearch liefert lastUpdate in unterschiedlichen Keys
                // je nach Version. Wir versuchen alle bekannten + fallen
                // auf Index::getUpdatedAt() zurück.
                $last = $this->resolveLastUpdate($indexUid, $stats);
                $lastFmt = $last !== null
                    ? date('d.m.Y H:i', $last)
                    : ($count > 0 ? 'unbekannt' : '—');
                $boxes[] = sprintf(
                    '<div style="flex:1;min-width:200px;padding:14px 18px;border:1px solid #d1d5db;border-radius:6px;background:#f9fafb;">'.
                    '<div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;font-weight:600;">Sprache %s</div>'.
                    '<div style="font-size:1.7rem;font-weight:700;color:#3a7178;margin-top:4px;">%s</div>'.
                    '<div style="font-size:.8rem;color:#6b7280;">Dokumente · zuletzt %s</div>'.
                    '</div>',
                    htmlspecialchars(strtoupper($locale)),
                    number_format($count, 0, ',', '.'),
                    htmlspecialchars($lastFmt),
                );
            } catch (\Throwable $e) {
                $boxes[] = sprintf(
                    '<div style="flex:1;min-width:200px;padding:14px 18px;border:1px solid #fca5a5;border-radius:6px;background:#fef2f2;color:#991b1b;">'.
                    '<strong>Sprache %s:</strong> noch keine Daten oder Verbindungsfehler<br><small style="color:#b91c1c;">%s</small></div>',
                    htmlspecialchars(strtoupper($locale)),
                    htmlspecialchars(substr($e->getMessage(), 0, 120)),
                );
            }
        }

        return $this->darkModeStyleBlock().'<div class="vsearch-panel" style="padding:14px 18px;"><div style="display:flex;gap:14px;flex-wrap:wrap;">'.implode('', $boxes).'</div></div>';
    }

    public function buildDocumentsHtml(): string
    {
        if (!$this->settings->isConfigured()) {
            return '<div class="tl_help tl_tip" style="padding:12px;color:#4b5563;">Speichere zuerst deinen API-Key, dann kannst du die indexierten Daten sehen.</div>';
        }

        try {
            $config = $this->settings->load();
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveAuthException) {
            return $this->renderErrorBox('Plattform-API-Key ungültig', 'Trag einen frischen Key aus deinem venne-search.de-Dashboard ein.');
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveSubscriptionException) {
            return $this->renderErrorBox('Abo nicht aktiv', 'Reaktiviere dein Search-Abo unter venne-search.de/billing.');
        } catch (\VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveProvisioningException) {
            return $this->renderErrorBox('Bundle wartet auf Provisionierung', 'Bitte support@venne-media.de kontaktieren.');
        } catch (\Throwable $e) {
            return $this->renderErrorBox('Plattform aktuell nicht erreichbar', htmlspecialchars(substr($e->getMessage(), 0, 200)));
        }

        $locale = $config->enabledLocales[0] ?? 'de';
        $indexUid = $config->indexPrefix.'_'.$locale;
        $query = trim((string) ($_GET['vsq'] ?? ''));
        $typeFilter = (string) ($_GET['vstype'] ?? '');
        $permFilter = (string) ($_GET['vsperm'] ?? '');
        // Format-Filter: PDF, DOCX, ODT etc. — wird über das tags-Feld
        // gefiltert (dort speichert das Bundle die Datei-Extension).
        $extFilter = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($_GET['vsext'] ?? '')) ?? '');

        // Wichtig: bei JEDEM Render hier sicherstellen, dass der laufende
        // Index die aktuellen Filterable-Attribute hat (is_protected wurde
        // in v0.4.0 dazugemacht — alte Indexe wissen das nicht und liefern
        // sonst "attribute not filterable"-Errors. ensureIndex() ist
        // idempotent und schickt nur bei Diff einen Update an Meili.
        try {
            $this->ensureIndexerSchema($locale);
        } catch (\Throwable) {
            // Best-effort. Wenn das fehlschlägt, fallen wir unten auf
            // facet/filter-loses Search zurück.
        }

        $params = [
            'limit' => 100,
            'attributesToRetrieve' => ['id', 'type', 'title', 'url', 'tags', 'indexed_at', 'is_protected', 'allowed_groups'],
            'facets' => ['type', 'is_protected', 'tags'],
            'sort' => ['indexed_at:desc'],
        ];
        $filterParts = [];
        if ($typeFilter !== '') {
            $filterParts[] = sprintf('type = "%s"', addslashes($typeFilter));
        }
        if ($permFilter === 'protected') {
            $filterParts[] = 'is_protected = true';
        } elseif ($permFilter === 'public') {
            $filterParts[] = 'is_protected = false';
        }
        if ($extFilter !== '') {
            $filterParts[] = sprintf('tags = "%s"', addslashes($extFilter));
        }
        if ($filterParts !== []) {
            $params['filter'] = implode(' AND ', $filterParts);
        }

        $result = null;
        try {
            $result = $this->meilisearch->index($indexUid)->search($query, $params)->toArray();
        } catch (\Throwable $firstError) {
            // Fallback 1: ohne Permission-Filter+Facet (alter Index ohne is_protected).
            $fallbackParams = $params;
            unset($fallbackParams['facets']);
            $fallbackParams['facets'] = ['type'];
            if (isset($fallbackParams['filter'])) {
                $clean = array_filter(
                    $filterParts,
                    static fn (string $p): bool => !str_contains($p, 'is_protected'),
                );
                $fallbackParams['filter'] = $clean !== [] ? implode(' AND ', $clean) : '';
                if ($fallbackParams['filter'] === '') {
                    unset($fallbackParams['filter']);
                }
            }
            try {
                $result = $this->meilisearch->index($indexUid)->search($query, $fallbackParams)->toArray();
            } catch (\Throwable $secondError) {
                return sprintf(
                    '<div style="padding:14px 18px;border:1px solid #f87171;border-radius:6px;background:#fef2f2;color:#b91c1c;">'
                    . '<strong>Index nicht erreichbar:</strong> %s'
                    . '<div style="margin-top:8px;font-size:.85rem;color:#7f1d1d;">Tipp: Klick oben auf „Vorschau & Indexieren" — beim ersten Indexieren wird das Schema mit Permission-Feldern aktualisiert.</div>'
                    . '</div>',
                    htmlspecialchars(substr($secondError->getMessage(), 0, 200)),
                );
            }
        }

        $hits = $result['hits'] ?? [];
        $total = (int) ($result['estimatedTotalHits'] ?? \count($hits));
        $time = (int) ($result['processingTimeMs'] ?? 0);
        $facets = (array) ($result['facetDistribution']['type'] ?? []);
        // Extensions aus dem tags-Facet (PDF, DOCX, ODT, RTF, TXT, MD, …).
        // Sortieren nach Vorkommen, damit gängige Formate oben stehen.
        $extFacets = (array) ($result['facetDistribution']['tags'] ?? []);
        arsort($extFacets);

        // Type-Label-Map (deutsch)
        $typeLabels = ['page' => 'Seite', 'file' => 'Datei', 'article' => 'Artikel'];

        // Toolbar (Light-Mode)
        $rt = htmlspecialchars((string) ($_GET['rt'] ?? ''));
        $id = htmlspecialchars((string) ($_GET['id'] ?? '1'));
        $base = sprintf('?do=venne_search&act=edit&id=%s&rt=%s', $id, $rt);
        // Wichtig: KEIN <form> hier — die DCA-Edit-Maske wickelt unsere
        // Felder bereits in ein <form method="post"> ein, und HTML kennt
        // keine verschachtelten Formulare. Browser ignoriert das innere
        // <form>-Tag, dann landen vsext/vstype/vsperm im Outer-POST-Body
        // und der Filter wirkt nie. Wir nutzen stattdessen einen
        // <div class="vsearch-filter-bar"> mit normalen Inputs und einen
        // JavaScript-Handler der beim Klick auf "Filtern" die URL baut.
        $toolbar = '<div class="vsearch-filter-bar" data-vsearch-base="'.$base.'" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;padding:10px 0 14px;">'.
            '<input type="text" data-vsearch-q value="'.htmlspecialchars($query).'" placeholder="Suchen…" style="background:#fff;border:1px solid #d1d5db;color:#1f2937;padding:.5rem .8rem;border-radius:6px;font-size:.9rem;min-width:220px;">'.
            '<select data-vsearch-type style="background:#fff;border:1px solid #d1d5db;color:#1f2937;padding:.5rem .8rem;border-radius:6px;">'.
            '<option value=""'.($typeFilter === '' ? ' selected' : '').'>Alle Typen</option>'.
            '<option value="page"'.($typeFilter === 'page' ? ' selected' : '').'>Seiten</option>'.
            '<option value="article"'.($typeFilter === 'article' ? ' selected' : '').'>Artikel</option>'.
            '<option value="file"'.($typeFilter === 'file' ? ' selected' : '').'>Dateien</option>'.
            '</select>'.
            '<select data-vsearch-perm style="background:#fff;border:1px solid #d1d5db;color:#1f2937;padding:.5rem .8rem;border-radius:6px;">'.
            '<option value=""'.($permFilter === '' ? ' selected' : '').'>Alle Sichtbarkeiten</option>'.
            '<option value="public"'.($permFilter === 'public' ? ' selected' : '').'>Nur öffentliche</option>'.
            '<option value="protected"'.($permFilter === 'protected' ? ' selected' : '').'>Nur geschützte</option>'.
            '</select>'.
            $this->renderExtensionFilter($extFacets, $extFilter).
            '<button type="button" data-vsearch-apply style="background:#3a7178;color:#fff;padding:.55rem 1.1rem;border:0;border-radius:6px;cursor:pointer;font-weight:600;">Filtern</button>'.
            ($query !== '' || $typeFilter !== '' || $permFilter !== '' || $extFilter !== '' ? '<a href="'.$base.'" style="color:#6b7280;text-decoration:none;font-size:.85rem;padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;background:#fff;">Zurücksetzen</a>' : '').
            '</div>';

        // Bulk-Toolbar: Markierte entfernen / Alle protected entfernen
        $bulkToolbar = '<div style="display:flex;gap:.5rem;align-items:center;padding:6px 0 10px;border-bottom:1px solid #e5e7eb;margin-bottom:8px;flex-wrap:wrap;">'.
            '<button type="button" id="vsearch-doc-remove-selected" disabled style="background:#fff;color:#9ca3af;border:1px solid #e5e7eb;padding:.4rem .9rem;border-radius:5px;font-size:.82rem;cursor:not-allowed;font-weight:500;display:inline-flex;align-items:center;gap:.4rem;">'.self::SVG_ICON_TRASH.' Markierte entfernen</button>'.
            '<button type="button" id="vsearch-doc-remove-protected" style="background:#fff;color:#b91c1c;border:1px solid #fca5a5;padding:.4rem .9rem;border-radius:5px;font-size:.82rem;cursor:pointer;font-weight:500;display:inline-flex;align-items:center;gap:.4rem;">'.self::SVG_ICON_BAN.' Alle geschützten entfernen</button>'.
            '<span style="font-size:.8rem;color:#6b7280;margin-left:auto;">Pro Zeile lassen sich Einträge einzeln aktualisieren oder entfernen.</span>'.
            '</div>';

        // Stats-Zeile (Light-Mode)
        $facetHtml = '';
        foreach ($facets as $type => $count) {
            $color = match ($type) { 'page' => '#3a7178', 'file' => '#c4341e', 'article' => '#2d8048', default => '#6b7280' };
            $label = $typeLabels[$type] ?? ucfirst($type);
            $facetHtml .= sprintf(
                ' <span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:.7rem;font-weight:600;letter-spacing:.04em;background:%s1A;color:%s;margin-left:4px;">%s · %d</span>',
                $color, $color, htmlspecialchars($label), $count,
            );
        }
        $stats = sprintf(
            '<div style="padding:6px 0 12px;color:#6b7280;font-size:.85rem;"><strong style="color:#1f2937;">%s</strong> Dokumente%s · %d ms%s</div>',
            number_format($total, 0, ',', '.'),
            ($query !== '' || $typeFilter !== '') ? ' (gefiltert)' : '',
            $time,
            $facetHtml,
        );

        // Tabelle (Light-Mode)
        if ($hits === []) {
            $body = '<div style="padding:30px;text-align:center;color:#6b7280;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">'.
                ($query !== '' ? 'Keine Treffer für „'.htmlspecialchars($query).'"' : 'Noch keine Dokumente. Klicke oben auf „Vorschau & Indexieren".').
                '</div>';
        } else {
            $rows = '';
            foreach ($hits as $hit) {
                $typeRaw = (string) ($hit['type'] ?? 'page');
                $type = htmlspecialchars($typeRaw);
                $typeLabel = htmlspecialchars($typeLabels[$typeRaw] ?? ucfirst($typeRaw));
                $color = match ($typeRaw) { 'page' => '#3a7178', 'file' => '#c4341e', 'article' => '#2d8048', default => '#6b7280' };
                $title = htmlspecialchars((string) ($hit['title'] ?? '(ohne Titel)'));
                $urlRaw = (string) ($hit['url'] ?? '');
                $urlPretty = $this->prettifyUrl($urlRaw, $typeRaw);
                $indexed = isset($hit['indexed_at']) && $hit['indexed_at'] ? date('d.m.Y H:i', (int) $hit['indexed_at']) : '—';
                $isProtectedDoc = (bool) ($hit['is_protected'] ?? false);
                $allowedGroupsArr = (array) ($hit['allowed_groups'] ?? []);
                $permIcon = $isProtectedDoc
                    ? '<span style="color:#a16207;">' . self::SVG_ICON_LOCK . '</span>'
                    : '<span style="color:#3a7178;">' . self::SVG_ICON_PUBLIC . '</span>';
                $permTooltip = $isProtectedDoc
                    ? sprintf('Geschützt – Zugriff für Gruppen: %s', implode(',', array_map('intval', $allowedGroupsArr)) ?: '(keine)')
                    : 'Öffentlich – für alle sichtbar';
                $docId = htmlspecialchars((string) ($hit['id'] ?? ''));
                $tags = '';
                foreach ((array) ($hit['tags'] ?? []) as $tag) {
                    $tags .= '<span style="display:inline-block;padding:1px 7px;margin-right:3px;font-size:.7rem;background:#f3f4f6;border-radius:3px;color:#4b5563;">'.htmlspecialchars((string) $tag).'</span>';
                }
                $rows .= sprintf(
                    '<tr style="border-bottom:1px solid #e5e7eb;" data-doc-id="%s">'.
                    '<td style="padding:10px 8px;width:30px;text-align:center;"><input type="checkbox" class="vsearch-doc-cb" value="%s" style="cursor:pointer;"></td>'.
                    '<td style="padding:10px 12px;"><span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:.7rem;font-weight:600;letter-spacing:.04em;background:%s1A;color:%s;">%s</span></td>'.
                    '<td style="padding:10px 6px;width:30px;text-align:center;font-size:1rem;" title="%s">%s</td>'.
                    '<td style="padding:10px 12px;color:#1f2937;font-weight:500;">%s</td>'.
                    '<td style="padding:10px 12px;"><a href="%s" target="_blank" style="color:#3a7178;text-decoration:none;font-size:.85rem;" title="%s">%s</a></td>'.
                    '<td style="padding:10px 12px;">%s</td>'.
                    '<td style="padding:10px 12px;color:#6b7280;font-size:.8rem;">%s</td>'.
                    '<td style="padding:10px 4px;width:75px;text-align:center;white-space:nowrap;">'.
                    '<button type="button" class="vsearch-doc-refresh" data-doc-id="%s" title="Diese Datei neu indexieren" style="background:transparent;border:0;cursor:pointer;color:#3a7178;padding:4px 6px;border-radius:4px;margin-right:2px;line-height:0;">'.self::SVG_ICON_REFRESH.'</button>'.
                    '<button type="button" class="vsearch-doc-remove" data-doc-id="%s" title="Aus Index entfernen" style="background:transparent;border:0;cursor:pointer;color:#b91c1c;padding:4px 6px;border-radius:4px;line-height:0;">'.self::SVG_ICON_TRASH.'</button>'.
                    '</td>'.
                    '</tr>',
                    $docId, $docId,
                    $color, $color, $typeLabel,
                    htmlspecialchars($permTooltip), $permIcon,
                    $title,
                    htmlspecialchars($urlRaw), htmlspecialchars($urlRaw),
                    htmlspecialchars($urlPretty),
                    $tags, $indexed, $docId, $docId,
                );
            }
            $body = '<div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff;">'.
                '<table style="width:100%;border-collapse:collapse;">'.
                '<thead><tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;">'.
                '<th style="text-align:center;font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;padding:10px 8px;width:30px;"><input type="checkbox" id="vsearch-doc-cb-all" style="cursor:pointer;"></th>'.
                '<th style="text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;padding:10px 12px;width:90px;">Typ</th>'.
                '<th style="text-align:center;font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;padding:10px 6px;width:30px;" title="Sichtbarkeit">'.self::SVG_ICON_EYE.'</th>'.
                '<th style="text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;padding:10px 12px;">Titel</th>'.
                '<th style="text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;padding:10px 12px;">Pfad</th>'.
                '<th style="text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;padding:10px 12px;width:100px;">Tags</th>'.
                '<th style="text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;padding:10px 12px;width:130px;">Indexiert</th>'.
                '<th style="text-align:center;font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;padding:10px 8px;width:75px;"></th>'.
                '</tr></thead><tbody>'.$rows.'</tbody></table></div>';
        }

        $removeUrl = '/contao/venne-search/reindex-remove';
        $refreshUrl = '/contao/venne-search/reindex-single';

        $script = '<script>(function(){'.
            // Filter-Bar: keine echte Form (nested form wäre invalid HTML);
            // wir bauen die URL aus den Filter-Werten und navigieren via location.
            'var filterBar=document.querySelector(".vsearch-filter-bar");'.
            'if(filterBar){'.
            ' var applyFilter=function(){'.
            '  var base=filterBar.dataset.vsearchBase||"";'.
            '  var q=(filterBar.querySelector("[data-vsearch-q]")||{}).value||"";'.
            '  var t=(filterBar.querySelector("[data-vsearch-type]")||{}).value||"";'.
            '  var p=(filterBar.querySelector("[data-vsearch-perm]")||{}).value||"";'.
            '  var e=(filterBar.querySelector("[data-vsearch-ext]")||{}).value||"";'.
            '  var url=base;'.
            '  if(q)url+="&vsq="+encodeURIComponent(q);'.
            '  if(t)url+="&vstype="+encodeURIComponent(t);'.
            '  if(p)url+="&vsperm="+encodeURIComponent(p);'.
            '  if(e)url+="&vsext="+encodeURIComponent(e);'.
            '  window.location.href=url;'.
            ' };'.
            ' var btn=filterBar.querySelector("[data-vsearch-apply]");'.
            ' if(btn)btn.addEventListener("click",applyFilter);'.
            ' var input=filterBar.querySelector("[data-vsearch-q]");'.
            ' if(input)input.addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();applyFilter();}});'.
            ' filterBar.querySelectorAll("select").forEach(function(s){s.addEventListener("change",applyFilter);});'.
            '}'.
            'var removeUrl='.json_encode($removeUrl).';'.
            'var refreshUrl='.json_encode($refreshUrl).';'.
            'var rows=document.querySelectorAll(".vsearch-doc-cb");'.
            'var allCb=document.getElementById("vsearch-doc-cb-all");'.
            'var bulkBtn=document.getElementById("vsearch-doc-remove-selected");'.
            'var protectedBtn=document.getElementById("vsearch-doc-remove-protected");'.
            'function refreshBulkState(){'.
            ' var anyChecked=Array.from(rows).some(function(cb){return cb.checked;});'.
            ' if(anyChecked){bulkBtn.disabled=false;bulkBtn.style.cursor="pointer";bulkBtn.style.color="#b91c1c";bulkBtn.style.borderColor="#fca5a5";}'.
            ' else{bulkBtn.disabled=true;bulkBtn.style.cursor="not-allowed";bulkBtn.style.color="#9ca3af";bulkBtn.style.borderColor="#e5e7eb";}'.
            '}'.
            'rows.forEach(function(cb){cb.addEventListener("change",refreshBulkState);});'.
            'if(allCb){allCb.addEventListener("change",function(){rows.forEach(function(cb){cb.checked=allCb.checked;});refreshBulkState();});}'.
            'function removeDocs(payload,confirmText){'.
            ' if(!confirm(confirmText))return;'.
            ' fetch(removeUrl,{method:"POST",headers:{"Content-Type":"application/json","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin",body:JSON.stringify(payload)})'.
            '  .then(function(r){return r.json();})'.
            '  .then(function(d){'.
            '   if(d&&d.ok){alert("Entfernt — Index aktualisiert. Tabelle wird neu geladen.");window.location.reload();}'.
            '   else{alert("Fehler: "+(d&&d.error||"unbekannt"));}'.
            '  }).catch(function(e){alert("Netzwerkfehler: "+e.message);});'.
            '}'.
            'document.querySelectorAll(".vsearch-doc-remove").forEach(function(btn){'.
            ' btn.addEventListener("click",function(){'.
            '  var id=btn.dataset.docId;'.
            '  removeDocs({docIds:[id]},"Diesen Eintrag wirklich aus dem Suchindex entfernen?\\n\\n"+id);'.
            ' });'.
            '});'.
            // Refresh-Button: dieses eine Doc neu indexieren. Während des Calls
            // ist der Button deaktiviert (Spinner-Style via opacity), danach
            // Reload der Tabelle damit der "Indexiert"-Timestamp aktuell ist.
            'document.querySelectorAll(".vsearch-doc-refresh").forEach(function(btn){'.
            ' btn.addEventListener("click",function(){'.
            '  if(btn.disabled)return;'.
            '  btn.disabled=true;btn.style.cursor="wait";btn.style.opacity="0.4";btn.title="Wird neu indexiert…";'.
            '  fetch(refreshUrl,{method:"POST",headers:{"Content-Type":"application/json","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin",body:JSON.stringify({docId:btn.dataset.docId})})'.
            '   .then(function(r){return r.json();})'.
            '   .then(function(d){'.
            '    if(d&&d.ok){btn.title="Neu indexiert ("+(d.contentLen||0)+" Zeichen, "+(d.durationMs||0)+" ms)";setTimeout(function(){window.location.reload();},500);}'.
            '    else{btn.disabled=false;btn.style.cursor="pointer";btn.style.opacity="1";alert("Reindex fehlgeschlagen: "+(d&&d.error||"unbekannt"));}'.
            '   })'.
            '   .catch(function(e){btn.disabled=false;btn.style.cursor="pointer";btn.style.opacity="1";alert("Netzwerkfehler: "+e.message);});'.
            ' });'.
            '});'.
            'if(bulkBtn){bulkBtn.addEventListener("click",function(){'.
            ' var ids=Array.from(rows).filter(function(cb){return cb.checked;}).map(function(cb){return cb.value;});'.
            ' if(ids.length===0)return;'.
            ' removeDocs({docIds:ids},"Diese "+ids.length+" Einträge wirklich aus dem Suchindex entfernen?");'.
            '});}'.
            'if(protectedBtn){protectedBtn.addEventListener("click",function(){'.
            ' removeDocs({removeAllProtected:true},"Wirklich ALLE geschützten Dokumente aus dem Index entfernen?\\n\\nNur sinnvoll wenn du auf den Modus \\"Nur öffentliche\\" zurückwechselst und den Index säuberst.");'.
            '});}'.
            '})();</script>';

        return $this->darkModeStyleBlock().'<div class="vsearch-panel" style="padding:14px 18px;">'.$toolbar.$stats.$bulkToolbar.$body.$script.'</div>';
    }

    /**
     * Macht eine URL lesbar fuer die Backend-Tabelle:
     * - File: nur Dateiname (z.B. "FFA-Kinojahr_2024.pdf" statt
     *   "/files/dokumentenverwaltung/.../FFA-Kinojahr_2024.pdf")
     * - Page: dekodierte URL ("/foerderungen") oder "Startseite" fuer "/"
     */

    /**
     * Rendert das Format-Filter-Dropdown. Zeigt nur Extensions die wirklich
     * im Index vorkommen — sortiert nach Häufigkeit, mit Anzahl in Klammern.
     *
     * @param array<string, int> $extFacets
     */
    private function renderExtensionFilter(array $extFacets, string $current): string
    {
        $opts = '<option value=""' . ($current === '' ? ' selected' : '') . '>Alle Formate</option>';
        foreach ($extFacets as $ext => $count) {
            $ext = (string) $ext;
            if ($ext === '') {
                continue;
            }
            $sel = $current === $ext ? ' selected' : '';
            $opts .= sprintf(
                '<option value="%s"%s>%s (%d)</option>',
                htmlspecialchars($ext),
                $sel,
                htmlspecialchars(strtoupper($ext)),
                $count,
            );
        }

        return '<select data-vsearch-ext style="background:#fff;border:1px solid #d1d5db;color:#1f2937;padding:.5rem .8rem;border-radius:6px;">' . $opts . '</select>';
    }

    private function prettifyUrl(string $url, string $type): string
    {
        if ($url === '') {
            return '—';
        }
        $decoded = rawurldecode($url);
        if ($type === 'file') {
            $idx = strrpos($decoded, '/');
            return $idx === false ? $decoded : substr($decoded, $idx + 1);
        }
        return $decoded === '/' ? 'Startseite' : $decoded;
    }

    /**
     * Liest die installierte Bundle-Version aus dem Composer-Manifest des
     * Customer-Projekts (vendor/composer/installed.json). So zeigt das
     * Backend-Panel exakt die Version die gerade laeuft — gut zum Debuggen
     * von Update-Cache-Problemen.
     */
    private function resolveBundleVersion(): string
    {
        try {
            $kernelDir = (string) \Contao\System::getContainer()->getParameter('kernel.project_dir');
            $manifest = $kernelDir.'/vendor/composer/installed.json';
            if (is_file($manifest)) {
                $json = json_decode((string) file_get_contents($manifest), true);
                $packages = $json['packages'] ?? $json ?? [];
                foreach ($packages as $pkg) {
                    if (($pkg['name'] ?? '') === 'venne-media/venne-search-contao-bundle') {
                        $v = (string) ($pkg['version'] ?? '');
                        return ltrim($v, 'v') ?: 'unknown';
                    }
                }
            }
        } catch (\Throwable) {
        }
        return 'unknown';
    }
}
