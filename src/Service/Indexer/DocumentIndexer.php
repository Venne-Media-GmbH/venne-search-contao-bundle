<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Indexer;

use Meilisearch\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Zentrale Schreib-API für den Meilisearch-Index.
 *
 * Verantwortlich für:
 *   - Index-Setup (Erst-Init: Filterable/Sortable/Searchable Attribute setzen)
 *   - Upsert eines SearchDocuments (sofortig oder via Messenger gequeuet)
 *   - Delete eines Dokuments (z.B. bei tl_files Delete-Hook)
 *   - Bulk-Reindex (für app:venne-search:reindex-all)
 *
 * Index-Naming: `<settings.indexPrefix>_<locale>` (z.B. `site_de`). Pro
 * Sprache ein eigener Index, weil Meilisearch sprachspezifische Tokenizer
 * pro Index braucht.
 */
final class DocumentIndexer
{
    /**
     * Ranking-Rules in dieser Reihenfolge (höchste Priorität zuerst).
     * Klassisches BM25-Ranking ohne Embeddings oder semantische Komponenten —
     * deterministisch, schnell, ohne externe Modelle.
     */
    private const RANKING_RULES = [
        'words',         // Alle Query-Worte gefunden?
        'typo',          // Wie viele Tippfehler?
        'proximity',     // Worte nah beieinander?
        'attribute',     // Title > Tags > Content
        'sort',          // Falls per Sort-Param sortiert
        'exactness',     // Exakte Wortform vor Stamm
    ];

    /**
     * Welche Felder durchsuchbar sind (Reihenfolge = Gewichtung).
     * v2.1.0: alt_text steht zwischen tags und content — Datei-Metadaten
     * (Titel, ALT) sind aussagekräftiger als der OCR/PDF-Volltext, sollten
     * also stärker ins Ranking einfließen, aber nicht über echten Tags.
     */
    private const SEARCHABLE_ATTRIBUTES = [
        'title',
        'tags',
        'alt_text',
        'content',
    ];

    /** Felder die als Filter (z.B. `type=page`) genutzt werden können. */
    private const FILTERABLE_ATTRIBUTES = [
        'type',
        'locale',
        'tags',
        'published_at',
        // v2.2.0: für `type_asc`-Sort + zukünftige „nach Dateityp filtern"
        // Queries auf einem dedizierten Feld (statt missbrauchtem tags-Array).
        'content_type',
        // Permission-ACL (v0.4.0): Frontend-Suche filtert hart nach diesen,
        // damit ein nicht-eingeloggter Besucher keine geschützten Treffer
        // sieht, selbst wenn sie irgendwie im Index liegen.
        'is_protected',
        'allowed_groups',
        // v2.2.0: Filter-Loeschen per URL — wird vom Lupen-Toggle in der
        // Seitenstruktur (PageSearchToggleController) genutzt um page +
        // crawled-Twin gleichzeitig zu entfernen.
        'url',
    ];

    /** Felder nach denen sortiert werden darf. */
    private const SORTABLE_ATTRIBUTES = [
        'published_at',
        'indexed_at',
        'weight',
        // v2.2.0: Sortierung nach Dokumentenart (Pages → Files alphabetisch
        // nach Extension). Lexikographische Sortierung über content_type
        // bringt page < pdf < ... automatisch in eine sinnvolle Reihenfolge.
        'content_type',
    ];

    /**
     * Deutsche Spezial-Synonyme die Meilisearch nicht out-of-the-box hat.
     * `Maße = masse` muss explizit gemappt werden, weil Meilisearch nur
     * Diakritik (ä→a, ö→o), nicht Ligaturen (ß→ss) folded.
     *
     * Pro Locale erweiterbar — User-eigene Synonyme kommen über die
     * Settings-DB-Tabelle dazu (in einer späteren Phase).
     */
    private const DEFAULT_SYNONYMS_DE = [
        'maße' => ['masse', 'mass'],
        'masse' => ['maße', 'mass'],
        'fußball' => ['fussball'],
        'fussball' => ['fußball'],
        'straße' => ['strasse'],
        'strasse' => ['straße'],
        'gruß' => ['gruss'],
        'gruss' => ['gruß'],
    ];

    /**
     * typoTolerance-Profile pro Suchstärke. Werte sind Schwellen ab denen
     * Meilisearch 1 bzw. 2 Tippfehler erlaubt.
     *
     * Beispiel STRICT: oneTypo=8 → Wörter mit ≤7 Zeichen müssen exakt matchen.
     * "vegan" (5 Zeichen) findet damit NUR "vegan", nicht mehr "verantwortung".
     */
    private const TYPO_PROFILES = [
        'strict' => ['oneTypo' => 8, 'twoTypos' => 12],
        'balanced' => ['oneTypo' => 6, 'twoTypos' => 10],
        'tolerant' => ['oneTypo' => 5, 'twoTypos' => 9],
    ];

