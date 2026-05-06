<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Locale;

use Doctrine\DBAL\Connection;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsConfig;

/**
 * Bestimmt für eine Datei das passende Locale, in dem sie indexiert werden soll.
 *
 * Strategien (erste die liefert gewinnt — bewusst NICHT alphabetisch sortiert):
 *   1. Explizites Override aus tl_venne_search_settings.file_locale_overrides
 *   2. Page-Embedding: in welchen Pages ist die Datei eingebunden? Dominante Locale gewinnt.
 *   3. Pfad-Hint: /files/de/foo.pdf, /files/en_US/...
 *   4. Filename-Hint: foo_de.pdf, manual-en.pdf
 *   5. Default: settings.default_file_locale → sonst erste enabled_locale
 *
 * Memo-Cache pro Request (kein persistenter Cache nötig — der Reindex-Lauf
 * geht einmal durch die Files; Folgeläufe sind selten genug, dass File-IO
 * nicht der Flaschenhals ist).
 */
final class FileLocaleDetector
{
    /** @var array<string, string> path => locale */
    private array $memo = [];

    /**
     * Erlaubte Locale-Tokens für Pfad-/Filename-Hint. Bewusst breit gefasst,
     * gefiltert wird gegen $config->enabledLocales.
     */
    private const LOCALE_TOKEN_PATTERN = '/(de|en|fr|it|es|nl|pl|cs|tr|ar|ru|pt|sv|da|fi|no|nb|hu|ro|el|bg|hr|sk|sl|et|lv|lt|uk|zh|ja|ko)(?:[_-][A-Z]{2})?/i';

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function detect(string $relativePath, SettingsConfig $config): string
    {
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '') {
            return $this->fallbackLocale($config);
        }

        if (isset($this->memo[$relativePath])) {
            return $this->memo[$relativePath];
        }

        $locale = $this->detectInternal($relativePath, $config);
        $this->memo[$relativePath] = $locale;

