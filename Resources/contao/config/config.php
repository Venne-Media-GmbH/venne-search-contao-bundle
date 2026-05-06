<?php

declare(strict_types=1);

/**
 * Backend-Modul: Venne Search — alles in einem Modul:
 * Settings (API-Key + Sprachen + PDF-Toggle), Status, Index-Browser, Reindex.
 * Auf Edit-Form werden Status + Index-Tabelle direkt unter den Form-Feldern
 * mitgerendert (siehe DCA-onsubmit_callback / Twig-Snippet).
 */
$GLOBALS['BE_MOD']['system']['venne_search'] = [
    'tables' => ['tl_venne_search_settings', 'tl_venne_search_tag'],
    'icon' => 'bundles/vennesearchcontao/icon.svg',
];

/**
 * Hook-Registrierung als Fallback für Contao 4.13 vor 4.13.7. Ab 4.13.7
 * würden #[AsHook]-Attribute in den Listener-Klassen automatisch greifen,
 * aber die explizite Registrierung hier ist redundanz-sicher und schadet
 * nicht (Contao deduped automatisch).
 */
$GLOBALS['TL_HOOKS']['onsubmit_callback'][] = [
    VenneMedia\VenneSearchContaoBundle\EventListener\PageSaveListener::class,
    '__invoke',
];

$GLOBALS['TL_HOOKS']['postUpload'][] = [
    VenneMedia\VenneSearchContaoBundle\EventListener\FileUploadListener::class,
    '__invoke',
];

$GLOBALS['TL_HOOKS']['initializeSystem'][] = [
    VenneMedia\VenneSearchContaoBundle\EventListener\InitializeSettingsListener::class,
    '__invoke',
];

/**
 * Frontend-Modul: Suchformular mit Live-Vorschau für die Endbesucher.
 */
$GLOBALS['FE_MOD']['miscellaneous']['venne_search'] = VenneMedia\VenneSearchContaoBundle\Module\ModuleVenneSearch::class;

/**
 * Content-Element: dieselbe Suche direkt im Artikel als Elementtyp wählbar
 * (kein Modul-Umweg nötig). Erscheint in der Element-Liste unter
 * "Verschiedenes → Venne Search".
 */
$GLOBALS['TL_CTE']['miscellaneous']['venne_search'] = VenneMedia\VenneSearchContaoBundle\Element\ContentVenneSearch::class;
