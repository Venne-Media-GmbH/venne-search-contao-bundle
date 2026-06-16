<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\DocumentIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\IndexableItemProcessor;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * POST /contao/venne-search/page/toggle-no-search
 * Body: { pageId: int }
 *
 * Toggle't das Contao-Standard-Feld tl_page.noSearch fuer die uebergebene
 * Page-ID. Erfolgt der Wechsel auf "nicht suchbar" (noSearch=1), wird die
 * Page sofort aus dem Meilisearch-Index entfernt — ohne diesen Schritt
 * wuerde sie bis zum naechsten Full-Reindex weiter gefunden werden.
 * Erfolgt der Wechsel zurueck (noSearch=0), wird sie sofort reindexiert.
 */
final class PageSearchToggleController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly IndexableItemProcessor $processor,
        private readonly DocumentIndexer $indexer,
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
        $pageId = (int) ($payload['pageId'] ?? 0);
        if ($pageId <= 0) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_page'], 400);
        }

        try {
            $current = $this->db->fetchOne('SELECT noSearch FROM tl_page WHERE id = :id', ['id' => $pageId]);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false, 'error' => 'no_search_unsupported'], 500);
        }
        if ($current === false) {
            return new JsonResponse(['ok' => false, 'error' => 'page_not_found'], 404);
        }

        $next = ((string) $current === '1') ? '' : '1';
        $this->db->update('tl_page', ['noSearch' => $next, 'tstamp' => time()], ['id' => $pageId]);

        // Index synchron halten: bei "nicht suchbar" loeschen, sonst reindexieren.
        try {
            if ($next === '1') {
                $locales = $this->settings->load()->enabledLocales ?: ['de'];
                $pageUrls = $this->collectPageUrlVariants($pageId);
                foreach ($locales as $locale) {
                    // 1) direktes page-Dokument loeschen
                    $this->indexer->delete('page-' . $pageId, $locale);
                    // 2) gleichzeitig crawled-Twins entfernen — sonst bleibt
                    //    der Inhalt unter derselben URL ueber den crawled-Hit
                    //    weiter sichtbar. Beim Re-Enable rebuildet der Crawler
                    //    diese Twins beim naechsten Cron-Lauf automatisch.
                    if ($pageUrls !== []) {
                        $this->indexer->deleteByUrls($pageUrls, $locale);
                    }
                }
            } else {
                $this->processor->processItem(
                    ['type' => 'page', 'ref' => $pageId, 'docId' => 'page-' . $pageId],
                    $this->settings->load(),
                    (string) $this->getParameter('kernel.project_dir'),
                );
            }
        } catch (\Throwable) {
            // Best-Effort — Toggle hat in der DB schon erfolgreich stattgefunden.
        }

        return new JsonResponse([
            'ok' => true,
            'pageId' => $pageId,
            'searchable' => $next !== '1',
        ]);
    }

    /**
     * Baut alle URL-Varianten unter denen die Page im Meili-Index liegen kann:
     *   /alias.html, https://host/alias.html, http://host/alias.html
     * Verwendet die Live-URL (Contao\PageModel) wenn verfuegbar, sonst greift
     * der Fallback auf $alias.html.
     *
     * @return string[]
     */
    private function collectPageUrlVariants(int $pageId): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT alias FROM tl_page WHERE id = :id', ['id' => $pageId]);
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($row)) {
            return [];
        }
        $alias = (string) ($row['alias'] ?? '');
        if ($alias === '') {
            return [];
        }
        $path = '/' . ltrim($alias, '/') . '.html';
        $variants = [$path];
        // Host(s) aus root-Pages — fuer FFA typischerweise www.ffa.de, ffa.de.
        try {
            $hosts = $this->db->fetchFirstColumn(
                "SELECT DISTINCT dns FROM tl_page WHERE type='root' AND dns IS NOT NULL AND dns != ''"
            );
        } catch (\Throwable) {
            $hosts = [];
        }
        foreach ($hosts as $host) {
            $host = (string) $host;
            if ($host === '') {
                continue;
            }
            $variants[] = 'https://' . $host . $path;
            $variants[] = 'http://' . $host . $path;
        }
        return array_values(array_unique($variants));
    }
}
