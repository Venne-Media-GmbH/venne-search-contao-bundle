<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Element;

use Contao\BackendTemplate;
use Contao\ContentElement;

/**
 * Content-Element „Venne Search".
 *
 * Erscheint im Artikel-Editor unter „Elementtyp" direkt wählbar — kein
 * Umweg über ein Frontend-Modul-Setup.
 *
 * Identische Konfigurations-Felder wie das ModuleVenneSearch (Display-Mode
 * Inline/Modal, Placeholder, Button-Label, Min-Chars, Limit, Facets).
 *
 * Template: `ce_venne_search.html5` — kann von der Site überschrieben werden.
 */
class ContentVenneSearch extends ContentElement
{
    protected $strTemplate = 'ce_venne_search';

    public function generate(): string
    {
        if ($this->isBackendRequest()) {
            $template = new BackendTemplate('be_wildcard');
            $template->wildcard = '### VENNE SEARCH ###';
            $template->title = $this->headline;
            $template->id = $this->id;
            $template->link = $this->vsearch_placeholder ?: 'Suche…';

            return $template->parse();
        }

        return parent::generate();
    }

    protected function compile(): void
    {
        $this->Template->moduleId = 'ce-'.$this->id;
        $this->Template->displayMode = $this->vsearch_display_mode ?: 'inline';
        $this->Template->triggerLabel = $this->vsearch_trigger_label ?: 'Suche öffnen';
        $this->Template->placeholder = $this->vsearch_placeholder ?: 'Suche…';
        $this->Template->buttonLabel = $this->vsearch_button_label ?: 'Suchen';
        $this->Template->locale = $this->getLocale();
        $this->Template->apiUrl = '/vsearch/api';
        $this->Template->minChars = (int) ($this->vsearch_min_chars ?: 3);
        // Default 100 = Meilisearch-Hard-Cap. Wenn der Admin im Backend keinen
        // Wert gesetzt hat (oder den alten Default 10 hat), zeigen wir alle.
        $configuredLimit = (int) ($this->vsearch_limit ?? 0);
        $this->Template->limit = ($configuredLimit > 0 && $configuredLimit !== 10) ? $configuredLimit : 100;
        $this->Template->headline = $this->headline;
        $this->Template->cssID = $this->cssID;
        $this->Template->showFacets = (bool) $this->vsearch_show_facets;
        $this->Template->hl = $this->hl ?: 'h2';
    }

    private function getLocale(): string
    {
        // v2.0.0: explizit gesetztes Element-Locale gewinnt.
        $explicit = strtolower(substr((string) ($this->vsearch_locale ?? ''), 0, 2));
        if ($explicit !== '') {
            return $explicit;
        }

        if (isset($GLOBALS['objPage']) && \is_object($GLOBALS['objPage']) && property_exists($GLOBALS['objPage'], 'language')) {
            $lang = (string) $GLOBALS['objPage']->language;
            if ($lang !== '') {
                return strtolower(substr($lang, 0, 2));
            }
        }

        return strtolower(substr((string) ($GLOBALS['TL_LANGUAGE'] ?? 'de'), 0, 2));
    }

    private function isBackendRequest(): bool
    {
        if (\defined('TL_MODE') && \TL_MODE === 'BE') {
            return true;
        }

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
            }
        }

        return false;
    }
}
