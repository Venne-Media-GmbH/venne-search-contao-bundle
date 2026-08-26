<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\DocumentIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Search\SearchHit;
use VenneMedia\VenneSearchContaoBundle\Service\Search\SearchService;

/**
 * Ticket FFA: Bei „Relevanz" standen die ältesten Geschäftsberichte oben.
 * Ursache: ohne expliziten Tie-Breaker sortiert Meilisearch gleich gut
 * passende Dokumente nach interner Doc-ID (= Reihenfolge des Indexierens,
 * bei Files „ORDER BY path" → 2010 vor 2024). Gewollt: bei gleicher
 * Relevanz das neueste Dokument zuerst.
 */
final class RelevanceTieBreakTest extends TestCase
{
    public function testRankingRulesEndWithNewestFirstTieBreak(): void
    {
        $rules = DocumentIndexer::RANKING_RULES;

        self::assertSame('published_at:desc', end($rules), 'published_at:desc muss der LETZTE Tie-Breaker sein');
        // Relevanz-Regeln bleiben vor dem Datums-Tie-Break — sonst würde das
        // Datum echte Treffer-Qualität (exactness) überstimmen.
        self::assertLessThan(
            array_search('published_at:desc', $rules, true),
            array_search('exactness', $rules, true),
        );
    }

    public function testCompareRelevancePrefersHigherScore(): void
    {
        $old = self::hit('a', 0.9, 1_600_000_000);
        $newer = self::hit('b', 0.5, 1_700_000_000);

        self::assertLessThan(0, SearchService::compareRelevance($old, $newer));
        self::assertGreaterThan(0, SearchService::compareRelevance($newer, $old));
    }

    public function testCompareRelevanceBreaksTiesNewestFirst(): void
    {
        $hits = [
            self::hit('gb-2010', 0.8, mktime(0, 0, 0, 3, 1, 2010)),
            self::hit('gb-2024', 0.8, mktime(0, 0, 0, 3, 1, 2024)),
            self::hit('gb-2017', 0.8, mktime(0, 0, 0, 3, 1, 2017)),
        ];
        usort($hits, [SearchService::class, 'compareRelevance']);

        self::assertSame(['gb-2024', 'gb-2017', 'gb-2010'], array_map(static fn (SearchHit $h) => $h->id, $hits));
    }

    public function testCompareRelevanceIsStableForIdenticalHits(): void
    {
        $a = self::hit('x', 0.8, 1_700_000_000);
        $b = self::hit('y', 0.8, 1_700_000_000);
        self::assertSame(0, SearchService::compareRelevance($a, $b));
    }

    private static function hit(string $id, float $score, int $publishedAt): SearchHit
    {
        return new SearchHit(
            id: $id,
            type: 'file',
            locale: 'de',
            title: $id,
            url: '/' . $id . '.pdf',
            snippet: '',
            tags: [],
            score: $score,
            publishedAt: $publishedAt,
        );
    }
}
