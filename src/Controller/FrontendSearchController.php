<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
// Route-Attribute werden bewusst NICHT importiert — wir registrieren die
// Routes über Resources/config/routes.yaml, damit das Bundle sowohl unter
// Symfony 5 (Contao 4.13) als auch Symfony 6/7 (Contao 5.x) funktioniert.
use VenneMedia\VenneSearchContaoBundle\Service\Analytics\SearchAnalyticsBuffer;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveAuthException;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveProvisioningException;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveRateLimitException;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveSubscriptionException;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveTransportException;
use VenneMedia\VenneSearchContaoBundle\Service\Search\SearchService;
use VenneMedia\VenneSearchContaoBundle\Service\Tag\TagRepository;

/**
 * Public Search-API für die Frontend-Live-Suche.
 *
 *   GET /vsearch/api?q=spongebob&locale=de&type=page&limit=20&offset=0
 *
 * Antwortet mit JSON: hits, totalHits, facets, queryTimeMs.
 *
 * Plattform-Resolve-Fehler werden in stabile JSON-Antworten übersetzt:
 *   401 → "Bundle nicht autorisiert — bitte API-Key prüfen."
 *   402 → "Abo nicht aktiv — Suche temporär deaktiviert."
 *   403 → "Bundle wartet auf Provisionierung — Admin kontaktieren."
 *   429 → "Suche aktuell überlastet — bitte gleich erneut versuchen."
 *   503 → "Suche kurzzeitig nicht erreichbar."
 */
