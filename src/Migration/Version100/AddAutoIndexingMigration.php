<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version100;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Legt die auto_indexing-Spalte in tl_venne_search_settings an, falls noch
 * nicht vorhanden. Greift bei Bestandskunden, die von 0.4.x auf 1.0 updaten,
 * bevor Contao seinen DCA-Schema-Diff anwendet — sonst wirft das Backend
 * einen "Unknown column auto_indexing"-Fehler beim ersten Settings-Render.
 */
final class AddAutoIndexingMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        if (!$schema->tablesExist(['tl_venne_search_settings'])) {
            return false;
        }

        $columns = $schema->listTableColumns('tl_venne_search_settings');

        return !isset($columns['auto_indexing']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            "ALTER TABLE tl_venne_search_settings ADD auto_indexing CHAR(1) NOT NULL DEFAULT '1'",
        );

        return $this->createResult(true, 'Spalte tl_venne_search_settings.auto_indexing angelegt.');
    }
}
