<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version220;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * v2.2.0 / Mig02 — Spalte `auto_match_pattern` in tl_venne_search_tag.
 *
 * Erlaubt Glob-Patterns pro Tag (eines pro Zeile). Bei der Suche prüft
 * SearchService jede Treffer-URL gegen die Patterns aller Tags — passt
 * eines, hängt es den Tag automatisch dran. Kein Index-Schreibvorgang
 * nötig, funktioniert für Contao-Pages, Files und extern gecrawlte URLs.
 */
final class Mig02_AddTagAutoMatchPattern extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Venne Search 2.2.0 — Spalte auto_match_pattern in tl_venne_search_tag';
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();
        if (!$schema->tablesExist(['tl_venne_search_tag'])) {
            return false;
        }
        $columns = $schema->listTableColumns('tl_venne_search_tag');

        return !isset($columns['auto_match_pattern']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            'ALTER TABLE tl_venne_search_tag ADD COLUMN auto_match_pattern TEXT NULL'
        );

        return $this->createResult(true, 'tl_venne_search_tag.auto_match_pattern hinzugefügt.');
    }
}
