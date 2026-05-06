<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\IndexableItemProcessor;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * v2.0.0 — Backend-Endpoint zum Setzen eines File-Locale-Overrides.
 *
 * POST /contao/venne-search/file-locale
 *
 * Body (JSON):
 *   { "path": "files/foo/bar.pdf", "locale": "en" }
 *   leeres Locale = Override entfernen, Detector entscheidet wieder.
 *
 * Antwort:
 *   { "ok": true, "path": "...", "locale": "en", "reindexed": true }
 */
final class FileLocaleController extends AbstractController
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

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $path = ltrim((string) ($payload['path'] ?? ''), '/');
        $locale = strtolower(trim((string) ($payload['locale'] ?? '')));
        if ($path === '') {
            return new JsonResponse(['ok' => false, 'error' => 'missing_path'], 400);
        }

        if (!$this->settings->isConfigured()) {
            return new JsonResponse(['ok' => false, 'error' => 'not_configured'], 200);
        }

        $config = $this->settings->load();
        if ($locale !== '' && !\in_array($locale, $config->enabledLocales, true)) {
            return new JsonResponse(['ok' => false, 'error' => 'locale_not_enabled'], 400);
        }

        $this->settings->setFileLocaleOverride($path, $locale);

        // Reindex: Document-Locale könnte sich verschoben haben — alte Locale
        // räumen wir nicht explizit auf (das bleibt als Orphan beim nächsten
        // Reindex-Plan zur User-Bestätigung sichtbar). Hier nur das neue
        // Doc indexieren.
        $reindexed = false;
        try {
            $config = $this->settings->load(); // mit aktualisiertem Override
            $projectDir = (string) $this->getParameter('kernel.project_dir');
            $result = $this->processor->processItem(
                ['type' => 'file', 'ref' => $path, 'docId' => $this->buildFileDocId($path)],
                $config,
                $projectDir,
            );
            $reindexed = (bool) ($result['ok'] ?? false);
        } catch (\Throwable) {
        }

        return new JsonResponse([
            'ok' => true,
            'path' => $path,
            'locale' => $locale,
            'reindexed' => $reindexed,
        ]);
    }

    /**
     * Document-ID muss sich aus tl_files.uuid ableiten — sonst stimmt sie
     * nicht mit dem Catalog-/Plan-Output überein.
     */
    private function buildFileDocId(string $path): string
    {
        try {
            $row = $this->container->get('database_connection')->fetchAssociative(
                'SELECT uuid FROM tl_files WHERE path = ? LIMIT 1',
                [$path],
            );
            if (\is_array($row) && \is_string($row['uuid'] ?? null) && $row['uuid'] !== '') {
                return 'file-' . bin2hex((string) $row['uuid']);
            }
        } catch (\Throwable) {
        }
        return 'file-path-' . md5($path);
    }

    private function denyUnlessBackendUser(): ?JsonResponse
    {
        if (!$this->isGranted('ROLE_USER') || !$this->getUser() instanceof BackendUser) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 403);
        }
        return null;
    }
}
