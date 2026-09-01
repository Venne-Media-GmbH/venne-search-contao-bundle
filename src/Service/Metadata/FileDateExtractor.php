<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Metadata;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * v2.2.0: Liest das "Erstellungsdatum" aus den Datei-Metadaten — pro Dateityp
 * mit eigener Strategie. Fällt auf filemtime / filectime zurück wenn nichts
 * Sinnvolles gefunden wurde.
 *
 * Strategie (Reihenfolge pro Datei):
 *   PDF  → Smalot\PdfParser->getDetails()['CreationDate']
 *   DOCX → docProps/core.xml → <dcterms:created>
 *   ODT  → meta.xml → <meta:creation-date>
 *   JPG/TIFF → EXIF DateTimeOriginal (falls ext-exif)
 *   PNG  → tEXt/iTXt Chunk "Creation Time"
 *   TXT/MD → keine Metadaten — sofort Fallback
 *   Sonst → keine Metadaten — sofort Fallback
 *
 * Fallback-Kette wenn Metadaten leer:
 *   1) filectime() — auf Windows == Creation-Time (ctime), auf Linux == inode-change.
 *      Auf Linux daher schlechter, deshalb max(filectime, filemtime) als Best-Effort.
 *   2) 0 wenn die Datei nicht lesbar ist (sollte nicht passieren).
 */
