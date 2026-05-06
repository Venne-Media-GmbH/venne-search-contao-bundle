<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Analytics;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Verschickt jedes Such-Event sofort an venne-search.de — ohne Buffer,
 * ohne Cron, ohne Backend-Button. Endkunden sehen nichts davon, das passiert
 * komplett unsichtbar im Hintergrund.
 *
 * Defensiv:
 *   - Aufruf darf den Such-Request NIEMALS blockieren oder crashen lassen.
 *   - Timeout 2 Sekunden (Connect+Total).
 *   - Bei Network-Fehler: stiller Log, weitermachen.
 *   - Wenn Bundle nicht konfiguriert / Analytics deaktiviert / Query zu kurz → no-op.
 *
 * Klassenname bleibt "SearchAnalyticsBuffer" für Backwards-Compat im DI-Container.
 */
final class SearchAnalyticsBuffer
{
    public const DEFAULT_PLATFORM_URL = 'https://venne-search.de';
    private const TIMEOUT_SECONDS = 2.0;

    private HttpClientInterface $http;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create([
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
        ]);
    }

    /**
     * Schickt EIN Such-Event sofort an die Plattform.
     *
     * No-op wenn:
     *   - Bundle nicht konfiguriert
     *   - Analytics-Toggle aus
     *   - Query unter 2 Zeichen (kein sinnvolles Signal)
     *   - Netz-Fehler / Timeout (still fail, Such-Request läuft trotzdem durch)
     */
    public function record(string $query, string $locale, int $resultCount): void
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return;
        }

        $apiKey = '';
        $platformUrl = self::DEFAULT_PLATFORM_URL;

        try {
            if (!$this->settings->isConfigured()) {
                return;
            }
            $config = $this->settings->load();
            if (!$config->analyticsEnabled) {
                return;
            }
            $apiKey = $this->settings->getPlatformApiKey();
            $platformUrl = $this->settings->getPlatformUrl() ?: self::DEFAULT_PLATFORM_URL;
        } catch (\Throwable) {
            return;
        }

        if ($apiKey === '') {
            return;
        }

        $endpoint = rtrim($platformUrl, '/') . '/api/v1/analytics/search-events';

        $event = [
            'q' => mb_substr($query, 0, 255),
            'locale' => mb_substr(strtolower($locale), 0, 8),
            'results' => max(0, $resultCount),
            'ts' => time(),
        ];

        try {
            $this->http->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => ['events' => [$event]],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
            ])->getStatusCode(); // forciert den Round-Trip im selben Request — sonst lazy.
        } catch (\Throwable $e) {
            // Niemals den Such-Pfad killen.
            $this->logger->info('venne_search.analytics.send_failed', [
                'error' => substr($e->getMessage(), 0, 200),
            ]);
        }
    }
}
