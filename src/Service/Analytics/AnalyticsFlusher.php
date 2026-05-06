<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Analytics;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Flushed JSONL-Tagespuffer an die Plattform.
 *
 * Strategie:
 *   1. Jede *.jsonl-Datei AUSSER der für heute (die ist evtl. noch in use)
 *      wird aufgesammelt.
 *   2. Pro Datei: bis zu 200 Events pro POST-Batch an
 *      `<platformUrl>/api/v1/analytics/search-events`.
 *   3. 200 → in `flushed/` rotiert (mit Timestamp im Namen).
 *      4xx (Auth, Validation, Subscription) → in `failed/` verschoben.
 *      5xx / Network → Datei behalten, retry beim nächsten Lauf.
 */
final class AnalyticsFlusher
{
    public const BATCH_SIZE = 200;
    public const DEFAULT_PLATFORM_URL = 'https://venne-search.de';

    private HttpClientInterface $http;

    public function __construct(
        private readonly SearchAnalyticsBuffer $buffer,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create([
            'timeout' => 10.0,
            'max_duration' => 30.0,
        ]);
    }

    /**
     * @return array{
     *   processedFiles:int,
     *   processedEvents:int,
     *   failedFiles:int,
     *   skippedFiles:int,
     *   errors:list<string>
     * }
     */
    public function flush(bool $keepFlushed = false): array
    {
        $result = [
            'processedFiles' => 0,
            'processedEvents' => 0,
            'failedFiles' => 0,
            'skippedFiles' => 0,
            'errors' => [],
        ];

        if (!$this->settings->isConfigured()) {
            $result['errors'][] = 'not_configured';
            return $result;
        }

        $apiKey = $this->settings->getPlatformApiKey();
        if ($apiKey === '') {
            $result['errors'][] = 'no_api_key';
            return $result;
        }

        $platformUrl = $this->resolvePlatformUrl();
        $endpoint = rtrim($platformUrl, '/') . '/api/v1/analytics/search-events';

        $dir = $this->buffer->bufferDir();
        if (!is_dir($dir)) {
            return $result;
        }

        $files = glob($dir . '/*.jsonl') ?: [];
        $today = date('Y-m-d');
        $flushedDir = $dir . '/flushed';
        $failedDir = $dir . '/failed';

        foreach ($files as $file) {
            $base = basename($file);
            if ($base === $today . '.jsonl') {
                // Heute-Datei wird noch beschrieben → nicht anfassen.
                ++$result['skippedFiles'];
                continue;
            }

            $events = $this->readEvents($file);
            if ($events === []) {
                @unlink($file);
                continue;
            }

            $allOk = true;
            foreach (array_chunk($events, self::BATCH_SIZE) as $batch) {
                $sendResult = $this->postBatch($endpoint, $apiKey, $batch);
                if ($sendResult['ok']) {
                    $result['processedEvents'] += $sendResult['stored'];
                } else {
                    $allOk = false;
                    if ($sendResult['fatal']) {
                        // 4xx → Datei nach failed/ verschieben.
                        $this->ensureDir($failedDir);
                        $target = $failedDir . '/' . pathinfo($base, \PATHINFO_FILENAME)
                            . '_' . date('Ymd_His') . '.jsonl';
                        @rename($file, $target);
                        ++$result['failedFiles'];
                        $result['errors'][] = sprintf('%s → %s', $base, $sendResult['error']);
                        break 1;
                    }
                    // 5xx / Network → Datei dranlassen, nächster Lauf retried.
                    $result['errors'][] = sprintf('%s → retry: %s', $base, $sendResult['error']);
                    break 1;
                }
            }

            if ($allOk) {
                if ($keepFlushed) {
                    $this->ensureDir($flushedDir);
                    @rename($file, $flushedDir . '/' . pathinfo($base, \PATHINFO_FILENAME)
                        . '_' . date('Ymd_His') . '.jsonl');
                } else {
                    @unlink($file);
                }
                ++$result['processedFiles'];
            }
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEvents(string $file): array
    {
        $events = [];
        $fp = @fopen($file, 'rb');
        if (!\is_resource($fp)) {
            return [];
        }
        try {
            while (($line = fgets($fp)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $event = json_decode($line, true);
                if (\is_array($event) && isset($event['q'], $event['locale'])) {
                    $events[] = $event;
                }
            }
        } finally {
            fclose($fp);
        }
        return $events;
    }

    /**
     * @param list<array<string, mixed>> $batch
     * @return array{ok:bool, fatal:bool, stored:int, error:string}
     */
    private function postBatch(string $endpoint, string $apiKey, array $batch): array
    {
        try {
            $response = $this->http->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => ['events' => $batch],
            ]);
            $status = $response->getStatusCode();
            if ($status === 200) {
                $body = $response->toArray(false);
                return ['ok' => true, 'fatal' => false, 'stored' => (int) ($body['stored'] ?? 0), 'error' => ''];
            }
            // 4xx ist fatal (Auth/Validation/Subscription) — Datei nach failed/.
            // 5xx und 429 sind transient — retry.
            $fatal = $status >= 400 && $status < 500 && $status !== 429;
            return ['ok' => false, 'fatal' => $fatal, 'stored' => 0, 'error' => 'http_' . $status];
        } catch (\Throwable $e) {
            // Network/Timeout → transient.
            return ['ok' => false, 'fatal' => false, 'stored' => 0, 'error' => 'network: ' . substr($e->getMessage(), 0, 100)];
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function resolvePlatformUrl(): string
    {
        try {
            return $this->settings->getPlatformUrl();
        } catch (\Throwable) {
            return self::DEFAULT_PLATFORM_URL;
        }
    }
}
