# Changelog

Alle nennenswerten Änderungen am Venne-Search-Contao-Bundle.

Format orientiert an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

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
