<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Permission;

use Doctrine\DBAL\Connection;

/**
 * Ermittelt für Pages und Files ob sie öffentlich oder geschützt sind,
 * und welche tl_member_group-IDs Zugriff haben.
 *
 * Contao-Logik:
 *   tl_page.protected (char(1) "1" oder ""): wird über die Hierarchie
 *     vererbt — sobald ein Ancestor protected=1 hat, ist die Seite
 *     geschützt. Beim Walking nach oben "gewinnt" das nächste explizit
 *     gesetzte Flag (in Contao 4 wird bis zur Root vererbt).
 *   tl_page.groups (blob, serialized array): tl_member_group-IDs die
 *     Zugriff haben. Bei mehreren Ebenen werden die Gruppen kumuliert.
 *
 *   Files: Contao 4.13+ markiert öffentliche Ordner über eine .public-
 *     Datei direkt im Filesystem (Marker-File, leerer Inhalt). Ein File
 *     ist öffentlich, wenn es selbst oder einer seiner Vorfahren-Ordner
 *     die .public-Marker-Datei besitzt. Ohne .public irgendwo im Pfad
 *     liefert Contao die Datei NICHT über /files/ aus → wir indexieren
 *     sie auch nicht. tl_files.groups (serialisiertes Array) bleibt
 *     daneben relevant für ACL-Filterung im Suchindex.
 *
 * Performance: Wir cachen die Folder-Permissions in einem Run, damit
 * 1000 Files nicht 1000 SQL-Queries auslösen.
 */
final class PermissionResolver
{
    /** @var array<int, array{isProtected:bool, allowedGroups:list<int>}> */
    private array $pageCache = [];

    /** @var array<string, array{isProtected:bool, allowedGroups:list<int>}> */
    private array $folderCache = [];

    /** @var array<string, bool> */
    private array $folderPublicCache = [];

    private ?string $projectDir = null;

    /** Cache: hat tl_files überhaupt eine `protected`-Spalte? (4.13: ja, 5.x: nein) */
    private ?bool $tlFilesHasProtectedColumn = null;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @return array{isProtected:bool, allowedGroups:list<int>}
     */
    public function resolvePagePermissions(int $pageId): array
    {
        if (isset($this->pageCache[$pageId])) {
            return $this->pageCache[$pageId];
        }

        $current = $pageId;
        $isProtected = false;
        $groups = [];

        // Walke Hierarchie nach oben — sobald wir auf eine protected Seite
        // stoßen, ist die Page geschützt. Groups akkumulieren über alle
        // protected Ancestors (Contao-Verhalten).
        for ($depth = 0; $depth < 50 && $current > 0; $depth++) {
            $row = $this->db->fetchAssociative(
                'SELECT id, pid, type, protected, `groups` FROM tl_page WHERE id = ?',
                [$current],
            );
            if (!\is_array($row)) {
                break;
            }

            if ((string) ($row['protected'] ?? '') === '1') {
                $isProtected = true;
                $rowGroups = $this->unserializeGroups((string) ($row['groups'] ?? ''));
                foreach ($rowGroups as $g) {
                    $groups[$g] = true;
                }
            }

            // Root-Page erreicht? Schluss.
            if (($row['type'] ?? '') === 'root') {
                break;
            }
            $current = (int) ($row['pid'] ?? 0);
        }

        $result = [
            'isProtected' => $isProtected,
            'allowedGroups' => array_values(array_map('intval', array_keys($groups))),
        ];
        $this->pageCache[$pageId] = $result;

        return $result;
    }

    /**
     * Liefert die Permissions für eine Datei. Walkt die Folder-Hierarchie
     * nach oben (über tl_files.path-Struktur). Sobald ein Folder protected=1
     * gesetzt hat, ist die Datei geschützt.
     *
     * @return array{isProtected:bool, allowedGroups:list<int>}
     */
    public function resolveFilePermissions(string $relativePath, bool $skipGroupLookup = false): array
    {
        // Standardannahme: Datei ist öffentlich. Sie wird NUR dann als
        // protected markiert, wenn wir konkrete Hinweise dafür finden:
        //   - Contao 4.13: tl_files.protected = '1' im Ordner (eigene Spalte)
        //   - Contao 5.x:  tl_files-Spalte existiert nicht mehr; Indikator
        //                  ist die Abwesenheit eines .public-Markers IRGENDWO
        //                  in der Hierarchie UND zusätzlich eine explizite
        //                  Sperre per Member-Group-Mount (heuristisch).
        //
        // Konkretes Verhalten:
        //   1) Wenn .public-Marker im Pfad existiert → public
        //   2) Sonst auf 4.13 nachsehen, ob protected-Spalte das sagt
        //   3) Sonst: public (default-offen, sonst kommen 0 Files in den Index)
        $isProtected = false;
        if (!$this->folderIsPublic($relativePath)) {
            if ($this->tlFilesHasProtectedColumn()) {
                $isProtected = $this->folderTreeIsProtected($relativePath);
            }
        }

        // Im public_only-Modus brauchen wir keine ACL — Catalog ruft uns
        // mit skipGroupLookup=true auf. Spart pro File einen LIKE-Scan auf
        // tl_content (Full Table Scan, weil multiSRC nicht indexiert ist).
        if ($skipGroupLookup) {
            return ['isProtected' => $isProtected, 'allowedGroups' => []];
        }

        // ACL: in 4.13 hat tl_files keine eigene `groups`-Spalte, deshalb
        // erben wir die Member-Groups vom Verlinkungs-Kontext: jede Page
        // die das File in einem Content-Element einbindet, darf ihre
        // tl_page.groups beisteuern. Anonymer User in der Frontend-Suche
        // matcht dann nur Files ohne ACL; eingeloggte Member sehen Files,
        // deren allowed_groups eine ihrer Member-Group-IDs enthält.
        $groups = $this->collectGroupsFromReferencingPages($relativePath);

        // Wenn das File nirgends referenziert ist, fallen wir auf die
        // (in 5.x optional vorhandene) tl_files.groups-Spalte zurück.
        // 4.13 wirft dort eine SQL-Exception → leeres Ergebnis.
        if ($groups === []) {
            $groups = $this->collectGroupsFromFolderTree($relativePath);
        }

        return [
            'isProtected' => $isProtected,
            'allowedGroups' => $groups,
        ];
    }

