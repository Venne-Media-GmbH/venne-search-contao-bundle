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
 * POST /contao/venne-search/tag/bulk-assign
 * Body: { targetType, targetIds: [...], tagSlug }
 *
 * Mehrere Targets in einem Schwung — Reindex pro Target sequenziell.
 */
final class TagBulkAssignController extends AbstractController
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
        @set_time_limit(120);

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $targetType = (string) ($payload['targetType'] ?? '');
        $targetIds = (array) ($payload['targetIds'] ?? []);
        $tagSlug = (string) ($payload['tagSlug'] ?? '');

        if (!\in_array($targetType, ['page', 'file'], true) || $targetIds === [] || $tagSlug === '') {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_request'], 400);
        }

        $tag = $this->tags->findBySlug($tagSlug);
        if ($tag === null) {
            return new JsonResponse(['ok' => false, 'error' => 'tag_not_found'], 404);
        }

        $assigned = 0;
        $reindexed = 0;
        $config = $this->settings->isConfigured() ? $this->settings->load() : null;
        $projectDir = (string) $this->getParameter('kernel.project_dir');

        foreach ($targetIds as $rawId) {
            $tid = (string) $rawId;
            if ($tid === '') {
                continue;
            }
            if ($this->tags->assign($tag['id'], $targetType, $tid)) {
                ++$assigned;
            }
            if ($config !== null) {
                try {
                    if ($targetType === 'page') {
                        $pageId = (int) $tid;
                        if ($pageId > 0) {
                            $this->processor->processItem(
                                ['type' => 'page', 'ref' => $pageId, 'docId' => 'page-' . $pageId],
                                $config,
                                $projectDir,
                            );
                            ++$reindexed;
                        }
                    } else {
                        $this->processor->processItem(
                            ['type' => 'file', 'ref' => $tid, 'docId' => 'file-path-' . md5($tid)],
                            $config,
                            $projectDir,
                        );
                        ++$reindexed;
                    }
                } catch (\Throwable) {
                }
            }
        }

        return new JsonResponse([
            'ok' => true,
            'assigned' => $assigned,
            'reindexed' => $reindexed,
        ]);
    }
}
