<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveException;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Entfernt einzelne oder mehrere Dokumente direkt aus dem Meilisearch-Index.
 *
 * Body (JSON):
 *   {"docIds": ["page-475", "file-abc123"]}    // Liste konkreter IDs
 *   {"removeAllProtected": true}                 // Alle Dokumente mit is_protected=true
 *
 * Antwort:
 *   {"ok": true, "removed": 12}
 *
 * Use-Case:
 *   - Backend-Admin sieht in der Index-Browser-Tabelle einen Doc den er
 *     NICHT in der Suche haben will → Klick auf den Entfernen-Button → weg.
 *   - Bei Modus-Wechsel public_only → with_protected zurück: alle protected
 *     Docs in einem Rutsch raus.
 */
final class ReindexRemoveController extends AbstractController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Client $meilisearch,
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

            $locale = $config->enabledLocales[0] ?? 'de';
            $indexUid = $config->indexPrefix . '_' . $locale;

            $totalRemoved = 0;

            // Konkrete IDs löschen
            if (isset($payload['docIds']) && \is_array($payload['docIds']) && $payload['docIds'] !== []) {
                $ids = array_values(array_filter(
                    $payload['docIds'],
                    static fn ($v): bool => \is_string($v) && $v !== '',
                ));
                if ($ids !== []) {
                    try {
                        $this->meilisearch->index($indexUid)->deleteDocuments($ids);
                        $totalRemoved += \count($ids);
                    } catch (\Throwable $e) {
                        return new JsonResponse([
                            'ok' => false,
                            'error' => 'delete_failed:' . substr($e->getMessage(), 0, 200),
                        ], 200);
                    }
                }
            }

            // Alle protected Docs auf einen Schlag raus
            if (!empty($payload['removeAllProtected'])) {
                try {
                    $this->meilisearch->index($indexUid)->deleteDocuments([
                        'filter' => 'is_protected = true',
                    ]);
                    // Wir wissen den Count nicht (Meilisearch returniert nur Task-ID)
                    // — Frontend zeigt "wird gelöscht" + reload.
                    $totalRemoved += -1;  // Marker für "Bulk via Filter"
                } catch (\Throwable $e) {
                    return new JsonResponse([
                        'ok' => false,
                        'error' => 'delete_protected_failed:' . substr($e->getMessage(), 0, 200),
                    ], 200);
                }
            }

            return new JsonResponse([
                'ok' => true,
                'removed' => $totalRemoved,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'remove_crash:' . substr($e->getMessage(), 0, 200),
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
