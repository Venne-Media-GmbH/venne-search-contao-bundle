<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Indexer;

use Doctrine\DBAL\Connection;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Symfony\Component\HttpKernel\KernelInterface;
use VenneMedia\VenneSearchContaoBundle\Service\Locale\FileLocaleDetector;
use VenneMedia\VenneSearchContaoBundle\Service\Permission\PermissionResolver;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsConfig;

/**
 * Baut die komplette Reindex-Vorschau in einem einzigen Aufruf.
 *
 * Sammelt:
 *   - alle indexierbaren tl_page-Rows (mit Permission-Check)
 *   - alle indexierbaren tl_files-Rows (extension-Filter, Permission-Check, Pattern-Match)
 *   - die docId-Liste die aktuell im Meilisearch-Index liegt
 *
 * Bildet Diff:
 *   - "new"       → Item ist auf der Site, aber NICHT im Index
 *   - "existing"  → Item ist auf der Site UND im Index (würde übersprungen)
 *   - "excluded"  → Item würde indexiert, ist aber durch Modus oder Pattern ausgeschlossen
 *   - "orphan"    → Item ist im Index, aber NICHT mehr auf der Site
 *
 * Damit kann das Backend dem User VORAB sagen, was passiert — kein
 * "stand bei 750 von 2566 still"-Drama mehr, plus Sicherheitsanzeige
 * (öffentlich/geschützt/ausgeschlossen).
 */
final class ReindexCatalog
{
    public const INDEXABLE_FILE_EXTENSIONS = ['pdf', 'txt', 'md', 'docx', 'odt', 'rtf'];

    public function __construct(
        private readonly Connection $db,
        private readonly Client $meilisearch,
        private readonly KernelInterface $kernel,
        private readonly PermissionResolver $permissions,
        private readonly FileLocaleDetector $localeDetector,
    ) {
    }

