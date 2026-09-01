<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version220;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * v2.2.0 / Mig03 — Synonym-Tabelle tl_venne_search_synonym.
 *
 * Pro Eintrag: ein „Term" (das im Index/Content steht, z.B. „Ausstellung")
 * und eine Liste von Synonymen (komma-separiert, z.B. „Messe, Expo, Fair").
 * Beim Push zu Meilisearch werden die Eintraege als bidirektionales Mapping
 * ans Index-Setting `synonyms` durchgereicht — Suche nach „Messe" findet
 * dann auch Docs mit „Ausstellung" und umgekehrt.
 */
final class Mig03_AddSynonyms extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Venne Search 2.2.0 — Tabelle tl_venne_search_synonym';
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        return !$schema->tablesExist(['tl_venne_search_synonym']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_venne_search_synonym (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                tstamp INT(10) UNSIGNED NOT NULL DEFAULT 0,
                term VARCHAR(128) NOT NULL DEFAULT '',
                synonyms TEXT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                active CHAR(1) NOT NULL DEFAULT '1',
                PRIMARY KEY (id),
                KEY term (term)
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        return $this->createResult(true, 'tl_venne_search_synonym angelegt.');
    }
}
