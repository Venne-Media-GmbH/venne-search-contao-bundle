<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Doctrine\DBAL\Connection;
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

        // Bullet-proof: Wir umhüllen ALLES — egal was schief geht, der User
        // bekommt JSON zurück (kein 500-HTML). So kann das Frontend immer
        // einen sinnvollen Fehler-Modal zeigen.
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');

        try {
            if (!$this->settings->isConfigured()) {
                return new JsonResponse(['ok' => false, 'error' => 'API-Key nicht konfiguriert.'], 400);
            }

            try {
                $config = $this->settings->load();
            } catch (ResolveAuthException) {
                return new JsonResponse(['ok' => false, 'error' => 'Plattform-Key ungültig oder widerrufen.'], 200);
            } catch (ResolveSubscriptionException) {
                return new JsonResponse(['ok' => false, 'error' => 'Venne-Search-Abo nicht aktiv.'], 200);
            } catch (ResolveProvisioningException) {
                return new JsonResponse(['ok' => false, 'error' => 'Bundle wartet auf Provisionierung.'], 200);
            } catch (\Throwable $e) {
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
            } catch (\Throwable) {
                // Best-effort. Wenn Meili down ist, fängt das der Plan-Build
                // selber unten gleich ab.
            }

            try {
                $plan = $this->catalog->buildPlan($config);
            } catch (\Throwable $e) {
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
                return new JsonResponse(['ok' => false, 'error' => 'json_encode_failed'], 200);
            }
            return new JsonResponse($json, 200, [], true);
        } catch (\Throwable $e) {
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
