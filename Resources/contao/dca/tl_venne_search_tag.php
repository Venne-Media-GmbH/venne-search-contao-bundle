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
        'ondelete_callback' => [
            [
                \VenneMedia\VenneSearchContaoBundle\EventListener\TagDeleteListener::class,
                'onDelete',
            ],
        ],
        'onsubmit_callback' => [
            // POST['translations'][locale] = label → JSON-Map in DB schreiben.
            static function ($dc) {
                if (!\is_object($dc) || (int) ($dc->id ?? 0) <= 0) {
                    return;
                }
                $raw = $_POST['translations'] ?? null;
                $tagId = (int) $dc->id;
                $container = \Contao\System::getContainer();
                $db = $container?->get('database_connection');
                if ($db === null) {
                    return;
                }
                $out = [];
                if (\is_array($raw)) {
                    foreach ($raw as $loc => $val) {
                        $loc = preg_replace('/[^a-z]/', '', strtolower((string) $loc)) ?? '';
                        $val = trim((string) $val);
                        if ($loc !== '' && \strlen($loc) <= 5 && $val !== '') {
                            $out[$loc] = $val;
                        }
                    }
                }
                $json = $out === [] ? null : json_encode($out, JSON_UNESCAPED_UNICODE);
                try {
                    $db->executeStatement(
                        'UPDATE tl_venne_search_tag SET translations = ? WHERE id = ?',
                        [$json, $tagId],
                    );
                } catch (\Throwable) {
                }

                // Selbes Spiel fuer Auto-Match-Pattern-Uebersetzungen pro Locale.
                $patternsRaw = $_POST['auto_match_pattern_translations'] ?? null;
                $patternsOut = [];
                if (\is_array($patternsRaw)) {
                    foreach ($patternsRaw as $loc => $val) {
                        $loc = preg_replace('/[^a-z]/', '', strtolower((string) $loc)) ?? '';
                        $val = trim((string) $val);
                        if ($loc !== '' && \strlen($loc) <= 5 && $val !== '') {
                            $patternsOut[$loc] = $val;
                        }
                    }
                }
                $patternsJson = $patternsOut === [] ? null : json_encode($patternsOut, JSON_UNESCAPED_UNICODE);
                try {
                    $db->executeStatement(
                        'UPDATE tl_venne_search_tag SET auto_match_pattern_translations = ? WHERE id = ?',
                        [$patternsJson, $tagId],
                    );
                } catch (\Throwable) {
                }
            },
        ],
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
        'default' => '{title_legend},label,color,boost;{translations_legend},translations;{auto_match_legend},auto_match_pattern,auto_match_pattern_translations;{description_legend:hide},description;{assignments_legend},assignments_panel',
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
                        $container = \Contao\System::getContainer();
                        $db = $container?->get('database_connection');
                        if ($db === null) {
                            return $value;
                        }
                        $tagId = (int) $dc->id;
                        $oldSlug = (string) ($db->fetchOne(
                            'SELECT slug FROM tl_venne_search_tag WHERE id = ?',
                            [$tagId],
                        ) ?: '');

                        $slug = \VenneMedia\VenneSearchContaoBundle\Migration\Version200\Mig02_AddTagSystem::slugify((string) $value);
                        if ($slug === '') {
                            return $value;
                        }
                        // Doppelte Slugs vermeiden
                        $exists = $db->fetchOne(
                            'SELECT id FROM tl_venne_search_tag WHERE slug = ? AND id <> ?',
                            [$slug, $tagId],
                        );
                        if ($exists !== false && $exists !== null) {
                            $slug .= '-' . $tagId;
                        }
                        $db->executeStatement(
                            'UPDATE tl_venne_search_tag SET slug = ? WHERE id = ?',
                            [$slug, $tagId],
                        );

                        // Wenn sich der Slug oder das Label geändert hat,
                        // alle zugewiesenen Pages/Files reindexieren — sonst
                        // ist der alte Tag-Wert weiter im Such-Index aktiv.
                        $needsReindex = $oldSlug !== $slug; // Slug-Wechsel = Label hat sich geändert
                        if ($needsReindex) {
                            try {
                                $assignments = $db->fetchAllAssociative(
                                    'SELECT target_type, target_id FROM tl_venne_search_tag_assignment WHERE tag_id = ?',
                                    [$tagId],
                                );
                                $settings = $container->get('VenneMedia\\VenneSearchContaoBundle\\Service\\Settings\\SettingsRepository');
                                if ($settings && $settings->isConfigured()) {
                                    $config = $settings->load();
                                    $processor = $container->get('VenneMedia\\VenneSearchContaoBundle\\Service\\Indexer\\IndexableItemProcessor');
                                    $projectDir = (string) $container->getParameter('kernel.project_dir');
                                    foreach ($assignments as $a) {
                                        $type = (string) $a['target_type'];
                                        $tid = (string) $a['target_id'];
                                        if ($type === 'page') {
                                            $pageId = (int) $tid;
                                            if ($pageId > 0) {
                                                @$processor->processItem(
                                                    ['type' => 'page', 'ref' => $pageId, 'docId' => 'page-' . $pageId],
                                                    $config,
                                                    $projectDir,
                                                );
                                            }
                                        } elseif ($type === 'file') {
                                            @$processor->processItem(
                                                ['type' => 'file', 'ref' => $tid, 'docId' => 'file-path-' . md5($tid)],
                                                $config,
                                                $projectDir,
                                            );
                                        }
                                    }
                                }
                            } catch (\Throwable) {
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
        'boost' => [
            // Tag-Boost: skaliert die Such-Relevanz aller diesem Tag
            // zugeordneten Pages/Files. weight=max(boost) der Tags eines
            // Documents fließt in die Sortier-Reihenfolge des Indexes
            // (weight DESC vor indexed_at DESC). Bei Boost-Änderung werden
            // alle zugewiesenen Targets im save_callback reindexiert.
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['boost'],
            'inputType' => 'select',
            'options' => ['1.00', '1.50', '2.00', '3.00', '5.00', '10.00'],
            'reference' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['boost_options'],
            'eval' => ['tl_class' => 'w50', 'mandatory' => true, 'helpwizard' => true],
            'explanation' => 'venneSearchTagBoost',
            'sql' => "decimal(4,2) NOT NULL default '1.00'",
            'save_callback' => [
                static function ($value, $dc) {
                    // Boost-Wert sanitizen + Re-Index aller Targets, damit
                    // die neue Gewichtung im Such-Index landet.
                    $allowed = ['1.00', '1.50', '2.00', '3.00', '5.00', '10.00'];
                    $normalized = number_format((float) $value, 2, '.', '');
                    if (!\in_array($normalized, $allowed, true)) {
                        $normalized = '1.00';
                    }
                    if (!\is_object($dc) || (int) $dc->id <= 0) {
                        return $normalized;
                    }
                    $container = \Contao\System::getContainer();
                    $db = $container?->get('database_connection');
                    if ($db === null) {
                        return $normalized;
                    }
                    $tagId = (int) $dc->id;
                    $oldBoost = (string) ($db->fetchOne(
                        'SELECT boost FROM tl_venne_search_tag WHERE id = ?',
                        [$tagId],
                    ) ?: '1.00');
                    if ($oldBoost === $normalized) {
                        return $normalized;
                    }
                    try {
                        $assignments = $db->fetchAllAssociative(
                            'SELECT target_type, target_id FROM tl_venne_search_tag_assignment WHERE tag_id = ?',
                            [$tagId],
                        );
                        $settings = $container->get('VenneMedia\\VenneSearchContaoBundle\\Service\\Settings\\SettingsRepository');
                        if ($settings && $settings->isConfigured()) {
                            $config = $settings->load();
                            $processor = $container->get('VenneMedia\\VenneSearchContaoBundle\\Service\\Indexer\\IndexableItemProcessor');
                            $projectDir = (string) $container->getParameter('kernel.project_dir');
                            // Boost in DB zuerst persistieren, damit der
                            // anschließende Reindex die neue Gewichtung sieht.
                            $db->executeStatement(
                                'UPDATE tl_venne_search_tag SET boost = ? WHERE id = ?',
                                [$normalized, $tagId],
                            );
                            foreach ($assignments as $a) {
                                $type = (string) $a['target_type'];
                                $tid = (string) $a['target_id'];
                                if ($type === 'page') {
                                    $pageId = (int) $tid;
                                    if ($pageId > 0) {
                                        @$processor->processItem(
                                            ['type' => 'page', 'ref' => $pageId, 'docId' => 'page-' . $pageId],
                                            $config,
                                            $projectDir,
                                        );
                                    }
                                } elseif ($type === 'file') {
                                    @$processor->processItem(
                                        ['type' => 'file', 'ref' => $tid, 'docId' => 'file-path-' . md5($tid)],
                                        $config,
                                        $projectDir,
                                    );
                                }
                            }
                        }
                    } catch (\Throwable) {
                    }
                    return $normalized;
                },
            ],
        ],
        'translations' => [
            // Pro aktive Locale ein eigenes Text-Input via input_field_callback.
            // Beim Submit ueberschreibt unser onsubmit-Hook tl_venne_search_tag
            // die JSON-Map in der `translations`-Spalte basierend auf POST.
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['translations'],
            'input_field_callback' => [
                \VenneMedia\VenneSearchContaoBundle\EventListener\TagTranslationsFieldListener::class,
                'render',
            ],
            'eval' => ['doNotShow' => false, 'doNotCopy' => true],
            'sql' => 'text NULL',
        ],
        'auto_match_pattern' => [
            // Glob-Patterns (eines pro Zeile) — jede URL eines Treffers, die
            // matched, bekommt diesen Tag automatisch in der Such-Antwort.
            // Funktioniert für Contao-Pages, Files und externe gecrawlte URLs.
            // Beispiel: *pressemitteilungen-detailseite* → Tag "Pressemitteilung"
            // Sprachen-Override: siehe `auto_match_pattern_translations`.
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['auto_match_pattern'],
            'inputType' => 'textarea',
            'eval' => ['tl_class' => 'clr long', 'rows' => 4, 'decodeEntities' => true],
            'sql' => 'text NULL',
        ],
        'auto_match_pattern_translations' => [
            // Patterns pro Locale — englische Detail-Seiten haben oft andere
            // URL-Struktur (z.B. /press-release-detail/ statt /pressemitteilungen-
            // detailseite/). Speichert als JSON-Map {locale: patterns-string}.
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_tag']['auto_match_pattern_translations'],
            'input_field_callback' => [
                \VenneMedia\VenneSearchContaoBundle\EventListener\TagPatternTranslationsFieldListener::class,
                'render',
            ],
            'eval' => ['doNotShow' => false, 'doNotCopy' => true],
            'sql' => 'text NULL',
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
