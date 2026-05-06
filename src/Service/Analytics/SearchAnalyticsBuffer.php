<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Analytics;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\KernelInterface;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Append-only Puffer für Such-Events. Pro Tag eine JSONL-Datei in
 *   var/cache/venne-search/analytics/<YYYY-MM-DD>.jsonl
 *
 * Schreiben muss schnell + crash-safe sein, damit der Frontend-Search-Pfad
 * keine Latenz spürt. Wir nutzen file_put_contents(LOCK_EX) — schreibt einen
 * einzelnen JSONL-Record pro Aufruf. Bei IO-Fehlern: still failen, niemals
 * den Such-Request crashen.
 *
 * Ein Cron-Worker (Command venne-search:analytics:flush) ruft danach
 * regelmäßig ab und schickt alles per POST an die Plattform.
 */
final class SearchAnalyticsBuffer
{
    public const RELATIVE_DIR = 'var/cache/venne-search/analytics';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly KernelInterface $kernel,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Schreibt ein Such-Event in den Tagespuffer. No-op wenn:
     *   - Bundle nicht konfiguriert
     *   - Analytics-Toggle aus
     *   - Query zu kurz (< 2 Zeichen) — sehr kurze Tippvorgänge sind kein Signal
     */
    public function record(string $query, string $locale, int $resultCount): void
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return;
        }

        try {
            if (!$this->settings->isConfigured()) {
                return;
            }
            $config = $this->settings->load();
            if (!$config->analyticsEnabled) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $event = [
            'q' => mb_substr($query, 0, 255),
            'locale' => mb_substr(strtolower($locale), 0, 8),
            'results' => max(0, $resultCount),
            'ts' => time(),
        ];

        try {
            $dir = $this->bufferDir();
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
            $file = $dir . '/' . date('Y-m-d') . '.jsonl';
            $line = json_encode($event, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . "\n";
            @file_put_contents($file, $line, \FILE_APPEND | \LOCK_EX);
        } catch (\Throwable $e) {
            // Niemals den Such-Pfad killen.
            $this->logger->warning('venne_search.analytics.buffer_write_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Statistiken für das Backend-Status-Panel.
     *
     * @return array{
     *   totalEvents:int,
     *   pendingFiles:int,
     *   currentFileSize:int,
     *   lastFlushAt:?int,
     *   failedFiles:int
     * }
     */
    public function stats(): array
    {
        $dir = $this->bufferDir();
        if (!is_dir($dir)) {
            return [
                'totalEvents' => 0,
                'pendingFiles' => 0,
                'currentFileSize' => 0,
                'lastFlushAt' => null,
                'failedFiles' => 0,
            ];
        }

        $pending = 0;
        $totalEvents = 0;
        $currentFileSize = 0;
        $today = date('Y-m-d');

        foreach (glob($dir . '/*.jsonl') ?: [] as $file) {
            $base = basename($file);
            ++$pending;
            $size = filesize($file) ?: 0;
            // Schnelle Event-Schätzung: durchschnittlich ~80 Bytes pro Zeile.
            // Genaue Zählung bei Bedarf via wc -l, aber für UI reicht es.
            if ($base === $today . '.jsonl') {
                $currentFileSize = $size;
            }
            $totalEvents += $size > 0 ? (int) max(1, round($size / 80)) : 0;
        }

        $lastFlushAt = null;
        $flushedDir = $dir . '/flushed';
        if (is_dir($flushedDir)) {
            $latest = 0;
            foreach (glob($flushedDir . '/*.jsonl') ?: [] as $f) {
                $latest = max($latest, (int) filemtime($f));
            }
            $lastFlushAt = $latest > 0 ? $latest : null;
        }

        $failed = 0;
        $failedDir = $dir . '/failed';
        if (is_dir($failedDir)) {
            $failed = \count(glob($failedDir . '/*.jsonl') ?: []);
        }

        return [
            'totalEvents' => $totalEvents,
            'pendingFiles' => $pending,
            'currentFileSize' => $currentFileSize,
            'lastFlushAt' => $lastFlushAt,
            'failedFiles' => $failed,
        ];
    }

    public function bufferDir(): string
    {
        return rtrim($this->kernel->getProjectDir(), '/') . '/' . self::RELATIVE_DIR;
    }
}
