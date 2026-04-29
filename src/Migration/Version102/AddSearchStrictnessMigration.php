<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version102;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * v1.0.2: Spalte search_strictness in tl_venne_search_settings anlegen.
 * Steuert die Tippfehler-Toleranz von Meilisearch (strict/balanced/tolerant).
 *
 * Hintergrund: User berichteten, dass Suchen wie „vegan" auch Treffer wie
 * „Verantwortung" liefern, weil Meilisearch standardmäßig ab Wortlänge 5
 * einen Tippfehler erlaubt. Mit dem neuen Setting kann der Site-Betreiber
 * im Backend zwischen strict (exakter), balanced (Default) und tolerant
 * (Original-Verhalten) wählen.
 */
final class AddSearchStrictnessMigration extends AbstractMigration
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

        return !isset($columns['search_strictness']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            "ALTER TABLE tl_venne_search_settings ADD search_strictness VARCHAR(16) NOT NULL DEFAULT 'balanced'",
        );

        return $this->createResult(true, 'Spalte tl_venne_search_settings.search_strictness angelegt (Default: balanced).');
    }
}
