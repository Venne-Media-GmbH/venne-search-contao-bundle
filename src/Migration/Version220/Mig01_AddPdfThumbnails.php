<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Migration\Version220;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * v2.2.0 / Mig01 — Setting `generate_pdf_thumbnails` in tl_venne_search_settings.
 *
 * Steuert, ob beim Indexieren pro PDF ein JPG-Thumbnail der ersten Seite
 * generiert und als cover_url ins Meilisearch-Document geschrieben wird.
 * Voraussetzung: Ghostscript (`gs`) oder PHP-Imagick auf dem Server.
 *
 * Default: '0' (aus) — User soll bewusst aktivieren, weil pro PDF
 * 100-500ms zusätzliche Indexing-Zeit anfallen.
 */
final class Mig01_AddPdfThumbnails extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'Venne Search 2.2.0 — Setting generate_pdf_thumbnails';
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();
        if (!$schema->tablesExist(['tl_venne_search_settings'])) {
            return false;
        }
        $columns = $schema->listTableColumns('tl_venne_search_settings');

        return !isset($columns['generate_pdf_thumbnails']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            "ALTER TABLE tl_venne_search_settings ADD COLUMN generate_pdf_thumbnails CHAR(1) NOT NULL DEFAULT '0'"
        );

        return $this->createResult(true, "tl_venne_search_settings.generate_pdf_thumbnails hinzugefügt (Default '0').");
    }
}
