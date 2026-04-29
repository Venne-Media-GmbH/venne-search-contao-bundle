<?php

declare(strict_types=1);

/**
 * DCA: Singleton-Settings für Venne Search.
 *
 * Im Backend unter "System → Venne Search → Verbindung" auf einem Datensatz.
 * Endpoint und Index-Prefix sind nicht editierbar — die werden serverseitig
 * vom Bundle festgelegt. Der Kunde sieht nur seinen API-Key + welche
 * Sprachen indexiert werden sollen.
 */
$GLOBALS['TL_DCA']['tl_venne_search_settings'] = [
    'config' => [
        // Contao 5 erwartet FQCN, Contao 4.13 String 'Table'.
        'dataContainer' => class_exists(\Contao\DC_Table::class) ? \Contao\DC_Table::class : 'Table',
        'closed' => true,
        'notDeletable' => true,
        'notCopyable' => true,
        'notCreatable' => true,
        'sql' => [
            'keys' => ['id' => 'primary'],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => 1,
            'fields' => ['id'],
            'panelLayout' => '',
        ],
        'label' => [
            'fields' => ['api_key'],
            'format' => 'Venne Search · API-Key %s…',
            'showColumns' => false,
        ],
        'global_operations' => [
            'reindex' => [
                'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['reindex'],
                'href' => 'key=reindex',
                'class' => 'header_run',
                'attributes' => 'onclick="return confirm(\'Komplett-Indexierung starten? Das l&auml;uft im Hintergrund und kann je nach Site-Gr&ouml;&szlig;e ein paar Minuten dauern.\')"',
            ],
            'status' => [
                'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['status'],
                'href' => 'key=status',
                'class' => 'header_info',
            ],
        ],
        'operations' => [
            'edit' => [
                'href' => 'act=edit',
                'icon' => 'edit.svg',
                'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['edit'],
            ],
        ],
    ],
    'palettes' => [
        // Pseudo-Felder rendern Custom-HTML via input_field_callback:
        //   reindex_button = großer Reindex-Button mit Beschreibung
        //   status_panel   = Live-Status (Anzahl Dokumente)
        //   documents_panel = Tabelle mit Filter
        'default' => '{verbindung_legend},api_key;{indexing_legend},enabled_locales,index_pdfs,auto_indexing;{search_legend},search_strictness;{security_legend:hide},index_mode,excluded_folders;{reindex_legend},reindex_button;{status_legend},status_panel;{documents_legend},documents_panel',
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'api_key' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['api_key'],
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 128,
                'tl_class' => 'w50',
                'preserveTags' => true,
                'rgxp' => 'alnum',
                'placeholder' => 'vsk_live_…',
            ],
            'save_callback' => [
                // Resolve-Cache wegwerfen wenn der Key sich ändert — sonst
                // läuft das Bundle bis zur nächsten TTL mit dem alten Token
                // weiter und der User wundert sich, warum nichts passiert.
                static function ($value) {
                    try {
                        \Contao\System::getContainer()
                            ->get('database_connection')
                            ?->executeStatement('UPDATE tl_venne_search_settings SET resolve_cache = NULL WHERE id = 1');
                    } catch (\Throwable) {
                    }

                    return $value;
                },
            ],
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        'enabled_locales' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['enabled_locales'],
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 64,
                'tl_class' => 'w50',
                'placeholder' => 'de,en',
            ],
            'sql' => "varchar(64) NOT NULL default 'de'",
        ],
        'index_pdfs' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['index_pdfs'],
            'inputType' => 'checkbox',
            'default' => '1',
            'eval' => ['tl_class' => 'w50 m12', 'submitOnChange' => true],
            'sql' => "char(1) NOT NULL default '1'",
        ],
        'auto_indexing' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['auto_indexing'],
            'inputType' => 'checkbox',
            'default' => '1',
            'eval' => ['tl_class' => 'w50 m12', 'submitOnChange' => true],
            'sql' => "char(1) NOT NULL default '1'",
        ],
        'search_strictness' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['search_strictness'],
            'inputType' => 'select',
            'options' => ['strict', 'balanced', 'tolerant'],
            'reference' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['search_strictness_options'],
            'default' => 'balanced',
            'eval' => ['tl_class' => 'w50', 'submitOnChange' => true],
            'sql' => "varchar(16) NOT NULL default 'balanced'",
            'save_callback' => [
                // Wenn die Strenge geändert wird, müssen die Index-Settings
                // neu geschrieben werden — sonst greift die neue Toleranz
                // erst beim nächsten Reindex. Wir triggern das hier direkt.
                static function ($value) {
                    try {
                        $container = \Contao\System::getContainer();
                        $settings = $container?->get('VenneMedia\\VenneSearchContaoBundle\\Service\\Settings\\SettingsRepository');
                        $indexer = $container?->get('VenneMedia\\VenneSearchContaoBundle\\Service\\Indexer\\DocumentIndexer');
                        if ($settings && $indexer && $settings->isConfigured()) {
                            // Cache invalidieren damit der neue Wert wirklich greift
                            $container->get('database_connection')
                                ?->executeStatement(
                                    'UPDATE tl_venne_search_settings SET search_strictness = ?, resolve_cache = NULL WHERE id = 1',
                                    [(string) $value],
                                );
                            $config = $settings->load();
                            foreach ($config->enabledLocales as $locale) {
                                try {
                                    $indexer->ensureIndex($locale);
                                } catch (\Throwable) {
                                }
                            }
                        }
                    } catch (\Throwable) {
                    }
                    return $value;
                },
            ],
        ],
        'reindex_button' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['reindex_button'],
            'input_field_callback' => [VenneMedia\VenneSearchContaoBundle\EventListener\BackendActionListener::class, 'renderReindexPanel'],
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
        ],
        // Pseudo-Felder ohne SQL — rendern via input_field_callback Custom-HTML.
        'status_panel' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['status_panel'],
            'input_field_callback' => [VenneMedia\VenneSearchContaoBundle\EventListener\BackendActionListener::class, 'renderStatusPanel'],
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
        ],
        'documents_panel' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['documents_panel'],
            'input_field_callback' => [VenneMedia\VenneSearchContaoBundle\EventListener\BackendActionListener::class, 'renderDocumentsPanel'],
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
        ],
        // Endpoint und Index-Prefix sind LEGACY — werden seit der Umstellung
        // auf den Resolve-Endpoint nicht mehr selbst gepflegt, kommen jetzt
        // von der Plattform. Spalten bleiben nur damit alte Setups durch die
        // Migration laufen, ohne Schema-Konflikt zu produzieren.
        'endpoint' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'index_prefix' => [
            'sql' => "varchar(64) NOT NULL default ''",
        ],
        // URL der venne_search-Plattform. Default ist https://venne-search.de
        // — kann hier überschrieben werden für Dev-Setups (lokaler Tunnel).
        // Im normalen Betrieb leer lassen → Bundle nimmt Default.
        'platform_url' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        // Cached das Resolve-Result von der Plattform.
        // JSON: {key:hash, endpoint, indexPrefix, scopedToken, expiresAt, ttl}.
        // Wird vom ResolveClient befüllt + automatisch invalidiert.
        'resolve_cache' => [
            'sql' => 'longtext NULL',
        ],
        'max_file_size_mb' => [
            'sql' => 'int(10) unsigned NOT NULL default 25',
        ],
        // Fortschrittsanzeige für laufenden Reindex.
        'reindex_total' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'reindex_started_at' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        // Persistente Resume-Liste fuer den Reindex: alle docIds die im
        // aktuellen Lauf erfolgreich indexiert wurden — als JSON-Array. Wird
        // beim ersten Batch (offset=0) und beim "Index leeren" zurueckgesetzt.
        // Damit funktioniert Resume nach Reconnect zuverlaessig, ohne auf
        // Meilisearchs async Indexing-Queue warten zu muessen.
        'reindex_done_ids' => [
            'sql' => 'longtext NULL',
        ],
        // Eindeutige ID des aktuell laufenden Reindex-Runs (Plan-First-API,
        // ab v0.3.0). Wird beim Plan-Call gesetzt und beim Finalize-Call
        // wieder geleert. Browser-JS speichert diese ID im localStorage
        // damit ein Reload den Run wiederherstellen kann.
        'reindex_run_id' => [
            'sql' => "varchar(32) NOT NULL default ''",
        ],
        // === Security-Felder (v0.4.0) ===
        // Indexier-Modus: bestimmt was indexiert wird. Default `public_only`
        // — sichere Variante. User muss explizit umschalten wenn er auch
        // geschützte Inhalte mit ACL-Filterung indexieren will.
        'index_mode' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['index_mode'],
            'inputType' => 'select',
            'options' => ['public_only', 'with_protected'],
            'reference' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['index_mode_options'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50', 'submitOnChange' => true],
            'sql' => "varchar(32) NOT NULL default 'public_only'",
        ],
        'excluded_folders' => [
            'label' => &$GLOBALS['TL_LANG']['tl_venne_search_settings']['excluded_folders'],
            'inputType' => 'fileTree',
            'eval' => [
                'multiple' => true,
                'fieldType' => 'checkbox',
                'files' => false,
                'orderField' => '',
                'tl_class' => 'clr',
                'submitOnChange' => true,
            ],
            'sql' => 'blob NULL',
        ],
        // Legacy-Felder aus v0.x: bleiben in der DB für sanfte Migration.
        // Werte werden beim ersten Laden in excluded_folders überführt.
        'excluded_paths' => [
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
            'sql' => 'text NULL',
        ],
        'included_paths' => [
            'eval' => ['doNotShow' => true, 'doNotCopy' => true],
            'sql' => 'text NULL',
        ],
    ],
];