    /**
     * Sucht in tl_content nach Download-/Image-/Video-Elementen, die diese
     * Datei einbinden, walkt zur Trägerseite hoch und sammelt deren
     * tl_page.groups (rekursiv über Eltern).
     *
     * @return list<int>
     */
    private function collectGroupsFromReferencingPages(string $relativePath): array
    {
        $uuidBin = $this->lookupFileUuid($relativePath);
        if ($uuidBin === null) {
            return [];
        }

        $articleIds = [];
        // Single-Reference: singleSRC + posterSRC sind binary(16).
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT pid FROM tl_content WHERE (singleSRC = ? OR posterSRC = ?) AND ptable = 'tl_article' AND invisible = ''",
                [$uuidBin, $uuidBin],
            );
            foreach ($rows as $row) {
                $articleIds[(int) $row['pid']] = true;
            }
        } catch (\Throwable) {
        }
        // Multi-Reference: multiSRC ist serialized array — wir grep'n binär.
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT pid, multiSRC FROM tl_content WHERE multiSRC LIKE ? AND ptable = 'tl_article' AND invisible = ''",
                ['%' . $uuidBin . '%'],
            );
            foreach ($rows as $row) {
                $multi = (string) ($row['multiSRC'] ?? '');
                $data = @unserialize($multi, ['allowed_classes' => false]);
                $found = false;
                if (\is_array($data)) {
                    foreach ($data as $entry) {
                        if (\is_string($entry) && $entry === $uuidBin) {
                            $found = true;
                            break;
                        }
                    }
                } else {
                    $found = str_contains($multi, $uuidBin);
                }
                if ($found) {
                    $articleIds[(int) $row['pid']] = true;
                }
            }
        } catch (\Throwable) {
        }

        if ($articleIds === []) {
            return [];
        }

        $pageIds = [];
        foreach (array_keys($articleIds) as $articleId) {
            try {
                $row = $this->db->fetchAssociative('SELECT pid FROM tl_article WHERE id = ?', [$articleId]);
                if (\is_array($row) && isset($row['pid'])) {
                    $pageIds[(int) $row['pid']] = true;
                }
            } catch (\Throwable) {
            }
        }

        // Pro Trägerseite die Page-Permissions auflösen — die walken selbst
        // die Hierarchie nach oben und akkumulieren protected Ancestors.
        $allGroups = [];
        foreach (array_keys($pageIds) as $pageId) {
            $perm = $this->resolvePagePermissions($pageId);
            foreach ($perm['allowedGroups'] as $g) {
                $allGroups[$g] = true;
            }
        }

        return array_values(array_map('intval', array_keys($allGroups)));
    }

    private function lookupFileUuid(string $relativePath): ?string
    {
        try {
            $row = $this->db->fetchAssociative('SELECT uuid FROM tl_files WHERE path = ? LIMIT 1', [$relativePath]);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($row) || !isset($row['uuid'])) {
            return null;
        }
        $uuid = (string) $row['uuid'];

        return \strlen($uuid) === 16 ? $uuid : null;
    }

    /**
     * Prüft einmalig per INFORMATION_SCHEMA (cross-DB-fähig), ob tl_files
     * die `protected`-Spalte hat. Contao 4.13 hat sie, Contao 5.x nicht.
     */
    private function tlFilesHasProtectedColumn(): bool
    {
        if ($this->tlFilesHasProtectedColumn !== null) {
            return $this->tlFilesHasProtectedColumn;
        }
        try {
            $schema = $this->db->createSchemaManager();
            $columns = $schema->listTableColumns('tl_files');
            $this->tlFilesHasProtectedColumn = isset($columns['protected']);
        } catch (\Throwable) {
            $this->tlFilesHasProtectedColumn = false;
        }
        return $this->tlFilesHasProtectedColumn;
    }

    /**
     * Walkt den Ordnerbaum hoch und schaut in tl_files, ob ein Vorfahre
     * protected=1 hat. Nur sinnvoll wenn die protected-Spalte existiert
     * (Contao 4.13).
     */
    private function folderTreeIsProtected(string $relativePath): bool
    {
        $parent = \dirname($relativePath);
        while ($parent !== '.' && $parent !== '/' && $parent !== '') {
            $folder = $this->loadFolderPermission($parent);
            if ($folder['isProtected']) {
                return true;
            }
            $next = \dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }
        return false;
    }

    /**
     * Liefert true, wenn die Datei in einem Ordner liegt, der über eine
     * .public-Marker-Datei (in irgendeinem Vorfahren) öffentlich erreichbar
     * markiert ist. Contao gibt Files nur dann via /files/-URL aus.
     */
    private function folderIsPublic(string $relativePath): bool
    {
        $projectDir = $this->resolveProjectDir();
        $parent = \dirname($relativePath);

        while ($parent !== '.' && $parent !== '/' && $parent !== '') {
            if (isset($this->folderPublicCache[$parent])) {
                if ($this->folderPublicCache[$parent]) {
                    return true;
                }
            } else {
                $isPublic = is_file($projectDir . '/' . $parent . '/.public');
                $this->folderPublicCache[$parent] = $isPublic;
                if ($isPublic) {
                    return true;
                }
            }
            $next = \dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function collectGroupsFromFolderTree(string $relativePath): array
    {
        $groups = [];
        $parent = \dirname($relativePath);
        while ($parent !== '.' && $parent !== '/' && $parent !== '') {
            $folder = $this->loadFolderPermission($parent);
            foreach ($folder['allowedGroups'] as $g) {
                $groups[$g] = true;
            }
            $next = \dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }

        return array_values(array_map('intval', array_keys($groups)));
    }

    private function resolveProjectDir(): string
    {
        if ($this->projectDir !== null) {
            return $this->projectDir;
        }
        try {
            $this->projectDir = (string) \Contao\System::getContainer()->getParameter('kernel.project_dir');
        } catch (\Throwable) {
            $this->projectDir = '';
        }

        return $this->projectDir;
    }

    /**
     * Glob-Match für excluded/included paths. Unterstützt Standard-Glob:
     *   files/intern/*       → alles direkt unter files/intern
     *   files/intern/**      → rekursiv alles darunter
     *   files/_*             → alles in files/ das mit _ anfängt
     *
     * @param list<string> $patterns
     */
    public function isPathExcluded(string $path, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }
        foreach ($patterns as $pattern) {
            if ($this->globMatch($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{isProtected:bool, allowedGroups:list<int>}
     */
    private function loadFolderPermission(string $folderPath): array
    {
        if (isset($this->folderCache[$folderPath])) {
            return $this->folderCache[$folderPath];
        }

        // tl_files hat in Contao 4.13 keine protected/groups-Spalten — der
        // SELECT würde eine SQL-Exception werfen, die wir hier bewusst
        // schlucken. In 5.x mit aktiviertem Folder-ACL existieren die
        // Spalten und wir können die Werte lesen.
        try {
            $row = $this->db->fetchAssociative(
                "SELECT protected, `groups` FROM tl_files WHERE path = ? AND type = 'folder' LIMIT 1",
                [$folderPath],
            );
        } catch (\Throwable) {
            $row = null;
        }

        if (!\is_array($row)) {
            $result = ['isProtected' => false, 'allowedGroups' => []];
        } else {
            $result = [
                'isProtected' => (string) ($row['protected'] ?? '') === '1',
                'allowedGroups' => $this->unserializeGroups((string) ($row['groups'] ?? '')),
            ];
        }
        $this->folderCache[$folderPath] = $result;

        return $result;
    }

    /**
     * @return list<int>
     */
    private function unserializeGroups(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        // Contao serialisiert Arrays mit PHP serialize(). Allowed_classes
        // false fängt Object-Injection ab.
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!\is_array($data)) {
            return [];
        }
        return array_values(array_filter(
            array_map('intval', $data),
            static fn (int $v): bool => $v > 0,
        ));
    }

    private function globMatch(string $pattern, string $path): bool
    {
        // PHP fnmatch ist auf Linux super, auf Windows weniger zuverlässig
        // — wir bauen ein simples Regex-Äquivalent das überall gleich läuft.
        $regex = $this->globToRegex($pattern);
        return (bool) preg_match($regex, $path);
    }

    private function globToRegex(string $pattern): string
    {
        $regex = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === '*') {
                if (isset($pattern[$i + 1]) && $pattern[$i + 1] === '*') {
                    // ** = beliebig viele Verzeichnis-Ebenen
                    $regex .= '.*';
                    $i++;
                } else {
                    // * = alles außer Slash
                    $regex .= '[^/]*';
                }
            } elseif ($c === '?') {
                $regex .= '[^/]';
            } elseif (\in_array($c, ['.', '+', '(', ')', '[', ']', '{', '}', '|', '^', '$', '\\'], true)) {
                $regex .= '\\' . $c;
            } else {
                $regex .= $c;
            }
        }
        return '#^' . $regex . '$#i';
    }
}
