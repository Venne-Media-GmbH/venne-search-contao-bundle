<?php

declare(strict_types=1);

/**
 * DCA: Synonym-Mapping fuer Venne Search.
 * Im Backend unter „System → Venne Search → Synonyme".
 *
 *   Term      = das Wort wie es im Index/Content steht (z.B. „Ausstellung")
 *   Synonyme  = Komma-getrennte Alternativ-Begriffe (z.B. „Messe, Expo, Fair")
 *
 * Beim Save wird die Liste an Meilisearch durchgereicht — Suche nach „Messe"
 * findet dann auch Docs mit „Ausstellung".
 */
$GLOBALS['TL_DCA']['tl_venne_search_synonym'] = [
    'config' => [
        'dataContainer' => class_exists(\Contao\DC_Table::class) ? \Contao\DC_Table::class : 'Table',
        'enableVersioning' => true,
        'onsubmit_callback' => [
            [
                \VenneMedia\VenneSearchContaoBundle\EventListener\SynonymSaveListener::class,
                'onSave',
            ],
        ],
        'ondelete_callback' => [
            [
                \VenneMedia\VenneSearchContaoBundle\EventListener\SynonymSaveListener::class,
                'onDelete',
            ],
        ],
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'term' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => 1,
            'fields' => ['term'],
            'panelLayout' => 'search,limit',
        ],
        'label' => [
            'fields' => ['term', 'synonyms'],
            'format' => '%s &nbsp;<span style="color:#888">→ %s</span>',
            'showColumns' => false,
        ],
        'global_operations' => [
            'all' => [
                'label' => &$GLOBALS['TL_LANG']['MSC']['all'],
                'href' => 'act=select',
                'class' => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()"',
            ],
        ],
        'operations' => [
            'edit' => [
                'href' => 'act=edit',
                'icon' => 'edit.svg',
            ],
            'toggle' => [
                'href' => 'act=toggle&amp;field=active',
                'icon' => 'visible.svg',
            ],
            'delete' => [
                'href' => 'act=delete',
                'icon' => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\'Synonym wirklich loeschen?\'))return false;Backend.getScrollOffset()"',
            ],
        ],
    ],
    'palettes' => [
        'default' => '{term_legend},term,synonyms;{settings_legend},active,description',
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'term' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_synonym']['term'],
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 128, 'tl_class' => 'w50'],
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        'synonyms' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_synonym']['synonyms'],
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 512, 'tl_class' => 'w50'],
            'sql' => 'text NULL',
        ],
        'active' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_synonym']['active'],
            'inputType' => 'checkbox',
            'eval' => ['tl_class' => 'w50 m12'],
            'sql' => "char(1) NOT NULL default '1'",
        ],
        'description' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_synonym']['description'],
            'inputType' => 'text',
            'eval' => ['maxlength' => 255, 'tl_class' => 'long clr'],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
    ],
];
