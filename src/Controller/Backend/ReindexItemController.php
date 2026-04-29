<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\IndexableItemProcessor;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveAuthException;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveProvisioningException;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveSubscriptionException;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Indexiert ein einzelnes Item — wird vom Browser-JS in einer Schleife
 * (Concurrency=2) für jedes "new"-Item aus dem Plan aufgerufen.
 *
 * Pro Request: max ~10 Sekunden für ein dickes PDF. Passt sicher in jedes
 * PHP-FPM-max_execution_time. Kein SSE-Stream, keine Reconnects, kein
 * Watchdog — wenn ein Request stirbt, retryed der Browser nur diesen einen.
 *
 * Body (JSON):
 *   {
 *     "runId": "abc...",
 *     "item": {"docId": "page-475", "type": "page", "ref": 475}
 *   }
 *
 * Antwort:
 *   {"ok": true, "docId": "...", "contentLen": 12345, "durationMs": 4200}
 *   {"ok": true, "skipped": true, "reason": "empty_textlayer_image_only", ...}
 *   {"ok": false, "error": "..."}
 */
final class ReindexItemController extends AbstractController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly IndexableItemProcessor $processor,
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

        @set_time_limit(60);
        // Hoch genug damit auch ein 20-MB-PDF (~1.5 GB pdfparser peak)
        // nicht zum Fatal-Out-of-Memory führt. PHP-Fatal ist kein Exception
        // und zerstört den HTTP-Response → Browser sieht "invalid_json".
        @ini_set('memory_limit', '2G');

        // Shutdown-Handler: wenn PHP intern crasht (Fatal-Error, Segfault
        // im pdfparser, Stack-Overflow), wirft das KEINE Exception. Hier
        // fangen wir den letzten Atemzug ab und schreiben eine valide
        // JSON-Antwort statt einer HTML-Symfony-Fehlerseite.
        @ob_start();
        register_shutdown_function(function (): void {
            $err = error_get_last();
            if ($err !== null && \in_array($err['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                // Output-Buffer wegwerfen damit kein HTML-Müll vor unserem JSON steht
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                    http_response_code(200);
                }
                echo json_encode([
                    'ok' => false,
                    'error' => 'php_fatal:' . substr((string) ($err['message'] ?? ''), 0, 200),
                    'errorFile' => basename((string) ($err['file'] ?? '')) . ':' . (int) ($err['line'] ?? 0),
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        });

        try {
            if (!$this->settings->isConfigured()) {
                return new JsonResponse(['ok' => false, 'error' => 'not_configured'], 200);
            }

            try {
                $config = $this->settings->load();
            } catch (ResolveAuthException) {
                return new JsonResponse(['ok' => false, 'error' => 'auth'], 200);
            } catch (ResolveSubscriptionException) {
                return new JsonResponse(['ok' => false, 'error' => 'subscription'], 200);
            } catch (ResolveProvisioningException) {
                return new JsonResponse(['ok' => false, 'error' => 'provisioning'], 200);
            } catch (\Throwable $e) {
                return new JsonResponse(['ok' => false, 'error' => 'platform:' . substr($e->getMessage(), 0, 160)], 200);
            }

            $payload = json_decode((string) $request->getContent(), true);
            if (!\is_array($payload) || !\is_array($payload['item'] ?? null)) {
                return new JsonResponse(['ok' => false, 'error' => 'invalid_body'], 200);
            }

            $item = $payload['item'];
            if (!isset($item['docId'], $item['type'], $item['ref'])) {
                return new JsonResponse(['ok' => false, 'error' => 'invalid_item'], 200);
            }

            $type = (string) $item['type'];
            if (!\in_array($type, ['page', 'file'], true)) {
                return new JsonResponse(['ok' => false, 'error' => 'invalid_type'], 200);
            }

            $projectDir = (string) $this->getParameter('kernel.project_dir');

            $result = $this->processor->processItem(
                [
                    'type' => $type,
                    'ref' => $item['ref'],
                    'docId' => (string) $item['docId'],
                ],
                $config,
                $projectDir,
            );

            $result['docId'] = (string) $item['docId'];

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'item_crash:' . substr($e->getMessage(), 0, 200),
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
