# Venne Search · Contao Bundle

Volltext-Suche für Contao. Findet Inhalte in Seiten, Artikeln, Content-Elementen und Dateien (PDF, DOCX, ODT, RTF, TXT, MD) — mehrsprachig, mit Tags und anonymen Such-Analytics.

Die Plattform-Anbindung läuft über [venne-search.de](https://venne-search.de) — das Bundle holt sich beim ersten Request einen scoped Meilisearch-Token und schreibt direkt in den passenden Index. Pro Site ein API-Key, fertig.

## Was es kann

- **Live-Indexing**: Wenn du eine Seite speicherst oder eine Datei hochlädst, ist sie kurze Zeit später in der Suche
- **Mehrsprachig**: Pro Locale ein Index, automatische Sprach-Erkennung für Dateien (Pfad → Embedding → Filename) mit Override-Möglichkeit
- **Tag-System mit Tree-Picker**: Seitenbaum öffnen, per Klick oder Drag-&-Drop Tags zuweisen — Tags erscheinen klickbar als Filter in den Such-Treffern
- **Anonymes Search-Analytics**: Erfasst auf der Plattform welche Begriffe gesucht werden (kein IP, kein User-Agent, keine User-ID) — nützlich um Content-Lücken zu finden
- **PDF-Inhalte durchsuchen**: Texte aus PDFs werden extrahiert und indexiert
- **Tippfehler-Toleranz**: `Krabbnburger` findet `Krabbenburger`
- **Diakritik-Folding**: `cafe` findet `café`, `Cafe`, `CAFÉ`
- **ß ↔ ss**: `masse` findet `Maße`
- **Schnell**: Suchen unter 50 ms bei zehntausenden Dokumenten
- **JSON-API** unter `/vsearch/api?q=…` für eigene Frontends
- **Permission-aware**: geschützte Inhalte erscheinen nur in der Suche, wenn der eingeloggte Member auch wirklich Zugriff hat

## Voraussetzungen

- Contao 4.13 oder 5.x (getestet auf 4.13, 5.3 und 5.7)
- PHP 8.1 oder neuer
- Aktiver Account auf [venne-search.de](https://venne-search.de)

## Installation

```bash
composer require venne-media/venne-search-contao-bundle
vendor/bin/contao-console contao:migrate
```

Wer den Contao-Manager nutzt: Paket suchen, installieren, Updates anwenden — der Manager ruft `contao:migrate` automatisch im Anschluss auf.

## Konfiguration

1. Auf venne-search.de einloggen → Dashboard → API-Keys → einen erzeugen
2. Im Contao-Backend: **System → Venne Search** öffnen
3. API-Key eintragen, Sprachen wählen, speichern

Das war's. Beim ersten Speichern fragt das Bundle den Endpoint und den scoped Token von venne-search.de ab und cached das eine Stunde.

## Erste Indexierung

Im Backend bei „Venne Search" auf **Vorschau & Indexieren** klicken. Du siehst vorab:

- Wie viele Seiten und Dateien indexiert werden
- Wie viele schon im Index sind (werden übersprungen)
- Welche durch Berechtigungen ausgeschlossen sind

Danach läuft die Indexierung live durch. Pro Datei siehst du Anzahl Zeichen, Dauer und ETA. Bei Problemen klare Skip-Gründe wie „PDF passwortgeschützt", „PDF zu groß" oder „PDF enthält nur Bilder".

## Sicherheit

Geschützte Inhalte (Member-Bereiche) bleiben geschützt. Zwei Modi zur Auswahl:

- **Nur öffentlich erreichbare Inhalte** (empfohlen, Default): geschützte Seiten und Dateien landen gar nicht erst im Index
- **Auch geschützte Inhalte — Frontend filtert pro Mitglied**: alles indexiert, anonyme Besucher sehen nur freie Treffer, eingeloggte Member zusätzlich ihre erlaubten geschützten — markiert mit einem Schloss-Icon

Zusätzlich gibt es einen **Tree-Picker** im Backend, mit dem du pro Klick einzelne Ordner aus dem Datei-Manager komplett von der Indexierung ausschließen kannst. Bei der ersten Migration werden — falls vorhanden — `files/intern`, `files/admin` und `files/private` automatisch vorausgewählt.

Im Frontend filtert Meilisearch hart bei jedem Such-Call:

```
Anonymer Besucher           → is_protected != true
Eingeloggter Member [3, 7]  → is_protected != true OR allowed_groups IN [3, 7]
```

Auch wenn versehentlich was Geschütztes im Index liegt, der Filter blockiert es zur Suchzeit.

## Frontend einbauen

### Variante 1: Contao-Frontend-Modul

Layout → Frontend-Module → Neues Modul → Typ **Venne Search** → ins Layout ziehen.

### Variante 2: Eigenes Frontend per JSON-API

```html
<input id="search" placeholder="Suchen…">
<div id="results"></div>

<script>
document.getElementById('search').addEventListener('input', async (e) => {
    const q = e.target.value.trim();
    if (q.length < 3) { document.getElementById('results').innerHTML = ''; return; }
    const r = await fetch('/vsearch/api?q=' + encodeURIComponent(q) + '&locale=de');
    const data = await r.json();
    document.getElementById('results').innerHTML = data.hits
        .map(h => `<a href="${h.url}"><b>${h.title}</b><br>${h.snippet}</a>`)
        .join('<hr>');
});
</script>
```

### API-Antwort

```json
{
  "hits": [
    {
      "id": "page-42",
      "type": "page",
      "title": "Über uns",
      "url": "/ueber-uns.html",
      "snippet": "…<mark>Team</mark>…",
      "tags": ["team"],
      "score": 0.876
    }
  ],
  "totalHits": 12,
  "facets": {"type": {"page": 8, "file": 4}},
  "queryTimeMs": 7
}
```

### Fehler-Codes der API

| HTTP | `error`                  | Was bedeutet das                                       |
|------|--------------------------|--------------------------------------------------------|
| 401  | `unauthorized`           | Plattform-Key ungültig oder widerrufen                 |
| 402  | `subscription_inactive`  | Kein aktives Abo                                       |
| 403  | `not_provisioned`        | Plattform-Admin muss den Key noch einrichten           |
| 429  | `rate_limited`           | Zu viele Anfragen, kurz warten                         |
| 503  | `platform_unreachable`   | venne-search.de gerade nicht erreichbar                |

## Architektur in einem Bild

```
Contao-Site (Bundle)
   │
   │  1. Plattform-Key vsk_live_…
   ▼
venne-search.de   ───►   { endpoint, indexPrefix, scopedToken }
                                       │
                                       │  2. scoped Token darf nur t_<prefix>_*
                                       ▼
                                Meilisearch-Server
```

Der Master-Key bleibt bei der Plattform, das Bundle bekommt nur den Token für seinen eigenen Tenant. Alle Index-Calls werden zusätzlich gegen ein erwartetes UID-Pattern validiert — Defense in Depth.

## Auto-Indexing

Diese Hooks sind aktiv, ohne dass du was tun musst:

- Speichern einer Seite, eines Artikels oder Content-Elements → indexiert
- Hochladen einer Datei im Datei-Manager → indexiert
- Löschen einer Datei → aus Index entfernt

Im Backend gibt es einen **Toggle**, der das Auto-Indexing komplett abschaltet — falls du den Index lieber gebündelt manuell aktualisierst. Pro Eintrag in der Tabelle „Indexierte Daten" gibt es einen **Refresh-Knopf**, mit dem du einzelne Dokumente neu indexieren kannst, ohne den ganzen Lauf anzustoßen.

Datei per FTP direkt auf den Server kopieren umgeht den Hook — dafür gibt's den **Vorschau & Indexieren**-Button im Backend, der die Datei dann beim nächsten Lauf erfasst.

## Mehrsprachigkeit

Das Bundle indexiert pro **Locale** in einen eigenen Index — eine Site mit `de,en,fr` als aktive Sprachen hat drei Indexe. Pages bringen ihre Sprache aus `tl_page.language` mit; **Dateien werden über fünf Strategien automatisch zugeordnet** (in dieser Reihenfolge, erste die liefert gewinnt):

1. **Override** aus den Settings (`file_locale_overrides` JSON-Map)
2. **Page-Embedding**: Wo ist die Datei in `tl_content.singleSRC` / `multiSRC` eingebunden? Dominante Sprache der Pages gewinnt.
3. **Pfad-Hint**: `files/de/...`, `files/en_US/...`
4. **Filename-Hint**: `manual_de.pdf`, `flyer-en.pdf`
5. **Default**: `default_file_locale` aus den Settings, sonst erste aktive Locale

Du kannst pro Datei direkt im Documents-Panel die Sprache überschreiben — der Eintrag wird sofort reindexiert.

Beim Anlegen eines Frontend-Moduls oder Content-Elements vom Typ "Venne Search" wählst du **eine** feste Such-Sprache. Endnutzer wechseln die Sprache **nicht** selbst — sie sehen genau eine Suche, die zur aktuellen Seite passt.

## Tag-System

Im Backend unter **System → Venne Search** zwei neue Bereiche:

- **Seitenbaum-Tagging**: Tree mit allen Seiten, pro Zeile aktuelle Tag-Chips. "+"-Button öffnet eine Combobox mit Live-Search; nicht existierende Tags lassen sich on-the-fly anlegen. Drag-&-Drop: Chip auf eine andere Page-Zeile ziehen = Tag mit-zuweisen. Jede Änderung triggert sofortiges Reindexing.
- **Tag-Verwaltung**: Eigene Tabelle `tl_venne_search_tag` mit Slug, Label, Beschreibung, Farbe (8 vorgegebene Farben). Pro Tag wird die Anzahl der Zuweisungen direkt angezeigt.
- **Tag-Übersicht**: Tabellarisch alle Tags mit Counts, Farb-Chips und Edit-Link.

Im Frontend werden Tag-Chips an jeden Treffer gehängt (`tagsResolved` mit `slug`, `label`, `color`). Klickbar als Filter via `?tags[]=spongebob&tags[]=krabbenburger`. Mehrere Tags = AND-Verknüpfung (Meilisearch `IN`).

Die Migration übernimmt einmalig vorhandene `tl_page.keywords`-CSVs ins neue System — gleicher Slug = gleicher Tag.

## Search-Analytics

Standardmäßig aktiv (`analytics_enabled = 1`). Jeder Such-Request wird **anonym** in einen JSONL-Tagespuffer geschrieben:

```jsonl
{"q":"spongebob","locale":"de","results":12,"ts":1715000000}
```

**Was wird gespeichert**: Suchbegriff, Locale, Treffer-Anzahl, Timestamp.
**Was wird NICHT gespeichert**: IP-Adresse, User-Agent, Cookies, Session-IDs, User-Account-Bezug.

Ein Cron-Worker schickt die Tagespuffer (außer dem heutigen, der noch in use ist) regelmäßig zur Plattform:

```cron
*/5 * * * *  cd /var/www/site && php vendor/bin/contao-console venne-search:analytics:flush
```

Auf [venne-search.de](https://venne-search.de) gibt's pro API-Key ein **Analytics-Dashboard**: Top-Queries, Zero-Result-Rate (super wertvoll für SEO), Sparkline letzte 30 Tage, CSV-Export, **Reset-Button** (User-getriggertes Löschen). Kein Auto-Purge — die Logs bleiben so lange du willst.

Im Backend unter **System → Venne Search → Analytics-Status** siehst du den Buffer-Stand und kannst manuell flushen.

## Lizenz

LGPL-3.0-or-later

## Kontakt

- E-Mail: [jschwarz@venne-media.de](mailto:jschwarz@venne-media.de)
- Issues: [GitHub](https://github.com/Venne-Media-GmbH/venne-search-contao-bundle/issues)
- Plattform: [venne-search.de](https://venne-search.de)
