<?php

declare(strict_types=1);

/**
 * Beschriftungen für das Settings-Backend-Formular.
 */

// Legends (Form-Gruppen)
$GLOBALS['TL_LANG']['tl_venne_search_settings']['verbindung_legend'] = 'Verbindung';
$GLOBALS['TL_LANG']['tl_venne_search_settings']['indexing_legend'] = 'Indexierung';
$GLOBALS['TL_LANG']['tl_venne_search_settings']['reindex_legend'] = 'Index aktualisieren';
$GLOBALS['TL_LANG']['tl_venne_search_settings']['security_legend'] = 'Sicherheit & Zugriff';
$GLOBALS['TL_LANG']['tl_venne_search_settings']['status_legend'] = 'Status';
$GLOBALS['TL_LANG']['tl_venne_search_settings']['documents_legend'] = 'Indexierte Daten';
$GLOBALS['TL_LANG']['tl_venne_search_settings']['reindex_button'] = ['Index aktualisieren', 'Erst Vorschau (was ist neu vs. schon im Index?), dann live indexieren.'];

// Pseudo-Felder
$GLOBALS['TL_LANG']['tl_venne_search_settings']['status_panel'] = ['Index-Status', 'Live-Statistik aus dem Suchindex.'];
$GLOBALS['TL_LANG']['tl_venne_search_settings']['documents_panel'] = ['Dokumente', 'Liste aller indexierten Seiten und Dateien mit Filter.'];

// Felder
$GLOBALS['TL_LANG']['tl_venne_search_settings']['api_key'] = [
    'API-Key',
    'Dein persönlicher Venne-Search-API-Key. Erstelle einen unter <a href="https://venne-search.de" target="_blank">venne-search.de</a> → Dashboard → API-Keys. Format: <code>vsk_live_…</code>',
];
$GLOBALS['TL_LANG']['tl_venne_search_settings']['enabled_locales'] = [
    'Aktive Sprachen',
    'Komma-getrennte Liste der Sprachen, in denen die Site indexiert werden soll. Standard: <code>de</code>. Mehrsprachige Sites z. B.: <code>de,en,fr</code>.',
];
$GLOBALS['TL_LANG']['tl_venne_search_settings']['index_pdfs'] = [
    'Auch Dateien indexieren',
    'PDF, DOCX, ODT, RTF, TXT, MD werden durchsuchbar (max. 25 MB pro Datei).',
];
$GLOBALS['TL_LANG']['tl_venne_search_settings']['auto_indexing'] = [
    'Automatisch indexieren',
    'Reindex bei jedem Save und nach Datei-Upload/-Löschen.',
];

// Operations
$GLOBALS['TL_LANG']['tl_venne_search_settings']['edit'] = ['Bearbeiten', 'Verbindung und Indexierung anpassen'];
$GLOBALS['TL_LANG']['tl_venne_search_settings']['reindex'] = ['Komplett-Indexierung', 'Alle Seiten und Dateien neu indexieren — läuft asynchron im Hintergrund.'];
$GLOBALS['TL_LANG']['tl_venne_search_settings']['status'] = ['Status', 'Index-Status anzeigen: Anzahl Dokumente, letzter Reindex, Fehler.'];

// Errors / Hints
$GLOBALS['TL_LANG']['tl_venne_search_settings']['no_api_key_hint'] = 'Bitte trage deinen API-Key ein, bevor du indexieren kannst.';
$GLOBALS['TL_LANG']['tl_venne_search_settings']['connection_ok'] = 'Verbindung zum Venne-Search-Service hergestellt.';
$GLOBALS['TL_LANG']['tl_venne_search_settings']['connection_failed'] = 'Verbindung fehlgeschlagen — prüfe deinen API-Key.';

// Sicherheit & Zugriff
$GLOBALS['TL_LANG']['tl_venne_search_settings']['index_mode'] = [
    'Was soll indexiert werden?',
    'Empfehlung: "Nur öffentliche". Geschützte Seiten und Dateien bleiben dann garantiert aus der Suche raus.',
];
$GLOBALS['TL_LANG']['tl_venne_search_settings']['index_mode_options'] = [
    'public_only' => 'Nur öffentlich erreichbare Inhalte (empfohlen)',
    'with_protected' => 'Auch geschützte Inhalte — Frontend filtert pro Mitglied',
];
$GLOBALS['TL_LANG']['tl_venne_search_settings']['excluded_folders'] = [
    'Diese Ordner überspringen',
    'Wähle Ordner, deren Inhalt NICHT in die Suche soll. Unterordner werden automatisch mit ausgeschlossen. Klassische Kandidaten: <code>files/intern</code>, <code>files/admin</code>, <code>files/private</code>.',
];
