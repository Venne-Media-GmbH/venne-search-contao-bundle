<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Settings;

use Doctrine\DBAL\Connection;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveClient;

/**
 * Lädt die Bundle-Settings aus tl_venne_search_settings + ruft die Plattform.
 *
 * Kunden-pflegbare Felder (DCA-sichtbar):
 *   - api_key            (Plattform-Key, Format vsk_live_…)
 *   - enabled_locales    (CSV: 'de,en,fr')
 *   - index_pdfs         (bool)
 *   - max_file_size_mb   (int)
 *
 * Vom Bundle aufgelöst (NICHT in DB editierbar — kommt vom Resolve-Endpoint
 * der Plattform):
 *   - endpoint           (Meilisearch-Server-URL)
 *   - indexPrefix        (`t_<hash>`, deterministisch pro Plattform-Key)
 *   - scopedToken        (Meilisearch-Key, nur für eigene Indexe)
 *
 * Optional konfigurierbar:
 *   - platform_url       (default https://venne-search.de — für Dev-Setups)
 */
final class SettingsRepository
{
    private const TABLE = 'tl_venne_search_settings';

    /**
     * Default-URL der Plattform. Kann via DCA-Feld `platform_url` überschrieben
     * werden — z.B. für Dev-Tunneling über localhost.
     */
    private const DEFAULT_PLATFORM_URL = 'https://venne-search.de';

    public function __construct(
        private readonly Connection $db,
        private readonly ResolveClient $resolveClient,
        private readonly ?string $platformUrlOverride = null,
    ) {
    }

    /**
     * Lädt + resolved. Wirft {@see \VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveException}
     * bei Plattform-Problemen, die der aufrufende Controller in eine
     * User-freundliche Fehlermeldung übersetzt.
     */
    public function load(): SettingsConfig
    {
        // Tabelle könnte noch nicht existieren (frisch installiertes Bundle,
        // Migration noch nicht gelaufen) oder einzelne Spalten fehlen
        // (Teil-Migration). Wir fangen das hier ab und liefern eine leere
        // Config zurück, sodass der Container weiterbauen kann und das
        // Backend wenigstens noch erreichbar bleibt — sonst Komplettausfall.
        try {
            $row = $this->db->fetchAssociative(
                \sprintf('SELECT * FROM %s WHERE id = 1', self::TABLE),
            );
        } catch (\Throwable) {
            $row = null;
        }
        if (!\is_array($row)) {
            $row = [];
        }

        $apiKey = (string) ($row['api_key'] ?? '');
        $enabledLocales = $this->parseCsv((string) ($row['enabled_locales'] ?? 'de'));
        if ($enabledLocales === []) {
            $enabledLocales = ['de'];
        }
        $platformUrl = $this->platformUrlOverride
            ?? ((string) ($row['platform_url'] ?? '') ?: self::DEFAULT_PLATFORM_URL);

        $indexMode = (string) ($row['index_mode'] ?? '') ?: SettingsConfig::MODE_PUBLIC_ONLY;

        // Tree-Picker: excluded_folders speichert Folder-UUIDs als
        // serialisiertes Array. Wir lösen die zu Pfaden auf und ergänzen
        // ein Sub-Folder-Wildcard, damit der bestehende isPathExcluded()
        // ohne Änderung weiterläuft.
        $excludedPaths = $this->resolveFolderUuidsToPaths((string) ($row['excluded_folders'] ?? ''));

        // Legacy: alte Glob-Pattern aus v0.x mitnehmen, damit Bestandskunden
        // nichts verlieren bevor sie ihre Auswahl im Tree-Picker neu treffen.
        $legacyExcluded = $this->parseLines((string) ($row['excluded_paths'] ?? ''));
        $excludedPaths = array_values(array_unique(array_merge($excludedPaths, $legacyExcluded)));

        $includedPaths = $this->parseLines((string) ($row['included_paths'] ?? ''));
        // Auto-Indexing standardmäßig an (Default '1' in der DCA, leer/0 deaktiviert).
        $autoIndexing = (string) ($row['auto_indexing'] ?? '1') === '1';

        // Such-Strenge: nur die drei Konstanten sind erlaubt — ungültige Werte
        // (z.B. wenn die Spalte in einer alten DB noch fehlt) fallen auf Default.
        $rawStrictness = (string) ($row['search_strictness'] ?? '');
        $allowedStrictness = [
            SettingsConfig::STRICTNESS_STRICT,
            SettingsConfig::STRICTNESS_BALANCED,
            SettingsConfig::STRICTNESS_TOLERANT,
        ];
        $searchStrictness = \in_array($rawStrictness, $allowedStrictness, true)
            ? $rawStrictness
            : SettingsConfig::STRICTNESS_BALANCED;

        // v2.0.0: File-Locale + Analytics
        $defaultFileLocale = strtolower(trim((string) ($row['default_file_locale'] ?? '')));
        if ($defaultFileLocale !== '' && !\in_array($defaultFileLocale, $enabledLocales, true)) {
            $defaultFileLocale = '';
        }
        $fileLocaleOverrides = $this->parseFileLocaleOverrides(
            (string) ($row['file_locale_overrides'] ?? ''),
            $enabledLocales,
        );
        $analyticsEnabled = (string) ($row['analytics_enabled'] ?? '1') === '1';

        // Default-Blacklist: wenn der User noch nichts ausgewählt hat,
        // schützen wir konventionell die typischen Admin-Verzeichnisse.
        if ($excludedPaths === []) {
            $excludedPaths = ['files/intern/*', 'files/admin/*', 'files/private/*'];
        }

        if ($apiKey === '') {
            // Bundle ist noch nicht konfiguriert — leere Config zurückgeben
            // (kein Resolve-Call). Aufrufer prüft mit isConfigured().
            return new SettingsConfig(
                endpoint: '',
                apiKey: '',
                enabledLocales: $enabledLocales,
                indexPrefix: '',
                indexPdfs: (bool) ($row['index_pdfs'] ?? true),
                ocrFallback: false,
                ocrLanguages: [],
                maxFileSizeMb: (int) ($row['max_file_size_mb'] ?? 25),
                indexMode: $indexMode,
                excludedPaths: $excludedPaths,
                includedPaths: $includedPaths,
                autoIndexing: $autoIndexing,
                searchStrictness: $searchStrictness,
                defaultFileLocale: $defaultFileLocale,
                fileLocaleOverrides: $fileLocaleOverrides,
                analyticsEnabled: $analyticsEnabled,
            );
        }

        $resolved = $this->resolveClient->resolve($apiKey);

        return new SettingsConfig(
            endpoint: $resolved->endpoint,
            apiKey: $resolved->scopedToken, // Bundle redet mit Meili über scoped Token
            enabledLocales: $enabledLocales,
            indexPrefix: $resolved->indexPrefix,
            indexPdfs: (bool) ($row['index_pdfs'] ?? true),
            ocrFallback: false,
            ocrLanguages: [],
            maxFileSizeMb: (int) ($row['max_file_size_mb'] ?? 25),
            indexMode: $indexMode,
            excludedPaths: $excludedPaths,
            includedPaths: $includedPaths,
            autoIndexing: $autoIndexing,
            searchStrictness: $searchStrictness,
            defaultFileLocale: $defaultFileLocale,
            fileLocaleOverrides: $fileLocaleOverrides,
            analyticsEnabled: $analyticsEnabled,
        );
    }