    public function __construct(
        private readonly Client $meilisearch,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?\VenneMedia\VenneSearchContaoBundle\Service\Synonym\SynonymRepository $synonyms = null,
    ) {
    }

    /**
     * Liefert die Synonym-Map fuer eine Locale: DB-Custom-Synonyme (User-gepflegt)
     * gemerged mit den DEFAULT_SYNONYMS_DE. User-Eintraege gewinnen bei Konflikt.
     *
     * @return array<string, list<string>>
     */
    private function buildSynonyms(string $locale): array
    {
        $base = $locale === 'de' ? self::DEFAULT_SYNONYMS_DE : [];
        if ($this->synonyms === null) {
            return $base;
        }
        $custom = $this->synonyms->buildMeilisearchSynonyms();
        // Custom ueberschreibt Base.
        return array_merge($base, $custom);
    }

    /**
     * Schreibt die aktuelle Synonym-Map in den Meilisearch-Index. Wird vom
     * Synonym-Save-Listener nach jedem DB-Change gerufen, damit Aenderungen
     * sofort wirksam sind (ohne Reindex).
     */
    public function pushSynonyms(string $locale): void
    {
        $indexUid = $this->indexName($locale);
        try {
            $index = $this->meilisearch->index($indexUid);
            $index->updateSynonyms($this->buildSynonyms($locale));
        } catch (\Throwable $e) {
            $this->logger->warning('venne_search.synonyms.push_failed', [
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stellt sicher dass der Index für eine Locale existiert und richtig
     * konfiguriert ist. Idempotent — Aufruf bei Bundle-Boot oder im Setup-Cmd.
     */
    public function ensureIndex(string $locale): void
    {
        $indexUid = $this->indexName($locale);

        try {
            $this->meilisearch->getIndex($indexUid);
        } catch (\Throwable) {
            // Index existiert noch nicht — anlegen mit Primary-Key 'id'.
            $this->meilisearch->createIndex($indexUid, ['primaryKey' => 'id']);
            // createIndex ist async — wait bis fertig (max 30s).
            $this->waitForTaskCompletion($indexUid);
        }

        $index = $this->meilisearch->index($indexUid);
        $index->updateRankingRules(self::RANKING_RULES);
        $index->updateSearchableAttributes(self::SEARCHABLE_ATTRIBUTES);
        $index->updateFilterableAttributes(self::FILTERABLE_ATTRIBUTES);
        $index->updateSortableAttributes(self::SORTABLE_ATTRIBUTES);

        // Synonyme = Default-Map (z.B. Maße/Masse) + User-gepflegte Synonyme aus DB.
        $synonyms = $this->buildSynonyms($locale);
        if ($synonyms !== []) {
            $index->updateSynonyms($synonyms);
        }

        // Such-Strenge: typoTolerance + prefix-search entsprechend setzen.
        // Bei strict deaktivieren wir zusätzlich Prefix-Search — sonst matcht
        // eine 2-Buchstaben-Query wie "Di" alles was mit "di…" anfängt
        // (Dokumentation, Direktor, …). Das ist genau der User-Bug.
        try {
            $config = $this->settings->load();
            $profile = self::TYPO_PROFILES[$config->searchStrictness] ?? self::TYPO_PROFILES['balanced'];
            $index->updateTypoTolerance([
                'enabled' => true,
                'minWordSizeForTypos' => [
                    'oneTypo' => $profile['oneTypo'],
                    'twoTypos' => $profile['twoTypos'],
                ],
            ]);
            // updateSearchCutoffMs gibt es nicht für alle Meili-Versionen.
            // updatePrefixSearch ist erst in Meilisearch 1.12+ verfügbar —
            // bei älteren Servern fängt das try/catch unten ab.
            try {
                if ($config->searchStrictness === 'strict') {
                    $index->updateSettings(['prefixSearch' => 'disabled']);
                } else {
                    // Default zurücksetzen (falls vorher strict war).
                    $index->updateSettings(['prefixSearch' => 'indexingTime']);
                }
            } catch (\Throwable) {
                // Ältere Meili-Version ohne prefixSearch-Support — egal.
            }
        } catch (\Throwable $e) {
            // Settings nicht ladbar (z.B. in Migrations-Phase) — Default belassen.
            $this->logger->warning('venne_search.indexer.typo_tolerance_skipped', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function upsert(SearchDocument $doc): void
    {
        $this->ensureIndex($doc->locale);
        $this->meilisearch
            ->index($this->indexName($doc->locale))
            ->addDocuments([$doc->jsonSerialize()]);

        $this->logger->debug('venne_search.indexer.upserted', [
            'id' => $doc->id,
            'locale' => $doc->locale,
            'type' => $doc->type,
        ]);
    }

    /**
     * @param iterable<SearchDocument> $docs
     */
    public function upsertMany(iterable $docs): void
    {
        // Gruppiert pro Locale, damit pro Index nur ein Batch-Request rausgeht.
        $byLocale = [];
        foreach ($docs as $doc) {
            $byLocale[$doc->locale][] = $doc->jsonSerialize();
        }

        foreach ($byLocale as $locale => $payload) {
            $this->ensureIndex($locale);
            $this->meilisearch->index($this->indexName($locale))->addDocuments($payload);
        }
    }

    public function delete(string $documentId, string $locale): void
    {
        try {
            $this->meilisearch
                ->index($this->indexName($locale))
                ->deleteDocument($documentId);
        } catch (\Throwable $e) {
            // Nicht kritisch — Dokument existiert evtl. eh nicht mehr.
            $this->logger->warning('venne_search.indexer.delete_failed', [
                'id' => $documentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Loescht alle Meili-Dokumente, deren `url`-Attribut exakt einem der
     * uebergebenen Werte entspricht. Wird beim Toggle "nicht durchsuchbar"
     * gebraucht, damit nicht nur das page-Dokument verschwindet, sondern auch
     * etwaige crawled-Twins mit derselben URL. Setzt voraus, dass `url` in
     * den filterable-attributes des Index gelistet ist (siehe v2.2.0
     * Mig06_AddUrlFilterable).
     *
     * @param string[] $urls
     */
    public function deleteByUrls(array $urls, string $locale): void
    {
        $urls = array_values(array_filter(array_unique($urls), static fn ($u): bool => $u !== ''));
        if ($urls === []) {
            return;
        }
        try {
            $index = $this->meilisearch->index($this->indexName($locale));
            $filter = implode(' OR ', array_map(
                static fn (string $u): string => 'url = "' . addslashes($u) . '"',
                $urls,
            ));
            $index->deleteDocuments(['filter' => $filter]);
        } catch (\Throwable $e) {
            $this->logger->warning('venne_search.indexer.delete_by_urls_failed', [
                'urls' => $urls,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function purge(string $locale): void
    {
        $indexUid = $this->indexName($locale);
        try {
            $this->meilisearch->deleteIndex($indexUid);
        } catch (\Throwable) {
            // Ignorieren — vielleicht existiert der Index noch nicht.
        }
    }

    /**
     * Defense-in-Depth: jeder Index-Aufruf MUSS mit dem aufgelösten Prefix
     * beginnen — sonst ist die Tenant-Isolation gebrochen. Wir berechnen
     * den Prefix nicht selbst, sondern lesen ihn vom Resolve-Endpoint
     * (über SettingsRepository). Sollte im Code irgendwo ein Locale
     * untergeschmuggelt werden, das zu einem unerwarteten indexUid führt,
     * fängt diese Methode das ab.
     */
    private function indexName(string $locale): string
    {
        $config = $this->settings->load();

        if ($config->indexPrefix === '') {
            throw new \RuntimeException('Bundle nicht konfiguriert — indexPrefix fehlt vom Resolve.');
        }

        // Locale auf [a-z]{2,5} (ISO-Codes) beschränken. Verhindert dass
        // ein wildes Locale-Feld in der DB einen kaputten oder bösartigen
        // indexUid baut.
        $sanitized = strtolower(preg_replace('/[^a-z]/', '', $locale) ?? '');
        if ($sanitized === '' || strlen($sanitized) > 5) {
            throw new \RuntimeException(\sprintf('Ungültige Locale "%s" — abgelehnt.', $locale));
        }

        $indexUid = \sprintf('%s_%s', $config->indexPrefix, $sanitized);

        // Letzter Sanity-Check: muss mit "t_" + 16hex + "_" + locale anfangen.
        if (!\preg_match('/^t_[a-f0-9]{16}_[a-z]{2,5}$/', $indexUid)) {
            throw new \RuntimeException(\sprintf('Index-UID "%s" hat unerwartetes Format — Block.', $indexUid));
        }

        return $indexUid;
    }

    private function waitForTaskCompletion(string $indexUid): void
    {
        // Poll auf den letzten Index-Task — max 30 s.
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            try {
                $this->meilisearch->getIndex($indexUid);

                return;
            } catch (\Throwable) {
                usleep(100_000); // 100ms
            }
        }
        throw new \RuntimeException(\sprintf('Meilisearch-Index "%s" wurde nicht innerhalb 30 s erstellt.', $indexUid));
    }
}
