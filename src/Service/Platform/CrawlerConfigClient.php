<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Platform;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * v2.2.0: Liest und schreibt die Crawler-Config eines Tenants über die
 * REST-API von venne-search.de.
 *
 *   GET  /api/v1/crawler/config
 *   PUT  /api/v1/crawler/config
 *
 * Authentifizierung: Authorization: Bearer <api-key-plaintext>. Der Plaintext
 * liegt verschlüsselt in tl_venne_search_settings.api_key. Wir senden ihn
 * direkt — die Plattform validiert via Hash-Lookup.
 *
 * Antwort-Shape (von der Plattform definiert):
 *   {
 *     active: bool,
 *     startUrls: string[],
 *     allowedHosts: string[],
 *     urlPatterns: string[],
 *     excludePatterns: string[],
 *     maxDepth: int,
 *     maxPages: int,
 *     intervalDays: int,
 *     respectRobotsTxt: bool,
 *     lastRunAt: ?ISO 8601,
 *     nextRunAt: ?ISO 8601,
 *     lastRunStats: ?{pagesSeen,pagesIndexed,errors,durationSec,errorSample}
 *   }
 */
final class CrawlerConfigClient
{
    private const HTTP_TIMEOUT = 8.0;
    private const CACHE_TTL = 60;
    private const ENDPOINT = '/api/v1/crawler/config';

    /** @var array{config: array<string, mixed>|null, fetchedAt: int}|null */
    private ?array $cache = null;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Connection $db,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Aktuelle Crawler-Config vom Server holen. Memoized 60 s pro Request-Cycle.
     *
     * @return array<string, mixed>
     */
    public function fetch(bool $refresh = false): array
    {
        if (!$refresh && $this->cache !== null && (time() - $this->cache['fetchedAt']) < self::CACHE_TTL) {
            return $this->cache['config'] ?? $this->defaults();
        }

        $apiKey = $this->settings->getPlatformApiKey();
        if ($apiKey === '') {
            return $this->defaults();
        }

        $url = $this->settings->getPlatformUrl() . self::ENDPOINT;
        try {
            $response = $this->httpRequest('GET', $url, $apiKey, null);
            $config = $response['ok'] ? $response['body'] : null;
        } catch (\Throwable $e) {
            $this->logger->warning('venne_search.crawler.fetch_failed', ['error' => $e->getMessage()]);
            $config = null;
        }

        $this->cache = ['config' => \is_array($config) ? $config : null, 'fetchedAt' => time()];
        return $config ?? $this->defaults();
    }

    /**
     * Crawler-Config auf der Plattform aktualisieren.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $apiKey = $this->settings->getPlatformApiKey();
        if ($apiKey === '') {
            throw new \RuntimeException('Kein Plattform-API-Key konfiguriert — speichere zuerst den API-Key.');
        }
        $url = $this->settings->getPlatformUrl() . self::ENDPOINT;
        $response = $this->httpRequest('PUT', $url, $apiKey, $payload);
        if (!$response['ok']) {
            $err = \is_array($response['body']) ? ($response['body']['error'] ?? 'unknown') : 'unknown';
            throw new \RuntimeException(\sprintf(
                'Plattform-Update fehlgeschlagen (HTTP %d, %s)',
                $response['status'],
                $err,
            ));
        }
        $this->cache = ['config' => $response['body'], 'fetchedAt' => time()];
        return $response['body'];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{ok:bool, status:int, body:array<string,mixed>|null}
     */
    private function httpRequest(string $method, string $url, string $bearerToken, ?array $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearerToken,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_TIMEOUT => (int) self::HTTP_TIMEOUT,
            \CURLOPT_CONNECTTIMEOUT => 5,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, \CURLOPT_POSTFIELDS, json_encode($body, \JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('curl: ' . $err);
        }
        $decoded = json_decode((string) $raw, true);
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => \is_array($decoded) ? $decoded : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'active' => false,
            'startUrls' => [],
            'allowedHosts' => [],
            'urlPatterns' => [],
            'excludePatterns' => [],
            'maxDepth' => 3,
            'maxPages' => 1000,
            'intervalDays' => 7,
            'respectRobotsTxt' => true,
            'lastRunAt' => null,
            'nextRunAt' => null,
            'lastRunStats' => null,
        ];
    }
}
