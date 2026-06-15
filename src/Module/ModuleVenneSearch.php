<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Module;

use Contao\BackendTemplate;
use Contao\Module;

/**
 * Frontend-Modul: Suchfeld mit AJAX-Live-Vorschau.
 *
 * Im Backend → Layout → Frontend-Module als "Venne Search" wählbar.
 * Rendert ein `<input>` + Trefferliste. Die eigentliche Suche läuft über
 * `/vsearch/api?q=...` (FrontendSearchController).
 *
 * Funktioniert in Contao 4.13 (Legacy-Module-Class) und 5.x (gleiche Class
 * wird von Contao auch dort als FE-Modul akzeptiert, weil `Module` weiter
 * existiert).
 *
 * Template: `mod_venne_search.html5` — kann von der Site überschrieben werden.
 */
class ModuleVenneSearch extends Module
{
    protected $strTemplate = 'mod_venne_search';

    public function generate(): string
    {
        // Backend-Wildcard-Vorschau: in Contao 4.13 via TL_MODE, in 5.x via
        // Symfony-Scope. Wir prüfen beide ohne Hard-Dependency auf Constants.
        if ($this->isBackendRequest()) {
            $template = new BackendTemplate('be_wildcard');
            $template->wildcard = '### VENNE SEARCH ###';
            $template->title = $this->headline;
            $template->id = $this->id;
            $template->link = $this->name;
            $template->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id='.$this->id;

            return $template->parse();
        }

        return parent::generate();
    }

    protected function compile(): void
    {
        $this->Template->moduleId = $this->id;
        $this->Template->displayMode = $this->vsearch_display_mode ?: 'inline';
        $locale = $this->getLocale();
        $defaults = self::defaultLabels($locale);
        $this->Template->triggerLabel = $this->vsearch_trigger_label ?: $defaults['trigger'];
        $this->Template->placeholder = $this->vsearch_placeholder ?: $defaults['placeholder'];
        $this->Template->buttonLabel = $this->vsearch_button_label ?: $defaults['button'];
        $this->Template->locale = $locale;
        $this->Template->apiUrl = '/vsearch/api';
        $this->Template->minChars = (int) ($this->vsearch_min_chars ?: 3);
        // Default 100 = Meilisearch-Hard-Cap. Wenn der Admin im Backend keinen
        // Wert gesetzt hat (oder den alten Default 10 hat), zeigen wir alle.
        $configuredLimit = (int) ($this->vsearch_limit ?? 0);
        $this->Template->limit = ($configuredLimit > 0 && $configuredLimit !== 10) ? $configuredLimit : 100;
        $this->Template->headline = $this->headline;
        $this->Template->cssID = $this->cssID;
        $this->Template->showFacets = (bool) $this->vsearch_show_facets;
        // v2.1.0: zusätzliche UI-Optionen
        $this->Template->showSort = (bool) $this->vsearch_show_sort;
        $this->Template->showFiletypeFilter = (bool) $this->vsearch_show_filetype_filter;
        $this->Template->openInNewTab = (bool) $this->vsearch_open_in_new_tab;
    }

    private function getLocale(): string
    {
        // v2.0.0: explizit gesetztes Modul-Locale gewinnt — der Site-Betreiber
        // legt im Backend fest, in welcher Sprache dieses Suchmodul sucht.
        // Endnutzer sieht/wechselt die Sprache nie selbst.
        $explicit = strtolower(substr((string) ($this->vsearch_locale ?? ''), 0, 2));
        if ($explicit !== '') {
            return $explicit;
        }

        // Contao 5: aus Page-Model (currentRoot), Contao 4.13: TL_LANGUAGE.
        if (isset($GLOBALS['objPage']) && \is_object($GLOBALS['objPage']) && property_exists($GLOBALS['objPage'], 'language')) {
            $lang = (string) $GLOBALS['objPage']->language;
            if ($lang !== '') {
                return strtolower(substr($lang, 0, 2));
            }
        }

        return strtolower(substr((string) ($GLOBALS['TL_LANGUAGE'] ?? 'de'), 0, 2));
    }

    /**
     * Default-Labels pro Locale fuer Trigger / Placeholder / Button. User-Werte
     * aus dem Modul-Setting gewinnen — diese Defaults greifen nur wenn das
     * jeweilige Feld leer ist und sorgen dafuer, dass auf EN-Pages keine
     * deutschen Default-Strings auftauchen.
     *
     * @return array{trigger:string, placeholder:string, button:string}
     */
    public static function defaultLabels(string $locale): array
    {
        $map = [
            'de' => ['trigger' => 'Suche öffnen',  'placeholder' => 'Suche…',  'button' => 'Suchen'],
            'en' => ['trigger' => 'Open search',   'placeholder' => 'Search…', 'button' => 'Search'],
            'fr' => ['trigger' => 'Ouvrir',        'placeholder' => 'Recherche…', 'button' => 'Rechercher'],
            'it' => ['trigger' => 'Apri ricerca',  'placeholder' => 'Cerca…',  'button' => 'Cerca'],
            'es' => ['trigger' => 'Abrir búsqueda','placeholder' => 'Buscar…', 'button' => 'Buscar'],
            'nl' => ['trigger' => 'Zoeken openen', 'placeholder' => 'Zoeken…', 'button' => 'Zoeken'],
        ];
        return $map[$locale] ?? $map['en'];
    }

    private function isBackendRequest(): bool
    {
        // Contao 4.13 Konstante
        if (\defined('TL_MODE') && \TL_MODE === 'BE') {
            return true;
        }

        // Contao 5 — über Scope-Matcher im Container
        if (class_exists(\Contao\System::class)) {
            try {
                $container = \Contao\System::getContainer();
                if ($container && $container->has('contao.routing.scope_matcher')) {
                    $matcher = $container->get('contao.routing.scope_matcher');
                    $request = $container->get('request_stack')->getCurrentRequest();
                    if ($request !== null && $matcher->isBackendRequest($request)) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                // Fallback: kein Container, kein Backend.
            }
        }

        return false;
    }
}
