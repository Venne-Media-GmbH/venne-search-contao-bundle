<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Search;

use Meilisearch\Client;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Public Read-API: nimmt eine Query-Eingabe und liefert ein SearchResult zurück.
 *
 * Wird von folgenden Stellen genutzt:
 *   - Frontend-Modul (ModuleVenneSearch via AJAX-Endpoint)
 *   - Backend-Modul (Globale Suche im Contao-System-Bereich)
 *   - REST-API (FrontendController unter /vsearch/api?q=...)
 */
final class SearchService
{
    public function __construct(
        private readonly Client $meilisearch,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Erlaubte Sort-Modes (v2.1.0). Whitelist verhindert dass Frontend-User
     * beliebige Sort-Strings durchschmuggeln.
     */
    public const SORT_RELEVANCE = 'relevance';
    public const SORT_DATE_DESC = 'date_desc';
    public const SORT_DATE_ASC = 'date_asc';
    /** v2.2.0: Sortierung nach Dokumentenart — Pages zuerst, dann Files
     *  gruppiert nach Extension (pdf, docx, …). Innerhalb der Gruppe sortiert
     *  Meilisearch nach Relevanz (BM25), weil weiteres explicit sort fehlt. */
    public const SORT_TYPE_ASC = 'type_asc';

    private const SORT_MODES = [
        self::SORT_RELEVANCE,
        self::SORT_DATE_DESC,
        self::SORT_DATE_ASC,
        self::SORT_TYPE_ASC,
    ];

    /**
     * @param array<string, mixed> $filters    z.B. ['type' => 'page', 'tags' => ['shop']]
     * @param list<int>            $userGroups tl_member_group-IDs des aktuellen Frontend-Users.
     *                                          Leeres Array = anonymer Besucher → nur public docs.
     * @param list<string>         $locales    v2.0.0: Multi-Locale-Suche. Wenn nicht-leer
     *                                          → über mehrere Indexe parallel; $locale wird ignoriert.
     * @param string               $sort       v2.1.0: 'relevance' (Default, mit weight-Boost),
     *                                          'date_desc' (Neueste), 'date_asc' (Älteste).
     */
    public function search(
        string $query,
        string $locale = 'de',
        array $filters = [],
        int $limit = 20,
        int $offset = 0,
        array $userGroups = [],
        array $locales = [],
        string $sort = self::SORT_RELEVANCE,
    ): SearchResult {
        $config = $this->settings->load();

        // Sort-Mode-Whitelist: alles Unbekannte fällt auf "relevance" zurück.
        if (!\in_array($sort, self::SORT_MODES, true)) {
            $sort = self::SORT_RELEVANCE;
        }

        // Multi-Locale-Pfad: parallele Suche, Treffer mergen + nach Score re-ranken.
        if ($locales !== []) {
            $sanitized = array_values(array_filter(array_map(
                static fn (string $l): string => strtolower(preg_replace('/[^a-z]/', '', $l) ?? ''),
                $locales,
            ), static fn (string $l): bool => $l !== ''));
            if (\count($sanitized) > 1) {
                return $this->searchMultiLocale($query, $sanitized, $filters, $limit, $offset, $userGroups, $config, $sort);
            }
            // Fallthrough: genau 1 Locale → Single-Path
            if ($sanitized !== []) {
                $locale = $sanitized[0];
            }
        }

        $indexUid = \sprintf('%s_%s', $config->indexPrefix, $locale);

        $params = [
            'limit' => max(1, min(100, $limit)),
            'offset' => max(0, $offset),
            // Highlighting: Treffer in Title + Content mit <mark>...</mark> markieren.
            'attributesToHighlight' => ['title', 'content'],
            'highlightPreTag' => '<mark>',
            'highlightPostTag' => '</mark>',
            // Snippet aus dem Content um den Treffer herum (max 30 Worte).
            'attributesToCrop' => ['content'],
            'cropLength' => 30,
            'cropMarker' => '…',
            // Facets für UI ("12 Treffer in Pages, 3 in Dateien").
            'facets' => ['type', 'tags', 'locale'],
            // Mit Score zurückliefern, damit das Frontend Relevanz-Sortierung nutzen kann.
            'showRankingScore' => true,
            // Sort-Reihenfolge je nach Mode (v2.1.0).
            //   relevance: weight DESC, indexed_at DESC — geboostete oben, dann neuste
            //   date_desc: published_at DESC — Neueste oben (Relevanz nur Tie-Breaker via BM25)
            //   date_asc:  published_at ASC  — Älteste oben
            'sort' => $this->buildSortClause($sort),
        ];

        // Strenge Such-Strategie: bei "strict" zwingen wir Meilisearch dazu,
        // ALLE Query-Tokens zu finden (kein partielles Match). Das verhindert
        // u.a. dass eine Query "Di Caprio" auch Pages mit nur "die" matched.
        // Außerdem quoten wir kurze Tokens (≤3 Zeichen) als Phrase, damit
        // Meilisearch sie nicht als Prefix-Match auf längere Wörter wertet
        // ("Di" sonst → "die", "DFFF" usw.).
        if ($config->searchStrictness === 'strict') {
            $params['matchingStrategy'] = 'all';
            $query = $this->quoteShortTokens($query);
        } elseif ($config->searchStrictness === 'balanced') {
            // Bei balanced behalten wir last-Match (Default), aber Single-Token-
            // Queries unter 3 Zeichen quoten wir trotzdem — sonst sind die
            // Treffer nicht sinnvoll.
            $tokens = preg_split('/\s+/', trim($query)) ?: [];
            if (\count($tokens) === 1 && mb_strlen($tokens[0]) <= 3) {
                $query = '"' . str_replace('"', '', $tokens[0]) . '"';
            }
        }

        // Permission-Filter ist HARD-REQUIRED: nur Dokumente die der aktuelle
        // User sehen darf. Anonyme User sehen nur is_protected=false. Eingeloggte
        // sehen zusätzlich alle Docs deren allowed_groups eine ihrer Gruppen-IDs
        // enthält. Diese Filter werden IMMER mit AND verknüpft, der User-Filter
        // kommt zusätzlich dazu.
        $permissionExpr = $this->buildPermissionFilter($userGroups);
        $userExpr = $filters !== [] ? $this->buildFilterExpression($filters) : '';

        if ($permissionExpr !== '' && $userExpr !== '') {
            $params['filter'] = '(' . $permissionExpr . ') AND (' . $userExpr . ')';
        } elseif ($permissionExpr !== '') {
            $params['filter'] = $permissionExpr;
        } elseif ($userExpr !== '') {
            $params['filter'] = $userExpr;
        }

        try {
            $response = $this->meilisearch->index($indexUid)->search($query, $params);
        } catch (\Throwable) {
            // Defensiv gegen alte Indexe ohne sortable-Felder. Wir fallen
            // schrittweise auf einen weniger anspruchsvollen Sort zurück:
            //   1) nur indexed_at (kein weight, kein published_at)
            //   2) komplett ohne Sort
            $params['sort'] = ['indexed_at:desc'];
            try {
                $response = $this->meilisearch->index($indexUid)->search($query, $params);
            } catch (\Throwable) {
                unset($params['sort']);
                $response = $this->meilisearch->index($indexUid)->search($query, $params);
            }
        }
        $raw = $response->toArray();

        $hits = [];
        foreach ($raw['hits'] ?? [] as $hit) {
            $highlighted = $hit['_formatted'] ?? $hit;
            $hits[] = new SearchHit(
                id: (string) $hit['id'],
                type: (string) ($hit['type'] ?? 'unknown'),
                locale: (string) ($hit['locale'] ?? $locale),
                title: (string) ($highlighted['title'] ?? $hit['title'] ?? ''),
                url: (string) ($hit['url'] ?? ''),
                snippet: (string) ($highlighted['content'] ?? ''),
                tags: array_values((array) ($hit['tags'] ?? [])),
                score: (float) ($hit['_rankingScore'] ?? 0.0),
                isProtected: (bool) ($hit['is_protected'] ?? false),
                altText: (string) ($hit['alt_text'] ?? ''),
                coverUrl: (string) ($hit['cover_url'] ?? ''),
                contentType: (string) ($hit['content_type'] ?? ($hit['type'] ?? '')),
                publishedAt: (int) ($hit['published_at'] ?? 0),
            );
        }

        return new SearchResult(
            hits: $hits,
            totalHits: (int) ($raw['estimatedTotalHits'] ?? \count($hits)),
            offset: (int) ($raw['offset'] ?? $offset),
            limit: (int) ($raw['limit'] ?? $limit),
            facets: (array) ($raw['facetDistribution'] ?? []),
            queryTimeMs: (int) ($raw['processingTimeMs'] ?? 0),
        );
    }

    /**
     * Baut den ACL-Permission-Filter.
     *
     * Wichtig: wir benutzen `is_protected != true` statt `= false`, damit
     * auch Legacy-Docs (vor v0.4.0 indexiert, ohne das Feld) als "öffentlich"
     * gelten. Dasselbe für `allowed_groups`. So kippen alte Indexe nach dem
     * Update nicht plötzlich auf "alles versteckt".
     *
     *   - Anonymer Besucher (userGroups = []): "is_protected != true"
     *   - Eingeloggter Member mit Groups [3, 7]:
     *       "is_protected != true OR allowed_groups IN [3, 7]"
     *
     * @param list<int> $userGroups
     */
    private function buildPermissionFilter(array $userGroups): string
    {
        if ($userGroups === []) {
            return 'is_protected != true';
        }
        $clean = array_values(array_filter(array_map('intval', $userGroups), static fn (int $v): bool => $v > 0));
        if ($clean === []) {
            return 'is_protected != true';
        }
        return 'is_protected != true OR allowed_groups IN [' . implode(', ', $clean) . ']';
    }

/**
     * Multi-Locale-Pfad: pro Locale ein Search-Call, Treffer mergen + nach
     * Sort-Mode neu sortieren. Pagination wird auf den gemergten Trefferpool
     * angewendet.
     *
     * @param array<string, mixed> $filters
     * @param list<int>            $userGroups
     * @param list<string>         $locales
     */
    private function searchMultiLocale(
        string $query,
        array $locales,
        array $filters,
        int $limit,
        int $offset,
        array $userGroups,
        \VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsConfig $config,
        string $sort = self::SORT_RELEVANCE,
    ): SearchResult {
        $allHits = [];
        $facets = [];
        $totalHits = 0;
        $queryTime = 0;

        // Pro Locale eine Suche absetzen mit großzügigem Limit, damit das
        // Re-Ranking sinnvoll ist (Top-50 aus jedem Index).
        $perLocaleLimit = max($limit + $offset, 50);

        foreach ($locales as $loc) {
            try {
                $r = $this->search(
                    query: $query,
                    locale: $loc,
                    filters: $filters,
                    limit: $perLocaleLimit,
                    offset: 0,
                    userGroups: $userGroups,
                    locales: [], // wichtig: rekursiver Single-Locale-Pfad
                    sort: $sort,
                );
            } catch (\Throwable) {
                continue;
            }
            foreach ($r->hits as $h) {
                $allHits[] = $h;
            }
            $totalHits += $r->totalHits;
            $queryTime += $r->queryTimeMs;
            foreach ($r->facets as $facetName => $values) {
                if (!isset($facets[$facetName])) {
                    $facets[$facetName] = [];
                }
                foreach ((array) $values as $v => $cnt) {
                    $facets[$facetName][$v] = ($facets[$facetName][$v] ?? 0) + (int) $cnt;
                }
            }
        }

        // Re-Ranking nach Sort-Mode. Im Multi-Locale-Pfad mergen wir Treffer
        // aus mehreren Indexen — Meilisearch hat sie pro Locale schon
        // sortiert, der globale Merge muss aber den gleichen Sort-Key benutzen.
        // v2.2.0: SearchHit trägt publishedAt + contentType — wir können
        // jetzt korrekt global sortieren statt nur die Pro-Locale-Reihenfolge
        // beizubehalten.
        if ($sort === self::SORT_DATE_DESC) {
            usort($allHits, static fn ($a, $b): int => $b->publishedAt <=> $a->publishedAt);
        } elseif ($sort === self::SORT_DATE_ASC) {
            usort($allHits, static function ($a, $b): int {
                // 0 (unbekannt) ans Ende statt an den Anfang sortieren.
                if ($a->publishedAt === 0 && $b->publishedAt === 0) return 0;
                if ($a->publishedAt === 0) return 1;
                if ($b->publishedAt === 0) return -1;
                return $a->publishedAt <=> $b->publishedAt;
            });
        } elseif ($sort === self::SORT_TYPE_ASC) {
            usort($allHits, static function ($a, $b): int {
                $cmp = strcmp($a->contentType, $b->contentType);
                if ($cmp !== 0) return $cmp;
                return $b->score <=> $a->score;
            });
        } else {
            usort($allHits, static fn ($a, $b): int => $b->score <=> $a->score);
        }
        $paged = \array_slice($allHits, $offset, $limit);

        return new SearchResult(
            hits: $paged,
            totalHits: $totalHits,
            offset: $offset,
            limit: $limit,
            facets: $facets,
            queryTimeMs: $queryTime,
        );
    }

    /**
     * Sort-Klausel pro Mode. weight + indexed_at sind v2.1.0-Felder; bei alten
     * Indexen fängt der search()-Try/Catch-Fallback das ab.
     *
     * @return list<string>
     */
    private function buildSortClause(string $sort): array
    {
        return match ($sort) {
            self::SORT_DATE_DESC => ['published_at:desc'],
            self::SORT_DATE_ASC => ['published_at:asc'],
            // v2.2.0: page < pdf < docx < … (alphabetisch, content_type ist
            // lexikographisch zwingend), Tie-Break über weight DESC damit
            // geboostete Items innerhalb der Gruppe oben sind.
            self::SORT_TYPE_ASC => ['content_type:asc', 'weight:desc'],
            default => ['weight:desc', 'indexed_at:desc'],
        };
    }

    /**
     * Umgibt kurze Tokens (≤3 Zeichen) mit Quotes, damit Meilisearch sie als
     * Phrase versteht — kein Prefix-Match, kein Tippfehler. Längere Tokens
     * bleiben unangetastet, damit der "Vegane Rezepte"-Treffer für "vegane"
     * weiterhin funktioniert.
     */
    private function quoteShortTokens(string $query): string
    {
        $tokens = preg_split('/\s+/', trim($query)) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            // Bereits quoted? Dann unverändert übernehmen.
            if ($token[0] === '"') {
                $out[] = $token;
                continue;
            }
            if (mb_strlen($token) <= 3) {
                $out[] = '"' . str_replace('"', '', $token) . '"';
            } else {
                $out[] = $token;
            }
        }
        return implode(' ', $out);
    }

