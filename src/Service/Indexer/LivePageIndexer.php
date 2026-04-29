<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Indexer;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VenneMedia\VenneSearchContaoBundle\Service\Pdf\PdfExtractor;
use VenneMedia\VenneSearchContaoBundle\Service\Permission\PermissionResolver;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsConfig;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;
use VenneMedia\VenneSearchContaoBundle\Service\Text\TextNormalizer;

/**
 * Indexiert einzelne Pages oder Files synchron — wird von den Contao-Hooks
 * (PageSave, FileUpload, FileDelete) direkt aufgerufen, ohne Messenger-Worker.
 *
 * Wird auch vom ReindexStreamController genutzt — DRY.
 */
final class LivePageIndexer
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentIndexer $indexer,
        private readonly TextNormalizer $normalizer,
        private readonly PdfExtractor $pdfExtractor,
        private readonly SettingsRepository $settings,
        private readonly PermissionResolver $permissions,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Indexiert eine Page komplett (inklusive aller Articles und Content-Elements).
     * Findet die Page via DB-Read, baut SearchDocument, schreibt's nach Meilisearch.
     */
    public function indexPage(int $pageId): void
    {
        if (!$this->settings->isConfigured()) {
            return;
        }

        try {
            $config = $this->settings->load();
        } catch (\Throwable) {
            return;
        }

        $pageRow = $this->db->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$pageId]);
        if (!$pageRow) {
            return;
        }

        // Nur publizierte Pages werden indexiert. Doctrine DBAL kann je nach
        // MySQL-Version int(1) oder string('1') zurückliefern, also tolerant
        // casten — sonst greift der String-Strict-Vergleich nicht.
        if ((string) ($pageRow['published'] ?? '') !== '1') {
            $this->deletePage($pageId, (string) ($pageRow['language'] ?: 'de'));
            return;
        }

        // Nur Page-Typen die wir indexieren
        $type = (string) ($pageRow['type'] ?? '');
        if (!\in_array($type, ['regular', 'forward', 'redirect'], true)) {
            return;
        }

        // Robots-Tag respektieren: noindex → aus Index raus
        $robots = (string) ($pageRow['robots'] ?? '');
        if ($robots !== '' && stripos($robots, 'noindex') !== false) {
            $this->deletePage($pageId, (string) ($pageRow['language'] ?: 'de'));
            return;
        }
        // Contao 4.13: noSearch-Flag (in 5.x nicht mehr vorhanden)
        if (isset($pageRow['noSearch']) && (string) $pageRow['noSearch'] === '1') {
            $this->deletePage($pageId, (string) ($pageRow['language'] ?: 'de'));
            return;
        }

        // Permission-Check + Mode-Filter — wenn die Page laut Modus
        // nicht indexiert werden darf, löschen wir sie auch direkt aus
        // dem Index (falls sie da noch ist) und brechen ab. So bleibt
        // der Index automatisch konsistent wenn der User eine Page
        // nachträglich auf protected umstellt.
        $perm = $this->permissions->resolvePagePermissions($pageId);
        $alias = (string) ($pageRow['alias'] ?? '');
        $logicalPath = 'tl_page/' . ($alias !== '' ? $alias : (string) $pageId);
        if ($this->shouldSkipForPermission('page', $logicalPath, $perm['isProtected'], $config)) {
            $this->deletePage($pageId, (string) ($pageRow['language'] ?: 'de'));
            return;
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

        // Echte Frontend-URL via Contao-PageModel — inkl. URL-Suffix (.html),
        // Sprach-Prefix, Tree-Pfad. Fallback auf simple alias-URL bei Fehler.
        $url = $this->resolvePageUrl($pageId, (string) ($pageRow['alias'] ?? ''));

        $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($pageRow['keywords'] ?? '')))));

        $doc = new SearchDocument(
            id: 'page-'.$pageId,
            type: 'page',
            locale: (string) ($pageRow['language'] ?: 'de'),
            title: (string) ($pageRow['pageTitle'] ?: ($pageRow['title'] ?? '')),
            url: $url,
            content: $normalizedContent,
            tags: $tags,
            publishedAt: !empty($pageRow['start']) ? (int) $pageRow['start'] : (int) ($pageRow['tstamp'] ?? 0),
            weight: 1.0,
            isProtected: $perm['isProtected'],
            allowedGroups: $perm['allowedGroups'],
        );

        try {
            $this->indexer->upsert($doc);
        } catch (\Throwable $e) {
            $this->logger->warning('venne_search.live.page_index_failed', [
                'pageId' => $pageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Findet die Parent-Page einer Article/Content-ID und indexiert sie neu.
     */
    public function indexFromArticleOrContent(string $table, int $id): void
    {
        $pageId = match ($table) {
            'tl_page' => $id,
            'tl_article' => (int) ($this->db->fetchOne('SELECT pid FROM tl_article WHERE id = ?', [$id]) ?: 0),
            'tl_content' => $this->resolvePageIdFromContent($id),
            default => 0,
        };

        if ($pageId > 0) {
            $this->indexPage($pageId);
        }
    }

    private function resolvePageIdFromContent(int $contentId): int
    {
        $content = $this->db->fetchAssociative('SELECT pid, ptable FROM tl_content WHERE id = ?', [$contentId]);
        if (!$content || ($content['ptable'] ?? 'tl_article') !== 'tl_article') {
            return 0;
        }
        return (int) ($this->db->fetchOne('SELECT pid FROM tl_article WHERE id = ?', [(int) $content['pid']]) ?: 0);
    }

    /**
     * Indexiert eine Datei aus tl_files (z.B. nach Upload). Pfad relativ zur
     * Contao-files/-Wurzel.
     */
    public function indexFile(string $relativePath, string $projectDir, ?string $uuidBin = null): void
    {
        if (!$this->settings->isConfigured()) {
            return;
        }

        $config = $this->settings->load();
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        $supportedExt = ['pdf', 'txt', 'md', 'docx', 'odt', 'rtf'];
        if (!\in_array($extension, $supportedExt, true)) {
            return;
        }
        // index_pdfs ist seit v0.3.0 ein Master-Toggle für ALLE Files.
        if (!$config->indexPdfs) {
            return;
        }

        // Permission-Check (siehe indexPage()).
        $perm = $this->permissions->resolveFilePermissions($relativePath);
        if ($this->shouldSkipForPermission('file', $relativePath, $perm['isProtected'], $config)) {
            $this->deleteFile($uuidBin, $relativePath);
            return;
        }

        $absolute = rtrim($projectDir, '/').'/'.$relativePath;
        if (!is_file($absolute) || !is_readable($absolute)) {
            return;
        }

        $text = match ($extension) {
            'pdf' => $this->pdfExtractor->extract($absolute, $config->maxFileSizeMb)->text,
            'txt', 'md' => (string) file_get_contents($absolute),
            default => '',
        };

        if ($text === '') {
            return;
        }

        $docId = $uuidBin !== null && $uuidBin !== ''
            ? 'file-'.bin2hex($uuidBin)
            : 'file-path-'.md5($relativePath);

        $doc = new SearchDocument(
            id: $docId,
            type: 'file',
            locale: $config->enabledLocales[0] ?? 'de',
            title: $this->humanizeFilename(pathinfo($relativePath, PATHINFO_FILENAME)),
            url: '/'.$relativePath,
            content: $this->normalizer->normalize($text),
            tags: [$extension],
            isProtected: $perm['isProtected'],
            allowedGroups: $perm['allowedGroups'],
        );

        try {
            $this->indexer->upsert($doc);
        } catch (\Throwable $e) {
            $this->logger->warning('venne_search.live.file_index_failed', [
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deletePage(int $pageId, string $locale = 'de'): void
    {
        try {
            $this->indexer->delete('page-'.$pageId, $locale);
        } catch (\Throwable) {
        }
    }

    public function deleteFile(?string $uuidBin, string $relativePath, string $locale = 'de'): void
    {
        $docId = $uuidBin !== null && $uuidBin !== ''
            ? 'file-'.bin2hex($uuidBin)
            : 'file-path-'.md5($relativePath);
        try {
            $this->indexer->delete($docId, $locale);
        } catch (\Throwable) {
        }
    }

    /**
     * Spiegelt die Modus-Logik aus IndexableItemProcessor und ReindexCatalog.
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

    private function humanizeFilename(string $filename): string
    {
        $cleaned = preg_replace('/[-_]+/', ' ', $filename) ?? $filename;
        return ucwords(mb_strtolower(trim($cleaned)));
    }

    /**
     * Generiert die Frontend-URL rein aus DB-Daten — KEINE Contao-Framework-
     * Aufrufe (PageModel, UrlGenerator) im Indexer-Kontext, die crashen.
     */
    private function resolvePageUrl(int $pageId, string $aliasFallback): string
    {
        $alias = ltrim($aliasFallback, '/');
        if ($alias === '' || $alias === 'index') {
            return '/';
        }
        return '/'.$alias.$this->resolveUrlSuffix($pageId);
    }

    /**
     * Liest den URL-Suffix: Root-Page urlSuffix oder Container-Parameter
     * contao.url_suffix (Default '.html').
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
                    break;
                }
                $current = (int) ($row['pid'] ?? 0);
            }
        } catch (\Throwable) {
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
}
