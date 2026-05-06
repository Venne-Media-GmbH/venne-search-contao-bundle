<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version200;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * v2.0.0 / Mig02 — Tag-System: tl_venne_search_tag + tl_venne_search_tag_assignment.
 *
 * Beim ersten Run werden vorhandene tl_page.keywords (CSV) in das neue
 * Tag-System überführt — gleicher Slug = gleicher Tag, kein Duplikat.
 * Nach der Migration ist der CSV-Pfad weiterhin lesbar (für Bestandskompatibilität),
 * aber das DCA-Feld wird im Backend als Legacy markiert.
 */
final class Mig02_AddTagSystem extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Venne Search 2.0.0 — Tag-System (tl_venne_search_tag + assignment)';
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();
        return !$schema->tablesExist(['tl_venne_search_tag'])
            || !$schema->tablesExist(['tl_venne_search_tag_assignment']);
    }

    public function run(): MigrationResult
    {
        $schema = $this->connection->createSchemaManager();
        $created = [];

        if (!$schema->tablesExist(['tl_venne_search_tag'])) {
            $this->connection->executeStatement(<<<'SQL'
                CREATE TABLE tl_venne_search_tag (
                    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                    tstamp INT(10) UNSIGNED NOT NULL DEFAULT 0,
                    slug VARCHAR(64) NOT NULL DEFAULT '',
                    label VARCHAR(120) NOT NULL DEFAULT '',
                    description TEXT NULL,
                    color VARCHAR(16) NOT NULL DEFAULT 'blue',
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_slug (slug)
                ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
            SQL);
            $created[] = 'tl_venne_search_tag';
        }

        if (!$schema->tablesExist(['tl_venne_search_tag_assignment'])) {
            $this->connection->executeStatement(<<<'SQL'
                CREATE TABLE tl_venne_search_tag_assignment (
                    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                    tstamp INT(10) UNSIGNED NOT NULL DEFAULT 0,
                    tag_id INT(10) UNSIGNED NOT NULL,
                    target_type VARCHAR(8) NOT NULL,
                    target_id VARCHAR(128) NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_assign (tag_id, target_type, target_id),
                    KEY idx_target (target_type, target_id),
                    KEY idx_tag (tag_id)
                ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
            SQL);
            $created[] = 'tl_venne_search_tag_assignment';
        }

        // Legacy-Keywords aus tl_page in das Tag-System überführen (einmalig).
        $migratedCount = $this->migrateLegacyKeywords();

        $message = $created === []
            ? 'Tag-System bereits vorhanden — nichts zu tun.'
            : 'Tag-System angelegt: ' . implode(', ', $created);
        if ($migratedCount > 0) {
            $message .= sprintf(' · %d Legacy-Keywords aus tl_page übernommen.', $migratedCount);
        }

        return $this->createResult(true, $message);
    }

    /**
     * Liest tl_page.keywords (CSV pro Page), legt pro eindeutigem Keyword
     * einen Tag an (slug = sluggify(label)) und fügt Assignments hinzu.
     */
    private function migrateLegacyKeywords(): int
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, keywords FROM tl_page WHERE keywords IS NOT NULL AND keywords <> ''"
            );
        } catch (\Throwable) {
            return 0;
        }

        $assignmentsCreated = 0;
        foreach ($rows as $row) {
            $pageId = (int) $row['id'];
            $raw = (string) $row['keywords'];
            $keywords = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
            foreach ($keywords as $keyword) {
                $slug = self::slugify($keyword);
                if ($slug === '') {
                    continue;
                }
                $tagId = $this->ensureTag($slug, $keyword);
                if ($tagId === 0) {
                    continue;
                }
                try {
                    $this->connection->executeStatement(
                        'INSERT IGNORE INTO tl_venne_search_tag_assignment (tstamp, tag_id, target_type, target_id) VALUES (?, ?, ?, ?)',
                        [time(), $tagId, 'page', (string) $pageId],
                    );
                    if ($this->connection->fetchOne(
                        'SELECT 1 FROM tl_venne_search_tag_assignment WHERE tag_id = ? AND target_type = ? AND target_id = ?',
                        [$tagId, 'page', (string) $pageId],
                    )) {
                        ++$assignmentsCreated;
                    }
                } catch (\Throwable) {
                }
            }
        }

        return $assignmentsCreated;
    }

    private function ensureTag(string $slug, string $label): int
    {
        try {
            $existing = $this->connection->fetchOne(
                'SELECT id FROM tl_venne_search_tag WHERE slug = ?',
                [$slug],
            );
            if ($existing !== false && $existing !== null) {
                return (int) $existing;
            }
            $this->connection->executeStatement(
                'INSERT INTO tl_venne_search_tag (tstamp, slug, label, color) VALUES (?, ?, ?, ?)',
                [time(), $slug, mb_substr($label, 0, 120), 'blue'],
            );
            return (int) $this->connection->lastInsertId();
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function slugify(string $raw): string
    {
        $raw = mb_strtolower(trim($raw), 'UTF-8');
        $raw = strtr($raw, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'à' => 'a', 'á' => 'a', 'â' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
        $raw = preg_replace('/[^a-z0-9]+/', '-', $raw) ?? $raw;
        $raw = trim($raw, '-');
        return mb_substr($raw, 0, 64);
    }
}