final class FileDateExtractor
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Liefert das ermittelte Erstellungsdatum als Unix-Timestamp.
     * 0 wenn nichts ermittelt werden konnte.
     */
    public function extract(string $absolutePath, string $extension): int
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return 0;
        }

        $extension = strtolower($extension);

        // Metadaten-Strategien pro Format.
        $ts = match ($extension) {
            'pdf' => $this->fromPdf($absolutePath),
            'docx', 'doc' => $this->fromOfficeDocx($absolutePath),
            'odt' => $this->fromOdt($absolutePath),
            'xlsx', 'pptx' => $this->fromOfficeDocx($absolutePath),
            'jpg', 'jpeg', 'tiff', 'tif' => $this->fromExif($absolutePath),
            'png' => $this->fromPngTextChunks($absolutePath),
            default => 0,
        };

        if ($ts > 0 && $this->isPlausible($ts)) {
            return $ts;
        }

        // Filesystem-Fallback: filectime ist auf Windows == Creation-Time.
        // Auf Linux ist es inode-change — wir nehmen das Minimum von ctime/mtime
        // damit Replace-Operationen das Datum nicht ohne Not nach vorn schieben.
        $ctime = @filectime($absolutePath) ?: 0;
        $mtime = @filemtime($absolutePath) ?: 0;
        if ($ctime > 0 && $mtime > 0) {
            return min($ctime, $mtime);
        }
        return $ctime ?: $mtime ?: 0;
    }

    /**
     * PDF: smalot/pdfparser liefert getDetails()['CreationDate'] als String
     * im PDF-Format "D:20240315120000+02'00'". Wir parsen das raus.
     */
    private function fromPdf(string $absolutePath): int
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($absolutePath);
            $details = $pdf->getDetails();
            $raw = $details['CreationDate'] ?? ($details['ModDate'] ?? null);
            if (!\is_string($raw) || $raw === '') {
                return 0;
            }
            return $this->parsePdfDate($raw);
        } catch (\Throwable $e) {
            $this->logger->debug('venne_search.file_date.pdf_failed', [
                'file' => $absolutePath, 'err' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Parst das Datum-Feld von PDF-Metadaten. smalot/pdfparser liefert
     * inzwischen ISO 8601 ("2024-03-15T10:00:00+00:00"), aber legacy PDFs
     * können auch das alte PDF-Format "D:YYYYMMDDHHmmSSOHH'mm'" liefern.
     * Wir versuchen ISO zuerst, dann das alte Format.
     */
    private function parsePdfDate(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        // ISO 8601 oder ähnlich? strtotime kommt damit klar.
        if (str_contains($raw, '-') || str_contains($raw, 'T')) {
            $ts = strtotime($raw);
            if ($ts !== false && $ts > 0) {
                return $ts;
            }
        }
        // Altes PDF-Format: "D:20240315120000+02'00'" — D: ist optional.
        $stripped = ltrim($raw, 'D:');
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:(\d{2})(\d{2})(\d{2}))?/', $stripped, $m)) {
            return 0;
        }
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
        $h = (int) ($m[4] ?? 0); $mi = (int) ($m[5] ?? 0); $s = (int) ($m[6] ?? 0);
        if ($y < 1970 || $y > 2100 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return 0;
        }
        $ts = mktime($h, $mi, $s, $mo, $d, $y);
        return \is_int($ts) ? $ts : 0;
    }

    /**
     * DOCX/XLSX/PPTX sind ZIP-Archive mit `docProps/core.xml`.
     * Beispiel-Eintrag: <dcterms:created xsi:type="dcterms:W3CDTF">2024-03-15T10:00:00Z</dcterms:created>
     */
    private function fromOfficeDocx(string $absolutePath): int
    {
        if (!\class_exists(\ZipArchive::class)) {
            return 0;
        }
        $zip = new \ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return 0;
        }
        $xml = $zip->getFromName('docProps/core.xml');
        $zip->close();
        if (!\is_string($xml) || $xml === '') {
            return 0;
        }
        // dcterms:created bevorzugt, sonst dcterms:modified
        if (preg_match('/<dcterms:created[^>]*>([^<]+)<\/dcterms:created>/', $xml, $m)
            || preg_match('/<dcterms:modified[^>]*>([^<]+)<\/dcterms:modified>/', $xml, $m)) {
            $ts = strtotime(trim($m[1]));
            return $ts !== false ? $ts : 0;
        }
        return 0;
    }

    /**
     * ODT ist ein ZIP mit `meta.xml`.
     * Beispiel: <meta:creation-date>2024-03-15T10:00:00</meta:creation-date>
     */
    private function fromOdt(string $absolutePath): int
    {
        if (!\class_exists(\ZipArchive::class)) {
            return 0;
        }
        $zip = new \ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return 0;
        }
        $xml = $zip->getFromName('meta.xml');
        $zip->close();
        if (!\is_string($xml) || $xml === '') {
            return 0;
        }
        if (preg_match('/<meta:creation-date[^>]*>([^<]+)<\/meta:creation-date>/', $xml, $m)
            || preg_match('/<dc:date[^>]*>([^<]+)<\/dc:date>/', $xml, $m)) {
            $ts = strtotime(trim($m[1]));
            return $ts !== false ? $ts : 0;
        }
        return 0;
    }

    /**
     * EXIF DateTimeOriginal für JPG/TIFF — Format "YYYY:MM:DD HH:MM:SS".
     */
    private function fromExif(string $absolutePath): int
    {
        if (!\function_exists('exif_read_data')) {
            return 0;
        }
        try {
            $exif = @exif_read_data($absolutePath, 'EXIF', true);
        } catch (\Throwable) {
            return 0;
        }
        if (!\is_array($exif)) {
            return 0;
        }
        $candidates = [
            $exif['EXIF']['DateTimeOriginal'] ?? null,
            $exif['EXIF']['DateTimeDigitized'] ?? null,
            $exif['IFD0']['DateTime'] ?? null,
        ];
        foreach ($candidates as $raw) {
            if (!\is_string($raw) || $raw === '') {
                continue;
            }
            // EXIF nutzt "YYYY:MM:DD HH:MM:SS" — strtotime mag das nicht direkt.
            $normalized = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $raw);
            $ts = strtotime((string) $normalized);
            if ($ts !== false && $ts > 0) {
                return $ts;
            }
        }
        return 0;
    }

    /**
     * PNG: tEXt/iTXt-Chunks können "Creation Time"-Schlüssel enthalten.
     * Wir scannen den File-Header (max 4KB) ohne externe Lib.
     */
    private function fromPngTextChunks(string $absolutePath): int
    {
        $fh = @fopen($absolutePath, 'rb');
        if (!$fh) {
            return 0;
        }
        // PNG-Signatur prüfen
        $sig = fread($fh, 8);
        if ($sig !== "\x89PNG\r\n\x1a\n") {
            fclose($fh);
            return 0;
        }
        $found = '';
        // Max 20 Chunks oder 64KB durchscannen
        for ($i = 0; $i < 20; $i++) {
            $header = fread($fh, 8);
            if (\strlen($header) < 8) {
                break;
            }
            $unpacked = unpack('Nlen/a4type', $header);
            if (!\is_array($unpacked)) {
                break;
            }
            $len = $unpacked['len'];
            $type = $unpacked['type'];
            if ($type === 'IEND') {
                break;
            }
            $data = $len > 0 ? fread($fh, min($len, 65536)) : '';
            fread($fh, 4); // CRC
            if (($type === 'tEXt' || $type === 'iTXt') && \is_string($data)) {
                $nul = strpos($data, "\0");
                if ($nul !== false) {
                    $key = substr($data, 0, $nul);
                    if (stripos($key, 'Creation') !== false || stripos($key, 'Date') !== false) {
                        $found = trim((string) substr($data, $nul + 1));
                        // bei iTXt sind weitere NUL-Trennzeichen drin — letzten Wert nehmen
                        $found = trim(substr($found, strrpos($found, "\0") !== false
                            ? (int) strrpos($found, "\0") + 1
                            : 0));
                        if ($found !== '') {
                            break;
                        }
                    }
                }
            }
            // Überspringe Chunks die größer als unser Read-Limit sind
            if ($len > 65536) {
                fseek($fh, $len - 65536, SEEK_CUR);
            }
        }
        fclose($fh);
        if ($found === '') {
            return 0;
        }
        $ts = strtotime($found);
        return $ts !== false ? $ts : 0;
    }

    /**
     * Sanity-Check: Datum muss zwischen 1990 und in 1 Jahr in der Zukunft liegen.
     * Verhindert dass kaputte Metadaten (z.B. 1601-01-01 oder 9999-12-31)
     * die Date-Sortierung kaputtmachen.
     */
    private function isPlausible(int $ts): bool
    {
        return $ts > 631152000 /* 1990-01-01 */ && $ts < time() + 31536000 /* +1 Jahr */;
    }
}
