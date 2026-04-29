<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\MessageHandler;

use Contao\ArticleModel;
use Contao\ContentModel;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\StringUtil;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use VenneMedia\VenneSearchContaoBundle\Message\IndexPageMessage;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\DocumentIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\SearchDocument;
use VenneMedia\VenneSearchContaoBundle\Service\Text\TextNormalizer;

/**
 * Bearbeitet IndexPageMessage: lädt den Datensatz aus der DB, baut ein
 * SearchDocument und schickt es an Meilisearch.
 *
 * Bei tl_article und tl_content wird der zugehörige Page-Datensatz mit
 * neu indexiert (Aggregation: Page-Volltext = Title + alle Article-Texte).
 */
#[AsMessageHandler]
final class IndexPageHandler
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly DocumentIndexer $indexer,
        private readonly TextNormalizer $normalizer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(IndexPageMessage $msg): void
    {
        $this->framework->initialize();

        // Bei tl_article und tl_content den Parent-Page-Datensatz holen.
        $pageId = $this->resolvePageId($msg->table, $msg->id);
        if ($pageId === null) {
            $this->logger->warning('venne_search.indexer.page_not_found', [
                'table' => $msg->table,
                'id' => $msg->id,
            ]);

            return;
        }

        $page = PageModel::findById($pageId);
        if ($page === null || $page->published !== '1') {
            // Nicht-publizierte Pages aus dem Index entfernen falls vorher drin.
            $this->indexer->delete(\sprintf('page-%d', $pageId), $page->language ?? 'de');

            return;
        }

        $doc = $this->buildDocumentForPage($page);
        $this->indexer->upsert($doc);

        $this->logger->info('venne_search.indexer.page_indexed', [
            'page_id' => $pageId,
            'title' => $page->title,
        ]);
    }

    private function resolvePageId(string $table, int $id): ?int
    {
        return match ($table) {
            'tl_page' => $id,
            'tl_article' => (int) (ArticleModel::findById($id)?->pid ?? 0) ?: null,
            'tl_content' => $this->resolvePageIdFromContent($id),
            default => null,
        };
    }

    private function resolvePageIdFromContent(int $contentId): ?int
    {
        $content = ContentModel::findById($contentId);
        if ($content === null) {
            return null;
        }
        // ContentElement → ptable bestimmt was der Parent ist.
        // Bei ptable=tl_article holen wir den Article und davon die Page.
        if (($content->ptable ?? 'tl_article') === 'tl_article') {
            $article = ArticleModel::findById((int) $content->pid);

            return $article !== null ? (int) $article->pid : null;
        }

        // Andere ptables (z.B. eigene Module) ignorieren wir vorerst.
        return null;
    }

    private function buildDocumentForPage(PageModel $page): SearchDocument
    {
        // Volltext aggregieren: Title + Description + alle Article-Inhalte
        // dieser Page (inkl. ContentElement-Texte).
        $articles = ArticleModel::findPublishedByPidAndColumn((int) $page->id, 'main') ?? [];

        $contentParts = [
            (string) $page->pageTitle ?: (string) $page->title,
            (string) $page->description,
            (string) $page->keywords,
        ];

        foreach ($articles as $article) {
            $contentParts[] = (string) $article->title;
            $contentParts[] = (string) $article->teaser;

            $elements = ContentModel::findPublishedByPidAndTable((int) $article->id, 'tl_article') ?? [];
            foreach ($elements as $el) {
                $contentParts[] = strip_tags((string) ($el->headline ?: ''));
                $contentParts[] = strip_tags((string) ($el->text ?: ''));
            }
        }

        $rawContent = implode(' ', array_filter($contentParts));
        $normalizedContent = $this->normalizer->normalize($rawContent);

        // URL über Contao's Page-URL-Generator (berücksichtigt Domain, Sprache, Aliase).
        $url = $this->framework->getAdapter(Controller::class)::generateFrontendUrl(
            $page->row(),
            null,
            null,
            true,
        );

        return new SearchDocument(
            id: \sprintf('page-%d', $page->id),
            type: 'page',
            locale: (string) ($page->language ?: 'de'),
            title: (string) ($page->pageTitle ?: $page->title),
            url: $url,
            content: $normalizedContent,
            tags: $this->extractTags($page),
            publishedAt: $page->start !== '' ? (int) $page->start : (int) $page->tstamp,
            weight: 1.0,
        );
    }

    /**
     * @return list<string>
     */
    private function extractTags(PageModel $page): array
    {
        // Contao-Page-Keywords (CSV) als Tags. Optional könnten hier später
        // auch Page-Aliase oder Categorie-Bezüge ergänzt werden.
        $keywords = StringUtil::trimsplit(',', (string) $page->keywords);

        return array_values(array_filter(array_map('trim', $keywords)));
    }
}
