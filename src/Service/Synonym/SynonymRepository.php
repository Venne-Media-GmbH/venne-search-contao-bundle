<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Synonym;

use Doctrine\DBAL\Connection;

/**
 * DB-Zugriff auf tl_venne_search_synonym. Liefert die Synonym-Map in einem
 * Format das die Meilisearch-API direkt akzeptiert:
 *
 *   { "ausstellung": ["messe","expo","fair"],
 *     "messe":       ["ausstellung","expo","fair"],
 *     "expo":        ["ausstellung","messe","fair"],
 *     "fair":        ["ausstellung","messe","expo"] }
 *
 * Meilisearch loest in beide Richtungen auf — Suche nach „Messe" findet
 * auch Docs mit „Ausstellung" und umgekehrt.
 */
final class SynonymRepository
{
    public const TABLE = 'tl_venne_search_synonym';

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function tableExists(): bool
    {
        try {
            return $this->db->createSchemaManager()->tablesExist([self::TABLE]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Liefert die Bi-Direktional-Synonym-Map fuer Meilisearch.
     *
     * @return array<string, list<string>>
     */
    public function buildMeilisearchSynonyms(): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT term, synonyms FROM ' . self::TABLE . " WHERE active = '1'"
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $term = $this->normalize((string) ($row['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $list = array_values(array_filter(array_map(
                fn (string $s): string => $this->normalize($s),
                preg_split('/[,;\n\r]+/', (string) ($row['synonyms'] ?? '')) ?: [],
            ), static fn (string $s): bool => $s !== ''));
            if ($list === []) {
                continue;
            }
            // Bi-Direktional: jedes Wort der Gruppe ist Schluessel, Werte sind die anderen.
            $group = array_values(array_unique(array_merge([$term], $list)));
            foreach ($group as $word) {
                $out[$word] = array_values(array_diff($group, [$word]));
            }
        }
        return $out;
    }

    private function normalize(string $s): string
    {
        $s = trim($s);
        $s = mb_strtolower($s);
        // Keine Sonderzeichen, nur Buchstaben/Ziffern/Leerzeichen/Bindestriche.
        $s = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $s) ?? '';
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }
}