    /**
     * @return array{
     *   stats: array{total:int,new:int,existing:int,excluded:int,orphans:int,public:int,protected:int},
     *   items: list<array{docId:string,type:string,ref:int|string,label:string,status:string,sizeKb:int,permission:string,allowedGroups:list<int>,excludedReason:?string}>,
     *   orphans: list<string>
     * }
     */
    public function buildPlan(SettingsConfig $config): array
    {
        $locale = $config->enabledLocales[0] ?? 'de';
        $mode = $config->indexMode;

        // Robots-Filter: Contao-Pages mit "noindex" im robots-Tag oder
        // noSearch=1 (Contao 4.13-spezifisch) werden NIEMALS indexiert.
        // Das respektiert die SEO-Konfiguration der Site-Betreiber.
        // noSearch existiert nur in Contao 4 — in 5.x weggefallen, daher
        // versuchen wir es per try/catch.
        try {
            $pageRows = $this->db->fetchAllAssociative(
                "SELECT id, alias, title FROM tl_page
                WHERE type IN ('regular', 'forward', 'redirect')
                  AND published = '1'
                  AND (robots = '' OR robots IS NULL OR robots NOT LIKE '%noindex%')
                  AND (noSearch IS NULL OR noSearch = '' OR noSearch = '0')
                ORDER BY id ASC"
            );
        } catch (\Throwable) {
            // Contao 5.x: noSearch-Spalte gibt's nicht mehr → ohne diesen Filter
            $pageRows = $this->db->fetchAllAssociative(
                "SELECT id, alias, title FROM tl_page
                WHERE type IN ('regular', 'forward', 'redirect')
                  AND published = '1'
                  AND (robots = '' OR robots IS NULL OR robots NOT LIKE '%noindex%')
                ORDER BY id ASC"
            );
        }
        // tl_files kann durch wiederholte Filesync-Läufe Duplikat-Einträge
        // pro Pfad enthalten. Wir nehmen pro Pfad die Row mit der niedrigsten
        // id — funktioniert auf allen MySQL/MariaDB-Versionen, ohne ANY_VALUE
        // (das gibt es erst in MySQL 5.7+ / MariaDB 10.5+).
        $fileRows = $this->db->fetchAllAssociative(
            "SELECT f.id, f.uuid, f.path, f.extension
             FROM tl_files f
             INNER JOIN (
                SELECT path, MIN(id) AS min_id
                FROM tl_files
                WHERE type = 'file'
                GROUP BY path
             ) AS unique_files ON unique_files.min_id = f.id
             ORDER BY f.path ASC"
        );

        $projectDir = rtrim($this->kernel->getProjectDir(), '/');

        // Site-Items aufbauen mit Permissions + Mode-Filter
        $items = [];
        $newCount = 0;
        $existingCount = 0;
        $excludedCount = 0;
        $publicCount = 0;
        $protectedCount = 0;
        $siteItemIds = [];

        $indexUid = $config->indexPrefix . '_' . $locale;
        $indexedIds = $this->loadIndexedIds($indexUid);

        // ===== PAGES =====
        // Page-Locale lookup: tl_page.language gilt nur auf Root-Pages, deshalb
        // wandern wir bei Bedarf nach oben zur Root und nehmen deren Sprache.
        $pageLocaleCache = [];
        foreach ($pageRows as $row) {
            $pageId = (int) $row['id'];
            $alias = (string) ($row['alias'] ?? '');
            $title = (string) ($row['title'] ?? '');
            $label = $title !== '' ? $title : ($alias !== '' ? '/' . $alias : 'Seite #' . $pageId);
            $docId = 'page-' . $pageId;
            $siteItemIds[$docId] = true;

            $perm = $this->permissions->resolvePagePermissions($pageId);
            $decision = $this->decidePermission(
                'page',
                'tl_page/' . ($alias !== '' ? $alias : (string) $pageId),
                $perm['isProtected'],
                $config,
            );

            $items[] = $this->buildItemEntry(
                docId: $docId,
                type: 'page',
                ref: $pageId,
                label: $label,
                sizeKb: 0,
                indexedIds: $indexedIds,
                perm: $perm,
                decision: $decision,
                newCount: $newCount,
                existingCount: $existingCount,
                excludedCount: $excludedCount,
                publicCount: $publicCount,
                protectedCount: $protectedCount,
                detectedLocale: $this->resolvePageLocale($pageId, $pageLocaleCache, $config),
            );
        }

        // ===== FILES =====
        // Master-Toggle (alter Name index_pdfs, wirkt aber auf ALLE Files).
        if (!$config->indexPdfs) {
            $fileRows = [];
        }

        foreach ($fileRows as $row) {
            $ext = strtolower((string) ($row['extension'] ?? ''));
            if (!\in_array($ext, self::INDEXABLE_FILE_EXTENSIONS, true)) {
                continue;
            }
            $relativePath = (string) $row['path'];
            $uuidBin = $row['uuid'] ?? null;
            $docId = ($uuidBin !== null && $uuidBin !== '')
                ? 'file-' . bin2hex((string) $uuidBin)
                : 'file-path-' . md5($relativePath);
            $siteItemIds[$docId] = true;

            $absolute = $projectDir . '/' . ltrim($relativePath, '/');
            $bytes = @filesize($absolute);
            $sizeKb = $bytes === false ? 0 : (int) round($bytes / 1024);

            // public_only-Modus: ACL-Lookup skippen (spart pro File einen
            // LIKE-Scan auf tl_content). Im with_protected-Modus brauchen
            // wir die Member-Groups für die Such-Filterung.
            $skipAcl = $config->indexMode === SettingsConfig::MODE_PUBLIC_ONLY;
            $perm = $this->permissions->resolveFilePermissions($relativePath, $skipAcl);
            $decision = $this->decidePermission('file', $relativePath, $perm['isProtected'], $config);

            $detectedLocale = $this->localeDetector->detect($relativePath, $config);

            $items[] = $this->buildItemEntry(
                docId: $docId,
                type: 'file',
                ref: $relativePath,
                label: basename($relativePath),
                sizeKb: $sizeKb,
                indexedIds: $indexedIds,
                perm: $perm,
                decision: $decision,
                newCount: $newCount,
                existingCount: $existingCount,
                excludedCount: $excludedCount,
                publicCount: $publicCount,
                protectedCount: $protectedCount,
                detectedLocale: $detectedLocale,
            );
        }

        $orphans = [];
        foreach ($indexedIds as $indexedId => $_) {
            if (!isset($siteItemIds[$indexedId])) {
                $orphans[] = $indexedId;
            }
        }

        return [
            'stats' => [
                'total' => \count($items),
                'new' => $newCount,
                'existing' => $existingCount,
                'excluded' => $excludedCount,
                'orphans' => \count($orphans),
                'public' => $publicCount,
                'protected' => $protectedCount,
            ],
            'items' => $items,
            'orphans' => $orphans,
        ];
    }

