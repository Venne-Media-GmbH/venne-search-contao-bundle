<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Pdf;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * v2.2.0: Generiert pro PDF ein JPG-Thumbnail der ersten Seite.
 *
 * Wird vom IndexableItemProcessor während des Reindex-Laufs aufgerufen,
 * wenn das Setting `generate_pdf_thumbnails` aktiv ist. Die generierte
 * Thumbnail-URL landet im Meilisearch-Document als `cover_url` und wird
 * vom Frontend-Template als <img> gerendert.
 *
 * Strategie (erste die liefert gewinnt):
 *   1) Ghostscript via proc_open — schnell (~100-300ms), Standard auf
 *      Linux-Servern
 *   2) PHP-Imagick-Extension — Fallback wenn `gs` nicht installiert
 *   3) null zurück → kein Cover, Frontend rendert Icon
 *
 * Caching: Output landet unter files/_vsearch_covers/<sha1>.jpg. Der Name
 * leitet sich aus path+filemtime ab → wenn die PDF ersetzt wird, ändert
 * sich der Hash und das alte Cover bleibt liegen (Cleanup-Cron könnte
 * orphans später aufräumen, das machen wir hier explizit nicht).
 */
final class PdfThumbnailGenerator
{
    /**
     * Wo die Covers landen — relativ zum projectDir. Liegt unter files/
     * damit Contao sie als öffentliche Assets ausliefert.
     */
    public const COVER_DIR = 'files/_vsearch_covers';

    /** Render-Auflösung. 96 DPI ist gut für ~150px-Display, hat akzeptable Größe (~30-80KB). */
    private const RENDER_DPI = 96;

    /** Maximale Breite des Output-JPG in Pixeln. Größere PDFs (A2/A1) werden runterskaliert. */
    private const MAX_WIDTH = 400;

    /** JPEG-Qualität. 80 ist Standard-Balance zwischen Größe und Qualität. */
    private const JPEG_QUALITY = 80;

    /** Hard-Timeout für den Ghostscript-Aufruf — verhindert Hänger bei kaputten PDFs. */
    private const GS_TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Generiert (oder findet im Cache) ein Thumbnail-JPG der ersten PDF-Seite.
     *
     * @return string|null Pfad relativ zum projectDir (z.B. `files/_vsearch_covers/abc.jpg`)
     *                    oder null wenn weder Ghostscript noch Imagick verfügbar oder
     *                    die PDF kaputt/leer/Passwort-geschützt ist.
     */
    public function generate(string $absolutePdfPath, string $projectDir): ?string
    {
        if (!is_file($absolutePdfPath) || !is_readable($absolutePdfPath)) {
            return null;
        }

        $cacheKey = $this->computeCacheKey($absolutePdfPath);
        $relCachePath = self::COVER_DIR . '/' . $cacheKey . '.jpg';
        $absCachePath = rtrim($projectDir, '/') . '/' . $relCachePath;

        // Schon im Cache — fertig.
        if (is_file($absCachePath) && filesize($absCachePath) > 0) {
            return $relCachePath;
        }

        // Cache-Verzeichnis sicherstellen.
        $absCacheDir = \dirname($absCachePath);
        if (!is_dir($absCacheDir) && !@mkdir($absCacheDir, 0775, true) && !is_dir($absCacheDir)) {
            $this->logger->warning('venne_search.thumbnail.mkdir_failed', ['dir' => $absCacheDir]);
            return null;
        }

        // 1) Ghostscript-Versuch
        if ($this->tryGhostscript($absolutePdfPath, $absCachePath)) {
            return $relCachePath;
        }

        // 2) Imagick-Versuch
        if ($this->tryImagick($absolutePdfPath, $absCachePath)) {
            return $relCachePath;
        }

        return null;
    }

    /**
     * Erzeugt einen deterministischen Cache-Key aus Path + mtime. Wenn die
     * PDF ersetzt wird (gleicher Name, neuer Inhalt), ändert sich mtime →
     * neuer Hash → neues Cover wird generiert.
     */
    private function computeCacheKey(string $absolutePath): string
    {
        $mtime = @filemtime($absolutePath) ?: 0;
        return sha1($absolutePath . '|' . $mtime);
    }

