<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenneMedia\VenneSearchContaoBundle\Service\Tag\TagRepository;

/**
 * GET /contao/venne-search/tag/suggest?q=…
 * Antwort: list<{slug,label,color}>
 */
final class TagSuggestController extends AbstractController
{
    public function __construct(private readonly TagRepository $tags)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_USER') || !$this->getUser() instanceof BackendUser) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 403);
        }
        $q = mb_strtolower(trim((string) $request->query->get('q', '')));
        $all = $this->tags->findAll();
        if ($q === '') {
            return new JsonResponse(\array_slice($all, 0, 20));
        }
        $filtered = array_values(array_filter($all, static function (array $t) use ($q): bool {
            return mb_stripos($t['label'], $q) !== false || str_contains($t['slug'], $q);
        }));
        return new JsonResponse(\array_slice($filtered, 0, 20));
    }
}
