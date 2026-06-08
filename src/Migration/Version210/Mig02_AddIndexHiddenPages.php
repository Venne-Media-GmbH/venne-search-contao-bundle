<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version210;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * v2.1.0 / Mig02 — Setting `index_hidden_pages` in tl_venne_search_settings.
 *
 * Steuert, ob Seiten mit gesetztem `tl_page.hide`-Flag (Backend: „Im Menü
 * nicht anzeigen") in den Such-Index aufgenommen werden. Default: '1'
 * (= bisheriges Verhalten beibehalten — versteckte Seiten WERDEN indexiert).
 * Beim Umschalten muss der User reindexieren.
 */
final class Mig02_AddIndexHiddenPages extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Venne Search 2.1.0 — Setting index_hidden_pages';
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();
        if (!$schema->tablesExist(['tl_venne_search_settings'])) {
            return false;
        }
        $columns = $schema->listTableColumns('tl_venne_search_settings');

        return !isset($columns['index_hidden_pages']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            "ALTER TABLE tl_venne_search_settings ADD COLUMN index_hidden_pages CHAR(1) NOT NULL DEFAULT '1'"
        );

        return $this->createResult(true, "tl_venne_search_settings.index_hidden_pages hinzugefügt (Default '1').");
    }
}
