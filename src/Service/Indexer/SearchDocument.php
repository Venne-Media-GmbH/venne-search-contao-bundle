<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Indexer;

/**
 * Such-Dokument im Meilisearch-Index.
 *
 * Eine Document-ID ist deterministisch zusammengesetzt aus Type+Source-ID,
 * z.B. `page-42-de` oder `file-019dcf2a-a155-745c-b694-b1391b026f77`.
 * Damit kann derselbe Datensatz beim Re-Indexing einfach via upsert ersetzt
 * werden, ohne zuerst suchen zu müssen.
 */
final class SearchDocument implements \JsonSerializable
{
    /**
     * @param list<string> $tags
     * @param list<int>    $allowedGroups Bei isProtected=true: tl_member_group-IDs
     *                                    die Zugriff haben. Leer = öffentlich.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $locale,
        public readonly string $title,
        public readonly string $url,
        public readonly string $content,
        public readonly array $tags = [],
        public readonly ?int $publishedAt = null,
        public readonly float $weight = 1.0,
        public readonly bool $isProtected = false,
        public readonly array $allowedGroups = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'locale' => $this->locale,
            'title' => $this->title,
            'url' => $this->url,
            'content' => $this->content,
            'tags' => $this->tags,
            'published_at' => $this->publishedAt,
            'weight' => $this->weight,
            'is_protected' => $this->isProtected,
            'allowed_groups' => $this->allowedGroups,
            'indexed_at' => time(),
        ];
    }
}
