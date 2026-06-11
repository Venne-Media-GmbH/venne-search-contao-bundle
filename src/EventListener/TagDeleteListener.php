<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\IndexableItemProcessor;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;
use VenneMedia\VenneSearchContaoBundle\Service\Tag\TagRepository;

/**
 * Saeubert nach Tag-Loeschen die Assignments + reindexiert alle Targets,
 * damit der geloeschte Tag aus dem Such-Index verschwindet.
 *
 * Wird per DCA-ondelete_callback auf tl_venne_search_tag aufgerufen.
 */
final class TagDeleteListener
{
    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly IndexableItemProcessor $processor,
        private readonly string $projectDir,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function onDelete(/** @phpstan-ignore-next-line */ $dc = null, int $undoId = 0): void
    {
        if (!\is_object($dc) || !isset($dc->id)) {
            return;
        }
        $tagId = (int) $dc->id;
        if ($tagId <= 0) {
            return;
        }

        // Targets vor Loeschung der Assignments sammeln — danach sind sie weg.
        try {
            $assignments = $this->db->fetchAllAssociative(
                'SELECT target_type, target_id FROM ' . TagRepository::ASSIGN_TABLE . ' WHERE tag_id = ?',
                [$tagId],
            );
        } catch (\Throwable) {
            $assignments = [];
        }

        // Assignments hart entfernen (Contao loescht nur die Tag-Zeile selbst).
        try {
            $this->db->executeStatement(
                'DELETE FROM ' . TagRepository::ASSIGN_TABLE . ' WHERE tag_id = ?',
                [$tagId],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('venne_search.tag.delete_assignments_failed', [
                'tagId' => $tagId,
                'error' => $e->getMessage(),
            ]);
        }

        // Targets reindexieren, damit der geloeschte Tag aus dem Such-Index verschwindet.
        if (!$this->settings->isConfigured() || $assignments === []) {
            return;
        }
        try {
            $config = $this->settings->load();
            foreach ($assignments as $a) {
                $type = (string) ($a['target_type'] ?? '');
                $tid = (string) ($a['target_id'] ?? '');
                if ($tid === '') {
                    continue;
                }
                try {
                    if ($type === 'page') {
                        $pageId = (int) $tid;
                        if ($pageId > 0) {
                            $this->processor->processItem(
                                ['type' => 'page', 'ref' => $pageId, 'docId' => 'page-' . $pageId],
                                $config,
                                $this->projectDir,
                            );
                        }
                    } elseif ($type === 'file') {
                        $this->processor->processItem(
                            ['type' => 'file', 'ref' => $tid, 'docId' => 'file-path-' . md5($tid)],
                            $config,
                            $this->projectDir,
                        );
                    }
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('venne_search.tag.delete_reindex_failed', [
                'tagId' => $tagId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
