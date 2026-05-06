<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Settings;

/**
 * Wert-Objekt: bündelt alle Settings für Bundle-Init (Endpoint, Auth, Verhalten).
 *
 * Wird vom SettingsRepository aus tl_venne_search_settings hydriert. Kein
 * Doctrine-Entity, weil das Bundle ohne Doctrine-ORM auskommen können soll
 * (Contao 5 nutzt teils legacy DB-Layer für DCA).
 */
final class SettingsConfig
{
    /**
     * Indexier-Modus. Per Default `public_only` — nur was öffentlich
     * erreichbar ist landet im Such-Index. Andere Modi muss der User
     * explizit im Backend wählen.
     */
    public const MODE_PUBLIC_ONLY = 'public_only';
    public const MODE_WITH_PROTECTED = 'with_protected';
    public const MODE_WHITELIST = 'whitelist';
    public const MODE_BLACKLIST = 'blacklist';

    /**
     * Such-Strenge — beeinflusst Tippfehler-Toleranz und Prefix-Match.
     *
     *   strict: ab Wortlänge 8 = 1 Tippfehler, ab 12 = 2.
     *           Verhindert Treffer wie "vegan" → "verantwortung" oder
     *           "Di Caprio" → "DFFF 2018" (kurze Tokens wie "di" matchen nur exakt).
     *   balanced: ab 6 = 1 Tippfehler, ab 10 = 2.
     *             Sinnvoller Mittelweg: deutsche Komposita werden noch
     *             gefunden, aber 2-3-Buchstaben-Tokens nicht mehr fuzzy.
     *   tolerant: Meilisearch-Default (5 = 1 Tippfehler, 9 = 2). Findet
     *             auch grobe Vertipper wie "Krabbnburger" → "Krabbenburger",
     *             produziert aber bei kurzen Suchbegriffen viele false positives.
     */
    public const STRICTNESS_STRICT = 'strict';
    public const STRICTNESS_BALANCED = 'balanced';
    public const STRICTNESS_TOLERANT = 'tolerant';

    public function __construct(
        public readonly string $endpoint,
        public readonly string $apiKey,
        /** @var list<string> ISO-639-1, z.B. ['de', 'en'] */
        public readonly array $enabledLocales,
        public readonly string $indexPrefix,
        public readonly bool $indexPdfs,
        public readonly bool $ocrFallback,
        /** @var list<string> Tesseract-Sprach-Codes wie 'deu', 'eng' */
        public readonly array $ocrLanguages,
        public readonly int $maxFileSizeMb,
        /** Welcher Sicherheitsmodus für den Indexer (siehe MODE_* Konstanten). */
        public readonly string $indexMode = self::MODE_PUBLIC_ONLY,
        /** @var list<string> Glob-Patterns die NIEMALS indexiert werden (z.B. "files/intern/*"). */
        public readonly array $excludedPaths = [],
        /** @var list<string> Whitelist-Patterns für mode=whitelist. */
        public readonly array $includedPaths = [],
        /** Auto-Indexing über Backend-Hooks aktiv (Default: true). */
        public readonly bool $autoIndexing = true,
        /** Such-Strenge (siehe STRICTNESS_* Konstanten). Default: balanced. */
        public readonly string $searchStrictness = self::STRICTNESS_BALANCED,
        /** Default-Locale für Files wenn keine andere Strategie greift (leer = enabledLocales[0]). */
        public readonly string $defaultFileLocale = '',
        /** @var array<string,string> Pfad → Locale-Override (z.B. ['files/foo.pdf' => 'en']). */
        public readonly array $fileLocaleOverrides = [],
        /** Master-Toggle für Search-Analytics (Buffer + Flush). */
        public readonly bool $analyticsEnabled = true,
    ) {
    }
}
