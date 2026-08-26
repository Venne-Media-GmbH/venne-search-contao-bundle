# Changelog

Alle nennenswerten Änderungen am Venne-Search-Contao-Bundle.

Format orientiert an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased] — 2.2.0-dev (Cover & Type-Sort + komplettes Template-Refactor)

### Added — Template (v0.23)
- **Komplettes Layout-Refactor der Trefferliste.** Card-Style mit klarer dreizeiliger Hierarchie: Titel-Zeile (Titel + Tags + Schloss) / Meta-Zeile (Typ-Badge + Datum + Größe + URL) / Snippet (max 2 Zeilen mit `-webkit-line-clamp`).
- **Meta-Zeile:** Pro Treffer Typ-Badge (farblich passend zum Dateityp — PDF rot, DOCX blau, XLSX grün, …), Veröffentlichungsdatum (`dd.mm.yyyy`), Dateigröße (KB/MB lesbar formatiert), URL/Pfad. URL ist jetzt **sichtbar** statt nur als `title=`-Tooltip.
- **Skeleton-Loading:** 3 animierte Placeholder-Karten mit Shimmer-Effekt während des Such-Requests — kein „Suche läuft…"-Plaintext mehr.
- **Empty-State mit Tag-Vorschlägen:** Bei 0 Treffern werden bis zu 6 verfügbare Tags als klickbare Pills angezeigt („Vielleicht meinst du …") — Klick filtert die Suche auf den Tag.
- **Tag-Chips kompakt rechts neben dem Titel** (max 3 sichtbar), nicht mehr als eigene Zeile unter dem Snippet.
- **Clear-Button im Suchfeld** (X rechts), erscheint sobald Eingabe nicht leer ist.
- **Hover-Verhalten:** Card hebt sich um 1px an, dezenter Shadow in Accent-Farbe.
- **Mobile-Breakpoints (≤600px):** Kleinere Cover/Icons (44×44 statt 56×56), Snippet bis 3 Zeilen, URL ausgeblendet, Titel umbricht statt zu trunkieren, Padding reduziert.
- **`SearchDocument.file_size` + `SearchHit.fileSize`:** Dateigröße in Bytes wird beim Indexieren mitgeschrieben und im Frontend lesbar formatiert (KB/MB/GB).

### Added — PDF-Auto-Thumbnails
- **Neuer Service `PdfThumbnailGenerator`:** Generiert pro PDF ein JPG-Thumbnail der ersten Seite. Strategie: erst Ghostscript via `proc_open` (`gs`), Fallback auf PHP-Imagick wenn `gs` nicht installiert. 96 DPI, max 400px Breite, JPEG-Qualität 80 → ~30-80KB pro Thumbnail.
- **Setting `generate_pdf_thumbnails`** (Default off): Pro PDF wird beim Indexieren ein Cover generiert und unter `files/_vsearch_covers/<sha1>.jpg` gecached. Cache-Key = `sha1(absolutePath + mtime)` — beim Replace einer PDF ändert sich der mtime → neues Cover.
- **DCA-Checkbox** „PDF-Vorschaubilder" im Backend unter System → Venne Search → Indexierung. Save-Callback zeigt Hinweis auf nötigen Reindex.
- **Migration `Version220\Mig01_AddPdfThumbnails`** — Spalte `generate_pdf_thumbnails CHAR(1) NOT NULL DEFAULT '0'`.
- **Hard-Timeout 20s** für den Ghostscript-Aufruf, atomares Rename (.tmp → final), damit kaputte/abgebrochene Generationen keinen Müll-Cache hinterlassen.

### Added — Index / Sort (wie bisher in v2.2.0-dev)
- **Dokument-Cover in der Trefferliste:** Datei-Treffer werden mit einem echten Vorschaubild gerendert statt nur dem generischen SVG-Icon. Strategie: (a) Bilddateien zeigen sich selbst, (b) für PDF/DOCX/… wird ein gleichnamiges Bild im selben Verzeichnis als Cover genommen (`flyer.pdf` → `flyer.jpg`/`.png`/`.webp`, auch in Großschreibung), (c) sonst Fallback auf das Icon. Das `<img>` bekommt einen echten `alt=`-Attribut (ALT-Text aus `tl_files.meta` → Titel → „Vorschaubild") — barrierefrei und SEO-sauber, kein Hover-Tooltip-Hack mehr.
- **Sortier-Modus „Nach Dokumentenart" (`sort=type_asc`):** Sortiert lexikographisch nach `content_type` — Pages zuerst, dann Dateien gruppiert nach Extension (docx, pdf, xlsx, …). Innerhalb der Gruppe Tie-Break über `weight DESC`, damit Tag-geboostete Items innerhalb der Dateigruppe oben stehen. Im Frontend-Dropdown als vierte Option verfügbar.
- **`SearchDocument.cover_url`** + **`SearchDocument.content_type`** als Index-Felder. `content_type` ist filterable und sortable; `cover_url` ist nur Anzeige-Metadata.
- **`SearchHit.publishedAt`** als echtes Feld — Multi-Locale-Date-Merge sortiert jetzt global statt nur per Round-Robin (alte Logik vertraute auf Pro-Locale-Reihenfolge, was bei stark unterschiedlich befüllten Indexen falsch sortierte).
- **`tl_files.tstamp` als `published_at` für Datei-Treffer:** Vorher fehlte Files das Datum komplett, Date-Sort hat sie immer ans Ende verbannt. Fallback auf `filemtime()` wenn die DB-Spalte 0 ist.

### Fixed — Ticket FFA (Bernien): noSearch-Vererbung + Relevanz-Reihenfolge
- **„Nicht durchsuchen" wird jetzt über die Seiten-Hierarchie vererbt.** Bisher prüfte der Indexer `tl_page.noSearch` nur auf der Seite selbst — Unterseiten eines abgeschalteten Zweigs (FFA: Root „FFA Filmförderungsanstalt (ALT)" mit 106 Unterseiten, u.a. „Richtlinien") landeten trotzdem im Index. Neuer Service `PageSearchabilityResolver` lädt den Seitenbaum einmal pro Request und entscheidet pro Seite: eigenes Flag → `page_no_search_flag`, Vorfahre mit Flag → `page_no_search_inherited`, Root nicht veröffentlicht → `page_root_unpublished` (entspricht Contaos `PublishedFilter`: `!rootIsPublic` → 404 für den ganzen Baum; Zwischenebenen zählen wie in Contao nicht).
- Greift an allen drei Indexier-Pfaden: `ReindexCatalog` (Plan führt solche Seiten nicht mehr als Site-Item → bereits indexierte werden als Orphan erkannt und beim Finalize entfernt), `IndexableItemProcessor` (defensiver Skip mit lesbarem Grund im Indexier-Log) und `LivePageIndexer` (Save-Hook löscht Seite **plus Nachfahren** aus allen aktiven Locale-Indexen; Speichern einer Root mit `noSearch`/unveröffentlicht räumt den ganzen Baum ab).
- **Lupen-Toggle wirkt auf den ganzen Zweig:** Aus → Seite + alle Nachfahren + deren crawled-Twins aus dem Index; An → Seite + alle Nachfahren reindexiert (Nachfahren mit eigenem Flag bleiben draußen). Response liefert `affected`/`descendants`.
- **Lupen-Icon zeigt vererbten Zustand:** Unterseiten eines abgeschalteten Zweigs bekommen die durchgestrichene Lupe (abgeblendet) mit Tooltip „deaktiviert über die übergeordnete Seite „X"" — vorher sah man dort eine aktive Lupe, obwohl der Zweig laut Startpunkt nicht durchsucht werden sollte.
- **Relevanz-Sortierung: bei gleicher Relevanz jetzt das neueste Dokument zuerst.** Ohne expliziten Tie-Breaker ordnet Meilisearch gleich gut passende Treffer nach interner Doc-ID (= Indexier-Reihenfolge, Files kommen `ORDER BY path`) — „Geschäftsbericht" zeigte 2001, 2002, 2006 … oben. `DocumentIndexer::RANKING_RULES` endet jetzt auf `published_at:desc` (nach `exactness`, damit das Datum nie echte Treffer-Qualität überstimmt). PHP-seitige Re-Rankings (Multi-Locale-Merge, Type-Sort-Tie-Break) nutzen denselben Vergleich (`SearchService::compareRelevance`). **Nach dem Update einmal `venne-search:setup` ausführen** (oder irgendein Indexier-Vorgang), damit die neue Ranking-Rule in den bestehenden Index geschrieben wird.

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
