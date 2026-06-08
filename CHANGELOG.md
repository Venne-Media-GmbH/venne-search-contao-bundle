# Changelog

Alle nennenswerten Änderungen am Venne-Search-Contao-Bundle.

Format orientiert an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased] — 2.2.0-dev (Cover & Type-Sort)

### Added
- **Dokument-Cover in der Trefferliste:** Datei-Treffer werden mit einem echten Vorschaubild gerendert statt nur dem generischen SVG-Icon. Strategie: (a) Bilddateien zeigen sich selbst, (b) für PDF/DOCX/… wird ein gleichnamiges Bild im selben Verzeichnis als Cover genommen (`flyer.pdf` → `flyer.jpg`/`.png`/`.webp`, auch in Großschreibung), (c) sonst Fallback auf das Icon. Das `<img>` bekommt einen echten `alt=`-Attribut (ALT-Text aus `tl_files.meta` → Titel → „Vorschaubild") — barrierefrei und SEO-sauber, kein Hover-Tooltip-Hack mehr.
- **Sortier-Modus „Nach Dokumentenart" (`sort=type_asc`):** Sortiert lexikographisch nach `content_type` — Pages zuerst, dann Dateien gruppiert nach Extension (docx, pdf, xlsx, …). Innerhalb der Gruppe Tie-Break über `weight DESC`, damit Tag-geboostete Items innerhalb der Dateigruppe oben stehen. Im Frontend-Dropdown als vierte Option verfügbar.
- **`SearchDocument.cover_url`** + **`SearchDocument.content_type`** als Index-Felder. `content_type` ist filterable und sortable; `cover_url` ist nur Anzeige-Metadata.
- **`SearchHit.publishedAt`** als echtes Feld — Multi-Locale-Date-Merge sortiert jetzt global statt nur per Round-Robin (alte Logik vertraute auf Pro-Locale-Reihenfolge, was bei stark unterschiedlich befüllten Indexen falsch sortierte).
- **`tl_files.tstamp` als `published_at` für Datei-Treffer:** Vorher fehlte Files das Datum komplett, Date-Sort hat sie immer ans Ende verbannt. Fallback auf `filemtime()` wenn die DB-Spalte 0 ist.

### Changed
- `SearchService::SORT_MODES`-Whitelist um `type_asc` erweitert.
- Frontend-Template auf v0.22 — `data-vsearch-version="0.22"`, Sort-Dropdown um „Nach Dokumentenart", Cover-Img-Rendering mit `onerror`-Fallback.
- API-Antwort liefert pro Hit zusätzlich `coverUrl`, `contentType`, `publishedAt`.

### Removed
- `SearchService::mergeStable()` — wurde durch echte global-sortierte `usort()`-Calls im Multi-Locale-Pfad ersetzt (publishedAt + contentType liegen jetzt im DTO).

### Notes
- **Reindex zwingend nach Update** — neue Index-Felder (`cover_url`, `content_type`, `published_at` für Files) sind in alten Documents nicht vorhanden. Ohne Reindex zeigen Datei-Treffer kein Cover und `type_asc`-Sort sortiert nur Pages korrekt.
- Keine DB-Migration nötig — Index-Schema wird durch `DocumentIndexer::ensureIndex()` beim ersten Upsert automatisch aktualisiert.

## [2.1.0] — Search Quality

### Added
- **Tag-Boost / Hierarchisierung:** Pro Tag konfigurierbarer Relevanz-Boost (1.0 / 1.5 / 2.0 / 3.0 / 5.0 / 10.0). Treffer mit höher gewichteten Tags landen in der Ergebnisliste oben, auch wenn der Suchbegriff nur über den Tag matcht. Beispiel: Eine Seite „Einreich- und Sitzungstermine" mit Boost-Tag „Fristen" (5.0) erscheint bei der Suche „Fristen" über allen Volltext-Treffern. Boost-Wert wandert pro Indexierung in `SearchDocument.weight`; Sort-Reihenfolge: `weight DESC, indexed_at DESC`.
- **Datei-Metadaten-Auflösung:** Such-Treffer für Dateien zeigen jetzt den im Contao-Dateibaum hinterlegten Titel statt des humanisierten Dateinamens, plus den ALT-Text als Hover-Tooltip. Locale-aware aus `tl_files.meta` (zuerst exakter Match, dann `enabled_locales`-Reihenfolge, sonst erster verfügbarer Eintrag). Fallback auf bisheriges Verhalten wenn keine Meta-Daten gepflegt sind.
- **Sortier-Dropdown:** Endnutzer können in der Frontend-Suche zwischen „Relevanz", „Neueste zuerst" und „Älteste zuerst" wählen.
- **Dateityp-Filter-Pills:** Pre-defined Buttons für PDF / Excel / Word / Text / OpenDoc / RTF, kombinierbar mit Tag-Filtern. Pills sind nur sichtbar wenn der Type-Filter „Dateien" oder „Alle" ist.
- **URL-State / History-API:** Suchanfragen sind jetzt bookmark- und teilbar. Browser-Back/Forward stellt den vorherigen Such-Zustand (Query, Filter, Sort) wieder her — User verliert seine Suche nicht mehr beim Klick auf ein Ergebnis und Zurück. Pro Modul eindeutiger Hash-Prefix (`#vs<modul-id>:…`), mehrere Such-Module auf einer Seite koexistieren konfliktfrei.
- **Setting `index_hidden_pages`:** Steuert ob Seiten mit gesetztem `tl_page.hide`-Flag („Im Menü nicht anzeigen") in den Such-Index aufgenommen werden. Default `true` (= bisheriges Verhalten der Versionen vor v2.1).
- **Modul-Setting `vsearch_open_in_new_tab`:** Optional alle Suchergebnisse in neuem Tab öffnen. Standardmäßig deaktiviert — der URL-State-Mechanismus ist die saubere Lösung für Browser-Back-Verhalten.
- **`SearchDocument.alt_text`** als zusätzliches searchable Attribut (Reihenfolge: title > tags > alt_text > content).

### Changed
- `SearchService::search()` akzeptiert neuen Parameter `string $sort` (Whitelist: `relevance`, `date_desc`, `date_asc`).
- `FrontendSearchController` akzeptiert die neuen Query-Params `?sort=…` und `?ext[]=…`.
- `TagRepository`-Methoden geben zusätzlich das `boost`-Feld zurück (rückwärtskompatibel — fehlende Spalte fällt auf 1.0).
- Tag-Chips in der Trefferliste markieren Boost-Tags (>1.0) mit Pin-Icon und Outline.

### Migrations
- `Version210\Mig01_AddTagBoost` — Spalte `boost DECIMAL(4,2) NOT NULL DEFAULT 1.00` in `tl_venne_search_tag`.
- `Version210\Mig02_AddIndexHiddenPages` — Spalte `index_hidden_pages CHAR(1) NOT NULL DEFAULT '1'` in `tl_venne_search_settings`.

### Notes
- Re-Index aller Indexe nach Update empfohlen — neue Tag-Boosts und Datei-Metadaten erfordern frische Documents im Index. Tag-Boost-Änderungen lösen einen automatischen Reindex der zugewiesenen Items aus (DCA `save_callback`).
- Schaltet ein Admin das Setting „Versteckte Seiten indexieren" um, weist das Backend auf einen erforderlichen Reindex hin.

## [2.0.0] — Mehrsprachigkeit, Tag-System, Search-Analytics

### Added
- **Mehrsprachigkeit:** File-Locale-Detection mit fünf Strategien (Override → Page-Embedding → Pfad-Hint → Filename-Hint → Default). Pro Frontend-Modul/-Element konfigurierbares Locale; mehrere Locales gleichzeitig durchsuchbar via `multi-search`.
- **Tag-System:** Eigenständige Tabellen `tl_venne_search_tag` + `tl_venne_search_tag_assignment` mit Backend-Tree-Picker, Drag-and-Drop-Massentagging, Inline-Combobox, Farbe + Beschreibung pro Tag. Tags landen mit dem Index und sind frontendseitig als Filter-Chips klickbar.
- **Search-Analytics:** Anonyme Aggregation aller Suchanfragen pro API-Key via Plattform-Endpoint `POST /api/v1/analytics/search-events`. **Direkter Send pro Such-Request** (fire-and-forget, 2 s Timeout) — kein Cron, kein Buffer, kein User-Setup nötig. Plattform-Dashboard mit Top-Queries, Zero-Result-Filter, Sparkline und CSV-Export.
- **File-Locale-Override-UI:** Pro Datei direkt aus dem Documents-Panel die Sprache übersteuern.
- **Backend:** Neuer Status-Block "Analytics" mit Live-Buffer-Stand und manuellem Flush-Button.

### Changed
- `tl_page.keywords` (CSV) wird beim Update einmalig in Tags überführt; das Feld bleibt im DCA, ist aber als Legacy markiert.
- `SearchService::search()` akzeptiert optional eine Locale-Liste (`array $locales`) für Multi-Index-Queries.
- `IndexableItemProcessor` schreibt Tags aus dem neuen Tag-System statt aus `keywords`.

### Migrations
- `Version200\Mig01_AddMultilingualSupport` — Spalten `default_file_locale`, `file_locale_overrides`, `analytics_enabled` in `tl_venne_search_settings`; `vsearch_locale` in `tl_module` + `tl_content`.
- `Version200\Mig02_AddTagSystem` — Tabellen anlegen + Legacy-Keywords einmalig migrieren.

## [1.0.2] — 2026-04-30
### Added
- Backend-Setting "Such-Strenge" (strict / balanced / tolerant).

## [1.0.1] — 2026-04-30
### Fixed
- URL-Generierung respektiert pro Site den richtigen Suffix.

## [1.0.0] — 2026-04-29
- Initial Release.
