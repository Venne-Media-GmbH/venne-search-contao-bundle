<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Contao\System;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\CrawlerConfigClient;

/**
 * v2.2.0: Rendert das „Externer Crawler"-Panel in der Venne-Search-Settings-Maske.
 *
 * Liest aktuelle Config von der Plattform → rendert Form mit:
 *   - Aktiv-Checkbox
 *   - Start-URLs (Textarea, eine pro Zeile)
 *   - URL-Pattern-Whitelist (z.B. "*meldung=*")
 *   - Crawl-Intervall (Tage)
 *   - Max Tiefe / Max Pages
 *   - robots.txt respektieren
 *
 * Plus Status-Box: letzter Lauf, nächster Lauf, indexierte Pages.
 *
 * Formular wird über den Standard-DCA-Save-Hook verarbeitet. Bei Submit
 * wird die Config 1:1 an die Plattform-API geschickt.
 */
final class CrawlerPanelListener
{
    public function __construct(
        private readonly CrawlerConfigClient $client,
    ) {
    }

    public static function render(): string
    {
        try {
            $listener = System::getContainer()->get(self::class);
        } catch (\Throwable) {
            return '<p class="tl_help">Crawler-Panel konnte nicht geladen werden.</p>';
        }
        return $listener?->renderPanel() ?? '';
    }

    public function renderPanel(): string
    {
        // POST-Handling: nur die Aktiv-Checkbox wird hier umgeschaltet,
        // alles andere wird auf venne-search.de konfiguriert.
        $message = '';
        if (($_POST['vsearch_crawler_action'] ?? '') === 'toggle') {
            try {
                $this->client->save([
                    'active' => isset($_POST['crawler_active']),
                ]);
                $message = '<div style="padding:.6rem .9rem;background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:6px;margin-bottom:1rem;">Aktivierung gespeichert.</div>';
            } catch (\Throwable $e) {
                $message = '<div style="padding:.6rem .9rem;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:6px;margin-bottom:1rem;">Fehler: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }

        try {
            $config = $this->client->fetch(true);
        } catch (\Throwable $e) {
            return '<p class="tl_help" style="padding:1rem 1.2rem;">Crawler-Konfiguration aktuell nicht verfügbar: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        $active = (bool) ($config['active'] ?? false);
        $lastRunAt = $config['lastRunAt'] ?? null;
        $nextRunAt = $config['nextRunAt'] ?? null;
        $stats = $config['lastRunStats'] ?? null;

        $checkedActive = $active ? ' checked' : '';
        $statusHtml = $this->renderStatusBox($lastRunAt, $nextRunAt, $stats);

        return <<<HTML
<div class="widget clr" style="padding:1rem 1.2rem;">
    <h3 style="margin:0 0 .6rem 0;font-size:1rem;font-weight:600;">Externer Crawler</h3>
    <p class="tl_help" style="margin-top:0;margin-bottom:1rem;font-size:.85rem;line-height:1.5;color:#6b7280;white-space:normal;">
        Indexiert dynamische Detail-Seiten die nicht im Contao-Seitenbaum stehen (z.B. Custom-Catalog-URLs).
        Crawler läuft auf <strong>venne-search.de</strong> als Cron-Job und schreibt direkt in deinen Such-Index.
        Konfiguration (Start-URLs, Patterns, Intervall) auf <a href="https://venne-search.de" target="_blank">venne-search.de</a>.
    </p>

    {$message}
    {$statusHtml}

    <form method="post" action="" style="margin-top:1rem;">
        <input type="hidden" name="vsearch_crawler_action" value="toggle">
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500;">
            <input type="checkbox" name="crawler_active" value="1"{$checkedActive} onchange="this.form.submit()">
            Externes Crawling aktiviert
        </label>
    </form>
</div>
HTML;
    }

    /**
     * @param mixed $stats
     */
    private function renderStatusBox(?string $lastRunAt, ?string $nextRunAt, $stats): string
    {
        $lastFmt = $lastRunAt !== null ? date('d.m.Y H:i', strtotime($lastRunAt) ?: 0) : 'noch nie';
        $nextFmt = $nextRunAt !== null ? date('d.m.Y H:i', strtotime($nextRunAt) ?: 0) : '—';
        $indexed = \is_array($stats) ? (int) ($stats['pagesIndexed'] ?? 0) : 0;
        $seen = \is_array($stats) ? (int) ($stats['pagesSeen'] ?? 0) : 0;
        $errors = \is_array($stats) ? (int) ($stats['errors'] ?? 0) : 0;

        return <<<HTML
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.7rem;margin-bottom:1.2rem;max-width:100%;box-sizing:border-box;">
    <div style="padding:.7rem .9rem;border:1px solid #d1d5db;border-radius:6px;background:#f9fafb;min-width:0;">
        <div style="font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Letzter Lauf</div>
        <div style="font-size:1rem;font-weight:600;color:#1f2937;margin-top:.15rem;">{$lastFmt}</div>
    </div>
    <div style="padding:.7rem .9rem;border:1px solid #d1d5db;border-radius:6px;background:#f9fafb;min-width:0;">
        <div style="font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Nächster Lauf</div>
        <div style="font-size:1rem;font-weight:600;color:#1f2937;margin-top:.15rem;">{$nextFmt}</div>
    </div>
    <div style="padding:.7rem .9rem;border:1px solid #86efac;border-radius:6px;background:#f0fdf4;min-width:0;">
        <div style="font-size:.7rem;color:#15803d;text-transform:uppercase;letter-spacing:.04em;">Indexiert</div>
        <div style="font-size:1rem;font-weight:600;color:#15803d;margin-top:.15rem;">{$indexed} <span style="font-weight:400;color:#6b7280;font-size:.85rem;">/ {$seen}</span></div>
    </div>
    <div style="padding:.7rem .9rem;border:1px solid {$this->errorBorderColor($errors)};border-radius:6px;background:{$this->errorBg($errors)};min-width:0;">
        <div style="font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Fehler</div>
        <div style="font-size:1rem;font-weight:600;color:#1f2937;margin-top:.15rem;">{$errors}</div>
    </div>
</div>
HTML;
    }

    private function errorBorderColor(int $errors): string
    {
        return $errors > 0 ? '#fca5a5' : '#d1d5db';
    }

    private function errorBg(int $errors): string
    {
        return $errors > 0 ? '#fef2f2' : '#f9fafb';
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFormPayload(): array
    {
        $startUrls = $this->splitLines((string) ($_POST['crawler_start_urls'] ?? ''));
        $urlPatterns = $this->splitLines((string) ($_POST['crawler_url_patterns'] ?? ''));
        $excludePatterns = $this->splitLines((string) ($_POST['crawler_exclude_patterns'] ?? ''));

        return [
            'active' => isset($_POST['crawler_active']),
            'startUrls' => $startUrls,
            'urlPatterns' => $urlPatterns,
            'excludePatterns' => $excludePatterns,
            'maxDepth' => max(1, min(10, (int) ($_POST['crawler_max_depth'] ?? 3))),
            'maxPages' => max(10, min(10000, (int) ($_POST['crawler_max_pages'] ?? 1000))),
            'intervalDays' => max(1, min(30, (int) ($_POST['crawler_interval_days'] ?? 7))),
            'respectRobotsTxt' => isset($_POST['crawler_respect_robots']),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
