<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version110;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Legt die excluded_folders-Spalte an (blob mit serialisierten Folder-UUIDs)
 * und füllt sie beim ersten Migrationslauf mit sinnvollen Defaults: alle
 * Ordner unter files/intern, files/admin, files/private werden vorgewählt,
 * sofern sie existieren. So ist das Bundle nach dem Update sofort sicher,
 * ohne dass der Kunde was tun muss.
 */
final class AddExcludedFoldersMigration extends AbstractMigration
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

        return !isset($columns['excluded_folders']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            'ALTER TABLE tl_venne_search_settings ADD excluded_folders BLOB NULL',
        );

        // Default-Auswahl ableiten: typische Admin-Ordner falls vorhanden.
        // Pro Pfad nur eine UUID (manche Setups haben Duplikat-Einträge in
        // tl_files aus alten Filesync-Läufen — die wir hier wegfiltern).
        $defaults = [];
        $seenPaths = [];
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT uuid, path FROM tl_files WHERE path IN ('files/intern', 'files/admin', 'files/private') AND type = 'folder' ORDER BY id ASC",
            );
            foreach ($rows as $row) {
                $path = (string) ($row['path'] ?? '');
                if ($path === '' || isset($seenPaths[$path])) {
                    continue;
                }
                if (isset($row['uuid']) && \is_string($row['uuid'])) {
                    $defaults[] = $row['uuid'];
                    $seenPaths[$path] = true;
                }
            }
        } catch (\Throwable) {
            // tl_files muss noch nicht existieren beim allerersten Setup.
        }

        if ($defaults !== []) {
            $this->connection->executeStatement(
                'UPDATE tl_venne_search_settings SET excluded_folders = ? WHERE id = 1',
                [serialize($defaults)],
            );
        }

        return $this->createResult(true, \sprintf(
            'Spalte excluded_folders angelegt%s.',
            $defaults !== [] ? ', ' . \count($defaults) . ' Default-Ordner ausgeschlossen' : '',
        ));
    }
}
