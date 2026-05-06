<?php

declare(strict_types=1);

/**
 * Erweitert die Frontend-Modul-DCA um eine Palette für unser Modul "venne_search".
 * Der Admin sieht im Backend → Themes → Frontend-Module → "Neues Modul" eine
 * Auswahl "Venne Search" und kann darin Placeholder, Limit, Min-Chars,
 * Facets pflegen.
 *
 * Sprachdateien werden hier EXPLIZIT geladen — Contao 4.13 lädt zwar
 * Bundle-Languages automatisch, aber NICHT die unseres Frontend-Moduls
 * unter `tl_module`, weil das Frontend-Modul-DCA nur per System::loadLanguageFile
 * den Bundle-Pfad konsultiert. Ohne diese Zeilen sieht der User die
 * Field-Keys ("vsearch_display_mode") statt der deutschen Labels.
 */
\Contao\System::loadLanguageFile('tl_module');

$GLOBALS['TL_DCA']['tl_module']['palettes']['venne_search'] = '{title_legend},name,headline,type'.
    ';{config_legend},vsearch_display_mode,vsearch_locale,vsearch_trigger_label,vsearch_placeholder,vsearch_button_label,vsearch_min_chars,vsearch_limit,vsearch_show_facets'.
    ';{template_legend:hide},customTpl'.
    ';{protected_legend:hide},protected'.
    ';{expert_legend:hide},guests,cssID';

$GLOBALS['TL_DCA']['tl_module']['fields']['vsearch_display_mode'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_display_mode'],
    'inputType' => 'select',
    'options' => ['inline', 'modal'],
    'reference' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_display_modes'],
    'eval' => ['tl_class' => 'w50', 'mandatory' => true, 'submitOnChange' => true],
    'sql' => "varchar(16) NOT NULL default 'inline'",
];
$GLOBALS['TL_DCA']['tl_module']['fields']['vsearch_trigger_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_trigger_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 64, 'tl_class' => 'w50', 'placeholder' => 'Suche öffnen'],
    'sql' => "varchar(64) NOT NULL default ''",
];
$GLOBALS['TL_DCA']['tl_module']['fields']['vsearch_placeholder'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_placeholder'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 64, 'tl_class' => 'w50', 'placeholder' => 'Suche…'],
    'sql' => "varchar(64) NOT NULL default ''",
];
$GLOBALS['TL_DCA']['tl_module']['fields']['vsearch_button_label'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_button_label'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 32, 'tl_class' => 'w50', 'placeholder' => 'Suchen'],
    'sql' => "varchar(32) NOT NULL default ''",
];
$GLOBALS['TL_DCA']['tl_module']['fields']['vsearch_min_chars'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_min_chars'],
    'inputType' => 'text',
    'eval' => ['rgxp' => 'natural', 'tl_class' => 'w50', 'placeholder' => '3'],
    'sql' => 'int(2) unsigned NOT NULL default 3',
];
$GLOBALS['TL_DCA']['tl_module']['fields']['vsearch_limit'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_limit'],
    'inputType' => 'text',
    'eval' => ['rgxp' => 'natural', 'tl_class' => 'w50', 'placeholder' => '10'],
    'sql' => 'int(3) unsigned NOT NULL default 10',
];
$GLOBALS['TL_DCA']['tl_module']['fields']['vsearch_show_facets'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_show_facets'],
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => "char(1) NOT NULL default '1'",
];

// v2.0.0: pro Modul konfigurierbares Such-Locale. Optionen werden zur Laufzeit
// aus den enabled_locales gefüllt (siehe options_callback).
$GLOBALS['TL_DCA']['tl_module']['fields']['vsearch_locale'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['vsearch_locale'],
    'inputType' => 'select',
    'options_callback' => static function (): array {
        try {
            $repo = \Contao\System::getContainer()
                ?->get('VenneMedia\\VenneSearchContaoBundle\\Service\\Settings\\SettingsRepository');
            $config = $repo?->load();
            return array_combine($config->enabledLocales, $config->enabledLocales) ?: ['de' => 'de'];
        } catch (\Throwable) {
            return ['de' => 'de'];
        }
    },
    'eval' => ['includeBlankOption' => true, 'tl_class' => 'w50', 'blankOptionLabel' => 'Sprache der aktuellen Seite'],
    'sql' => "varchar(8) NOT NULL default ''",
];
