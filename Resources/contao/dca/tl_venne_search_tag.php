<?php

declare(strict_types=1);

/**
 * DCA: Tag-Verwaltung für das Venne-Search-Tag-System.
 * Im Backend unter "System → Venne Search → Tags".
 *
 * Bewusst minimal: nur Bezeichnung + Farbe + optionale Beschreibung.
 * Slug wird intern automatisch generiert (aus dem Label, deutsch-foldend).
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
            'fields' => ['label'],
            'format' => '%s',
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
        'default' => '{title_legend},label,color;{description_legend:hide},description;{assignments_legend},assignments_panel',
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
                    // Slug bei jedem Save aus Label aktualisieren — User
                    // muss das nicht mehr selbst pflegen, ist intern.
                    if (\is_object($dc) && $value !== '') {
                        $slug = \VenneMedia\VenneSearchContaoBundle\Migration\Version200\Mig02_AddTagSystem::slugify((string) $value);
                        if ($slug !== '') {
                            // Doppelte Slugs vermeiden: hänge -2 an wenn schon vorhanden
                            // bei einem anderen Datensatz.
                            $db = \Contao\System::getContainer()?->get('database_connection');
                            if ($db !== null) {
                                $exists = $db->fetchOne(
                                    'SELECT id FROM tl_venne_search_tag WHERE slug = ? AND id <> ?',
                                    [$slug, (int) $dc->id],
                                );
                                if ($exists !== false && $exists !== null) {
                                    $slug .= '-' . (int) $dc->id;
                                }
                                $db->executeStatement(
                                    'UPDATE tl_venne_search_tag SET slug = ? WHERE id = ?',
                                    [$slug, (int) $dc->id],
                                );
                            }
                        }
                    }
                    return $value;
                },
            ],
        ],
        'slug' => [
            // Intern, nicht im Backend bearbeitbar.
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
            'sql' => "varchar(64) NOT NULL default ''",
        ],
        'color' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['color'],
            'inputType' => 'select',
            'options' => ['blue', 'green', 'red', 'orange', 'purple', 'pink', 'gray', 'teal'],
            'reference' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['color_options'],
            'eval' => ['tl_class' => 'w50', 'mandatory' => true],
            'sql' => "varchar(16) NOT NULL default 'blue'",
        ],
        'description' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['description'],
            'inputType' => 'textarea',
            'eval' => ['rte' => null, 'tl_class' => 'clr long', 'rows' => 4],
            'sql' => 'text NULL',
        ],
        'assignments_panel' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['assignments_panel'],
            'input_field_callback' => [
                \VenneMedia\VenneSearchContaoBundle\EventListener\TagBackendListener::class,
                'renderAssignmentsPanel',
            ],
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
        ],
    ],
];
