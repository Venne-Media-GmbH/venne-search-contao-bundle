<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Backend-Übersicht: zeigt alle Dokumente, die im Index liegen, mit Filter
 * (Typ, Sprache), Schnell-Suche und Per-Document-Aktionen.
 *
 * Compatibility: Funktioniert in Contao 4.13 + 5.x. In 5.x extends Contao's
 * AbstractBackendController, in 4.13 fallen wir auf Symfony's
 * AbstractController zurück und rendern direkt das Twig-Template.
 *
 * Routing: über src/Resources/config/routes.yaml registriert (NICHT via
 * Attributes, weil Contao 4.13 noch Symfony 5 nutzt).
 */
final class IndexBrowserController extends AbstractController
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Client $meilisearch,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->framework->initialize();

        // Backend-Auth über Contao's BackendUser (gleicher Code für 4.13 + 5.x)
        if (!$this->isGranted('ROLE_USER') || !$this->getUser() instanceof BackendUser) {
            return $this->redirect('/contao/login');
        }

        $config = $this->settings->load();

        if (!$this->settings->isConfigured()) {
            return $this->renderBackend([
                'configured' => false,
                'documents' => [],
                'totalDocuments' => 0,
                'queryTimeMs' => 0,
                'currentLocale' => 'de',
                'currentType' => '',
                'currentQuery' => '',
                'enabledLocales' => $config->enabledLocales,
                'pageSize' => 0,
                'currentPage' => 1,
                'totalPages' => 1,
            ]);
        }

        $locale = (string) $request->query->get('locale', $config->enabledLocales[0] ?? 'de');
        $typeFilter = (string) $request->query->get('type', '');
        $query = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = 50;
        $offset = ($page - 1) * $pageSize;

        $indexUid = \sprintf('%s_%s', $config->indexPrefix, $locale);

        $hits = [];
        $totalHits = 0;
        $facets = [];
        $queryTimeMs = 0;
        $error = null;

        try {
            $params = [
                'limit' => $pageSize,
                'offset' => $offset,
                'attributesToRetrieve' => ['id', 'type', 'title', 'url', 'tags', 'indexed_at', 'published_at'],
                'facets' => ['type'],
                'sort' => ['indexed_at:desc'],
            ];
            if ($typeFilter !== '') {
                $params['filter'] = \sprintf('type = "%s"', addslashes($typeFilter));
            }

            $result = $this->meilisearch->index($indexUid)->search($query, $params)->toArray();
            $hits = $result['hits'] ?? [];
            $totalHits = (int) ($result['estimatedTotalHits'] ?? \count($hits));
            $facets = (array) ($result['facetDistribution']['type'] ?? []);
            $queryTimeMs = (int) ($result['processingTimeMs'] ?? 0);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->renderBackend([
            'configured' => true,
            'documents' => $hits,
            'totalDocuments' => $totalHits,
            'facets' => $facets,
            'queryTimeMs' => $queryTimeMs,
            'currentLocale' => $locale,
            'currentType' => $typeFilter,
            'currentQuery' => $query,
            'enabledLocales' => $config->enabledLocales,
            'indexUid' => $indexUid,
            'pageSize' => $pageSize,
            'currentPage' => $page,
            'totalPages' => $totalHits > 0 ? (int) ceil($totalHits / $pageSize) : 1,
            'error' => $error,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderBackend(array $data): Response
    {
        return $this->render('@VenneSearchContao/backend/index_browser.html.twig', $data);
    }
}
