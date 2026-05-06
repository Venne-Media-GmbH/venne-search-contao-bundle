<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Tag;

use Doctrine\DBAL\Connection;
use VenneMedia\VenneSearchContaoBundle\Migration\Version200\Mig02_AddTagSystem;

/**
 * Read/Write-API für das Tag-System. Defensiv gegen "Tabelle gibt's noch
 * nicht" (vor der Migration), so dass das Bundle auch in einem Übergangs-
 * zustand boot-fähig bleibt.
 */
final class TagRepository
{
    public const TAG_TABLE = 'tl_venne_search_tag';
    public const ASSIGN_TABLE = 'tl_venne_search_tag_assignment';

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @return list<array{id:int, slug:string, label:string, color:string, description:?string, count:int}>
     */
    public function findAllWithCounts(): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT t.id, t.slug, t.label, t.color, t.description,
                        (SELECT COUNT(*) FROM ' . self::ASSIGN_TABLE . ' a WHERE a.tag_id = t.id) AS cnt
                 FROM ' . self::TAG_TABLE . ' t
                 ORDER BY t.label ASC',
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'slug' => (string) $r['slug'],
                'label' => (string) $r['label'],
                'color' => (string) $r['color'],
                'description' => $r['description'] !== null ? (string) $r['description'] : null,
                'count' => (int) $r['cnt'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{id:int, slug:string, label:string, color:string}>
     */
    public function findAll(): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, slug, label, color FROM ' . self::TAG_TABLE . ' ORDER BY label ASC',
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'slug' => (string) $r['slug'],
                'label' => (string) $r['label'],
                'color' => (string) $r['color'],
            ];
        }
        return $out;
    }

    /**
     * Slugs aller Tags die einer Target zugewiesen sind.
     *
     * @return list<string>
     */
    public function slugsForTarget(string $targetType, string $targetId): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT t.slug FROM ' . self::TAG_TABLE . ' t
                 INNER JOIN ' . self::ASSIGN_TABLE . ' a ON a.tag_id = t.id
                 WHERE a.target_type = ? AND a.target_id = ?
                 ORDER BY t.label ASC',
                [$targetType, $targetId],
            );
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn ($r): string => (string) $r['slug'], $rows);
    }

    /**
     * Volle Tag-Daten für eine Target. Kommt im Frontend an die Search-Hits ran.
     *
     * @return list<array{slug:string, label:string, color:string}>
     */
    public function tagsForTarget(string $targetType, string $targetId): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT t.slug, t.label, t.color FROM ' . self::TAG_TABLE . ' t
                 INNER JOIN ' . self::ASSIGN_TABLE . ' a ON a.tag_id = t.id
                 WHERE a.target_type = ? AND a.target_id = ?
                 ORDER BY t.label ASC',
                [$targetType, $targetId],
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'slug' => (string) $r['slug'],
                'label' => (string) $r['label'],
                'color' => (string) $r['color'],
            ];
        }
        return $out;
    }

    /**
     * Tagged-Count für eine ganze Liste targets in einem Query (für Backend-Tree).
     *
     * @param list<array{type:string, id:string}> $targets
     * @return array<string, list<array{slug:string,label:string,color:string}>> Schlüssel: "type:id"
     */
    public function bulkTagsForTargets(array $targets): array
    {
        if (!$this->tablesExist() || $targets === []) {
            return [];
        }

        // Wir gruppieren pro target_type, damit der WHERE-Clause clean bleibt.
        $byType = [];
        foreach ($targets as $t) {
            $byType[$t['type']][] = $t['id'];
        }

        $result = [];
        foreach ($byType as $type => $ids) {
            $ids = array_values(array_unique($ids));
            if ($ids === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, \count($ids), '?'));
            try {
                $rows = $this->db->fetchAllAssociative(
                    'SELECT a.target_id, t.slug, t.label, t.color
                     FROM ' . self::ASSIGN_TABLE . ' a
                     INNER JOIN ' . self::TAG_TABLE . ' t ON t.id = a.tag_id
                     WHERE a.target_type = ? AND a.target_id IN (' . $placeholders . ')
                     ORDER BY t.label ASC',
                    array_merge([$type], $ids),
                );
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $r) {
                $key = $type . ':' . $r['target_id'];
                if (!isset($result[$key])) {
                    $result[$key] = [];
                }
                $result[$key][] = [
                    'slug' => (string) $r['slug'],
                    'label' => (string) $r['label'],
                    'color' => (string) $r['color'],
                ];
            }
        }
        return $result;
    }

    public function findBySlug(string $slug): ?array
    {
        if (!$this->tablesExist()) {
            return null;
        }
        try {
            $row = $this->db->fetchAssociative(
                'SELECT * FROM ' . self::TAG_TABLE . ' WHERE slug = ?',
                [$slug],
            );
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($row)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'label' => (string) $row['label'],
            'color' => (string) $row['color'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
        ];
    }

    public function ensureTag(string $label, ?string $slug = null, string $color = 'blue'): int
    {
        if (!$this->tablesExist()) {
            return 0;
        }
        $slug = $slug !== null && $slug !== '' ? $slug : Mig02_AddTagSystem::slugify($label);
        if ($slug === '') {
            return 0;
        }
        try {
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . self::TAG_TABLE . ' WHERE slug = ?',
                [$slug],
            );
            if ($existing !== false && $existing !== null) {
                return (int) $existing;
            }
            $this->db->executeStatement(
                'INSERT INTO ' . self::TAG_TABLE . ' (tstamp, slug, label, color) VALUES (?, ?, ?, ?)',
                [time(), $slug, mb_substr($label, 0, 120), $color],
            );
            return (int) $this->db->lastInsertId();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function assign(int $tagId, string $targetType, string $targetId): bool
    {
        if (!$this->tablesExist() || $tagId <= 0) {
            return false;
        }
        try {
            $this->db->executeStatement(
                'INSERT IGNORE INTO ' . self::ASSIGN_TABLE . ' (tstamp, tag_id, target_type, target_id) VALUES (?, ?, ?, ?)',
                [time(), $tagId, $targetType, $targetId],
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function unassign(int $tagId, string $targetType, string $targetId): bool
    {
        if (!$this->tablesExist() || $tagId <= 0) {
            return false;
        }
        try {
            $this->db->executeStatement(
                'DELETE FROM ' . self::ASSIGN_TABLE . ' WHERE tag_id = ? AND target_type = ? AND target_id = ?',
                [$tagId, $targetType, $targetId],
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Liste aller Targets mit einem bestimmten Tag — für die Drill-Down-Ansicht.
     *
     * @return list<array{targetType:string, targetId:string}>
     */
    public function targetsForTag(int $tagId): array
    {
        if (!$this->tablesExist() || $tagId <= 0) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT target_type, target_id FROM ' . self::ASSIGN_TABLE . ' WHERE tag_id = ? ORDER BY target_type ASC, target_id ASC',
                [$tagId],
            );
        } catch (\Throwable) {
            return [];
        }
        return array_map(
            static fn ($r): array => ['targetType' => (string) $r['target_type'], 'targetId' => (string) $r['target_id']],
            $rows,
        );
    }

    public function deleteTag(int $tagId): bool
    {
        if (!$this->tablesExist() || $tagId <= 0) {
            return false;
        }
        try {
            $this->db->executeStatement(
                'DELETE FROM ' . self::ASSIGN_TABLE . ' WHERE tag_id = ?',
                [$tagId],
            );
            $this->db->executeStatement(
                'DELETE FROM ' . self::TAG_TABLE . ' WHERE id = ?',
                [$tagId],
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function tablesExist(): bool
    {
        try {
            $sm = $this->db->createSchemaManager();
            return $sm->tablesExist([self::TAG_TABLE, self::ASSIGN_TABLE]);
        } catch (\Throwable) {
            return false;
        }
    }
}