final class FrontendSearchController extends AbstractController
{
    public function search(
        Request $request,
        SearchService $service,
        SearchAnalyticsBuffer $analytics,
        TagRepository $tags,
    ): JsonResponse {
        $query = trim((string) $request->query->get('q', ''));
        // Wenn Tag-Filter gesetzt sind, ist eine leere Volltext-Query OK
        // (Browse-by-Tag-Modus: User klickt eine Tag-Pill, sieht alle Treffer).
        $hasTagFilter = \is_array($request->query->all('tags') ?? null)
            && \count($request->query->all('tags')) > 0;
        $locale = preg_replace('/[^a-z]/', '', (string) $request->query->get('locale', 'de')) ?: 'de';
        $limit = (int) $request->query->get('limit', 20);
        $offset = (int) $request->query->get('offset', 0);

        // Facets-Only-Modus: leere Query + limit=0 ist ein expliziter Call vom
        // Frontend, um die Tag-Cloud im Initial-Modal („Was suchst du?") zu füllen.
        // Wir liefern hier alle im Backend angelegten Tags zurück (auch ohne
        // explizite Assignments, weil Auto-Match-Tags zur Laufzeit erst über
        // URL-Patterns vergeben werden und nicht in tl_venne_search_tag_assignment
        // landen). Counts berechnen wir best-effort via Meilisearch-facetDistribution
        // mit einem Wildcard-Search; klappt das nicht (Permission/Empty-Index),
        // fallen wir auf 0 zurück.
        $facetsOnly = $query === '' && !$hasTagFilter && $limit === 0;
        if ($facetsOnly) {
            $allTags = $tags->findAll();
            $tagCounts = [];
            try {
                $facetResult = $service->search(
                    query: '',
                    locale: $locale,
                    filters: [],
                    limit: 1,
                    offset: 0,
                    userGroups: $this->resolveCurrentUserGroups(),
                    locales: [],
                    sort: SearchService::SORT_RELEVANCE,
                );
                $rawTagFacet = (array) ($facetResult->facets['tags'] ?? []);
                foreach ($rawTagFacet as $token => $count) {
                    $tagCounts[(string) $token] = (int) $count;
                }
            } catch (\Throwable) {
                // Index leer, Auth-Issue etc. — wir liefern Tags trotzdem mit Count 0.
            }
            $tagFacetList = [];
            foreach ($allTags as $t) {
                $count = $tagCounts[$t['slug']] ?? $tagCounts[mb_strtolower($t['label'])] ?? 0;
                $tagFacetList[] = [
                    'slug' => $t['slug'],
                    'label' => TagRepository::translateLabel($t, $locale),
                    'color' => $t['color'],
                    'boost' => $t['boost'],
                    'count' => $count,
                ];
            }
            usort($tagFacetList, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            $facetsResp = new JsonResponse([
                'hits' => [],
                'totalHits' => 0,
                'queryTimeMs' => 0,
                'facets' => [],
                'tagFacets' => $tagFacetList,
            ]);
            $facetsResp->setPrivate();
            $facetsResp->setMaxAge(60);
            return $facetsResp;
        }
        if ($query === '' && !$hasTagFilter) {
            return new JsonResponse([
                'hits' => [],
                'totalHits' => 0,
                'queryTimeMs' => 0,
                'message' => 'Kein Suchbegriff angegeben.',
            ]);
        }

        // v2.0.0: optionales Multi-Locale via ?locales[]=de&locales[]=en
        $localesParam = $request->query->all('locales');
        $locales = [];
        if (\is_array($localesParam)) {
            foreach ($localesParam as $l) {
                $clean = preg_replace('/[^a-z]/', '', (string) $l);
                if ($clean !== '' && \strlen($clean) <= 5) {
                    $locales[] = $clean;
                }
            }
        }

        $filters = [];
        $type = (string) $request->query->get('type', '');
        if ($type !== '') {
            // "Seiten" deckt jetzt page UND crawled ab — gecrawlte externe
            // Treffer sind aus User-Sicht ebenfalls Seiten, nicht ein eigener
            // Typ. Daher beim Page-Filter beide doctypes via IN-Liste durchlassen.
            $filters['type'] = ($type === 'page' || $type === 'crawled')
                ? ['page', 'crawled']
                : $type;
        }
        // v2.0.0: ?tags[]=spongebob&tags[]=krabbenburger
        // Tags trennen wir auf in zwei Klassen:
        //   - „echte" Tags (im Index als tags[] vorhanden) → harter Meili-Filter
        //   - Auto-Match-Tags (URL-Pattern, im Index NICHT taggable) → werden
        //     hier ausgeklammert und unten via globMatch nach-gefiltert.
        $tagsParam = $request->query->all('tags');
        $autoMatchTagsAll = $tags->findAutoMatchTags();
        $autoMatchBySlug = [];
        foreach ($autoMatchTagsAll as $am) {
            $autoMatchBySlug[$am['slug']] = $am;
        }
        $autoMatchFilterTags = [];
        if (\is_array($tagsParam)) {
            $cleanTags = [];
            foreach ($tagsParam as $t) {
                $clean = preg_replace('/[^a-z0-9-]/', '', (string) $t) ?? '';
                if ($clean === '' || \strlen($clean) > 64) {
                    continue;
                }
                if (isset($autoMatchBySlug[$clean])) {
                    $autoMatchFilterTags[] = $autoMatchBySlug[$clean];
                } else {
                    $cleanTags[] = $clean;
                }
            }
            if ($cleanTags !== []) {
                $filters['tags'] = $cleanTags;
            }
        }

        // v2.1.0: ?ext[]=pdf&ext[]=xlsx — Datei-Extension-Filter. Im Index
        // werden Extensions als Tag mitgeschrieben (siehe IndexableItemProcessor),
        // also lassen sich beide Filter über die `tags`-Filterable kombinieren:
        // tags IN ["a"] AND tags IN ["pdf","xlsx"]. Meilisearch kann das, wir
        // schreiben es deshalb als zweites Filter-Set ('tags_ext').
        $extParam = $request->query->all('ext');
        if (\is_array($extParam)) {
            $cleanExts = [];
            foreach ($extParam as $e) {
                $clean = preg_replace('/[^a-z0-9]/', '', strtolower((string) $e)) ?? '';
                if ($clean !== '' && \strlen($clean) <= 8) {
                    $cleanExts[] = $clean;
                }
            }
            if ($cleanExts !== []) {
                // Wir nutzen einen logischen Filter-Schlüssel; das Mapping auf
                // den echten Filterable-Namen passiert im SearchService.
                $filters['file_ext'] = $cleanExts;
            }
        }

        // v2.1.0: Sort-Mode (relevance / date_desc / date_asc)
        $sort = (string) $request->query->get('sort', SearchService::SORT_RELEVANCE);

        $userGroups = $this->resolveCurrentUserGroups();

        // Auto-Match-Tag-Filter: wir holen den Pool gezielt per Volltext-Suche
        // mit den signifikanten Pattern-Termen (zwischen den * in Glob-Patterns),
        // sonst landen einzelne Detail-URLs nicht in den Top-N einer leeren
        // Wildcard-Suche. Beispiel Pattern „*pressemitteilungen-detailseite*"
        // → Suchterm „pressemitteilungen-detailseite" → trifft alle Docs mit
        //   diesem URL-Fragment. Anschliessend in PHP per globMatch nachfiltern.
        $effectiveQuery = $query;
        $serviceLimit = $limit;
        $serviceOffset = $offset;
        if ($autoMatchFilterTags !== []) {
            $serviceLimit = 1000;
            $serviceOffset = 0;
            if ($query === '') {
                $effectiveQuery = self::buildPatternQuery($autoMatchFilterTags, $locale);
            }
        }

        try {
            $result = $service->search(
                query: $effectiveQuery,
                locale: $locale,
                filters: $filters,
                limit: $serviceLimit,
                offset: $serviceOffset,
                userGroups: $userGroups,
                locales: $locales,
                sort: $sort,
            );
        } catch (ResolveAuthException) {
            return $this->errorResponse(401, 'unauthorized', 'Suche aktuell nicht verfügbar — der Site-Betreiber muss den Plattform-Schlüssel prüfen.');
        } catch (ResolveSubscriptionException) {
            return $this->errorResponse(402, 'subscription_inactive', 'Suche temporär deaktiviert (Abo nicht aktiv).');
        } catch (ResolveProvisioningException) {
            return $this->errorResponse(403, 'not_provisioned', 'Suche wird gerade eingerichtet — bitte später erneut versuchen.');
        } catch (ResolveRateLimitException) {
            return $this->errorResponse(429, 'rate_limited', 'Aktuell zu viele Anfragen — bitte einen Moment warten.');
        } catch (ResolveTransportException) {
            return $this->errorResponse(503, 'platform_unreachable', 'Suche kurzzeitig nicht erreichbar.');
        } catch (\Throwable $e) {
            // Letzter Fallback für Meilisearch- oder unerwartete Fehler.
            return $this->errorResponse(500, 'search_failed', 'Unerwarteter Fehler bei der Suche.');
        }

        // Auto-Match-Tag-Post-Filter: Treffer behalten, deren URL gegen MINDESTENS
        // ein Pattern eines angefragten Auto-Match-Tags matched (logisches AND
        // ueber alle angefragten Auto-Match-Tags, OR ueber Patterns innerhalb).
        if ($autoMatchFilterTags !== []) {
            $matchAuto = static function (string $url) use ($autoMatchFilterTags, $locale): bool {
                foreach ($autoMatchFilterTags as $am) {
                    $oneMatched = false;
                    foreach (TagRepository::patternsForLocale($am, $locale) as $pattern) {
                        if (self::globMatch($pattern, $url)) {
                            $oneMatched = true;
                            break;
                        }
                    }
                    if (!$oneMatched) {
                        return false;
                    }
                }
                return true;
            };
            $filtered = [];
            foreach ($result->hits as $h) {
                if ($matchAuto((string) $h->url)) {
                    $filtered[] = $h;
                }
            }
            // Nach Sort-Mode sortieren — Meilisearch hat den Pool zwar mit dem
            // angefragten Sort geholt, aber durch die Volltext-Pattern-Query
            // sind Hits mit hohem Score nach oben gewandert. Wir muessen den
            // Sort nach dem Post-Filter selbst nochmal anwenden.
            if ($sort === SearchService::SORT_DATE_DESC) {
                usort($filtered, static fn ($a, $b): int => $b->publishedAt <=> $a->publishedAt);
            } elseif ($sort === SearchService::SORT_DATE_ASC) {
                usort($filtered, static function ($a, $b): int {
                    if ($a->publishedAt === 0 && $b->publishedAt === 0) return 0;
                    if ($a->publishedAt === 0) return 1;
                    if ($b->publishedAt === 0) return -1;
                    return $a->publishedAt <=> $b->publishedAt;
                });
            } elseif ($sort === SearchService::SORT_TYPE_ASC) {
                usort($filtered, static function ($a, $b): int {
                    $cmp = strcmp($a->contentType, $b->contentType);
                    if ($cmp !== 0) return $cmp;
                    return $b->score <=> $a->score;
                });
            }
            // Type-Facet-Counts aus dem post-gefilterten Pool neu berechnen,
            // sonst zeigt die Sidebar inkonsistente Counts an (z.B. "Dateien (10)"
            // weil Meilisearch 10 Volltext-Dateien gefunden hat, aber unser
            // URL-Glob-Filter keine davon akzeptiert).
            $typeCounts = [];
            foreach ($filtered as $h) {
                $t = (string) $h->type;
                $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
            }
            $newFacets = $result->facets;
            $newFacets['type'] = $typeCounts;

            $totalAfter = \count($filtered);
            $paged = \array_slice($filtered, $offset, $limit);
            $result = new \VenneMedia\VenneSearchContaoBundle\Service\Search\SearchResult(
                hits: $paged,
                totalHits: $totalAfter,
                offset: $offset,
                limit: $limit,
                facets: $newFacets,
                queryTimeMs: $result->queryTimeMs,
            );
        }

        // URL-Deduplizierung: derselbe Inhalt kann doppelt im Index liegen
        // (z.B. als Contao-Page UND als crawled-Hit von der gleichen URL).
        // Wir behalten pro normalisierter URL nur den qualitativ besten Hit.
        // Prioritaet: page/file/article > crawled (interne Daten gewinnen,
        // weil sie strukturiert sind und korrekte Permissions/Tags haben).
        $result = self::dedupeByUrl($result);

        // v2.0.0: Anonymes Analytics-Tracking. Niemals den Such-Pfad blockieren.
        try {
            $analytics->record($query, $locale, $result->totalHits);
        } catch (\Throwable) {
        }

        // v2.0.0: Tag-Daten anreichern. Im Index liegen pro Tag ZWEI Einträge —
        // einmal der Slug, einmal das Label (damit Volltext-Suche das Label
        // matcht). Hier deduplizieren wir per Slug ODER Label-Match auf
        // eine bekannte Tag-Definition. Dateiendungen ("pdf"/"docx" etc.)
        // sind keine "echten" Tags und werden ausgeblendet.
        $allTags = $tags->findAll();
        $bySlug = [];
        $byLabelLower = [];
        foreach ($allTags as $tag) {
            $bySlug[$tag['slug']] = $tag;
            $byLabelLower[mb_strtolower($tag['label'])] = $tag;
        }
        $extensions = ['pdf', 'docx', 'doc', 'odt', 'rtf', 'txt', 'md'];

        // Tag-Facets aus dem Meilisearch-facetDistribution: pro echtem Tag
        // Slug+Label deduplizieren (im Index stehen beide), dann nur die
        // mit höchstem Count behalten. Dateiendungen ausblenden.
        $rawTagFacet = (array) ($result->facets['tags'] ?? []);
        $tagFacetByTag = [];
        foreach ($rawTagFacet as $token => $count) {
            $count = (int) $count;
            if ($token === '' || \in_array(strtolower((string) $token), $extensions, true)) {
                continue;
            }
            $tag = $bySlug[$token] ?? $byLabelLower[mb_strtolower((string) $token)] ?? null;
            if ($tag === null) {
                // Legacy/Roh-Tag — als grauer Chip
                $key = 'raw:' . mb_strtolower((string) $token);
                if (!isset($tagFacetByTag[$key]) || $tagFacetByTag[$key]['count'] < $count) {
                    $tagFacetByTag[$key] = [
                        'slug' => (string) $token,
                        'label' => (string) $token,
                        'color' => 'gray',
                        'boost' => 1.0,
                        'count' => $count,
                    ];
                }
                continue;
            }
            $key = $tag['slug'];
            if (!isset($tagFacetByTag[$key]) || $tagFacetByTag[$key]['count'] < $count) {
                $tagFacetByTag[$key] = [
                    'slug' => $tag['slug'],
                    'label' => TagRepository::translateLabel($tag, $locale),
                    'color' => $tag['color'],
                    'boost' => (float) ($tag['boost'] ?? 1.0),
                    'count' => $count,
                ];
            }
        }
        // Auto-Match-Tags: pro Tag URL-Glob-Patterns. Jeder Hit der
        // matcht, kriegt den Tag dazu — ohne Indexer-Eingriff.
        $autoMatchTags = $tags->findAutoMatchTags();
        // Counts für Auto-Tags zur Facet-Liste hinzufügen (nur wenn min. 1 Hit matcht).
        $autoMatchHitCounts = [];
        foreach ($result->hits as $h) {
            foreach ($autoMatchTags as $am) {
                foreach (TagRepository::patternsForLocale($am, $locale) as $pattern) {
                    if (self::globMatch($pattern, (string) $h->url)) {
                        $autoMatchHitCounts[$am['slug']] = ($autoMatchHitCounts[$am['slug']] ?? 0) + 1;
                        break 2;
                    }
                }
            }
        }
        foreach ($autoMatchTags as $am) {
            $count = $autoMatchHitCounts[$am['slug']] ?? 0;
            if ($count === 0) {
                continue;
            }
            $existing = $tagFacetByTag[$am['slug']] ?? null;
            if ($existing === null || $existing['count'] < $count) {
                $tagFacetByTag[$am['slug']] = [
                    'slug' => $am['slug'],
                    'label' => TagRepository::translateLabel($am, $locale),
                    'color' => $am['color'],
                    'boost' => $am['boost'],
                    'count' => $count,
                ];
            }
        }

        // Sortiert: häufigste Tags zuerst.
        $tagFacetList = array_values($tagFacetByTag);
        usort($tagFacetList, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $response = new JsonResponse([
            'hits' => array_map(
                static function ($h) use ($bySlug, $byLabelLower, $extensions, $autoMatchTags, $locale): array {
                    $resolvedById = [];
                    foreach ($h->tags as $raw) {
                        if ($raw === '' || \in_array(strtolower($raw), $extensions, true)) {
                            continue;
                        }
                        // Treffer per Slug?
                        if (isset($bySlug[$raw])) {
                            $t = $bySlug[$raw];
                            $resolvedById[$t['slug']] = [
                                'slug' => $t['slug'],
                                'label' => TagRepository::translateLabel($t, $locale),
                                'color' => $t['color'],
                                'boost' => $t['boost'] ?? 1.0,
                            ];
                            continue;
                        }
                        // Treffer per Label?
                        $low = mb_strtolower($raw);
                        if (isset($byLabelLower[$low])) {
                            $t = $byLabelLower[$low];
                            $resolvedById[$t['slug']] = [
                                'slug' => $t['slug'],
                                'label' => TagRepository::translateLabel($t, $locale),
                                'color' => $t['color'],
                                'boost' => $t['boost'] ?? 1.0,
                            ];
                            continue;
                        }
                        // Unbekannter Tag (Legacy aus tl_page.keywords ODER Tag aus
                        // einem anderen Tenant der den gleichen Index nutzt) — als
                        // grauer Chip, Slug ist die normalisierte Form.
                        $rawSlug = preg_replace('/[^a-z0-9]+/', '-', $low) ?? $low;
                        $rawSlug = trim((string) $rawSlug, '-');
                        $resolvedById['raw:' . $low] = ['slug' => $rawSlug !== '' ? $rawSlug : $raw, 'label' => $raw, 'color' => 'gray', 'boost' => 1.0];
                    }
                    // Auto-Match-Tags: URL gegen Patterns prüfen, passende dazu.
                    foreach ($autoMatchTags as $am) {
                        foreach (TagRepository::patternsForLocale($am, $locale) as $pattern) {
                            if (self::globMatch($pattern, (string) $h->url)) {
                                $resolvedById[$am['slug']] = [
                                    'slug' => $am['slug'],
                                    'label' => TagRepository::translateLabel($am, $locale),
                                    'color' => $am['color'],
                                    'boost' => $am['boost'],
                                ];
                                break;
                            }
                        }
                    }
                    return [
                        'id' => $h->id,
                        'type' => $h->type,
                        'title' => $h->title,
                        'url' => $h->url,
                        'snippet' => $h->snippet,
                        'tags' => array_values($h->tags),
                        'tagsResolved' => array_values($resolvedById),
                        'score' => $h->score,
                        'isProtected' => $h->isProtected,
                        'altText' => $h->altText,
                        // v2.2.0: Cover-Bild-URL (leer = kein Cover, Frontend
                        // rendert das generische Icon).
                        'coverUrl' => $h->coverUrl,
                        // v2.2.0: Sort-Key für „Nach Dokumentenart".
                        'contentType' => $h->contentType,
                        // v2.2.0: Unix-Timestamp, hilft Frontend bei Anzeige
                        // einer Datumsspalte falls sortiert nach Datum.
                        'publishedAt' => $h->publishedAt,
                        // v2.2.0: Dateigröße in Bytes (Frontend formatiert).
                        'fileSize' => $h->fileSize,
                    ];
                },
                $result->hits,
            ),
            'totalHits' => $result->totalHits,
            'offset' => $result->offset,
            'limit' => $result->limit,
            // crawled-Counts werden in den page-Count gemerged — "Websiteinhalt"
            // soll im Frontend nicht mehr als separater Tab erscheinen.
            'facets' => self::mergeCrawledIntoPage($result->facets),
            'tagFacets' => $tagFacetList,
            'queryTimeMs' => $result->queryTimeMs,
        ]);

        // Sicherheits-Härtung:
        // Suchergebnisse können je nach Frontend-Mitgliedsgruppen variieren.
        // Deshalb niemals über Shared Caches ausliefern.
        $response->setPrivate();
        $response->setMaxAge(30);
        $response->setVary(['Cookie'], false);
        return $response;
    }

    /**
     * Fasst die Facet-Counts fuer "page" und "crawled" zu einem einzigen
     * "page"-Bucket zusammen. Im Frontend soll es keinen separaten Tab
     * "Websiteinhalte" mehr geben — gecrawlte externe Seiten gehoeren aus
     * User-Sicht in die Kategorie "Seiten".
     *
     * @param array<string,mixed> $facets
     * @return array<string,mixed>
     */
    private static function mergeCrawledIntoPage(array $facets): array
    {
        if (!isset($facets['type']) || !\is_array($facets['type'])) {
            return $facets;
        }
        $type = $facets['type'];
        $crawled = (int) ($type['crawled'] ?? 0);
        if ($crawled > 0) {
            $type['page'] = (int) ($type['page'] ?? 0) + $crawled;
        }
        unset($type['crawled']);
        $facets['type'] = $type;
        return $facets;
    }

    /**
     * URL-Deduplizierung: Wenn derselbe Inhalt mehrfach im Index liegt
     * (z.B. als Contao-Page UND als gecrawltes Dokument von der gleichen URL),
     * behalten wir nur die qualitativ beste Variante.
     * Prio: page/file/article > crawled (interne Quellen gewinnen).
     * Facet-Counts werden entsprechend neu gezaehlt.
     */
    private static function dedupeByUrl(\VenneMedia\VenneSearchContaoBundle\Service\Search\SearchResult $result): \VenneMedia\VenneSearchContaoBundle\Service\Search\SearchResult
    {
        $priority = ['page' => 4, 'file' => 3, 'article' => 2, 'crawled' => 1];

        // Phase 1: URL-Dedup. Prio-Loser wird verworfen, Prio-Sieger behalten.
        $byUrl = [];
        $dropped = 0;
        foreach ($result->hits as $h) {
            $key = self::normalizeUrl((string) $h->url);
            if ($key === '') {
                $byUrl[spl_object_hash($h)] = $h;
                continue;
            }
            if (!isset($byUrl[$key])) {
                $byUrl[$key] = $h;
                continue;
            }
            $existing = $byUrl[$key];
            if (($priority[$h->type] ?? 0) > ($priority[$existing->type] ?? 0)) {
                $byUrl[$key] = $h;
            }
            $dropped++;
        }

        // Phase 2: Title-Dedup nur fuer crawled-Hits. Wenn der Crawler den
        // gleichen <title> (typischerweise Site-Default) auf mehreren URLs
        // gefunden hat, ist der Title fuer den User nicht unterscheidend.
        // Behalte den ersten pro Title, verwerfe die weiteren — User sieht
        // nicht mehr 6x „FFA - Die Filmfoerderung des Bundes" untereinander.
        $deduped = [];
        $crawledByTitle = [];
        foreach ($byUrl as $h) {
            if ($h->type !== 'crawled') {
                $deduped[] = $h;
                continue;
            }
            $title = strip_tags((string) $h->title);
            $titleKey = mb_strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? ''));
            if ($titleKey === '') {
                $deduped[] = $h;
                continue;
            }
            if (!isset($crawledByTitle[$titleKey])) {
                $crawledByTitle[$titleKey] = true;
                $deduped[] = $h;
            } else {
                $dropped++;
            }
        }

        if ($dropped === 0) {
            return $result;
        }

        // Type-Facets neu zaehlen.
        $typeCounts = [];
        foreach ($deduped as $h) {
            $t = (string) $h->type;
            $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
        }
        $facets = $result->facets;
        $facets['type'] = $typeCounts;
        return new \VenneMedia\VenneSearchContaoBundle\Service\Search\SearchResult(
            hits: $deduped,
            totalHits: max(0, $result->totalHits - $dropped),
            offset: $result->offset,
            limit: $result->limit,
            facets: $facets,
            queryTimeMs: $result->queryTimeMs,
        );
    }

