<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\KernelInterface;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\LivePageIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Synchroner Live-Indexer für Datei-Upload — kein Messenger-Worker nötig.
 * Bei jedem hochgeladenen PDF/TXT/MD/DOCX/ODT/RTF wird die Datei direkt nach
 * dem Upload indexiert.
 */
#[AsHook('postUpload')]
final class FileUploadListener
{
    public function __construct(
        private readonly LivePageIndexer $live,
        private readonly KernelInterface $kernel,
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * @param array<int, string> $files relative Pfade
     */
    public function __invoke(array $files): void
    {
        try {
            if (!$this->settings->load()->autoIndexing) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $projectDir = $this->kernel->getProjectDir();

        foreach ($files as $relativePath) {
            $relativePath = ltrim((string) $relativePath, '/');
            try {
                // UUID aus tl_files holen falls schon vorhanden (bei manchen
                // Upload-Flows wird die UUID asynchron eingetragen — dann
                // nehmen wir Path-MD5 als Fallback)
                $uuidBin = $this->db->fetchOne('SELECT uuid FROM tl_files WHERE path = ?', [$relativePath]);
                $this->live->indexFile(
                    relativePath: $relativePath,
                    projectDir: $projectDir,
                    uuidBin: \is_string($uuidBin) ? $uuidBin : null,
                );
            } catch (\Throwable) {
                // Upload soll nie an Indexierung scheitern.
            }
        }
    }
}
