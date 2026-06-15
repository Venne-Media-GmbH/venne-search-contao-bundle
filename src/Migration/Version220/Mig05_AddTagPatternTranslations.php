<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version220;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * v2.2.0 / Mig05 — Spalte `auto_match_pattern_translations` in tl_venne_search_tag.
 *
 * Erlaubt Auto-Match-URL-Patterns pro Locale, weil englische Detail-Seiten
 * oft andere URL-Struktur haben (z.B. `press-release-detail` statt
 * `pressemitteilungen-detailseite`). Speicherformat: JSON-Map locale →
 * patterns-string (eine Zeile pro Pattern, wie das deutsche Default-Feld).
 */
final class Mig05_AddTagPatternTranslations extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Venne Search 2.2.0 — Spalte auto_match_pattern_translations in tl_venne_search_tag';
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();
        if (!$schema->tablesExist(['tl_venne_search_tag'])) {
            return false;
        }
        $columns = $schema->listTableColumns('tl_venne_search_tag');
        return !isset($columns['auto_match_pattern_translations']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            'ALTER TABLE tl_venne_search_tag ADD COLUMN auto_match_pattern_translations TEXT NULL'
        );
        return $this->createResult(true, 'tl_venne_search_tag.auto_match_pattern_translations hinzugefuegt.');
    }
}