    /**
     * Normalisiert URLs fuer Dedup-Vergleich: protokoll + host weg, www. weg,
     * trailing slash weg, query bleibt drin (`?meldung=xyz` differenziert
     * unterschiedliche Detail-Seiten). Leere/relative URLs → leer.
     */
    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // Protokoll + Host raus
        $url = preg_replace('#^https?://(www\.)?[^/]+#i', '', $url) ?? $url;
        // Falls KEIN Protokoll war, evtl. fuehrendes "www.host" trotzdem entfernen.
        $url = preg_replace('#^(www\.)?[^/]+\.[a-z]{2,}#i', '', $url) ?? $url;
        $url = strtolower($url);
        $url = rtrim($url, '/');
        return $url;
    }

    /**
     * Baut aus Auto-Match-Patterns eine Volltext-Query, mit der Meilisearch
     * die relevanten Docs im Index aufstoebert. Pattern „*pressemitteilungen-detailseite*"
     * wird zu Suchterm „pressemitteilungen-detailseite". Mehrere Tags / Patterns
     * werden mit Leerzeichen zusammengefuegt — Meilisearch findet dann Docs die
     * MINDESTENS einen der Terme enthalten (Default-Matching-Strategy „last").
     *
     * @param list<array{patterns:list<string>}> $autoMatchTags
     */
    private static function buildPatternQuery(array $autoMatchTags, string $locale = 'de'): string
    {
        $terms = [];
        foreach ($autoMatchTags as $am) {
            foreach (TagRepository::patternsForLocale($am, $locale) as $pattern) {
                // Aufteilen an Wildcards: signifikante Token zwischen den * / ? extrahieren.
                $parts = preg_split('/[*?]+/', $pattern) ?: [];
                foreach ($parts as $p) {
                    $p = trim((string) $p);
                    // URL-Trennzeichen rauswerfen damit Meilisearch tokenisieren kann.
                    $p = str_replace(['/', '\\', '?', '#', '&', '=', '.'], ' ', $p);
                    $p = trim(preg_replace('/\s+/', ' ', $p) ?? '');
                    if ($p !== '' && \strlen($p) >= 3) {
                        $terms[] = $p;
                    }
                }
            }
        }
        return implode(' ', array_unique($terms));
    }

    /**
     * Glob-Match (Wildcard * = beliebig viele Zeichen, ? = ein Zeichen).
     * Case-insensitive, ohne Regex-Magie. Genutzt für Auto-Tag-Patterns.
     */
    public static function globMatch(string $pattern, string $url): bool
    {
        if ($pattern === '' || $url === '') {
            return false;
        }
        $regex = '#' . str_replace(
            ['\\*', '\\?'],
            ['.*', '.'],
            preg_quote($pattern, '#'),
        ) . '#i';
        return (bool) @preg_match($regex, $url);
    }

    /**
     * Findet die tl_member_group-IDs des aktuellen Frontend-Users.
     *
     * Funktioniert auf Contao 4.13 (FrontendUser::getInstance() Singleton)
     * und Contao 5.x (Symfony-Security via TokenStorage). Wir versuchen
     * zuerst die moderne Variante via Container-Token-Storage, fallen
     * dann auf die Contao-Singleton-API zurück.
     *
     * @return list<int>
     */
    private function resolveCurrentUserGroups(): array
    {
        $dbg = sys_get_temp_dir() . '/venne-search-resolve.log';
        @file_put_contents($dbg, date('c') . " resolveCurrentUserGroups START\n", FILE_APPEND);

        // Variante 1: Contao 4.13/5.x via Symfony-Security TokenStorage.
        try {
            $token = $this->container->has('security.token_storage')
                ? $this->container->get('security.token_storage')?->getToken()
                : null;
            @file_put_contents($dbg, '  token class: ' . ($token ? \get_class($token) : 'null') . "\n", FILE_APPEND);
            $user = $token?->getUser();
            @file_put_contents($dbg, '  user class: ' . ($user ? (\is_object($user) ? \get_class($user) : 'string') : 'null') . "\n", FILE_APPEND);

            if ($user !== null && \is_object($user) && method_exists($user, 'getGroups')) {
                /** @var mixed $g */
                $g = $user->getGroups();
                @file_put_contents($dbg, '  user->getGroups(): ' . var_export($g, true) . "\n", FILE_APPEND);
                $normalized = $this->normalizeGroupIds($g);
                if ($normalized !== []) {
                    return $normalized;
                }
            }

            // Contao FrontendUser (4.13+5.x): die Member-Groups stehen als
            // Property `groups` direkt am User-Objekt (serialized array).
            if ($user !== null && \is_object($user)) {
                if (property_exists($user, 'groups')) {
                    @file_put_contents($dbg, '  user->groups property: ' . var_export($user->groups, true) . "\n", FILE_APPEND);
                    $normalized = $this->normalizeGroupIds($user->groups);
                    if ($normalized !== []) {
                        return $normalized;
                    }
                }
                // Manche Setups rutschen die Groups in __get(); rohen Zugriff testen.
                try {
                    $rawGroups = @$user->groups;
                    @file_put_contents($dbg, '  user->groups via __get: ' . var_export($rawGroups, true) . "\n", FILE_APPEND);
                    $normalized = $this->normalizeGroupIds($rawGroups);
                    if ($normalized !== []) {
                        return $normalized;
                    }
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable $e) {
            @file_put_contents($dbg, '  exception: ' . $e->getMessage() . "\n", FILE_APPEND);
        }

        // Variante 2: Singleton-Fallback (selten, aber rettet Edge-Cases).
        try {
            if (class_exists(\Contao\FrontendUser::class)) {
                $user = \Contao\FrontendUser::getInstance();
                if (\is_object($user) && property_exists($user, 'groups')) {
                    return $this->normalizeGroupIds($user->groups);
                }
            }
        } catch (\Throwable) {
        }

        @file_put_contents($dbg, "  returning []\n", FILE_APPEND);
        return [];
    }

    /**
     * Contao speichert Member-Groups je nach Version unterschiedlich:
     *   - serialisiertes Array (legacy)
     *   - direktes Array
     *   - leerer String / null
     *
     * @return list<int>
     */
    private function normalizeGroupIds(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return [];
        }
        if (\is_string($raw)) {
            $maybe = @unserialize($raw, ['allowed_classes' => false]);
            if (\is_array($maybe)) {
                $raw = $maybe;
            } else {
                return [];
            }
        }
        if (!\is_array($raw)) {
            return [];
        }
        return array_values(array_filter(
            array_map('intval', $raw),
            static fn (int $v): bool => $v > 0,
        ));
    }

    private function errorResponse(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'hits' => [],
            'totalHits' => 0,
            'queryTimeMs' => 0,
            'error' => $code,
            'message' => $message,
        ], $status);
    }
}
