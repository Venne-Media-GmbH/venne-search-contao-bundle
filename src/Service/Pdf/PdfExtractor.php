<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Pdf;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Smalot\PdfParser\Parser;

/**
 * Extrahiert Text aus PDFs.
 *
 * Sicherheits-Grenzen:
 *   - Hard-Cap auf {@see $maxFileSizeMb} (Settings-Limit, default 50 MB)
 *   - Wallclock-Watchdog: pdfparser kann bei kaputten/komplexen PDFs in
 *     Endlos-Schleifen geraten. Mit pcntl_alarm() (CLI/SAPI mit pcntl)
 *     brechen wir nach {@see PARSE_TIMEOUT_SECONDS} ab; ohne pcntl
 *     dient set_time_limit() als Soft-Backstop. Bei Timeout liefert die
 *     Methode einen Skip-Reason — der Reindex-Lauf macht ungestört weiter.
 *   - Memory-Reset nach Parse, weil pdfparser interne Objektgraphen aufbaut
 *     die einige hundert MB peak halten können.
 */
final class PdfExtractor
{
    /**
     * Hartes Timeout für eine einzelne PDF-Extraktion. Mit Margin unter dem
     * Plesk-30s-PHP-Limit, damit ein blockendes PDF nicht den ganzen
     * Reindex-Stream killt.
     */
    private const PARSE_TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function extract(string $absolutePath, int $maxFileSizeMb = 50): PdfExtractionResult
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return new PdfExtractionResult('', 'file_not_readable');
        }

        $sizeBytes = filesize($absolutePath);
        if ($sizeBytes === false) {
            return new PdfExtractionResult('', 'file_size_unknown');
        }

        $maxBytes = $maxFileSizeMb * 1024 * 1024;
        if ($sizeBytes > $maxBytes) {
            return new PdfExtractionResult('', \sprintf('file_too_large_%d_mb', (int) ($sizeBytes / 1024 / 1024)));
        }

        // PRE-FLIGHT-CHECKS — sparen sich teuren pdfparser-Aufruf bei
        // hoffnungslosen Dateien (verschlüsselt, kaputt, zu komplex).
        //
        // Wir lesen die ersten 64 KB + letzten 64 KB, das deckt Header,
        // Catalog, Encrypt-Dict + Trailer ab. <2ms vs 1-15s pdfparser.
        $headBytes = (int) min(65536, $sizeBytes);
        $head = @file_get_contents($absolutePath, false, null, 0, $headBytes);
        $tail = '';
        if ($sizeBytes > $headBytes) {
            $tailBytes = (int) min(65536, $sizeBytes - $headBytes);
            $tail = (string) @file_get_contents($absolutePath, false, null, $sizeBytes - $tailBytes, $tailBytes);
        }

        if (!\is_string($head) || $head === '') {
            return new PdfExtractionResult('', 'file_unreadable');
        }

        // 1. Gültiger PDF-Header? Wenn nicht → defekt, gar nicht erst probieren.
        if (!str_starts_with(ltrim($head), '%PDF-') && !str_contains(substr($head, 0, 1024), '%PDF-')) {
            return new PdfExtractionResult('', 'pdf_header_missing');
        }

        // Den Encrypt-Pre-Flight haben wir bewusst rausgenommen: viele PDFs
        // (z.B. von Acrobat exportierte Behörden-Dokumente) tragen ein
        // Encrypt-Dict ohne User-Passwort — nur als Permission-Restriction
        // ("Kopieren verboten"). Smalot wirft dort eine echte Exception
        // "Secured pdf file are currently not supported", die wir unten
        // sauber als Skip übersetzen. So bleibt der Reason ehrlich
        // ("verschlüsselt / nicht unterstützt") statt fälschlich
        // "passwortgeschützt" zu behaupten.
        $combined = $head . $tail;

        // 3. PDF muss EOF-Marker haben — sonst ist sie abgeschnitten/kaputt.
        if (!str_contains($tail !== '' ? $tail : $head, '%%EOF')) {
            return new PdfExtractionResult('', 'pdf_eof_missing_corrupt');
        }

        // 4. Komplexitäts-Heuristik: pdfparser sprengt bei vielen Stream-
        // Objekten den Memory. Wir schätzen anhand Datei-Größe + Stream-
        // Marker-Dichte. Bei >2 MB UND viele Streams → Skip mit klarem Reason.
        if ($sizeBytes > 2 * 1024 * 1024) {
            // Counted occurrences of "/Length " im Sample (Indikator für
            // Stream-Objekte). Pro MB realistisch: 50–200 Streams. Über
            // 1500 in nur 128 KB Sample = extrem komplex / scan-PDF.
            $streamMarkers = substr_count($combined, '/Length ');
            if ($streamMarkers > 1500) {
                return new PdfExtractionResult('', 'pdf_too_complex_skipped');
            }
        }

        unset($head, $tail, $combined);

        // pdfparser baut interne Objektgraphen die je MB PDF-Größe schnell
        // ein Vielfaches an Memory brauchen. Empirisch: ~80–150x bei
        // grafiklastigen Reports, deshalb großzügig kalkulieren.
        $sizeMb = (int) ceil($sizeBytes / 1024 / 1024);
        $needMb = max(256, $sizeMb * 120);
        $previousMemoryLimit = \ini_get('memory_limit');

        // Hart-Cap: wenn wir mehr als 1.25 GB bräuchten, gar nicht erst
        // probieren. PHP-Fatal "Allowed memory exhausted" ist KEINE
        // catchbare Exception — der Shutdown-Handler kommt manchmal zu spät,
        // weil Symfony seinen eigenen Handler vorher hat. Lieber sauber
        // skippen, dann sieht der User einen klaren Skip-Reason statt
        // einer 500-HTML-Fehlerseite im Reindex-Log.
        if ($needMb > 1280) {
            return new PdfExtractionResult('', \sprintf('pdf_too_big_for_memory_%d_mb', $sizeMb));
        }

        @\ini_set('memory_limit', $needMb . 'M');

        // ─── Wallclock-Watchdog ──────────────────────────────────────────
        $usePcntl = \extension_loaded('pcntl') && \function_exists('pcntl_alarm') && \function_exists('pcntl_signal');
        $previousAlarmHandler = null;
        $previousTimeLimit = (int) \ini_get('max_execution_time');
        $extractStarted = microtime(true);
        $parser = null;
        $pdf = null;

        if ($usePcntl) {
            $previousAlarmHandler = \pcntl_signal_get_handler(\SIGALRM);
            \pcntl_signal(\SIGALRM, static function () {
                throw new PdfTimeoutException('pdf_parse_timeout');
            });
            \pcntl_alarm(self::PARSE_TIMEOUT_SECONDS);
        } else {
            // Soft-Backstop, greift in vielen FPM-Setups nicht zuverlässig.
            @\set_time_limit(self::PARSE_TIMEOUT_SECONDS + 5);
        }

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);
            $text = $pdf->getText();
        } catch (PdfTimeoutException) {
            $this->logger->warning('venne_search.pdf.timeout', [
                'path' => $absolutePath,
                'elapsed_ms' => (int) ((microtime(true) - $extractStarted) * 1000),
            ]);

            return new PdfExtractionResult('', 'parse_timeout_after_' . self::PARSE_TIMEOUT_SECONDS . 's');
        } catch (\Throwable $e) {
            $this->logger->warning('venne_search.pdf.parse_failed', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            // Smalot wirft "Secured pdf file are currently not supported"
            // sowohl bei wirklich passwortgeschützten PDFs als auch bei
            // PDFs mit reiner Permission-Restriction (z.B. "Kopieren
            // verboten"). Wir kennzeichnen das als eigenen Skip-Reason —
            // das hilft bei der UI-Anzeige (klare Fehlermeldung) und
            // verhindert, dass der Lauf eine Retry-Schleife dreht.
            $msg = $e->getMessage();
            if (stripos($msg, 'secured pdf') !== false) {
                return new PdfExtractionResult('', 'pdf_encrypted_or_restricted');
            }

            return new PdfExtractionResult('', 'parse_failed:' . substr($msg, 0, 80));
        } finally {
            if ($usePcntl) {
                \pcntl_alarm(0);
                if ($previousAlarmHandler !== null) {
                    \pcntl_signal(\SIGALRM, $previousAlarmHandler);
                }
            } else {
                @\set_time_limit($previousTimeLimit);
            }
            // Memory-Limit zurück
            if (\is_string($previousMemoryLimit) && $previousMemoryLimit !== '') {
                @\ini_set('memory_limit', $previousMemoryLimit);
            }
            unset($parser, $pdf);
            \gc_collect_cycles();
        }

        $text = trim($text);
        if ($text === '') {
            return new PdfExtractionResult('', 'empty_textlayer_image_only');
        }

        return new PdfExtractionResult($text, null);
    }
}

/**
 * Ergebnis der PDF-Extraktion: entweder Text oder Skip-Grund.
 */
final class PdfExtractionResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $skipReason,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->skipReason === null && $this->text !== '';
    }
}

/**
 * Wird vom SIGALRM-Handler geworfen — vom PdfExtractor::extract() abgefangen.
 */
final class PdfTimeoutException extends \RuntimeException
{
}
