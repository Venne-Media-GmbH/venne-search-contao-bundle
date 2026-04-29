<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Doctrine\DBAL\Connection;
use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveException;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Schließt einen Reindex-Run ab — Statistik schreiben + Karteileichen löschen.
 *
 * Wird vom Browser-JS einmal aufgerufen, wenn die Item-Schleife alle "new"-
 * Items abgearbeitet hat.
 *
 * Body:
 *   {
 *     "runId": "abc...",
 *     "indexed": 520,
 *     "skipped": 12,
 *     "errors": 0,
 *     "removeOrphans": true,
 *     "orphans": ["page-999", ...]
 *   }
 */
final class ReindexFinalizeController extends AbstractController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Client $meilisearch,
        private readonly Connection $db,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (($unauthorized = $this->denyUnlessBackendUser()) !== null) {
            return $unauthorized;
        }
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_request'], 400);
        }

        try {
            if (!$this->settings->isConfigured()) {
                return new JsonResponse(['ok' => false, 'error' => 'not_configured'], 200);
            }

            try {
                $config = $this->settings->load();
            } catch (ResolveException $e) {
                return new JsonResponse(['ok' => false, 'error' => substr($e->getMessage(), 0, 200)], 200);
            }

            $payload = json_decode((string) $request->getContent(), true);
            if (!\is_array($payload)) {
                return new JsonResponse(['ok' => false, 'error' => 'invalid_body'], 200);
            }

            $orphansRemoved = 0;
            $removeOrphans = (bool) ($payload['removeOrphans'] ?? false);
            $orphans = $payload['orphans'] ?? [];

            if ($removeOrphans && \is_array($orphans) && $orphans !== []) {
                $locale = $config->enabledLocales[0] ?? 'de';
                $indexUid = $config->indexPrefix . '_' . $locale;
                try {
                    $this->meilisearch->index($indexUid)->deleteDocuments(array_values(array_filter(
                        $orphans,
                        static fn ($v): bool => \is_string($v) && $v !== '',
                    )));
                    $orphansRemoved = \count($orphans);
                } catch (\Throwable) {
                    // Best-effort.
                }
            }

            try {
                $this->db->executeStatement(
                    'UPDATE tl_venne_search_settings SET reindex_run_id = NULL WHERE id = 1'
                );
            } catch (\Throwable) {
            }

            return new JsonResponse([
                'ok' => true,
                'orphansRemoved' => $orphansRemoved,
                'indexed' => (int) ($payload['indexed'] ?? 0),
                'skipped' => (int) ($payload['skipped'] ?? 0),
                'errors' => (int) ($payload['errors'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'finalize_crash:' . substr($e->getMessage(), 0, 200),
                'errorClass' => \get_class($e),
                'errorFile' => basename($e->getFile()) . ':' . $e->getLine(),
            ], 200);
        }
    }

    private function denyUnlessBackendUser(): ?JsonResponse
    {
        if (!$this->isGranted('ROLE_USER') || !$this->getUser() instanceof BackendUser) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 403);
        }

        return null;
    }
}
