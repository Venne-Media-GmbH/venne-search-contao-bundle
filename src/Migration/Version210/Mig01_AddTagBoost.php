<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version210;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * v2.1.0 / Mig01 — Tag-Boost-Feld in tl_venne_search_tag.
 *
 * Boost-Wert pro Tag, der in das `weight`-Feld jedes zugeordneten
 * Search-Documents fließt. Sortier-Reihenfolge im Index nutzt
 * `weight DESC` als Tie-Breaker vor `indexed_at DESC`. Damit ein
 * Tag-Boost greift, müssen alle zugeordneten Documents reindexiert
 * werden — der DCA save_callback triggert das automatisch.
 */
final class Mig01_AddTagBoost extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Venne Search 2.1.0 — Tag-Boost-Feld in tl_venne_search_tag';
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();
        if (!$schema->tablesExist(['tl_venne_search_tag'])) {
            return false;
        }
        $columns = $schema->listTableColumns('tl_venne_search_tag');

        return !isset($columns['boost']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            "ALTER TABLE tl_venne_search_tag ADD COLUMN boost DECIMAL(4,2) NOT NULL DEFAULT 1.00 AFTER color"
        );

        return $this->createResult(true, 'tl_venne_search_tag.boost hinzugefügt (Default 1.00).');
    }
}
