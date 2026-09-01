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
     * @param string $altText             v2.1.0: Contao-Datei-Metadaten — wird
     *                                    bei Files in den Hover-Tooltip gerendert
     *                                    (statt nackter URL). Leerer String =
     *                                    keine Meta vorhanden, Fallback greift.
     * @param string $coverUrl            v2.2.0: URL eines Cover-/Thumbnail-Bildes
     *                                    für Datei-Treffer (Bild selbst bei
     *                                    Bilddateien, leer wenn nichts verfügbar).
     *                                    Wird im Frontend als <img alt="…">
     *                                    statt des generischen SVG-Icons gerendert.
     * @param string $contentType         v2.2.0: normalisierter Dokument-Typ
     *                                    für `type_asc`-Sort (z.B. `page`, `pdf`,
     *                                    `docx`, `xlsx`). Pages nehmen `page`,
     *                                    Files nehmen die Extension (lowercase).
     * @param int    $fileSize            v2.2.0: Dateigröße in Bytes (nur Files,
     *                                    0 für Pages). Wird im Frontend lesbar
     *                                    formatiert (KB/MB).
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
        public readonly string $altText = '',
        public readonly string $coverUrl = '',
        public readonly string $contentType = '',
        public readonly int $fileSize = 0,
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
            'alt_text' => $this->altText,
            'cover_url' => $this->coverUrl,
            'content_type' => $this->contentType !== '' ? $this->contentType : $this->type,
            'file_size' => $this->fileSize,
            'indexed_at' => time(),
        ];
    }
}
