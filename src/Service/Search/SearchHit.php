<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Search;

/**
 * Ein einzelner Treffer mit dem für die UI nötigen Highlight-Snippet.
 */
final class SearchHit
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $locale,
        public readonly string $title,
        public readonly string $url,
        public readonly string $snippet,
        public readonly array $tags,
        public readonly float $score,
        public readonly bool $isProtected = false,
    ) {
    }
}
