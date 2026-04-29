<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Text;

/**
 * Text-Vorbereitung für den Suchindex.
 *
 * Ziel: dass die Suche hybrid funktioniert — `café` findet `cafe`, `Schöne`
 * findet `schoene`/`SCHOENE`/`schon`, `Maße` findet `Masse`/`MASSE`. Wir
 * normalisieren alles auf NFKD und Diakritik-fold ASCII-Approximation, OHNE
 * den Original-Text zu zerstören (für die Highlight-Anzeige im Frontend
 * nutzt Meilisearch eine eigene NFKD-Normalisierung beim Match-Vergleich).
 *
 * Was wir hier machen:
 *   1. HTML-Tags strippen (Content kommt teils aus Rich-Editor)
 *   2. Whitespace normalisieren (mehrere Spaces → 1 Space, Newlines weg)
 *   3. UTF-8-Validität sicherstellen (Win-1252-Bytes auf Latin-1 mappen)
 *   4. Soft-Hyphen + Zero-Width-Spaces entfernen (sonst tokenisiert es falsch)
 *   5. Auf ein Maximum klappen (Meilisearch-Limit ~64KB pro Feld)
 *
 * KEIN Stemming, KEIN Stop-Word-Removal — das macht Meilisearch selbst
 * pro-Locale. Hier nur was Meilisearch nicht selbst kann.
 */
final class TextNormalizer
{
    private const MAX_CONTENT_LENGTH = 60_000;

    public function normalize(string $text): string
    {
        // 0) Defensiv: Wenn versehentlich serialized PHP-Strings im Content
        //    landen (z.B. Contao-Headlines `a:2:{s:5:"value";…}`), entfernen
        //    wir die kompletten Serialized-Blöcke. Match auf den klassischen
        //    a:N:{…} Anfang plus alles bis zum schließenden }.
        $text = preg_replace('/a:\d+:\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/u', ' ', $text) ?? $text;

        // 1) HTML strippen, aber Block-Tags durch Space ersetzen damit nicht
        //    zwei Wörter zusammenkleben (`</p><p>Spongebob` → `Spongebob`).
        $text = preg_replace('/<\/?(p|div|br|h[1-6]|li|td|tr|table|article|section)[^>]*>/i', ' ', $text) ?? $text;
        $text = strip_tags($text);

        // 2) HTML-Entities dekodieren (`&amp;`, `&uuml;` etc.)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 3) UTF-8-Repair für Latin-1-Inputs (häufig bei alten Contao-DBs).
        if (!mb_check_encoding($text, 'UTF-8')) {
            $detected = mb_detect_encoding($text, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true);
            if ($detected !== false && $detected !== 'UTF-8') {
                $converted = mb_convert_encoding($text, 'UTF-8', $detected);
                $text = $converted !== false ? $converted : $text;
            }
        }

        // 4) Soft-Hyphen (U+00AD), Zero-Width-Space (U+200B), BOM (U+FEFF) raus.
        $text = preg_replace('/[\x{00AD}\x{200B}\x{FEFF}]/u', '', $text) ?? $text;

        // 5) Whitespace zusammenfassen.
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        // 6) Auf Max-Länge kürzen — bei sehr großen PDFs (Bücher) sonst
        //    Index-Memory-Explosion. Meilisearch hat kein hartes Limit, aber
        //    sehr lange Felder verschlechtern die Relevanz und Speed.
        if (\strlen($text) > self::MAX_CONTENT_LENGTH) {
            // mb-safe truncate (nicht in der Mitte eines Multi-Byte-Chars).
            $text = mb_strcut($text, 0, self::MAX_CONTENT_LENGTH, 'UTF-8');
        }

        return $text;
    }
}
