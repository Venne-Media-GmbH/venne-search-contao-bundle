<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Platform;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Klient für GET https://venne-search.de/api/v1/resolve.
 *
 * Liefert {endpoint, indexPrefix, scopedToken} für den aktuellen Plattform-
 * API-Key. Cached das Ergebnis in tl_venne_search_settings.resolve_cache
 * für die TTL die die Plattform mitgibt — Bundle-Reboot überlebt der Cache,
 * sodass nicht jede Page-Request einen Plattform-Roundtrip auslöst.
 *
 * Fehlerverhalten:
 *   - 401 → ResolveAuthException (Plattform-Key ungültig)
 *   - 402 → ResolveSubscriptionException (Abo nicht aktiv)
 *   - 403 → ResolveProvisioningException (Key existiert, aber Meili-Setup fehlt)
 *   - 429 → ResolveRateLimitException
 *   - 5xx / Netzwerk → ResolveTransportException (Cache wird WEITER genutzt
 *     wenn vorhanden, auch wenn TTL abgelaufen, damit ein Plattform-Ausfall
 *     nicht sofort die Suche tötet)
 */
final class ResolveClient
{
    private const TABLE = 'tl_venne_search_settings';

    /**
     * Wenn die Plattform 5xx liefert oder unerreichbar ist, wir aber einen
     * abgelaufenen Cache haben, nutzen wir diesen für maximal so lange als
     * Fallback (= Notbetrieb). 24 h ist großzügig und lässt Wartungsfenster zu.
     */
    private const STALE_FALLBACK_SECONDS = 86400;

    private HttpClientInterface $http;

    public function __construct(
        private readonly Connection $db,
        private readonly string $platformUrl,
        ?HttpClientInterface $http = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->http = $http ?? HttpClient::create([
            'timeout' => 6,
            'verify_peer' => true,
            'verify_host' => true,
        ]);
    }

    /**
     * Liefert das aufgelöste Resolve-Result für den gegebenen Plattform-Key.
     * Nutzt Cache wenn frisch, sonst HTTP-Call zur Plattform.
     */
    public function resolve(string $platformApiKey): ResolvedConfig
    {
        if ($platformApiKey === '') {
            throw new ResolveAuthException('Kein Plattform-API-Key konfiguriert.');
        }

        // 1) Cache lesen
        $cached = $this->readCache($platformApiKey);
        if ($cached !== null && $cached['expiresAt'] > time()) {
            return $cached['config'];
        }

        // 2) Plattform fragen
        try {
            $response = $this->http->request('GET', $this->buildResolveUrl(), [
                'headers' => ['Authorization' => 'Bearer ' . $platformApiKey],
            ]);
            $code = $response->getStatusCode();

            if ($code === 401) {
                $this->purgeCache();
                throw new ResolveAuthException('Plattform-API-Key ist ungültig oder widerrufen.');
            }
            if ($code === 402) {
                $this->purgeCache();
                throw new ResolveSubscriptionException('Venne-Search-Abo nicht aktiv.');
            }
            if ($code === 403) {
                $this->purgeCache();
                throw new ResolveProvisioningException('Plattform-Key noch nicht provisioniert — Admin-Eingriff nötig.');
            }
            if ($code === 429) {
                throw new ResolveRateLimitException('Resolve rate-limited — Bundle muss kürzer cachen oder warten.');
            }
            if ($code !== 200) {
                throw new ResolveTransportException(sprintf('Plattform antwortete HTTP %d', $code));
            }

            $body = $response->toArray(false);
        } catch (ResolveException $e) {
            // 5xx / Netzwerk: stale Cache nutzen wenn da.
            if ($e instanceof ResolveTransportException && $cached !== null && $cached['expiresAt'] + self::STALE_FALLBACK_SECONDS > time()) {
                $this->logger->warning('venne_search.resolve.using_stale_cache', [
                    'reason' => $e->getMessage(),
                    'cache_age_s' => time() - ($cached['expiresAt'] - $cached['ttl']),
                ]);

                return $cached['config'];
            }
            throw $e;
        } catch (ExceptionInterface $e) {
            // Echter Netzwerk-Fehler.
            if ($cached !== null && $cached['expiresAt'] + self::STALE_FALLBACK_SECONDS > time()) {
                $this->logger->warning('venne_search.resolve.network_error_stale_cache', [
                    'error' => $e->getMessage(),
                ]);

                return $cached['config'];
            }
            throw new ResolveTransportException(sprintf('Plattform nicht erreichbar: %s', $e->getMessage()), 0, $e);
        }

        $endpoint = (string) ($body['endpoint'] ?? '');
        $indexPrefix = (string) ($body['indexPrefix'] ?? '');
        $scopedToken = (string) ($body['scopedToken'] ?? '');
        $ttl = max(60, (int) ($body['ttl'] ?? 3600));

        if ($endpoint === '' || $indexPrefix === '' || $scopedToken === '') {
            throw new ResolveTransportException('Plattform lieferte unvollständiges Resolve-Result.');
        }

        $config = new ResolvedConfig(
            endpoint: $endpoint,
            indexPrefix: $indexPrefix,
            scopedToken: $scopedToken,
        );

        $this->writeCache($platformApiKey, $config, $ttl);

        return $config;
    }

