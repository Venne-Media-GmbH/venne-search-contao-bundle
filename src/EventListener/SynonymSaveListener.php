<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\DocumentIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Pusht die Synonym-Liste nach jedem Save/Delete im Backend an Meilisearch.
 *
 * Aufruf via DCA-Callbacks (onsubmit_callback + ondelete_callback) — die
 * Methoden sind als public statics aufrufbar von Contao 4.13 / 5.x. Auf
 * Contao 5.x werden DCA-Callbacks vom Container mit DI aufgeloest, hier
 * holen wir uns die Services aus dem Container per System::getContainer().
 */
final class SynonymSaveListener
{
    public function __construct(
        private readonly DocumentIndexer $indexer,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function onSave(/** @phpstan-ignore-next-line */ $dc = null): void
    {
        $this->pushAll();
    }

    public function onDelete(/** @phpstan-ignore-next-line */ $dc = null, int $undoId = 0): void
    {
        $this->pushAll();
    }

    private function pushAll(): void
    {
        if (!$this->settings->isConfigured()) {
            return;
        }
        try {
            $config = $this->settings->load();
            foreach ($config->enabledLocales as $locale) {
                $this->indexer->pushSynonyms($locale);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('venne_search.synonyms.listener_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
