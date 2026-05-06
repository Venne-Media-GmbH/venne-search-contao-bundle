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
 * POST /contao/venne-search/tag/unassign
 * Body: { targetType, targetId, tagSlug }
 */
final class TagUnassignController extends AbstractController
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

        if (!\in_array($targetType, ['page', 'file'], true) || $targetId === '' || $tagSlug === '') {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_request'], 400);
        }

        $tag = $this->tags->findBySlug($tagSlug);
        if ($tag === null) {
            return new JsonResponse(['ok' => false, 'error' => 'tag_not_found'], 404);
        }

        $this->tags->unassign($tag['id'], $targetType, $targetId);
        $this->triggerReindex($targetType, $targetId);

        return new JsonResponse(['ok' => true]);
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
                $this->processor->processItem(
                    ['type' => 'file', 'ref' => $targetId, 'docId' => 'file-path-' . md5($targetId)],
                    $config,
                    $projectDir,
                );
            }
        } catch (\Throwable) {
        }
    }
}
