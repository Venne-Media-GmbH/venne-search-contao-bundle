<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Indexer;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use VenneMedia\VenneSearchContaoBundle\Message\IndexFileMessage;
use VenneMedia\VenneSearchContaoBundle\Message\IndexPageMessage;

/**
 * Findet alle indexierbaren Inhalte einer Contao-Site und erzeugt
 * IndexJobs für jeden einzelnen.
 *
 * Wird vom "Komplett-Reindex"-Backend-Button (BackendActionListener) und
 * vom CLI-Befehl `venne-search:reindex-all` aufgerufen. Crawlt synchron
 * die Listen aus DB, dispatcht aber jeden Job asynchron — so blockiert
 * der Backend-Request nicht.
 */
final class SiteCrawler
{
    /** Datei-Endungen die wir als Volltext-indexierbar betrachten. */
    private const INDEXABLE_FILE_EXTENSIONS = ['pdf', 'txt', 'md', 'docx', 'odt', 'rtf'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $db,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Stößt den Komplett-Reindex an. Liefert Statistik darüber zurück,
     * wie viele Jobs eingequeued wurden.
     *
     * @return array{pages:int,files:int}
     */
    public function reindexAll(): array
    {
        $this->framework->initialize();

        $pages = $this->dispatchAllPages();
        $files = $this->dispatchAllFiles();

        return ['pages' => $pages, 'files' => $files];
    }

    private function dispatchAllPages(): int
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT id FROM tl_page WHERE type IN ('regular', 'forward', 'redirect') AND published = '1'"
        );

        $count = 0;
        foreach ($rows as $row) {
            $this->bus->dispatch(new IndexPageMessage('tl_page', (int) $row['id']));
            ++$count;
        }

        return $count;
    }

    private function dispatchAllFiles(): int
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, path, extension FROM tl_files WHERE type = ?',
            ['file'],
        );

        $count = 0;
        foreach ($rows as $row) {
            $ext = strtolower((string) $row['extension']);
            if (!\in_array($ext, self::INDEXABLE_FILE_EXTENSIONS, true)) {
                continue;
            }
            $path = (string) $row['path'];
            if ($path === '') {
                continue;
            }
            $this->bus->dispatch(new IndexFileMessage($path));
            ++$count;
        }

        return $count;
    }
}