    /**
     * Ruft `gs` via proc_open. Args:
     *   -dNOPAUSE -dBATCH -dSAFER       — keine Prompts, kein Filesystem-Escape
     *   -sDEVICE=jpeg                   — Output als JPEG
     *   -r<dpi>                          — Render-Auflösung
     *   -dFirstPage=1 -dLastPage=1      — nur erste Seite
     *   -dJPEGQ=<q>                     — JPEG-Qualität
     *   -sOutputFile=...                — Zielpfad
     *
     * Großer Vorteil ggü. Imagick: kein Speicher-Leak bei vielen PDFs in
     * Folge, deutlich stabiler.
     */
    private function tryGhostscript(string $absolutePdf, string $absOutJpg): bool
    {
        // proc_open verfügbar?
        if (!\function_exists('proc_open')) {
            return false;
        }
        // `gs`-Binary im PATH? — der `which`-Trick ist platform-unabhängig
        // genug für Linux/Mac. Auf Windows wäre's `gswin64c`, das skippen wir.
        $gsBin = $this->findGhostscriptBinary();
        if ($gsBin === null) {
            return false;
        }

        // Wichtig: temporäre Output-Datei, damit ein abgebrochener gs-Lauf
        // keine korrupte Datei im Cache hinterlässt.
        $tmpOut = $absOutJpg . '.tmp.' . bin2hex(random_bytes(4)) . '.jpg';

        $cmd = [
            $gsBin,
            '-dNOPAUSE', '-dBATCH', '-dSAFER', '-dQUIET',
            '-sDEVICE=jpeg',
            '-r' . self::RENDER_DPI,
            '-dFirstPage=1', '-dLastPage=1',
            '-dJPEGQ=' . self::JPEG_QUALITY,
            '-dMaxBitmap=500000000',
            '-sOutputFile=' . $tmpOut,
            $absolutePdf,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!\is_resource($process)) {
            return false;
        }

        // Stdin nicht benötigt — sofort schließen.
        fclose($pipes[0]);

        // Stdout + Stderr non-blocking lesen mit Timeout.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + self::GS_TIMEOUT_SECONDS;
        $stderrBuf = '';
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            $stderrBuf .= (string) stream_get_contents($pipes[2]);
            // Stdout nicht puffern — gs schreibt da eh nix Relevantes.
            stream_get_contents($pipes[1]);
            if (!$status['running']) {
                break;
            }
            usleep(50_000); // 50ms
        }
        $status = proc_get_status($process);
        if ($status['running']) {
            // Über Timeout — kill.
            @proc_terminate($process, 9);
            @unlink($tmpOut);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $this->logger->warning('venne_search.thumbnail.gs_timeout', ['pdf' => $absolutePdf]);
            return false;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_file($tmpOut) || filesize($tmpOut) === 0) {
            @unlink($tmpOut);
            $this->logger->info('venne_search.thumbnail.gs_failed', [
                'pdf' => $absolutePdf, 'exit' => $exitCode,
                'stderr' => mb_substr($stderrBuf, 0, 200),
            ]);
            return false;
        }

        // Postprocessing: wenn Bild breiter als MAX_WIDTH → runterskalieren
        // via PHP-GD (immer verfügbar im Contao-Stack), sonst lassen.
        $this->resizeIfNeeded($tmpOut);

