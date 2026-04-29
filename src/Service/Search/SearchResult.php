<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Search;

/**
 * Aggregiertes Such-Ergebnis: Hits + Pagination + Facets + Performance.
 */
final class SearchResult
{
    /**
     * @param list<SearchHit> $hits
     * @param array<string, array<string, int>> $facets z.B. ['type' => ['page' => 12, 'file' => 3]]
     */
    public function __construct(
        public readonly array $hits,
        public readonly int $totalHits,
        public readonly int $offset,
        public readonly int $limit,
        public readonly array $facets,
        public readonly int $queryTimeMs,
    ) {
    }
}
