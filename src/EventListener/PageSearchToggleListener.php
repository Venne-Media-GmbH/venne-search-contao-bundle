<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

/**
 * Rendert das Lupen-Icon pro Page-Zeile in der Seitenstruktur (button_callback).
 *
 * - noSearch = '' / '0' → aktive Lupe (Page ist suchbar). Klick = ausschalten.
 * - noSearch = '1'      → durchgestrichene Lupe (Page nicht im Index).
 *                         Klick = einschalten.
 *
 * Der Klick wird per Inline-JS abgefangen (event.preventDefault) und gegen
 * /contao/venne-search/page/toggle-no-search gepostet. Bei Erfolg wird das
 * <img>-src im aktuellen Link ausgetauscht — kein Seiten-Reload, fuehlt sich
 * wie ein Standard-Contao-Toggle (Auge) an.
 */
final class PageSearchToggleListener
{
    /**
     * Contao-button_callback-Signatur in 4.13. Wir nutzen nur $row;
     * die anderen Argumente kommen vom Framework, werden hier aber nicht
     * gebraucht weil wir das Icon-HTML komplett selbst zusammenbauen.
     *
     * @param array<string,mixed> $row
     */
    public function render(array $row, string $href, string $label, string $title, string $icon, string $attributes): string
    {
        $pageId = (int) ($row['id'] ?? 0);
        if ($pageId <= 0) {
            return '';
        }

        $isNoSearch = (string) ($row['noSearch'] ?? '') === '1';
        $iconFile = $isNoSearch
            ? 'bundles/vennesearchcontao/icons/search-off.svg'
            : 'bundles/vennesearchcontao/icons/search-on.svg';
        $titleText = $isNoSearch
            ? 'Diese Seite wird NICHT durchsucht — klicken zum Aktivieren'
            : 'Diese Seite wird durchsucht — klicken zum Deaktivieren';

        // Inline-JS wird einmal pro Seitenstruktur-Ansicht geliefert. Wir
        // packen es als data-Attribut an den Link und einen einmaligen Init-
        // Block — Contao-DCA hat keinen sauberen "TL_BODY"-Hook hier, also
        // injizieren wir das Script direkt ans erste gerenderte Icon.
        static $scriptInjected = false;
        $script = '';
        if (!$scriptInjected) {
            $scriptInjected = true;
            $script = self::buildBootstrapScript();
        }

        return sprintf(
            '<a href="#" class="vsearch-page-toggle" data-vsearch-toggle-page="%d" title="%s">'
                . '<img src="/%s" width="16" height="16" alt="%s">'
                . '</a>%s',
            $pageId,
            htmlspecialchars($titleText, \ENT_QUOTES, 'UTF-8'),
            $iconFile,
            htmlspecialchars($titleText, \ENT_QUOTES, 'UTF-8'),
            $script,
        );
    }

    private static function buildBootstrapScript(): string
    {
        // Capture-Phase + stopImmediatePropagation: Contao 4.13 bindet auf
        // das umschliessende <li class="click2edit"> einen Mootools-Handler,
        // der die Frontend-Vorschau oeffnet. Ohne Capture-Phase laeuft unser
        // Listener zu spaet und der Browser navigiert davon, bevor wir den
        // Toggle ausloesen koennen.
        return <<<'HTML'
<script>
(function(){
    if (window.__vsearchToggleBound) return;
    window.__vsearchToggleBound = true;
    var handler = function(e) {
        var a = e.target.closest && e.target.closest('[data-vsearch-toggle-page]');
        if (!a) return;
        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') {
            e.stopImmediatePropagation();
        }
        var pageId = parseInt(a.getAttribute('data-vsearch-toggle-page'), 10);
        if (!pageId) return;
        var img = a.querySelector('img');
        var prevSrc = img ? img.getAttribute('src') : '';
        if (img) img.style.opacity = '0.4';
        fetch('/contao/venne-search/page/toggle-no-search', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ pageId: pageId }),
        }).then(function(r){ return r.json(); }).then(function(d){
            if (!d || !d.ok) { if (img) img.style.opacity = '1'; alert('Toggle fehlgeschlagen: ' + (d && d.error || 'unknown')); return; }
            var on = !!d.searchable;
            var nextSrc = on
                ? '/bundles/vennesearchcontao/icons/search-on.svg'
                : '/bundles/vennesearchcontao/icons/search-off.svg';
            var nextTitle = on
                ? 'Diese Seite wird durchsucht — klicken zum Deaktivieren'
                : 'Diese Seite wird NICHT durchsucht — klicken zum Aktivieren';
            if (img) { img.setAttribute('src', nextSrc); img.style.opacity = '1'; img.setAttribute('alt', nextTitle); }
            a.setAttribute('title', nextTitle);
        }).catch(function(){
            if (img) { img.setAttribute('src', prevSrc); img.style.opacity = '1'; }
            alert('Netzwerkfehler beim Toggle');
        });
    };
    // Capture-Phase = lauft VOR allen Bubble-Handlern (inkl. Contao click2edit).
    document.addEventListener('click', handler, true);
    // Mousedown ebenfalls abfangen — manche Contao-Versionen reagieren
    // schon auf mousedown statt click.
    document.addEventListener('mousedown', function(e) {
        var a = e.target.closest && e.target.closest('[data-vsearch-toggle-page]');
        if (!a) return;
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') {
            e.stopImmediatePropagation();
        }
    }, true);
})();
</script>
HTML;
    }
}