        // Atomar verschieben.
        if (!@rename($tmpOut, $absOutJpg)) {
            @unlink($tmpOut);
            return false;
        }
        return true;
    }

    /**
     * Imagick-Fallback. Schwächer als Ghostscript (mehr Speicherbedarf,
     * gelegentliche Segfaults bei großen PDFs), aber wenn `gs` nicht da
     * ist, besser als gar kein Cover.
     */
    private function tryImagick(string $absolutePdf, string $absOutJpg): bool
    {
        if (!\class_exists(\Imagick::class)) {
            return false;
        }
        try {
            $im = new \Imagick();
            $im->setResolution(self::RENDER_DPI, self::RENDER_DPI);
            // [0] = erste Seite. Wichtig: VOR setResolution wäre falsch.
            $im->readImage($absolutePdf . '[0]');
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(self::JPEG_QUALITY);
            $im->setImageBackgroundColor('white');
            $im = $im->flattenImages();

            $w = $im->getImageWidth();
            if ($w > self::MAX_WIDTH) {
                $h = (int) round($im->getImageHeight() * (self::MAX_WIDTH / $w));
                $im->resizeImage(self::MAX_WIDTH, $h, \Imagick::FILTER_LANCZOS, 1);
            }

            $tmpOut = $absOutJpg . '.tmp.' . bin2hex(random_bytes(4)) . '.jpg';
            $im->writeImage($tmpOut);
            $im->clear();
            $im->destroy();

            if (!is_file($tmpOut) || filesize($tmpOut) === 0) {
                @unlink($tmpOut);
                return false;
            }
            if (!@rename($tmpOut, $absOutJpg)) {
                @unlink($tmpOut);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->info('venne_search.thumbnail.imagick_failed', [
                'pdf' => $absolutePdf, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Skaliert das JPEG via GD runter, falls breiter als MAX_WIDTH.
     * GD ist im Contao-Stack ohnehin Pflicht, also keine Extra-Abhängigkeit.
     */
    private function resizeIfNeeded(string $absJpgPath): void
    {
        if (!\function_exists('imagecreatefromjpeg')) {
            return;
        }
        $info = @getimagesize($absJpgPath);
        if (!\is_array($info)) {
            return;
        }
        [$w, $h] = $info;
        if ($w <= self::MAX_WIDTH) {
            return;
        }
        $newW = self::MAX_WIDTH;
        $newH = (int) round($h * ($newW / $w));

        $src = @imagecreatefromjpeg($absJpgPath);
        if ($src === false) {
            return;
        }
        $dst = imagecreatetruecolor($newW, $newH);
        // Weißer Hintergrund (PDFs ohne Hintergrund würden sonst schwarz).
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        @imagejpeg($dst, $absJpgPath, self::JPEG_QUALITY);
        imagedestroy($src);
        imagedestroy($dst);
    }

    /**
     * Versucht das gs-Binary zu finden. Erst via `which`, dann häufige
     * absolute Pfade. Kein Caching nötig — wird pro Reindex-Lauf ggf.
     * einmal ausgeführt.
     */
    private function findGhostscriptBinary(): ?string
    {
        $isWindows = \DIRECTORY_SEPARATOR === '\\';

        // Verbreitete absolute Pfade pro Plattform.
        $candidates = $isWindows
            ? [
                'C:\\Program Files\\gs\\gs10.07.1\\bin\\gswin64c.exe',
                'C:\\Program Files\\gs\\gs10.07\\bin\\gswin64c.exe',
                'C:\\Program Files (x86)\\gs\\bin\\gswin32c.exe',
            ]
            : [
                '/usr/bin/gs',
                '/usr/local/bin/gs',
                '/opt/homebrew/bin/gs',
            ];
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        // Fallback: PATH-Lookup via where/command -v.
        if (\function_exists('shell_exec')) {
            $cmd = $isWindows ? 'where gs 2>nul' : 'command -v gs 2>/dev/null';
            $found = @shell_exec($cmd);
            if (\is_string($found)) {
                // `where` kann mehrere Zeilen liefern — erste nehmen.
                $lines = preg_split('/\r?\n/', trim($found)) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '' && is_executable($line)) {
                        return $line;
                    }
                }
            }
            // Auf Windows zusätzlich gswin64c (typischer Konsolen-Name)
            if ($isWindows) {
                $found = @shell_exec('where gswin64c 2>nul');
                if (\is_string($found)) {
                    $line = trim(preg_split('/\r?\n/', trim($found))[0] ?? '');
                    if ($line !== '' && is_executable($line)) {
                        return $line;
                    }
                }
            }
        }
        return null;
    }
}