    /**
     * Parst das JSON-Map { "files/path/foo.pdf": "en", "..." : "de" } und
     * filtert ungültige Locales (nicht in enabledLocales) raus.
     *
     * @param list<string> $enabledLocales
     * @return array<string,string>
     */
    private function parseFileLocaleOverrides(string $json, array $enabledLocales): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $path => $locale) {
            if (!\is_string($path) || !\is_string($locale)) {
                continue;
            }
            $path = ltrim($path, '/');
            $locale = strtolower(trim($locale));
            if ($path === '' || !\in_array($locale, $enabledLocales, true)) {
                continue;
            }
            $out[$path] = $locale;
        }
        return $out;
    }

    /**
     * Setzt einen einzelnen File-Locale-Override und persistiert das JSON-Feld.
     * Wird vom Backend-Endpoint POST /contao/venne-search/file-locale aufgerufen.
     */
    public function setFileLocaleOverride(string $path, string $locale): void
    {
        $path = ltrim($path, '/');
        $locale = strtolower(trim($locale));
        if ($path === '') {
            return;
        }
        try {
            $row = $this->db->fetchAssociative(
                \sprintf('SELECT file_locale_overrides FROM %s WHERE id = 1', self::TABLE),
            );
        } catch (\Throwable) {
            return;
        }
        $current = [];
        if (\is_array($row) && \is_string($row['file_locale_overrides'] ?? null) && $row['file_locale_overrides'] !== '') {
            try {
                $decoded = json_decode((string) $row['file_locale_overrides'], true, 4, \JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    foreach ($decoded as $k => $v) {
                        if (\is_string($k) && \is_string($v)) {
                            $current[$k] = $v;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }
        if ($locale === '') {
            // Leeres Locale = Override entfernen.
            unset($current[$path]);
        } else {
            $current[$path] = $locale;
        }
        try {
            $this->db->executeStatement(
                \sprintf('UPDATE %s SET file_locale_overrides = ?, tstamp = ? WHERE id = 1', self::TABLE),
                [
                    $current === [] ? null : json_encode($current, \JSON_UNESCAPED_UNICODE),
                    time(),
                ],
            );
        } catch (\Throwable) {
        }
    }

    /**
     * Trennt Multi-Line-Felder (z.B. excluded_paths) in eine Liste.
     *
     * @return list<string>
     */
    private function parseLines(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $v): bool => $v !== '' && !str_starts_with($v, '#'),
        ));
    }

    /**
     * Übersetzt das fileTree-Feld (serialisiertes Array von UUIDs) in eine
     * Liste von Glob-Pattern, die der bestehende PermissionResolver direkt
     * weiterverarbeiten kann. Pro UUID liefern wir zwei Patterns:
     * der Ordner-Pfad selbst und ein "darunter rekursiv"-Pattern.
     *
     * @return list<string>
     */
    private function resolveFolderUuidsToPaths(string $serialized): array
    {
        if ($serialized === '') {
            return [];
        }

        $uuids = @unserialize($serialized, ['allowed_classes' => false]);
        if (!\is_array($uuids) || $uuids === []) {
            return [];
        }

        $paths = [];
        foreach ($uuids as $uuid) {
            if (!\is_string($uuid) || $uuid === '') {
                continue;
            }
            // fileTree speichert binäre UUIDs (16 Bytes). Manche Contao-
            // Versionen speichern auch String-UUIDs ('a8f...'). Wir
            // normalisieren auf hex-binary für die DB-Lookup-Query.
            $uuidBin = \strlen($uuid) === 16 ? $uuid : @hex2bin(str_replace('-', '', $uuid));
            if (!\is_string($uuidBin) || \strlen($uuidBin) !== 16) {
                continue;
            }
            try {
                $row = $this->db->fetchAssociative(
                    "SELECT path FROM tl_files WHERE uuid = ? AND type = 'folder' LIMIT 1",
                    [$uuidBin],
                );
            } catch (\Throwable) {
                continue;
            }
            $folderPath = \is_array($row) ? trim((string) ($row['path'] ?? ''), '/') : '';
            if ($folderPath === '') {
                continue;
            }
            // Der Ordner selbst und alles darunter — beides als Glob-Pattern,
            // damit isPathExcluded() für Files in tieferen Sub-Ordnern matcht.
            $paths[] = $folderPath;
            $paths[] = $folderPath . '/*';
        }

        return array_values(array_unique($paths));
    }

    /**
     * Schnell-Check OHNE Resolve-Call — nutze in Hot-Pfaden.
     */
    public function isConfigured(): bool
    {
        return $this->getPlatformApiKey() !== '';
    }

    /**
     * Liefert den Plattform-Key (vsk_live_…) ohne Resolve-Call.
     * Wird intern z.B. vom Resolve-Cache-Invalidator beim DCA-Save genutzt.
     */
    public function getPlatformApiKey(): string
    {
        try {
            $row = $this->db->fetchAssociative(
                \sprintf('SELECT api_key FROM %s WHERE id = 1', self::TABLE),
            );

            return is_array($row) ? (string) ($row['api_key'] ?? '') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Plattform-URL: Override aus DCA → Container-Override → Default.
     * Wird vom Analytics-Flusher genutzt, der nicht über den Resolve-Endpoint
     * geht sondern direkt einen eigenen Endpoint anspricht.
     */
    public function getPlatformUrl(): string
    {
        if ($this->platformUrlOverride !== null && $this->platformUrlOverride !== '') {
            return $this->platformUrlOverride;
        }
        try {
            $row = $this->db->fetchAssociative(
                \sprintf('SELECT platform_url FROM %s WHERE id = 1', self::TABLE),
            );
            $url = \is_array($row) ? (string) ($row['platform_url'] ?? '') : '';
            if ($url !== '') {
                return $url;
            }
        } catch (\Throwable) {
        }
        return self::DEFAULT_PLATFORM_URL;
    }

    /**
     * Löscht den API-Key + Resolve-Cache aus der DB.
     * Wird aufgerufen wenn die Plattform 401 zurückgibt (Key ungültig oder
     * widerrufen) — damit das Bundle danach „nicht konfiguriert" zeigt
     * und der User im Backend ohne 500-Crash einen neuen Key eintragen kann.
     */
    public function clearInvalidKey(): void
    {
        try {
            $this->db->executeStatement(
                \sprintf('UPDATE %s SET api_key = \'\', resolve_cache = NULL WHERE id = 1', self::TABLE),
            );
        } catch (\Throwable) {
            // Tabelle/Spalte existiert evtl. nicht — egal, beim nächsten
            // Migrationslauf kommt sie. Wichtigster Effekt: wir crashen nicht.
        }
    }

    /**
     * @return list<string>
     */
    private function parseCsv(string $csv): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $csv)),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
