<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Doctrine\DBAL\Connection;
use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Liefert JSON-Status für den Reindex-Fortschrittsbalken.
 *
 *   GET /contao/venne-search/progress
 *
 * Antwort:
 *   {
 *     "total": 145,            // letzte Reindex-Auftragsgröße
 *     "indexed": 87,           // aktuell im Index liegende Docs
 *     "percent": 60,           // 0–100
 *     "running": true,         // total > 0 und indexed < total
 *     "started_at": 1745764800
 *   }
 */
final class ProgressController extends AbstractController
{
    public function __construct(
        private readonly Client $meilisearch,
        private readonly SettingsRepository $settings,
        private readonly Connection $db,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        if (($unauthorized = $this->denyUnlessBackendUser()) !== null) {
            return $unauthorized;
        }

        if (!$this->settings->isConfigured()) {
            return new JsonResponse(['configured' => false]);
        }

        $config = $this->settings->load();

        $row = $this->db->fetchAssociative(
            'SELECT reindex_total, reindex_started_at FROM tl_venne_search_settings WHERE id = 1'
        ) ?: [];
        $total = (int) ($row['reindex_total'] ?? 0);
        $startedAt = (int) ($row['reindex_started_at'] ?? 0);

        // Indexed-Counter über Meilisearch — Summe über alle aktiven Locales.
        $indexed = 0;
        foreach ($config->enabledLocales as $locale) {
            $indexUid = $config->indexPrefix.'_'.$locale;
            try {
                $stats = $this->meilisearch->index($indexUid)->stats();
                $indexed += (int) ($stats['numberOfDocuments'] ?? 0);
            } catch (\Throwable) {
                // Index existiert noch nicht oder nicht erreichbar — skip.
            }
        }

        $percent = $total > 0 ? min(100, (int) round($indexed / $total * 100)) : 0;
        $running = $total > 0 && $indexed < $total;

        // Wenn schon eine Stunde her, gilt der Reindex als abgeschlossen
        // (falls die Worker wegen Crash hängen geblieben sind).
        if ($startedAt > 0 && time() - $startedAt > 3600) {
            $running = false;
        }

        return new JsonResponse([
            'configured' => true,
            'total' => $total,
            'indexed' => $indexed,
            'percent' => $percent,
            'running' => $running,
            'started_at' => $startedAt,
        ]);
    }

    private function denyUnlessBackendUser(): ?JsonResponse
    {
        if (!$this->isGranted('ROLE_USER') || !$this->getUser() instanceof BackendUser) {
            return new JsonResponse([
                'configured' => false,
                'error' => 'unauthorized',
            ], 403);
        }

        return null;
    }
}
