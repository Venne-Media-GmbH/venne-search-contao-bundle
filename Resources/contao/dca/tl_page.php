<?php

declare(strict_types=1);

/**
 * v2.2.0 — Erweitert tl_page um ein "Such-Tags"-Feld in der SEO-Palette.
 *
 * Das Feld ist KEIN echtes DCA-Feld mit DB-Spalte — Tags werden in
 * tl_venne_search_tag_assignment gespeichert. Wir nutzen `eval[doNotSaveEmpty]`
 * + `input_field_callback`, sodass Contao's Standard-Save-Pipeline das Feld
 * komplett überspringt. Die Combobox spricht direkt die existierenden
 * AJAX-Endpoints (tag/assign, tag/unassign, tag/suggest).
 *
 * Kompatibel mit Contao 4.13, 5.3, 5.7 — wir nutzen nur Standard-DCA-Strings
 * und vermeiden Klassen-Referenzen die zwischen den Versionen variieren.
 */

// Tag-Feld in die Palette einfuegen — als onload_callback, damit es NACH
// allen anderen DCA-Plugins laeuft. Auf FFA gibt es opengraph3, rocksolid_
// mega_menu, custom Page-DCAs etc., die die Palette evtl. ueberschreiben.
$GLOBALS['TL_DCA']['tl_page']['config']['onload_callback'][] = static function (): void {
    if (!isset($GLOBALS['TL_DCA']['tl_page']['palettes']) || !\is_array($GLOBALS['TL_DCA']['tl_page']['palettes'])) {
        return;
    }
    foreach ($GLOBALS['TL_DCA']['tl_page']['palettes'] as $key => &$palette) {
        if ($key === '__selector__' || !\is_string($palette)) {
            continue;
        }
        // Schon drin? Skip.
        if (str_contains($palette, 'vsearch_tags')) {
            continue;
        }
        // Eigene Legend ans Ende der Palette anhaengen — robust gegen alle
        // anderen Plugin-Manipulationen, weil wir NICHTS Existierendes
        // ersetzen oder erweitern.
        $palette .= ';{vsearch_legend},vsearch_tags';
    }
    unset($palette);
};

// Operation "Tags" pro Page-Zeile in der Seitenstruktur. Zeigt ein Icon das
// auf das DCA-Edit-Form springt. Contao 4.13 wuerde einen #-Anchor im href
// als Teil des `act=`-Parameters interpretieren — daher hier KEIN Anchor.
// Der User landet auf der Edit-Form, kann selbst zum Such-Tags-Feld scrollen.
if (isset($GLOBALS['TL_DCA']['tl_page']['list']['operations'])) {
    $GLOBALS['TL_DCA']['tl_page']['list']['operations']['vsearch_tags'] = [
        'label' => &$GLOBALS['TL_LANG']['tl_page']['vsearch_tags_op'],
        'href' => 'act=edit',
        'icon' => 'bundles/vennesearchcontao/icons/tag.svg',
        'attributes' => 'title="Such-Tags vergeben"',
    ];
}

$GLOBALS['TL_DCA']['tl_page']['fields']['vsearch_tags'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_page']['vsearch_tags'],
    'exclude' => true,
    'input_field_callback' => [
        VenneMedia\VenneSearchContaoBundle\EventListener\PageTagFieldListener::class,
        'render',
    ],
    'eval' => [
        'doNotSaveEmpty' => true,
        'doNotShow' => false,
        'doNotCopy' => true,
        // Kein SQL — das Feld liest und schreibt direkt in tl_venne_search_tag_assignment.
    ],
];
