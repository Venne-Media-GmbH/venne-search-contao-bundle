<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\DocumentIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\ReindexCatalog;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveAuthException;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveProvisioningException;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveSubscriptionException;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Liefert vor jedem Reindex die komplette Vorschau:
 *   - alle Pages + Files die indexiert werden
 *   - welche davon schon im Index sind
 *   - Karteileichen (im Index, aber nicht mehr auf der Site)
 *
 * Diese Antwort lädt das Backend-Panel beim Klick auf "Jetzt indexieren" —
 * der User sieht SOFORT was passiert, bevor irgendwas gestartet wird.
 *
 * Antwort:
 *   {
 *     "ok": true,
 *     "runId": "abc1234567890def",
 *     "stats": {"total": 2566, "new": 520, "existing": 2046, "orphans": 3},
 *     "items": [{"docId": ..., "type": ..., "label": ..., "status": "new"|"existing", ...}, ...],
 *     "orphans": ["page-999", ...]
 *   }
 */
final class ReindexPlanController extends AbstractController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ReindexCatalog $catalog,
        private readonly Connection $db,
        private readonly DocumentIndexer $indexer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Schreibt Diagnose-Meldungen in var/logs/venne-search-reindex.log —
     * unabhängig vom Symfony-Logger, damit der Admin auch dann was sieht
     * wenn Symfony's eigene Logger-Pipeline kaputt ist (Container-Defekte,
     * fehlende Cache-Files etc.).
     */
    public function diagLog(string $level, string $msg, array $context = []): void
    {
        $line = '[' . date('c') . '] ' . strtoupper($level) . ': ' . $msg;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        $line .= "\n";

        // Bundle-eigenes Log (immer, unabhängig von Symfony)
        $bundleLog = $this->resolveBundleLogPath();
        if ($bundleLog !== null) {
            @file_put_contents($bundleLog, $line, FILE_APPEND);
        }

        // Symfony-Logger zusätzlich (falls intakt)
        try {
            $this->logger->log($level === 'error' ? 'error' : 'info', '[venne-search.reindex-plan] ' . $msg, $context);
        } catch (\Throwable) {
            // Symfony-Pipeline kaputt — egal, wir haben unser eigenes Log
        }
    }

    private function resolveBundleLogPath(): ?string
    {
        // %kernel.project_dir%/var/logs/venne-search-reindex.log
        try {
            $projectDir = (string) $this->getParameter('kernel.project_dir');
        } catch (\Throwable) {
            return null;
        }
        $dir = $projectDir . '/var/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) ? $dir . '/venne-search-reindex.log' : null;
    }

    public function __invoke(Request $request): JsonResponse
    {
        $reqId = bin2hex(random_bytes(4));
        // Sehr früher Log-Eintrag — zeigt dass der Controller überhaupt
        // erreicht wurde. Wenn das im Log fehlt, hat Symfony den Request
        // gar nicht zum Controller geroutet (Container-Defekt, Routing-Fehler).
        $this->diagLog('info', 'request_start', [
            'reqId' => $reqId,
            'uri' => $request->getRequestUri(),
            'method' => $request->getMethod(),
            'isXhr' => $request->isXmlHttpRequest(),
            'php' => PHP_VERSION,
            'memory_limit' => \ini_get('memory_limit'),
            'time_limit' => \ini_get('max_execution_time'),
        ]);

        if (($unauthorized = $this->denyUnlessBackendUser()) !== null) {
            $this->diagLog('error', 'auth_denied', ['reqId' => $reqId]);
            return $unauthorized;
        }
        if (!$request->isXmlHttpRequest()) {
            $this->diagLog('error', 'not_xhr', ['reqId' => $reqId]);
            return new JsonResponse(['ok' => false, 'error' => 'invalid_request'], 400);
        }

        // Bullet-proof: Wir umhüllen ALLES — egal was schief geht, der User
        // bekommt JSON zurück (kein 500-HTML). So kann das Frontend immer
        // einen sinnvollen Fehler-Modal zeigen.
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');

        // Shutdown-Handler: fängt PHP-Fatals (Memory-Exhausted, Stack-Overflow,
        // Parse-Errors in lazy-geladenen Files) die kein try/catch kriegt.
        $self = $this;
        register_shutdown_function(static function () use ($self, $reqId) {
            $err = error_get_last();
            if ($err !== null && \in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                $self->diagLog('error', 'php_fatal', [
                    'reqId' => $reqId,
                    'type' => $err['type'],
                    'msg' => $err['message'],
                    'file' => $err['file'] . ':' . $err['line'],
                ]);
            }
        });

        try {
            if (!$this->settings->isConfigured()) {
                $this->diagLog('error', 'not_configured', ['reqId' => $reqId]);
                return new JsonResponse(['ok' => false, 'error' => 'API-Key nicht konfiguriert.'], 400);
            }

            try {
                $config = $this->settings->load();
                $this->diagLog('info', 'settings_loaded', [
                    'reqId' => $reqId,
                    'locales' => $config->enabledLocales,
                    'indexPrefix' => $config->indexPrefix,
                    'endpoint' => $config->endpoint,
                ]);
            } catch (ResolveAuthException) {
                $this->diagLog('error', 'resolve_auth_failed', ['reqId' => $reqId]);
                return new JsonResponse(['ok' => false, 'error' => 'Plattform-Key ungültig oder widerrufen.'], 200);
            } catch (ResolveSubscriptionException) {
                $this->diagLog('error', 'resolve_subscription_failed', ['reqId' => $reqId]);
                return new JsonResponse(['ok' => false, 'error' => 'Venne-Search-Abo nicht aktiv.'], 200);
            } catch (ResolveProvisioningException) {
                $this->diagLog('error', 'resolve_provisioning', ['reqId' => $reqId]);
                return new JsonResponse(['ok' => false, 'error' => 'Bundle wartet auf Provisionierung.'], 200);
            } catch (\Throwable $e) {
                $this->diagLog('error', 'settings_load_failed', [
                    'reqId' => $reqId,
                    'msg' => $e->getMessage(),
                    'class' => $e::class,
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ]);
                return new JsonResponse(['ok' => false, 'error' => 'Plattform-Fehler: ' . substr($e->getMessage(), 0, 200)], 200);
            }

            // Schema-Update: bevor wir Plan bauen, sicherstellen dass der
            // Index die aktuellen Filterable-Attribute kennt (is_protected,
            // allowed_groups). Sonst crasht der Documents-Panel beim
            // Permission-Filter, und der Plan-Stat funktioniert nicht.
            // ensureIndex() ist idempotent — bei modernen Indexen ein No-Op.
            try {
                foreach ($config->enabledLocales as $loc) {
                    $this->indexer->ensureIndex($loc);
                }
                $this->diagLog('info', 'ensure_index_ok', ['reqId' => $reqId]);
            } catch (\Throwable $e) {
                $this->diagLog('warning', 'ensure_index_failed', [
                    'reqId' => $reqId, 'msg' => $e->getMessage(),
                    'class' => $e::class, 'file' => $e->getFile() . ':' . $e->getLine(),
                ]);
                // Best-effort. Wenn Meili down ist, fängt das der Plan-Build
                // selber unten gleich ab.
            }

            $planStart = microtime(true);
            try {
                // Progress-Callback: jede Stufe im buildPlan landet im
                // bundle-eigenen Log mit reqId und Memory-Verbrauch — so
                // siehst du SOFORT wo's klemmt wenn der Request hängt.
                $self = $this;
                $plan = $this->catalog->buildPlan($config, static function (string $stage, array $ctx) use ($self, $reqId): void {
                    $self->diagLog('info', 'plan:' . $stage, array_merge(['reqId' => $reqId], $ctx));
                });
                $this->diagLog('info', 'plan_built', [
                    'reqId' => $reqId,
                    'durationMs' => (int) ((microtime(true) - $planStart) * 1000),
                    'stats' => $plan['stats'] ?? null,
                ]);
            } catch (\Throwable $e) {
                $this->diagLog('error', 'plan_build_failed', [
                    'reqId' => $reqId,
                    'durationMs' => (int) ((microtime(true) - $planStart) * 1000),
                    'msg' => $e->getMessage(),
                    'class' => $e::class,
                    'file' => $e->getFile() . ':' . $e->getLine(),
                    'trace' => substr($e->getTraceAsString(), 0, 2000),
                ]);
                return new JsonResponse([
                    'ok' => false,
                    'error' => 'Plan-Build fehlgeschlagen: ' . substr($e->getMessage(), 0, 200),
                    'errorClass' => $e::class,
                    'errorFile' => basename($e->getFile()) . ':' . $e->getLine(),
                ], 200);
            }

            $runId = bin2hex(random_bytes(8));
            // Wir schreiben NUR die Spalten, die in jedem Setup garantiert
            // existieren (reindex_total/started_at/done_ids — die sind seit
            // v0.2.0 in der DCA). reindex_run_id ist optional/komfort und
            // wird gar nicht erst angefasst, falls die Migration auf der
            // Customer-Site noch nicht gelaufen ist.
            try {
                $this->db->executeStatement(
                    'UPDATE tl_venne_search_settings SET reindex_total = ?, reindex_started_at = ?, reindex_done_ids = ? WHERE id = 1',
                    [$plan['stats']['total'], time(), '[]'],
                );
            } catch (\Throwable) {
            }

            // Manuelles JSON-Encoding mit invalid-UTF8-Substitution — sonst
            // crasht JsonResponse wenn z.B. ein File-Name nicht UTF-8 ist
            // (kommt z.B. bei Mac-OS-Dateinamen mit Sonderzeichen vor).
            $payload = [
                'ok' => true,
                'runId' => $runId,
                'stats' => $plan['stats'],
                'items' => $plan['items'],
                'orphans' => $plan['orphans'],
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (!\is_string($json)) {
                $this->diagLog('error', 'json_encode_failed', ['reqId' => $reqId, 'err' => json_last_error_msg()]);
                return new JsonResponse(['ok' => false, 'error' => 'json_encode_failed: ' . json_last_error_msg()], 200);
            }
            $this->diagLog('info', 'response_sent', ['reqId' => $reqId, 'bytes' => \strlen($json)]);
            return new JsonResponse($json, 200, [], true);
        } catch (\Throwable $e) {
            $this->diagLog('error', 'unexpected_error', [
                'reqId' => $reqId,
                'msg' => $e->getMessage(),
                'class' => \get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
            ]);
            // Letzter Fall-Back — nichts darf den Browser mit HTML-500 sehen lassen.
            return new JsonResponse([
                'ok' => false,
                'error' => 'Unerwarteter Fehler: ' . substr($e->getMessage(), 0, 200),
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
