<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_venne_search_tag']['title_legend'] = 'Tag';
$GLOBALS['TL_LANG']['tl_venne_search_tag']['description_legend'] = 'Beschreibung';
$GLOBALS['TL_LANG']['tl_venne_search_tag']['assignments_legend'] = 'Zugewiesene Seiten und Dateien';

$GLOBALS['TL_LANG']['tl_venne_search_tag']['label'] = ['Bezeichnung', 'Was Besucher als Tag-Chip neben Treffern sehen.'];
$GLOBALS['TL_LANG']['tl_venne_search_tag']['color'] = ['Farbe', 'Wird in Such-Treffern als Chip-Farbe verwendet.'];
$GLOBALS['TL_LANG']['tl_venne_search_tag']['boost'] = ['Relevanz-Boost', 'Hebt Treffer mit diesem Tag in der Trefferliste nach oben. Bei Standard (1.0) zählt nur die normale Relevanz; höhere Werte stellen den Treffer auch dann oben dar, wenn der Suchbegriff nur über den Tag matcht. Bei Änderung werden alle zugewiesenen Inhalte automatisch reindexiert.'];
$GLOBALS['TL_LANG']['tl_venne_search_tag']['description'] = ['Beschreibung', 'Optional. Erscheint im Tooltip, wenn der User über den Chip fährt.'];
$GLOBALS['TL_LANG']['tl_venne_search_tag']['assignments_panel'] = ['Zugewiesene Inhalte', 'Übersicht aller Seiten und Dateien, die diesen Tag tragen — mit Entfernen-Knopf.'];

$GLOBALS['TL_LANG']['tl_venne_search_tag']['color_options'] = [
    'blue'   => 'Blau',
    'green'  => 'Grün',
    'red'    => 'Rot',
    'orange' => 'Orange',
    'purple' => 'Lila',
    'pink'   => 'Pink',
    'gray'   => 'Grau',
    'teal'   => 'Türkis',
];

$GLOBALS['TL_LANG']['tl_venne_search_tag']['boost_options'] = [
    '1.00'  => 'Standard (1.0)',
    '1.50'  => 'Leicht erhöht (1.5)',
    '2.00'  => 'Erhöht (2.0)',
    '3.00'  => 'Stark (3.0)',
    '5.00'  => 'Sehr stark (5.0)',
    '10.00' => 'Maximum (10.0)',
];

// Help-Wizard-Text zum Boost-Feld.
$GLOBALS['TL_LANG']['XPL']['venneSearchTagBoost'] = [
    [
        'Was bewirkt der Boost?',
        '<p>Der Boost-Wert hebt Treffer, die diesem Tag zugewiesen sind, in der Suchergebnis-Liste nach oben. Beispiel: Eine Seite „Einreich- und Sitzungstermine" mit dem Boost-Tag „Fristen" (5.0) erscheint bei einer Suche nach „Fristen" über allen Seiten, auf denen das Wort nur im Fließtext steht.</p>'
        . '<p>Mehrere Tags pro Inhalt: Es zählt der höchste Boost-Wert.</p>'
        . '<p>Wirkung tritt erst nach Reindex der zugewiesenen Inhalte ein — das passiert beim Speichern automatisch.</p>',
    ],
];
