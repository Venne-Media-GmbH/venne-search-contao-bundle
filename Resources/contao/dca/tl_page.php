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

// Tag-Feld zur SEO-Palette hinzufügen (existiert in 4.13, 5.x).
// __selector__ NICHT anfassen — wir wollen kein subpalette.
if (isset($GLOBALS['TL_DCA']['tl_page']['palettes'])) {
    foreach ($GLOBALS['TL_DCA']['tl_page']['palettes'] as $key => &$palette) {
        if ($key === '__selector__' || !\is_string($palette)) {
            continue;
        }
        // Wir hängen das Feld an die "meta_legend"-Gruppe (SEO-Bereich) an,
        // falls vorhanden — sonst an "expert_legend" oder ans Ende.
        if (str_contains($palette, '{meta_legend')) {
            $palette = preg_replace(
                '/(\{meta_legend[^}]*\}[^;]*)/',
                '$1,vsearch_tags',
                $palette,
                1,
            );
        } elseif (str_contains($palette, '{expert_legend')) {
            $palette = preg_replace(
                '/(\{expert_legend[^}]*\}[^;]*)/',
                '$1,vsearch_tags',
                $palette,
                1,
            );
        } else {
            // Letzter Ausweg: an alle Palette anhängen unter eigenem Legend.
            $palette .= ';{vsearch_legend},vsearch_tags';
        }
    }
    unset($palette);
}

// Operation "Tags" pro Page-Zeile in der Seitenstruktur. Zeigt ein Icon das
// auf das DCA-Edit-Form springt (direkt mit Fokus auf das Tag-Feld via Anchor).
// Funktioniert auf Contao 4.13 + 5.x identisch — Standard-href-Notation.
if (isset($GLOBALS['TL_DCA']['tl_page']['list']['operations'])) {
    $GLOBALS['TL_DCA']['tl_page']['list']['operations']['vsearch_tags'] = [
        'label' => &$GLOBALS['TL_LANG']['tl_page']['vsearch_tags_op'],
        'href' => 'act=edit#pal_vsearch_legend',
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
