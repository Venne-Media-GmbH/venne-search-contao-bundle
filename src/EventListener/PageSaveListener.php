<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\LivePageIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Reindexiert die zugehörige Page sofort nach jedem Save eines
 * tl_page/tl_article/tl_content-Datensatzes im Backend.
 *
 * Performance: ~50-200 ms pro Save (DB-Reads + Meilisearch-Upsert) — das
 * fühlt sich für den Redakteur immer noch wie "instant" an. Bei Pages mit
 * sehr vielen ContentElements eventuell höher, aber selbst 500 ms sind ok.
 *
 * Wir registrieren bewusst als DCA-`onsubmit_callback` (pro Tabelle) und
 * nicht als Hook — `onsubmit_callback` ist kein globaler Contao-Hook,
 * sondern ein DCA-Callback, der pro Datensatz nach erfolgreichem Save feuert.
 */
final class PageSaveListener
{
    public function __construct(
        private readonly LivePageIndexer $live,
        private readonly SettingsRepository $settings,
    ) {
    }

    #[AsCallback(table: 'tl_page', target: 'config.onsubmit')]
    public function onPageSubmit(DataContainer $dc): void
    {
        $this->reindex('tl_page', (int) $dc->id);
    }

    #[AsCallback(table: 'tl_article', target: 'config.onsubmit')]
    public function onArticleSubmit(DataContainer $dc): void
    {
        $this->reindex('tl_article', (int) $dc->id);
    }

    #[AsCallback(table: 'tl_content', target: 'config.onsubmit')]
    public function onContentSubmit(DataContainer $dc): void
    {
        $this->reindex('tl_content', (int) $dc->id);
    }

    private function reindex(string $table, int $id): void
    {
        if ($id <= 0) {
            return;
        }

        // Auto-Indexing-Toggle aus Settings respektieren.
        try {
            if (!$this->settings->load()->autoIndexing) {
                return;
            }
        } catch (\Throwable) {
            // Bei nicht konfiguriertem Bundle: kein Indexing.
            return;
        }

        try {
            $this->live->indexFromArticleOrContent($table, $id);
        } catch (\Throwable) {
            // Backend-Save darf nie an Indexierung scheitern.
        }
    }
}
