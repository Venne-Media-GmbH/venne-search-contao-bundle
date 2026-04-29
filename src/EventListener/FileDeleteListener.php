<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Doctrine\DBAL\Connection;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\LivePageIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Wird vor dem Löschen einer Datei aufgerufen. Wir holen UUID + Pfad aus
 * tl_files und entfernen das Doc aus dem Suchindex, BEVOR die DB-Row weg ist.
 */
#[AsHook('removeFile')]
final class FileDeleteListener
{
    public function __construct(
        private readonly LivePageIndexer $live,
        private readonly SettingsRepository $settings,
        private readonly Connection $db,
    ) {
    }

    /**
     * Contao-Hook removeFile: bekommt den FileSystem-Pfad als String.
     */
    public function __invoke(string $strFile): void
    {
        try {
            $config = $this->settings->load();
        } catch (\Throwable) {
            return;
        }

        if (!$config->autoIndexing) {
            return;
        }

        $relativePath = ltrim($strFile, '/');
        $row = $this->db->fetchAssociative('SELECT uuid FROM tl_files WHERE path = ?', [$relativePath]);
        $uuidBin = \is_array($row) && isset($row['uuid']) ? (string) $row['uuid'] : null;

        $locale = $config->enabledLocales[0] ?? 'de';

        try {
            $this->live->deleteFile($uuidBin, $relativePath, $locale);
        } catch (\Throwable) {
        }
    }
}
