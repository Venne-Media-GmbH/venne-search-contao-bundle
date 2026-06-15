<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version220;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * v2.2.0 / Mig04 — Spalte `translations` in tl_venne_search_tag.
 *
 * JSON-Map locale → uebersetztes Label, z.B.
 *   {"en":"Press Releases","fr":"Communiqués"}
 *
 * Wenn fuer die aktuelle Page-Locale ein Eintrag existiert, wird der statt
 * dem Default-Label aus `tl_venne_search_tag.label` angezeigt. Fallback ist
 * immer das Default-Label.
 */
final class Mig04_AddTagTranslations extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Venne Search 2.2.0 — Spalte translations in tl_venne_search_tag';
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();
        if (!$schema->tablesExist(['tl_venne_search_tag'])) {
            return false;
        }
        $columns = $schema->listTableColumns('tl_venne_search_tag');
        return !isset($columns['translations']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            'ALTER TABLE tl_venne_search_tag ADD COLUMN translations TEXT NULL'
        );
        return $this->createResult(true, 'tl_venne_search_tag.translations hinzugefuegt.');
    }
}
