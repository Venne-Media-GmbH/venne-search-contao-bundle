<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version200;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * v2.0.0 / Mig01 — Mehrsprachigkeit & Analytics-Toggle.
 *
 * Erweitert tl_venne_search_settings um:
 *   - default_file_locale     varchar(8)  — Default für File-Locale-Detection (leer = erste enabled_locale)
 *   - file_locale_overrides   longtext    — JSON-Map { "files/foo.pdf": "en", … }
 *   - analytics_enabled       char(1)     — Master-Toggle für Search-Analytics
 *
 * Erweitert tl_module + tl_content um:
 *   - vsearch_locale          varchar(8)  — pro Modul/Element zu suchende Locale (leer = Page-Default)
 *
 * Idempotent über Spalten-Existenz-Checks.
 */
final class Mig01_AddMultilingualSupport extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Venne Search 2.0.0 — Mehrsprachigkeit & Analytics-Toggle';
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        $needsSettings = $schema->tablesExist(['tl_venne_search_settings'])
            && !$this->allSettingsColumnsExist();

        $needsModule = $schema->tablesExist(['tl_module'])
            && !$this->columnExists('tl_module', 'vsearch_locale');

        $needsContent = $schema->tablesExist(['tl_content'])
            && !$this->columnExists('tl_content', 'vsearch_locale');

        return $needsSettings || $needsModule || $needsContent;
    }

    public function run(): MigrationResult
    {
        $applied = [];

        if ($this->columnsExist('tl_venne_search_settings')) {
            if (!$this->columnExists('tl_venne_search_settings', 'default_file_locale')) {
                $this->connection->executeStatement(
                    "ALTER TABLE tl_venne_search_settings ADD default_file_locale VARCHAR(8) NOT NULL DEFAULT ''",
                );
                $applied[] = 'default_file_locale';
            }
            if (!$this->columnExists('tl_venne_search_settings', 'file_locale_overrides')) {
                $this->connection->executeStatement(
                    'ALTER TABLE tl_venne_search_settings ADD file_locale_overrides LONGTEXT NULL',
                );
                $applied[] = 'file_locale_overrides';
            }
            if (!$this->columnExists('tl_venne_search_settings', 'analytics_enabled')) {
                $this->connection->executeStatement(
                    "ALTER TABLE tl_venne_search_settings ADD analytics_enabled CHAR(1) NOT NULL DEFAULT '1'",
                );
                $applied[] = 'analytics_enabled';
            }
        }

        if ($this->connection->createSchemaManager()->tablesExist(['tl_module'])
            && !$this->columnExists('tl_module', 'vsearch_locale')) {
            $this->connection->executeStatement(
                "ALTER TABLE tl_module ADD vsearch_locale VARCHAR(8) NOT NULL DEFAULT ''",
            );
            $applied[] = 'tl_module.vsearch_locale';
        }

        if ($this->connection->createSchemaManager()->tablesExist(['tl_content'])
            && !$this->columnExists('tl_content', 'vsearch_locale')) {
            $this->connection->executeStatement(
                "ALTER TABLE tl_content ADD vsearch_locale VARCHAR(8) NOT NULL DEFAULT ''",
            );
            $applied[] = 'tl_content.vsearch_locale';
        }

        return $this->createResult(
            true,
            $applied === []
                ? 'Mehrsprachigkeits-Migration: alles bereits vorhanden, nichts zu tun.'
                : 'Mehrsprachigkeits-Migration angelegt: ' . implode(', ', $applied),
        );
    }

    private function columnsExist(string $table): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$table]);
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $columns = $this->connection->createSchemaManager()->listTableColumns($table);
        } catch (\Throwable) {
            return false;
        }
        // listTableColumns lowercased die Schlüssel.
        return isset($columns[strtolower($column)]);
    }

    private function allSettingsColumnsExist(): bool
    {
        return $this->columnExists('tl_venne_search_settings', 'default_file_locale')
            && $this->columnExists('tl_venne_search_settings', 'file_locale_overrides')
            && $this->columnExists('tl_venne_search_settings', 'analytics_enabled');
    }
}
