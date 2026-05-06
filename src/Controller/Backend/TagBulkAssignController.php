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
        $createLabel = (string) ($payload['createLabel'] ?? '');
        $createColor = (string) ($payload['createColor'] ?? 'blue');

        if (!\in_array($targetType, ['page', 'file'], true) || $targetIds === []) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_request'], 400);
        }

        // Tag-Lookup oder On-the-fly Erstellung wenn Label angegeben.
        $created = false;
        $tag = $tagSlug !== '' ? $this->tags->findBySlug($tagSlug) : null;
        if ($tag === null) {
            if ($createLabel === '') {
                return new JsonResponse(['ok' => false, 'error' => 'tag_not_found'], 404);
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
            'created' => $created,
            'slug' => $tag['slug'],
            'label' => $tag['label'],
            'color' => $tag['color'],
            'assigned' => $assigned,
            'reindexed' => $reindexed,
        ]);
    }
}
