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
        if ($query === '') {
            return new JsonResponse([
                'hits' => [],
                'totalHits' => 0,
                'queryTimeMs' => 0,
                'message' => 'Kein Suchbegriff angegeben.',
            ]);
        }

        $locale = preg_replace('/[^a-z]/', '', (string) $request->query->get('locale', 'de')) ?: 'de';
        $limit = (int) $request->query->get('limit', 20);
        $offset = (int) $request->query->get('offset', 0);

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
            $filters['type'] = $type;
        }
        // v2.0.0: ?tags[]=spongebob&tags[]=krabbenburger
        $tagsParam = $request->query->all('tags');
        if (\is_array($tagsParam)) {
            $cleanTags = [];
            foreach ($tagsParam as $t) {
                $clean = preg_replace('/[^a-z0-9-]/', '', (string) $t) ?? '';
                if ($clean !== '' && \strlen($clean) <= 64) {
                    $cleanTags[] = $clean;
                }
            }
            if ($cleanTags !== []) {
                $filters['tags'] = $cleanTags;
            }
        }

        $userGroups = $this->resolveCurrentUserGroups();

        try {
            $result = $service->search(
                query: $query,
                locale: $locale,
                filters: $filters,
                limit: $limit,
                offset: $offset,
                userGroups: $userGroups,
                locales: $locales,
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

        // v2.0.0: Anonymes Analytics-Tracking. Niemals den Such-Pfad blockieren.
        try {
            $analytics->record($query, $locale, $result->totalHits);
        } catch (\Throwable) {
        }

        // v2.0.0: Tag-Slugs in volle Tag-Objekte (label/color) hydrieren —
        // Ein Lookup-Pass, alle Slugs aus den Hits einsammeln.
        $allSlugs = [];
        foreach ($result->hits as $h) {
            foreach ($h->tags as $t) {
                $allSlugs[$t] = true;
            }
        }
        $tagMap = [];
        if ($allSlugs !== []) {
            foreach ($tags->findAll() as $tag) {
                if (isset($allSlugs[$tag['slug']])) {
                    $tagMap[$tag['slug']] = $tag;
                }
            }
        }

        $response = new JsonResponse([
            'hits' => array_map(
                static function ($h) use ($tagMap): array {
                    $tagsResolved = [];
                    foreach ($h->tags as $slug) {
                        if (isset($tagMap[$slug])) {
                            $tagsResolved[] = $tagMap[$slug];
                        } else {
                            // Built-in / Legacy-Tag (z.B. "pdf", "shop").
                            $tagsResolved[] = ['slug' => $slug, 'label' => $slug, 'color' => 'gray'];
                        }
                    }
                    return [
                        'id' => $h->id,
                        'type' => $h->type,
                        'title' => $h->title,
                        'url' => $h->url,
                        'snippet' => $h->snippet,
                        'tags' => $h->tags,
                        'tagsResolved' => $tagsResolved,
                        'score' => $h->score,
                        'isProtected' => $h->isProtected,
                    ];
                },
                $result->hits,
            ),
            'totalHits' => $result->totalHits,
            'offset' => $result->offset,
            'limit' => $result->limit,
            'facets' => $result->facets,
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