        return $locale;
    }

    public function clearMemo(): void
    {
        $this->memo = [];
    }

    private function detectInternal(string $relativePath, SettingsConfig $config): string
    {
        // 1. Override aus DCA — höchste Priorität, sticht alles.
        if (isset($config->fileLocaleOverrides[$relativePath])) {
            $override = strtolower((string) $config->fileLocaleOverrides[$relativePath]);
            if ($override !== '' && \in_array($override, $config->enabledLocales, true)) {
                return $override;
            }
        }

        // 2. Page-Embedding: wo ist die Datei wirklich eingebunden?
        $embedded = $this->detectFromPageEmbedding($relativePath, $config->enabledLocales);
        if ($embedded !== null) {
            return $embedded;
        }

        // 3. Pfad-Hint: /files/de/, /files/en_US/, ...
        $pathHint = $this->detectFromPath($relativePath, $config->enabledLocales);
        if ($pathHint !== null) {
            return $pathHint;
        }

        // 4. Filename-Hint: foo_de.pdf, foo-en.pdf
        $nameHint = $this->detectFromFilename($relativePath, $config->enabledLocales);
        if ($nameHint !== null) {
            return $nameHint;
        }

        // 5. Default.
        return $this->fallbackLocale($config);
    }

    /**
     * Sucht alle tl_content-Einträge die diese Datei via singleSRC oder
     * multiSRC referenzieren, und holt sich pro Treffer die Locale der Page.
     * Dominante Locale (höchster Count) gewinnt; bei Gleichstand → erste alphabetisch.
     *
     * @param list<string> $enabledLocales
     */
    private function detectFromPageEmbedding(string $relativePath, array $enabledLocales): ?string
    {
        try {
            $row = $this->db->fetchAssociative(
                'SELECT uuid FROM tl_files WHERE path = ? LIMIT 1',
                [$relativePath],
            );
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($row) || !\is_string($row['uuid'] ?? null) || $row['uuid'] === '') {
            return null;
        }
        $uuid = (string) $row['uuid'];

        // tl_content.singleSRC speichert die UUID binär (16 Byte). multiSRC
        // ist ein serialisiertes Array, deshalb LIKE auf Hex-Repräsentation.
        $uuidHex = bin2hex($uuid);
        $localeCounts = [];

        try {
            // singleSRC = direkter Match
            $rowsSingle = $this->db->fetchAllAssociative(
                'SELECT p.language AS locale
                 FROM tl_content c
                 INNER JOIN tl_article a ON a.id = c.pid AND c.ptable = \'tl_article\'
                 INNER JOIN tl_page p ON p.id = a.pid
                 WHERE c.singleSRC = ?',
                [$uuid],
            );
            foreach ($rowsSingle as $r) {
                $loc = $this->normalizeLocale((string) ($r['locale'] ?? ''), $enabledLocales);
                if ($loc !== null) {
                    $localeCounts[$loc] = ($localeCounts[$loc] ?? 0) + 1;
                }
            }
        } catch (\Throwable) {
        }

        try {
            // multiSRC = serialisiertes Array, LIKE-Match auf Hex
            $rowsMulti = $this->db->fetchAllAssociative(
                'SELECT p.language AS locale
                 FROM tl_content c
                 INNER JOIN tl_article a ON a.id = c.pid AND c.ptable = \'tl_article\'
                 INNER JOIN tl_page p ON p.id = a.pid
                 WHERE c.multiSRC LIKE ?',
                ['%' . $uuidHex . '%'],
            );
            foreach ($rowsMulti as $r) {
                $loc = $this->normalizeLocale((string) ($r['locale'] ?? ''), $enabledLocales);
                if ($loc !== null) {
                    $localeCounts[$loc] = ($localeCounts[$loc] ?? 0) + 1;
                }
            }
        } catch (\Throwable) {
        }

        if ($localeCounts === []) {
            return null;
        }

        // Sortieren: höchster Count zuerst, bei Gleichstand alphabetisch.
        ksort($localeCounts);
        arsort($localeCounts);
        return (string) array_key_first($localeCounts);
    }

    /**
     * @param list<string> $enabledLocales
     */
    private function detectFromPath(string $relativePath, array $enabledLocales): ?string
    {
        $segments = array_filter(explode('/', $relativePath), static fn (string $s): bool => $s !== '');
        // Letztes Segment ist der Filename — ignorieren.
        array_pop($segments);
        foreach ($segments as $segment) {
            if (preg_match('/^' . trim(self::LOCALE_TOKEN_PATTERN, '/i') . '$/i', $segment) === 1) {
                $loc = $this->normalizeLocale($segment, $enabledLocales);
                if ($loc !== null) {
                    return $loc;
                }
            }
        }
        return null;
    }

    /**
     * @param list<string> $enabledLocales
     */
    private function detectFromFilename(string $relativePath, array $enabledLocales): ?string
    {
        $filename = pathinfo($relativePath, PATHINFO_FILENAME);
        // Pattern: name_de oder name-en (am Ende des Stems)
        if (preg_match('/[._-]([a-z]{2}(?:[_-][A-Z]{2})?)$/i', $filename, $m) === 1) {
            $loc = $this->normalizeLocale($m[1], $enabledLocales);
            if ($loc !== null) {
                return $loc;
            }
        }
        return null;
    }

    /**
     * Reduziert ein Locale wie "de_DE" oder "en-US" auf den ISO-639-1-Hauptteil
     * und prüft Whitelist gegen $enabledLocales.
     *
     * @param list<string> $enabledLocales
     */
    private function normalizeLocale(string $raw, array $enabledLocales): ?string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }
        // de_DE / de-DE → de
        if (preg_match('/^([a-z]{2,3})/', $raw, $m) === 1) {
            $short = $m[1];
            if (\in_array($short, $enabledLocales, true)) {
                return $short;
            }
        }
        return null;
    }

    private function fallbackLocale(SettingsConfig $config): string
    {
        if ($config->defaultFileLocale !== '' && \in_array($config->defaultFileLocale, $config->enabledLocales, true)) {
            return $config->defaultFileLocale;
        }
        return $config->enabledLocales[0] ?? 'de';
    }
}
