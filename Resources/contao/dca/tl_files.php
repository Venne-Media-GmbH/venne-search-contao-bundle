<?php

declare(strict_types=1);

/**
 * v2.2.0 — Tag-Vergabe fuer Dateien (analog tl_page.php).
 *
 * Bisher konnten Tags nur an Contao-Pages haengen. FFA und andere
 * dokumentenlastige Sites wollen aber auch PDF/DOCX/XLSX taggen koennen
 * (z.B. „Pressemitteilung", „Formular"). Backend-Tag-Endpoints unterstuetzen
 * `target_type=file` bereits — uns fehlte nur die UI.
 *
 * Wir erweitern das tl_files-DCA per Hook:
 *   - Operation „Such-Tags" pro Datei-Zeile (Tag-Icon, springt zur Edit-Form)
 *   - input_field_callback `vsearch_file_tags` rendert die Tag-Picker-Box
 */

$GLOBALS['TL_DCA']['tl_files']['config']['onload_callback'][] = static function (): void {
    if (!isset($GLOBALS['TL_DCA']['tl_files']['palettes']) || !\is_array($GLOBALS['TL_DCA']['tl_files']['palettes'])) {
        return;
    }
    foreach ($GLOBALS['TL_DCA']['tl_files']['palettes'] as $key => &$palette) {
        if ($key === '__selector__' || !\is_string($palette)) {
            continue;
        }
        if (str_contains($palette, 'vsearch_file_tags')) {
            continue;
        }
        $palette .= ';{vsearch_legend},vsearch_file_tags';
    }
    unset($palette);
};

if (isset($GLOBALS['TL_DCA']['tl_files']['list']['operations'])) {
    $GLOBALS['TL_DCA']['tl_files']['list']['operations']['vsearch_tags'] = [
        'label' => &$GLOBALS['TL_LANG']['tl_files']['vsearch_tags_op'],
        'href' => 'act=edit',
        'icon' => 'bundles/vennesearchcontao/icons/tag.svg',
        'attributes' => 'title="Such-Tags vergeben"',
    ];
}

$GLOBALS['TL_DCA']['tl_files']['fields']['vsearch_file_tags'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_files']['vsearch_file_tags'],
    'exclude' => true,
    'input_field_callback' => [
        \VenneMedia\VenneSearchContaoBundle\EventListener\FileTagFieldListener::class,
        'render',
    ],
    'eval' => [
        'doNotSaveEmpty' => true,
        'doNotShow' => false,
        'doNotCopy' => true,
    ],
];
