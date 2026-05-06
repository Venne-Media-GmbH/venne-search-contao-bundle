<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\IndexableItemProcessor;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;
use VenneMedia\VenneSearchContaoBundle\Service\Tag\TagRepository;

/**
 * POST /contao/venne-search/tag/assign
 * Body: { targetType, targetId, tagSlug?, createLabel?, createColor? }
 *
 * Wenn tagSlug fehlt, aber createLabel gesetzt ist → neuer Tag wird angelegt.
 * Triggert sofort einen Reindex der betroffenen Page/File.
 */
final class TagAssignController extends AbstractController
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly SettingsRepository $settings,
        private readonly IndexableItemProcessor $processor,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_USER') || !$this->getUser() instanceof BackendUser) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $targetType = (string) ($payload['targetType'] ?? '');
        $targetId = (string) ($payload['targetId'] ?? '');
        $tagSlug = (string) ($payload['tagSlug'] ?? '');
        $createLabel = (string) ($payload['createLabel'] ?? '');
        $createColor = (string) ($payload['createColor'] ?? 'blue');

        if (!\in_array($targetType, ['page', 'file'], true) || $targetId === '') {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_target'], 400);
        }

        $created = false;
        $tag = $tagSlug !== '' ? $this->tags->findBySlug($tagSlug) : null;
        if ($tag === null) {
            if ($createLabel === '') {
                return new JsonResponse(['ok' => false, 'error' => 'tag_not_found'], 400);
            }
            $tagId = $this->tags->ensureTag($createLabel, $tagSlug !== '' ? $tagSlug : null, $createColor);
            if ($tagId === 0) {
                return new JsonResponse(['ok' => false, 'error' => 'create_failed'], 500);
            }
            $tag = $this->tags->findBySlug(
                $tagSlug !== '' ? $tagSlug : \VenneMedia\VenneSearchContaoBundle\Migration\Version200\Mig02_AddTagSystem::slugify($createLabel),
            );
            $created = true;
            if ($tag === null) {
                return new JsonResponse(['ok' => false, 'error' => 'create_lookup_failed'], 500);
            }
        }

        $this->tags->assign($tag['id'], $targetType, $targetId);
        $this->triggerReindex($targetType, $targetId);

        return new JsonResponse([
            'ok' => true,
            'created' => $created,
            'slug' => $tag['slug'],
            'label' => $tag['label'],
            'color' => $tag['color'],
        ]);
    }

    private function triggerReindex(string $targetType, string $targetId): void
    {
        if (!$this->settings->isConfigured()) {
            return;
        }
        try {
            $config = $this->settings->load();
            $projectDir = (string) $this->getParameter('kernel.project_dir');

            if ($targetType === 'page') {
                $pageId = (int) $targetId;
                if ($pageId > 0) {
                    $this->processor->processItem(
                        ['type' => 'page', 'ref' => $pageId, 'docId' => 'page-' . $pageId],
                        $config,
                        $projectDir,
                    );
                }
            } else {
                // targetId für Files = path
                $this->processor->processItem(
                    ['type' => 'file', 'ref' => $targetId, 'docId' => 'file-path-' . md5($targetId)],
                    $config,
                    $projectDir,
                );
            }
        } catch (\Throwable) {
            // Best-Effort. UI hat schon "ok" zurück bekommen.
        }
    }
}
