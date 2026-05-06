<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use VenneMedia\VenneSearchContaoBundle\Service\Analytics\AnalyticsFlusher;

/**
 * Backend-Endpoint für den "Jetzt flushen"-Button im Status-Panel.
 *
 * POST /contao/venne-search/analytics-flush
 * Antwort: gleiche Stats wie der Command.
 */
final class AnalyticsFlushController extends AbstractController
{
    public function __construct(private readonly AnalyticsFlusher $flusher)
    {
    }

    public function __invoke(): JsonResponse
    {
        if (!$this->isGranted('ROLE_USER') || !$this->getUser() instanceof BackendUser) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 403);
        }

        @set_time_limit(60);

        try {
            $result = $this->flusher->flush(false);
            return new JsonResponse([
                'ok' => true,
                'processedFiles' => $result['processedFiles'],
                'processedEvents' => $result['processedEvents'],
                'failedFiles' => $result['failedFiles'],
                'skippedFiles' => $result['skippedFiles'],
                'errors' => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'flush_failed: ' . substr($e->getMessage(), 0, 200),
            ], 200);
        }
    }
}
