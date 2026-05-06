<?php

declare(strict_types=1);

/**
 * DCA-Stub für die Assignment-Tabelle.
 *
 * Diese Tabelle hat KEINE Backend-UI (die Verwaltung läuft über den Tree-Picker
 * in tl_venne_search_settings). Wir deklarieren hier aber die Spaltenstruktur,
 * damit Contao die Tabelle beim Schema-Diff NICHT als "DROP" einstuft.
 */
$GLOBALS['TL_DCA']['tl_venne_search_tag_assignment'] = [
    'config' => [
        'dataContainer' => class_exists(\Contao\DC_Table::class) ? \Contao\DC_Table::class : 'Table',
        'closed' => true,
        'notDeletable' => true,
        'notCopyable' => true,
        'notCreatable' => true,
        'notEditable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                // Index-Namen folgen Contao-Konvention "Spalte_Spalte_..."
                'tag_id,target_type,target_id' => 'unique',
                'target_type,target_id' => 'index',
                'tag_id' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'tag_id' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'target_type' => [
            'sql' => "varchar(8) NOT NULL default ''",
        ],
        'target_id' => [
            'sql' => "varchar(128) NOT NULL default ''",
        ],
    ],
];