    /**
     * Entscheidet basierend auf Mode + Permissions ob ein Item indexiert
     * werden soll oder nicht.
     *
     * @return array{include:bool, reason:?string}
     */
    private function decidePermission(string $type, string $path, bool $isProtected, SettingsConfig $config): array
    {
        // Step 1 — Excluded-Patterns gelten IMMER (egal welcher Modus).
        // Das ist der harte Sicherheits-Backstop.
        if ($this->permissions->isPathExcluded($path, $config->excludedPaths)) {
            return ['include' => false, 'reason' => 'pattern_excluded'];
        }

        switch ($config->indexMode) {
            case SettingsConfig::MODE_PUBLIC_ONLY:
                if ($isProtected) {
                    return ['include' => false, 'reason' => 'protected_excluded'];
                }
                return ['include' => true, 'reason' => null];

            case SettingsConfig::MODE_WITH_PROTECTED:
                // Auch protected wird indexiert — die ACL-Filterung läuft
                // dann zur Such-Zeit über is_protected + allowed_groups.
                return ['include' => true, 'reason' => null];

            case SettingsConfig::MODE_WHITELIST:
                if (!$this->permissions->isPathExcluded($path, $config->includedPaths)) {
                    return ['include' => false, 'reason' => 'not_in_whitelist'];
                }
                if ($isProtected) {
                    // Whitelist + protected: trotzdem indexieren mit ACL.
                    return ['include' => true, 'reason' => null];
                }
                return ['include' => true, 'reason' => null];

            case SettingsConfig::MODE_BLACKLIST:
                // Excluded-Patterns wurden bereits oben geprüft.
                // Hier: protected darf rein, mit ACL-Filterung.
                return ['include' => true, 'reason' => null];

            default:
                return ['include' => false, 'reason' => 'unknown_mode'];
        }
    }

    /**
     * @param array<string,true>                              $indexedIds
     * @param array{isProtected:bool, allowedGroups:list<int>} $perm
     * @param array{include:bool, reason:?string}              $decision
     */
    private function buildItemEntry(
        string $docId,
        string $type,
        int|string $ref,
        string $label,
        int $sizeKb,
        array $indexedIds,
        array $perm,
        array $decision,
        int &$newCount,
        int &$existingCount,
        int &$excludedCount,
        int &$publicCount,
        int &$protectedCount,
        ?string $detectedLocale = null,
    ): array {
        $alreadyIndexed = isset($indexedIds[$docId]);

        if (!$decision['include']) {
            ++$excludedCount;
            $status = 'excluded';
        } elseif ($alreadyIndexed) {
            ++$existingCount;
            $status = 'existing';
        } else {
            ++$newCount;
            $status = 'new';
        }

        $permLabel = $perm['isProtected'] ? 'protected' : 'public';
        if ($perm['isProtected']) {
            ++$protectedCount;
        } else {
            ++$publicCount;
        }

        return [
            'docId' => $docId,
            'type' => $type,
            'ref' => $ref,
            'label' => $label,
            'status' => $status,
            'sizeKb' => $sizeKb,
            'permission' => $permLabel,
            'allowedGroups' => $perm['allowedGroups'],
            'excludedReason' => $decision['reason'],
            'detectedLocale' => $detectedLocale,
        ];
    }

    /**
     * Findet das Locale einer Page, indem nach oben zur Root gewandert wird.
     * Cached pro Run.
     *
     * @param array<int,string> $cache
     */
    private function resolvePageLocale(int $pageId, array &$cache, SettingsConfig $config): string
    {
        if (isset($cache[$pageId])) {
            return $cache[$pageId];
        }
        $current = $pageId;
        $locale = '';
        for ($depth = 0; $depth < 50 && $current > 0; $depth++) {
            try {
                $row = $this->db->fetchAssociative(
                    'SELECT pid, type, language FROM tl_page WHERE id = ?',
                    [$current],
                );
            } catch (\Throwable) {
                break;
            }
            if (!\is_array($row)) {
                break;
            }
            $lang = strtolower(trim((string) ($row['language'] ?? '')));
            if ($lang !== '') {
                $locale = $lang;
                break;
            }
            if (($row['type'] ?? '') === 'root') {
                break;
            }
            $current = (int) ($row['pid'] ?? 0);
        }
        if ($locale === '' || !\in_array($locale, $config->enabledLocales, true)) {
            $locale = $config->enabledLocales[0] ?? 'de';
        }
        $cache[$pageId] = $locale;
        return $locale;
    }

    /**
     * Lädt alle docIds aus dem Meilisearch-Index — paginiert in 1000er-Blöcken.
     *
     * @return array<string,true>
     */
    public function loadIndexedIds(string $indexUid): array
    {
        $ids = [];
        try {
            $offset = 0;
            $limit = 1000;
            for ($i = 0; $i < 200; $i++) {
                $query = (new DocumentsQuery())
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
            // Index existiert noch nicht — leere Liste zurückgeben.
        }

        return $ids;
    }
}
