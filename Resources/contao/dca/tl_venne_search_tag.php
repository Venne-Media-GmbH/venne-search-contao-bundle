<?php

declare(strict_types=1);

/**
 * DCA: Tag-Verwaltung für das Venne-Search-Tag-System.
 * Im Backend unter "System → Venne Search → Tags".
 */
$GLOBALS['TL_DCA']['tl_venne_search_tag'] = [
    'config' => [
        'dataContainer' => class_exists(\Contao\DC_Table::class) ? \Contao\DC_Table::class : 'Table',
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'slug' => 'unique',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => 1,
            'fields' => ['label'],
            'panelLayout' => 'search,limit',
        ],
        'label' => [
            'fields' => ['label', 'slug'],
            'format' => '%s <span style="color:#94a3b8;">(%s)</span>',
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
            'delete' => [
                'href' => 'act=delete',
                'icon' => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\'Tag wirklich löschen? Alle Zuweisungen werden ebenfalls entfernt.\'))return false;Backend.getScrollOffset()"',
            ],
        ],
    ],
    'palettes' => [
        'default' => '{title_legend},label,slug,color;{description_legend:hide},description',
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'label' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['label'],
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 120, 'tl_class' => 'w50'],
            'sql' => "varchar(120) NOT NULL default ''",
            'save_callback' => [
                static function ($value, $dc) {
                    // Auto-Slug wenn leer
                    if (\is_object($dc) && $value !== '' && empty($dc->activeRecord->slug)) {
                        $slug = \VenneMedia\VenneSearchContaoBundle\Migration\Version200\Mig02_AddTagSystem::slugify((string) $value);
                        \Contao\System::getContainer()
                            ?->get('database_connection')
                            ?->executeStatement(
                                'UPDATE tl_venne_search_tag SET slug = ? WHERE id = ?',
                                [$slug, (int) $dc->id],
                            );
                    }
                    return $value;
                },
            ],
        ],
        'slug' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['slug'],
            'inputType' => 'text',
            'eval' => ['maxlength' => 64, 'tl_class' => 'w50', 'rgxp' => 'alias', 'unique' => true],
            'sql' => "varchar(64) NOT NULL default ''",
        ],
        'color' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['color'],
            'inputType' => 'select',
            'options' => ['blue', 'green', 'red', 'orange', 'purple', 'pink', 'gray', 'teal'],
            'eval' => ['tl_class' => 'w50', 'mandatory' => true],
            'sql' => "varchar(16) NOT NULL default 'blue'",
        ],
        'description' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['description'],
            'inputType' => 'textarea',
            'eval' => ['rte' => null, 'tl_class' => 'clr long', 'rows' => 4],
            'sql' => 'text NULL',
        ],
    ],
];
