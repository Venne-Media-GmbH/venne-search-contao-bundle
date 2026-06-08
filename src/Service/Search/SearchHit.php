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
     * @param string       $altText v2.1.0: bei Datei-Treffern der ALT-Text aus
     *                              tl_files.meta — wird im Frontend als Hover-
     *                              Tooltip statt der URL angezeigt. Leer wenn
     *                              keine Meta hinterlegt ist (Fallback: URL).
     * @param string       $coverUrl v2.2.0: URL eines Cover-/Thumbnail-Bildes
     *                              für den Treffer. Leerer String = kein Cover,
     *                              das Frontend rendert dann das generische
     *                              Datei-/Page-Icon.
     * @param string       $contentType v2.2.0: Sort-Key für „Nach Dokumentenart":
     *                              `page` für Seiten, sonst die Extension (`pdf`,
     *                              `docx`, …).
     * @param int          $publishedAt v2.2.0: Unix-Timestamp für stabile Date-
     *                              Sortierung im Multi-Locale-Merge. 0 wenn
     *                              unbekannt.
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
        public readonly string $altText = '',
        public readonly string $coverUrl = '',
        public readonly string $contentType = '',
        public readonly int $publishedAt = 0,
    ) {
    }
}