    /**
     * Baut den Meilisearch-Filter-Ausdruck.
     *   ['type' => 'page']        → 'type = "page"'
     *   ['type' => ['page','file']] → 'type IN ["page", "file"]'
     *   ['type' => 'page', 'locale' => 'de'] → 'type = "page" AND locale = "de"'
     *
     * Spezialfall v2.1.0:
     *   ['file_ext' => ['pdf','xlsx']] → 'tags IN ["pdf", "xlsx"]'
     *   Wird genauso AND-verknüpft wie alle anderen Filter — wenn beide
     *   `tags`- und `file_ext`-Sets gesetzt sind, MUSS der Treffer in BEIDEN
     *   Tag-Listen liegen (also: korrekt getaggter Inhalt + passender Dateityp).
     *
     * @param array<string, mixed> $filters
     */
    private function buildFilterExpression(array $filters): string
    {
        $parts = [];
        foreach ($filters as $field => $value) {
            $rawField = $field;
            $field = preg_replace('/[^a-z0-9_]/i', '', (string) $field) ?? '';
            if ($field === '') {
                continue;
            }

            // file_ext ist ein logischer Filter, der auf das gleiche
            // tags-Filterable im Index abgebildet wird.
            if ($rawField === 'file_ext') {
                $field = 'tags';
            }

            if (\is_array($value)) {
                $escaped = array_map(static fn ($v): string => '"'.addslashes((string) $v).'"', $value);
                $parts[] = \sprintf('%s IN [%s]', $field, implode(', ', $escaped));
            } else {
                $parts[] = \sprintf('%s = "%s"', $field, addslashes((string) $value));
            }
        }

        return implode(' AND ', $parts);
    }
}
