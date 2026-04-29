<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\IndexableItemProcessor;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Refresh-Button im Documents-Panel: nimmt eine docId aus der Tabelle
 * entgegen und reindexiert genau dieses eine Doc. Wird vom JS aus
 * renderDocumentsPanel() aufgerufen (Klick auf den Refresh-Button in einer Zeile).
 *
 * Body (JSON): {"docId": "page-42"} oder {"docId": "file-<uuid-hex>"}
 *
 * Antwort:
 *   {"ok": true, "docId": "...", "contentLen": 12345, "durationMs": 4200}
 *   {"ok": false, "error": "doc_not_found"}
 */
final class ReindexSingleController extends AbstractController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly IndexableItemProcessor $processor,
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

        @set_time_limit(60);
        @ini_set('memory_limit', '2G');

        try {
            if (!$this->settings->isConfigured()) {
                return new JsonResponse(['ok' => false, 'error' => 'not_configured'], 200);
            }

            $payload = json_decode((string) $request->getContent(), true);
            $docId = (string) (\is_array($payload) ? ($payload['docId'] ?? '') : '');
            if ($docId === '') {
                return new JsonResponse(['ok' => false, 'error' => 'missing_docId'], 200);
            }

            $item = $this->resolveItemFromDocId($docId);
            if ($item === null) {
                return new JsonResponse(['ok' => false, 'error' => 'doc_not_found', 'docId' => $docId], 200);
            }

            $config = $this->settings->load();
            $projectDir = (string) $this->getParameter('kernel.project_dir');

            $result = $this->processor->processItem($item, $config, $projectDir);
            $result['docId'] = $docId;

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'crash:' . substr($e->getMessage(), 0, 200),
            ], 200);
        }
    }

    /**
     * Mappt eine docId zurück auf {type, ref, docId} für den ItemProcessor.
     *
     * @return array{type:string, ref:int|string, docId:string}|null
     */
    private function resolveItemFromDocId(string $docId): ?array
    {
        if (str_starts_with($docId, 'page-')) {
            $pageId = (int) substr($docId, 5);
            if ($pageId <= 0) {
                return null;
            }
            return ['type' => 'page', 'ref' => $pageId, 'docId' => $docId];
        }

        if (str_starts_with($docId, 'file-')) {
            // file-<32-hex-uuid>: aus tl_files nachschlagen → relativen Pfad holen.
            $hex = substr($docId, 5);
            if (!preg_match('/^[0-9a-f]+$/i', $hex)) {
                return null;
            }
            try {
                // UNHEX() ist MySQL-only — wir konvertieren PHP-seitig + nutzen
                // einen Standard-Binary-Vergleich, der auf jeder DB läuft.
                $uuidBin = @hex2bin($hex);
                if (!\is_string($uuidBin) || $uuidBin === '') {
                    return null;
                }
                $row = $this->db->fetchAssociative('SELECT path FROM tl_files WHERE uuid = ?', [$uuidBin]);
            } catch (\Throwable) {
                return null;
            }
            $path = \is_array($row) ? (string) ($row['path'] ?? '') : '';
            if ($path === '') {
                return null;
            }
            return ['type' => 'file', 'ref' => $path, 'docId' => $docId];
        }

        return null;
    }

    private function denyUnlessBackendUser(): ?JsonResponse
    {
        if (!$this->isGranted('ROLE_USER') || !$this->getUser() instanceof BackendUser) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 403);
        }

        return null;
    }
}