    /**
     * Wirft den Cache weg (z.B. nach Settings-Save oder bei 401/403).
     */
    public function purgeCache(): void
    {
        try {
            $this->db->executeStatement(
                sprintf('UPDATE %s SET resolve_cache = NULL WHERE id = 1', self::TABLE),
            );
        } catch (\Throwable) {
            // Tabelle/Spalte gibt's evtl noch nicht (Migration läuft) — egal.
        }
    }

    /**
     * @return array{config: ResolvedConfig, expiresAt: int, ttl: int}|null
     */
    private function readCache(string $platformApiKey): ?array
    {
        try {
            $row = $this->db->fetchAssociative(sprintf('SELECT resolve_cache FROM %s WHERE id = 1', self::TABLE));
            if (!is_array($row)) {
                return null;
            }
            $raw = (string) ($row['resolve_cache'] ?? '');
            if ($raw === '') {
                return null;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return null;
            }
            $cachedKey = (string) ($data['key'] ?? '');
            if ($cachedKey !== hash('sha256', $platformApiKey)) {
                // Key wurde geändert — Cache verwerfen.
                return null;
            }

            return [
                'config' => new ResolvedConfig(
                    endpoint: (string) ($data['endpoint'] ?? ''),
                    indexPrefix: (string) ($data['indexPrefix'] ?? ''),
                    scopedToken: (string) ($data['scopedToken'] ?? ''),
                ),
                'expiresAt' => (int) ($data['expiresAt'] ?? 0),
                'ttl' => (int) ($data['ttl'] ?? 3600),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function writeCache(string $platformApiKey, ResolvedConfig $config, int $ttl): void
    {
        $payload = [
            'key' => hash('sha256', $platformApiKey),
            'endpoint' => $config->endpoint,
            'indexPrefix' => $config->indexPrefix,
            'scopedToken' => $config->scopedToken,
            'expiresAt' => time() + $ttl,
            'ttl' => $ttl,
        ];
        try {
            $this->db->executeStatement(
                sprintf('UPDATE %s SET resolve_cache = ? WHERE id = 1', self::TABLE),
                [json_encode($payload, JSON_UNESCAPED_SLASHES) ?: null],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('venne_search.resolve.cache_write_failed', ['error' => $e->getMessage()]);
        }
    }

    private function buildResolveUrl(): string
    {
        return rtrim($this->platformUrl, '/') . '/api/v1/resolve';
    }
}

/**
 * @property-read string $endpoint     z.B. "https://search.serverk1.venne-hosting.de"
 * @property-read string $indexPrefix  z.B. "t_a1b2c3d4e5f6g7h8" (ohne locale-Suffix)
 * @property-read string $scopedToken  Meilisearch-Key, NUR für $indexPrefix_*
 */
final class ResolvedConfig
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $indexPrefix,
        public readonly string $scopedToken,
    ) {
    }
}

abstract class ResolveException extends \RuntimeException
{
}
final class ResolveAuthException extends ResolveException
{
}
final class ResolveSubscriptionException extends ResolveException
{
}
final class ResolveProvisioningException extends ResolveException
{
}
final class ResolveRateLimitException extends ResolveException
{
}
final class ResolveTransportException extends ResolveException
{
}
