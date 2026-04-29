<?php

declare(strict_types=1);

/**
 * Erweitert tl_content um eine Palette für unser Content-Element venne_search.
 * Die Konfigurations-Felder kommen aus der Modul-DCA-Registrierung
 * (tl_module.php) — wir nutzen die identische `vsearch_*`-Spalten-Struktur,
 * auch in tl_content.
 *
 * tl_module-Sprachdatei laden, weil unsere Field-Labels per Reference auf
 * $GLOBALS['TL_LANG']['tl_module']['vsearch_*'] zeigen.
 */
\Contao\System::loadLanguageFile('tl_module');

$GLOBALS['TL_DCA']['tl_content']['palettes']['venne_search'] = '{type_legend},type,headline'.
    ';{config_legend},vsearch_display_mode,vsearch_trigger_label,vsearch_placeholder,vsearch_button_label,vsearch_min_chars,vsearch_limit,vsearch_show_facets'.
    ';{template_legend:hide},customTpl'.
    ';{protected_legend:hide},protected'.
    ';{expert_legend:hide},guests,cssID';

// Felder werden auf tl_content gespiegelt, damit sie persistiert werden.
foreach (['vsearch_display_mode', 'vsearch_trigger_label', 'vsearch_placeholder', 'vsearch_button_label', 'vsearch_min_chars', 'vsearch_limit', 'vsearch_show_facets'] as $field) {
    if (!isset($GLOBALS['TL_DCA']['tl_content']['fields'][$field])
        && isset($GLOBALS['TL_DCA']['tl_module']['fields'][$field])
    ) {
        $GLOBALS['TL_DCA']['tl_content']['fields'][$field] = $GLOBALS['TL_DCA']['tl_module']['fields'][$field];
    }
}

// Falls tl_module-DCA noch nicht geladen war (Reihenfolge), explizit definieren.
// WICHTIG: jedes Field bekommt explizit ein 'label', sonst rendert Contao
// im Inhaltselement-Editor die Raw-Spaltennamen ("vsearch_display_mode"
// statt "Anzeige").
if (!isset($GLOBALS['TL_DCA']['tl_content']['fields']['vsearch_display_mode'])) {
    $GLOBALS['TL_DCA']['tl_content']['fields']['vsearch_display_mode'] = [
        'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_display_mode'],
        'inputType' => 'select',
        'options' => ['inline', 'modal'],
        'reference' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_display_modes'],
        'eval' => ['tl_class' => 'w50', 'mandatory' => true, 'submitOnChange' => true],
        'sql' => "varchar(16) NOT NULL default 'inline'",
    ];
    $GLOBALS['TL_DCA']['tl_content']['fields']['vsearch_trigger_label'] = [
        'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_trigger_label'],
        'inputType' => 'text',
        'eval' => ['maxlength' => 64, 'tl_class' => 'w50', 'placeholder' => 'Suche öffnen'],
        'sql' => "varchar(64) NOT NULL default ''",
    ];
    $GLOBALS['TL_DCA']['tl_content']['fields']['vsearch_placeholder'] = [
        'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_placeholder'],
        'inputType' => 'text',
        'eval' => ['maxlength' => 64, 'tl_class' => 'w50', 'placeholder' => 'Suche…'],
        'sql' => "varchar(64) NOT NULL default ''",
    ];
    $GLOBALS['TL_DCA']['tl_content']['fields']['vsearch_button_label'] = [
        'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_button_label'],
        'inputType' => 'text',
        'eval' => ['maxlength' => 32, 'tl_class' => 'w50', 'placeholder' => 'Suchen'],
        'sql' => "varchar(32) NOT NULL default ''",
    ];
    $GLOBALS['TL_DCA']['tl_content']['fields']['vsearch_min_chars'] = [
        'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_min_chars'],
        'inputType' => 'text',
        'eval' => ['rgxp' => 'natural', 'tl_class' => 'w50', 'placeholder' => '3'],
        'sql' => 'int(2) unsigned NOT NULL default 3',
    ];
    $GLOBALS['TL_DCA']['tl_content']['fields']['vsearch_limit'] = [
        'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_limit'],
        'inputType' => 'text',
        'eval' => ['rgxp' => 'natural', 'tl_class' => 'w50', 'placeholder' => '10'],
        'sql' => 'int(3) unsigned NOT NULL default 10',
    ];
    $GLOBALS['TL_DCA']['tl_content']['fields']['vsearch_show_facets'] = [
        'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_show_facets'],
        'inputType' => 'checkbox',
        'eval' => ['tl_class' => 'w50 m12'],
        'sql' => "char(1) NOT NULL default '1'",
    ];
}
