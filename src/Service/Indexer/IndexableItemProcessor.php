<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Indexer;

use Doctrine\DBAL\Connection;
use VenneMedia\VenneSearchContaoBundle\Service\Locale\FileLocaleDetector;
use VenneMedia\VenneSearchContaoBundle\Service\Pdf\PdfExtractor;
use VenneMedia\VenneSearchContaoBundle\Service\Pdf\PdfThumbnailGenerator;
use VenneMedia\VenneSearchContaoBundle\Service\Permission\PermissionResolver;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsConfig;
use VenneMedia\VenneSearchContaoBundle\Service\Tag\TagRepository;
use VenneMedia\VenneSearchContaoBundle\Service\Text\TextNormalizer;

/**
 * Verarbeitet EIN einzelnes Reindex-Item — ein Page oder File-Doc.
 *
 * Komplett losgelöst vom SSE-Stream-Controller, damit der neue Item-Endpoint
 * pro Request genau ein Item bearbeitet (max 5–10s, passt in jedes FPM-Limit).
 *
 * Document-IDs MÜSSEN identisch zu denen aus ReindexCatalog::buildPlan() sein,
 * sonst wird das Resume / die Idempotenz beim Upsert kaputt.
 */
final class IndexableItemProcessor
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentIndexer $indexer,
        private readonly TextNormalizer $normalizer,
        private readonly PdfExtractor $pdfExtractor,
        private readonly PermissionResolver $permissions,
        private readonly FileLocaleDetector $localeDetector,
        private readonly TagRepository $tags,
        private readonly PdfThumbnailGenerator $pdfThumbnails,
    ) {
    }

    /**
     * @param array{type:string,ref:int|string,docId:string} $item
     *
     * @return array{
     *   ok:bool,
     *   skipped?:bool,
     *   reason?:string,
     *   error?:string,
     *   contentLen?:int,
     *   durationMs?:int
     * }
     */
    public function processItem(array $item, SettingsConfig $config, string $projectDir): array
    {
        $start = microtime(true);
        $type = $item['type'];

        try {
            if ($type === 'page') {
                return $this->processPage((int) $item['ref'], $item['docId'], $config, $start);
            }
            if ($type === 'file') {
                return $this->processFile((string) $item['ref'], $item['docId'], $config, $projectDir, $start);
            }
            return ['ok' => false, 'error' => 'unknown_type:' . $type];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => substr($e->getMessage(), 0, 200),
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
            ];
        }
    }

    /**
     * @return array{ok:bool, skipped?:bool, reason?:string, contentLen?:int, durationMs?:int}
     */
    private function processPage(int $pageId, string $docId, SettingsConfig $config, float $start): array
    {
        $pageRow = $this->db->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$pageId]);
        if (!\is_array($pageRow)) {
            return ['ok' => false, 'error' => 'page_not_found:' . $pageId];
        }

        // Robots/noindex-Check (defensive, parallel zum Plan)
        $robots = (string) ($pageRow['robots'] ?? '');
        if ($robots !== '' && stripos($robots, 'noindex') !== false) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'page_noindex_robots',
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
            ];
        }
        if (isset($pageRow['noSearch']) && (string) $pageRow['noSearch'] === '1') {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'page_no_search_flag',
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
            ];
        }
        // v2.1.0: Setting "Versteckte Seiten indexieren" — wenn deaktiviert,
        // werden Seiten mit gesetztem `tl_page.hide`-Flag aus der Suche raus.
        if (!$config->indexHiddenPages && (string) ($pageRow['hide'] ?? '') === '1') {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'page_hidden_excluded',
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
            ];
        }

        // Defensive Permission-Check: auch wenn der Plan-Call das Item
        // freigegeben hat, prüfen wir hier nochmal — Plan kann veraltet sein,
        // jemand kann zwischenzeitlich die Page protected gemacht haben.
        $perm = $this->permissions->resolvePagePermissions($pageId);
        $alias = (string) ($pageRow['alias'] ?? '');
        $logicalPath = 'tl_page/' . ($alias !== '' ? $alias : (string) $pageId);
        if ($this->shouldSkipForPermission('page', $logicalPath, $perm['isProtected'], $config)) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'permission_excluded',
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
            ];
        }

        $locale = (string) ($pageRow['language'] ?: ($config->enabledLocales[0] ?? 'de'));
        $this->indexer->ensureIndex($locale);

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

        $url = $this->generatePageUrl($pageId, (string) ($pageRow['alias'] ?? ''));

        // v2.0.0: Tags aus dem zentralen Tag-System.
        // Wir schreiben sowohl Slug als auch Label ins Tag-Array, damit
        // (a) der Slug für "?tags[]=neues"-Filter-Queries genutzt werden kann
        //     und (b) das Label searchable ist (User tippt "neues" → Match).
        $tagObjects = $this->tags->tagsForTarget('page', (string) $pageId);
        $tags = [];
        // v2.1.0: Boost = max(boost) aller zugewiesenen Tags. Fließt in
        // SearchDocument.weight, das im Index per `weight DESC` sortiert
        // wird → geboostete Treffer landen oben.
        $weight = 1.0;
        foreach ($tagObjects as $t) {
            $tags[] = $t['slug'];
            if ($t['label'] !== '' && $t['label'] !== $t['slug']) {
                $tags[] = $t['label'];
            }
            $tagBoost = (float) ($t['boost'] ?? 1.0);
            if ($tagBoost > $weight) {
                $weight = $tagBoost;
            }
        }
        // Fallback: Legacy-Keywords-CSV wenn keine Tag-Zuweisungen.
        if ($tags === []) {
            $tags = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($pageRow['keywords'] ?? ''))
            )));
        }
        $tags = array_values(array_unique($tags));

        $doc = new SearchDocument(
            id: $docId,
            type: 'page',
            locale: $locale,
            title: (string) ($pageRow['pageTitle'] ?: ($pageRow['title'] ?? '')),
            url: $url,
            content: $normalizedContent,
            tags: $tags,
            publishedAt: !empty($pageRow['start']) ? (int) $pageRow['start'] : (int) ($pageRow['tstamp'] ?? 0),
            weight: $weight,
            isProtected: $perm['isProtected'],
            allowedGroups: $perm['allowedGroups'],
            contentType: 'page',
        );
        $this->indexer->upsert($doc);

        return [
            'ok' => true,
            'contentLen' => mb_strlen($normalizedContent),
            'durationMs' => (int) ((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * @return array{ok:bool, skipped?:bool, reason?:string, contentLen?:int, durationMs?:int}
     */
    private function processFile(string $relativePath, string $docId, SettingsConfig $config, string $projectDir, float $start): array
    {
        $absolute = rtrim($projectDir, '/') . '/' . ltrim($relativePath, '/');
        if (!is_file($absolute) || !is_readable($absolute)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'file_not_readable'];
        }

        // Defensive Permission-Check (siehe processPage()).
        $perm = $this->permissions->resolveFilePermissions($relativePath);
        if ($this->shouldSkipForPermission('file', $relativePath, $perm['isProtected'], $config)) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'permission_excluded',
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
            ];
        }

        $ext = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
        $extracted = $this->extractFileText($absolute, $ext, $config->maxFileSizeMb);
        $text = $extracted['text'];

        if ($text === '') {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => $extracted['reason'] ?? 'empty',
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
            ];
        }

        // v2.0.0: File-Locale per Detector statt einfach erste enabled_locale.
        $locale = $this->localeDetector->detect($relativePath, $config);
        $this->indexer->ensureIndex($locale);

        // Tags aus dem Tag-System (Slug + Label, beides searchable) + Extension.
        $tagObjects = $this->tags->tagsForTarget('file', $relativePath);
        $fileTags = [];
        // v2.1.0: Boost = max(boost) aller zugewiesenen Tags (siehe processPage).
        $weight = 1.0;
        foreach ($tagObjects as $t) {
            $fileTags[] = $t['slug'];
            if ($t['label'] !== '' && $t['label'] !== $t['slug']) {
                $fileTags[] = $t['label'];
            }
            $tagBoost = (float) ($t['boost'] ?? 1.0);
            if ($tagBoost > $weight) {
                $weight = $tagBoost;
            }
        }
        $fileTags[] = $ext;
        $fileTags = array_values(array_unique(array_filter($fileTags)));

        // v2.1.0: Datei-Titel und ALT-Text aus tl_files.meta lesen
        // (Contao-Datei-Metadaten, im Backend gepflegt). Fallback auf
        // humanisierten Dateinamen, wenn keine Meta-Daten hinterlegt sind.
        $meta = $this->extractFileMeta($relativePath, $locale, $config->enabledLocales);
        $fallbackTitle = $this->humanizeFilename(pathinfo($relativePath, PATHINFO_FILENAME));
        $title = $meta['title'] !== '' ? $meta['title'] : $fallbackTitle;

        // v2.2.0: Cover-URL ermitteln. Bilder = sich selbst; bei anderen
        // Dateitypen schauen wir, ob im selben Verzeichnis ein gleichnamiges
        // Bild liegt (z.B. `flyer.pdf` + `flyer.jpg` als Cover, Contao-Übliches
        // Pattern). PDFs bekommen optional ein generiertes Thumbnail.
        $coverUrl = $this->resolveCoverUrl($relativePath, $absolute, $ext, $projectDir);
        if ($coverUrl === '' && $ext === 'pdf' && $config->generatePdfThumbnails) {
            $generated = $this->pdfThumbnails->generate($absolute, $projectDir);
            if (\is_string($generated) && $generated !== '') {
                $coverUrl = '/' . ltrim($generated, '/');
            }
        }

        // v2.2.0: publishedAt aus tl_files.tstamp (Upload/Änderungszeit) für
        // stabile Date-Sortierung — vorher fehlend, fiel auf 0 zurück.
        $fileTstamp = $this->fetchFileTstamp($relativePath);
        if ($fileTstamp === 0) {
            $fileTstamp = @filemtime($absolute) ?: 0;
        }

        // v2.2.0: Dateigröße für lesbare Anzeige im Frontend.
        $fileSize = @filesize($absolute) ?: 0;

        $doc = new SearchDocument(
            id: $docId,
            type: 'file',
            locale: $locale,
            title: $title,
            url: '/' . ltrim($relativePath, '/'),
            content: $this->normalizer->normalize($text),
            tags: $fileTags,
            publishedAt: $fileTstamp,
            weight: $weight,
            isProtected: $perm['isProtected'],
            allowedGroups: $perm['allowedGroups'],
            altText: $meta['alt'],
            coverUrl: $coverUrl,
            contentType: $ext !== '' ? $ext : 'file',
            fileSize: (int) $fileSize,
        );
        $this->indexer->upsert($doc);

        return [
            'ok' => true,
            'contentLen' => mb_strlen($text),
            'durationMs' => (int) ((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * Spiegelt die Logik aus ReindexCatalog::decidePermission().
     * Doppelt geprüft, weil Plan und Indexing in zwei separaten Requests
     * laufen — zwischen Plan-Erstellung und Item-Indexing kann jemand was
     * geändert haben.
     */
    private function shouldSkipForPermission(string $type, string $logicalPath, bool $isProtected, SettingsConfig $config): bool
    {
        if ($this->permissions->isPathExcluded($logicalPath, $config->excludedPaths)) {
            return true;
        }
        switch ($config->indexMode) {
            case SettingsConfig::MODE_PUBLIC_ONLY:
                return $isProtected;
            case SettingsConfig::MODE_WITH_PROTECTED:
            case SettingsConfig::MODE_BLACKLIST:
                return false;
            case SettingsConfig::MODE_WHITELIST:
                return !$this->permissions->isPathExcluded($logicalPath, $config->includedPaths);
            default:
                return true;
        }
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

        return ['text' => '', 'reason' => 'unsupported_extension_' . $extension];
    }

    /**
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
        $xmlPaths = ['word/document.xml'];
        for ($i = 1; $i <= 3; $i++) {
            $xmlPaths[] = 'word/header' . $i . '.xml';
            $xmlPaths[] = 'word/footer' . $i . '.xml';
        }
        $text = '';
        foreach ($xmlPaths as $entry) {
            $raw = $zip->getFromName($entry);
            if ($raw === false || $raw === '') {
                continue;
            }
            $withBreaks = str_replace(['</w:p>', '</w:tab>', '<w:br/>', '<w:br />'], "\n", $raw);
            $stripped = trim(strip_tags($withBreaks));
            if ($stripped !== '') {
                $text .= $stripped . "\n";
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
     * @return array{text:string, reason:?string}
     */
    private function extractRtf(string $absolutePath): array
    {
        $raw = (string) file_get_contents($absolutePath);
        if ($raw === '') {
            return ['text' => '', 'reason' => 'rtf_empty_file'];
        }
        $raw = preg_replace_callback(
            '/\\\\\'([0-9a-fA-F]{2})/',
            static fn ($m) => chr(hexdec($m[1])),
            $raw,
        ) ?? $raw;
        $raw = preg_replace_callback(
            '/\\\\u(-?\d+)\??/',
            static fn ($m) => mb_chr((int) $m[1] & 0xFFFF, 'UTF-8'),
            $raw,
        ) ?? $raw;
        $raw = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', ' ', $raw) ?? $raw;
        $raw = str_replace(['{', '}', '\\*', '\\\\'], ['', '', '', ''], $raw);
        $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($text === '') {
            return ['text' => '', 'reason' => 'rtf_empty_after_strip'];
        }
        return ['text' => $text, 'reason' => null];
    }

    /**
     * Erzeugt die Frontend-URL einer Page. Drei-Stufen-Strategie:
     *   1) Contao 5.3+: ContentUrlGenerator (offizieller Weg, kennt alles)
     *   2) Contao 4.13 + 5.0–5.2: PageModel::getFrontendUrl (deprecated aber funktional)
     *   3) Fallback: alias + Root-Page-urlSuffix (oder globaler url_suffix)
     */
    private function generatePageUrl(int $pageId, string $aliasFallback): string
    {
        // Stufe 1: ContentUrlGenerator (Contao 5.3+)
        try {
            $container = \Contao\System::getContainer();
            if ($container->has('contao.routing.content_url_generator')
                && class_exists(\Contao\PageModel::class)) {
                $page = \Contao\PageModel::findWithDetails($pageId);
                if ($page !== null) {
                    $generator = $container->get('contao.routing.content_url_generator');
                    $url = $generator->generate(
                        $page,
                        [],
                        \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_PATH
                    );
                    if (\is_string($url) && $url !== '') {
                        return $this->stripHost($url);
                    }
                }
            }
        } catch (\Throwable) {
            // ContentUrlGenerator nicht verfügbar oder Page nicht routbar — Stufe 2.
        }

        // Stufe 2: PageModel::getFrontendUrl (auf 4.13 deprecated, aber kein Drop-in
        // Replacement vorhanden und liefert die korrekte URL inkl. Suffix).
        try {
            if (class_exists(\Contao\PageModel::class)) {
                $page = \Contao\PageModel::findWithDetails($pageId);
                if ($page !== null) {
                    /** @phpstan-ignore-next-line — getFrontendUrl ist auf 4.13 deprecated, aber funktional */
                    $url = @$page->getFrontendUrl();
                    if (\is_string($url) && $url !== '') {
                        return $this->stripHost($url);
                    }
                }
            }
        } catch (\Throwable) {
        }

        // Stufe 3: manueller Fallback aus DB-Daten.
        $alias = ltrim($aliasFallback, '/');
        if ($alias === '' || $alias === 'index') {
            return '/';
        }
        return '/' . $alias . $this->resolveUrlSuffix($pageId);
    }

    /**
     * Macht aus einer (evtl. absoluten/scheme-relativen) URL einen sauberen
     * Pfad mit führendem '/'. Beispiele:
     *   "https://example.com/home.html"  → "/home.html"
     *   "//127.0.0.1/home"               → "/home"
     *   "home.html"                      → "/home.html"   (4.13 getFrontendUrl)
     *   "/home.html"                     → "/home.html"
     */
    private function stripHost(string $url): string
    {
        $parts = parse_url($url);
        if (\is_array($parts) && isset($parts['path'])) {
            $path = (string) $parts['path'];
            if ($path !== '' && $path[0] !== '/') {
                $path = '/' . $path;
            }
            if (isset($parts['query']) && $parts['query'] !== '') {
                $path .= '?' . $parts['query'];
            }
            return $path;
        }
        // parse_url failed — bestmöglicher Fallback: Slash voranstellen wenn nötig.
        return $url !== '' && $url[0] !== '/' ? '/' . $url : $url;
    }

    /**
     * Liefert das URL-Suffix der Root-Page für $pageId. Auf Contao 4.13 gilt
     * tl_page.urlSuffix ist NUR überschreibend — wenn dort '' steht, fällt
     * Contao auf den globalen contao.url_suffix-Parameter zurück (Default '.html').
     * Auf 5.x ist die urlSuffix-Spalte teils gar nicht mehr da.
     *
     * Diese Methode ist nur Fallback — der Hauptpfad nutzt PageModel::getFrontendUrl().
     */
    private function resolveUrlSuffix(int $pageId): string
    {
        $rootSuffix = null;
        try {
            $current = $pageId;
            for ($i = 0; $i < 50 && $current > 0; $i++) {
                $row = $this->db->fetchAssociative('SELECT pid, type, urlSuffix FROM tl_page WHERE id = ?', [$current]);
                if (!\is_array($row)) {
                    break;
                }
                if (($row['type'] ?? '') === 'root') {
                    $rootSuffix = (string) ($row['urlSuffix'] ?? '');
                    break;
                }
                $current = (int) ($row['pid'] ?? 0);
            }
        } catch (\Throwable) {
        }

        // Wenn der Root-Page explizit ein nicht-leerer Suffix gesetzt ist,
        // nutze den. Sonst (leer oder Spalte fehlt) → globaler Fallback.
        if ($rootSuffix !== null && $rootSuffix !== '') {
            return $rootSuffix;
        }

        try {
            $container = \Contao\System::getContainer();
            if ($container->hasParameter('contao.url_suffix')) {
                return (string) $container->getParameter('contao.url_suffix');
            }
        } catch (\Throwable) {
        }
        return '.html';
    }

    /**
     * v2.2.0: Cover-URL für einen Datei-Treffer ermitteln.
     *
     * Strategie (erste die liefert gewinnt):
     *   1) Datei ist selbst ein Bild → direkt ihre URL als Cover
     *   2) Im gleichen Verzeichnis liegt ein gleichnamiges Bild
     *      (`flyer.pdf` → `flyer.jpg`, `.png`, `.webp`) → das nehmen
     *   3) Nichts gefunden → leerer String, Frontend rendert Icon
     */
    private function resolveCoverUrl(string $relativePath, string $absolutePath, string $ext, string $projectDir): string
    {
        $imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

        if (\in_array($ext, $imageExts, true)) {
            return '/' . ltrim($relativePath, '/');
        }

        $base = pathinfo($relativePath, PATHINFO_FILENAME);
        $dir = pathinfo($relativePath, PATHINFO_DIRNAME);
        if ($base === '' || $dir === '' || $dir === '.') {
            return '';
        }
        $absDir = rtrim($projectDir, '/') . '/' . ltrim($dir, '/');
        foreach ($imageExts as $candExt) {
            $candAbs = $absDir . '/' . $base . '.' . $candExt;
            if (is_file($candAbs)) {
                return '/' . ltrim($dir, '/') . '/' . $base . '.' . $candExt;
            }
        }
        // Auch Großschreibung der Extension probieren (häufig bei Uploads).
        foreach ($imageExts as $candExt) {
            $candAbs = $absDir . '/' . $base . '.' . strtoupper($candExt);
            if (is_file($candAbs)) {
                return '/' . ltrim($dir, '/') . '/' . $base . '.' . strtoupper($candExt);
            }
        }
        unset($absolutePath);
        return '';
    }

    /**
     * v2.2.0: tstamp einer Datei aus tl_files holen — Contao trackt Uploads
     * und nachträgliche Replace-Vorgänge dort, das ist deutlich näher am
     * „publiziert"-Konzept als filemtime() auf dem inode.
     */
    private function fetchFileTstamp(string $relativePath): int
    {
        try {
            $row = $this->db->fetchAssociative(
                'SELECT tstamp FROM tl_files WHERE path = ?',
                [ltrim($relativePath, '/')],
            );
        } catch (\Throwable) {
            return 0;
        }
        if (!\is_array($row)) {
            return 0;
        }
        return (int) ($row['tstamp'] ?? 0);
    }

    private function humanizeFilename(string $filename): string
    {
        $cleaned = preg_replace('/[-_]+/', ' ', $filename) ?? $filename;
        return ucwords(mb_strtolower(trim($cleaned)));
    }

    /**
     * Liest Title + ALT-Text aus tl_files.meta für eine Datei. Contao speichert
     * Meta als serialisiertes Array pro Locale: ['de' => ['title'=>..,'alt'=>..], ...].
     * Locale-Auswahl: zuerst die erkannte File-Locale, dann erste enabled_locale,
     * dann irgendein verfügbarer Locale-Eintrag.
     *
     * Beide Felder werden gestrippt + gekürzt — landen sonst 1:1 in der UI
     * und im Index.
     *
     * @param list<string> $enabledLocales
     * @return array{title:string, alt:string}
     */
    private function extractFileMeta(string $relativePath, string $locale, array $enabledLocales): array
    {
        $empty = ['title' => '', 'alt' => ''];

        try {
            $row = $this->db->fetchAssociative(
                'SELECT meta FROM tl_files WHERE path = ?',
                [ltrim($relativePath, '/')],
            );
        } catch (\Throwable) {
            return $empty;
        }
        if (!\is_array($row) || empty($row['meta'])) {
            return $empty;
        }

        $raw = (string) $row['meta'];
        // Contao 4.x: serialisiertes PHP-Array. Contao 5.x kann auch JSON sein,
        // wenn das Backend per Doctrine-Migration die Spalte umgewandelt hat.
        // Wir versuchen beides — was zuerst klappt.
        $decoded = null;
        if ($raw !== '' && ($raw[0] === 'a' || $raw[0] === 's' || $raw[0] === 'b')) {
            $decoded = @unserialize($raw, ['allowed_classes' => false]);
        }
        if (!\is_array($decoded)) {
            $jsonDecoded = json_decode($raw, true);
            if (\is_array($jsonDecoded)) {
                $decoded = $jsonDecoded;
            }
        }
        if (!\is_array($decoded)) {
            return $empty;
        }

        // Locale-Auswahl: erst exakter Match, dann enabledLocales-Reihenfolge,
        // dann beliebiger erster Eintrag.
        $candidates = array_unique(array_filter(array_merge([$locale], $enabledLocales)));
        $entry = null;
        foreach ($candidates as $loc) {
            if (isset($decoded[$loc]) && \is_array($decoded[$loc])) {
                $entry = $decoded[$loc];
                break;
            }
        }
        if ($entry === null) {
            foreach ($decoded as $value) {
                if (\is_array($value)) {
                    $entry = $value;
                    break;
                }
            }
        }
        if (!\is_array($entry)) {
            return $empty;
        }

        $title = trim(strip_tags((string) ($entry['title'] ?? '')));
        $alt = trim(strip_tags((string) ($entry['alt'] ?? '')));

        return [
            'title' => mb_substr($title, 0, 255),
            'alt' => mb_substr($alt, 0, 500),
        ];
    }

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
}
