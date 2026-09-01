<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Contao\System;
use VenneMedia\VenneSearchContaoBundle\Service\Tag\TagRepository;

/**
 * v2.2.0 — Inline-Tag-Combobox im tl_page Edit-Formular.
 *
 * Wird via `input_field_callback` aus tl_page.php aufgerufen. Rendert:
 *   - aktuelle Tags der Page als Chips (klickbar zum Entfernen)
 *   - eine Combobox die per Live-Search neue oder existierende Tags zuweist
 *   - „+ als neuen Tag anlegen"-Option wenn der Begriff noch nicht existiert
 *
 * Die JS-Logik spricht direkt die existierenden POST-Endpoints:
 *   /contao/venne-search/tag/assign
 *   /contao/venne-search/tag/unassign
 *   /contao/venne-search/tag/suggest
 *
 * Kompatibel mit Contao 4.13, 5.3, 5.7 — kein Code-Pfad nutzt versionsspezifische
 * APIs. Wir lesen die Page-ID aus $GLOBALS['CURRENT_ID'] oder Request-Query.
 */
final class PageTagFieldListener
{
    public function __construct(
        private readonly TagRepository $tags,
    ) {
    }

    /**
     * Wird statisch aus dem DCA aufgerufen — wir holen die Instanz aus dem Container.
     * Contao gibt den DataContainer als ersten Arg rein, den nutzen wir um die
     * aktuelle Page-ID zu finden (statt auf Globals zu vertrauen).
     */
    public static function render(mixed $dc = null): string
    {
        try {
            $listener = System::getContainer()->get(self::class);
        } catch (\Throwable) {
            return '<p class="tl_help">Tag-Feld konnte nicht geladen werden.</p>';
        }
        return $listener?->renderField($dc) ?? '';
    }

    public function renderField(mixed $dc = null): string
    {
        $pageId = $this->resolvePageId($dc);
        if ($pageId === 0) {
            return '<p class="tl_help">Tags können erst nach dem ersten Speichern der Seite vergeben werden.</p>';
        }

        // Aktuelle Tags der Page holen
        $assigned = $this->tags->tagsForTarget('page', (string) $pageId);

        // Chips-HTML bauen
        $chips = '';
        foreach ($assigned as $tag) {
            $chips .= sprintf(
                '<span class="vstag-chip" data-tag-slug="%s" data-color="%s" style="background:%s;color:%s;">'
                . '<span class="vstag-chip-label">%s</span>'
                . '<button type="button" class="vstag-chip-remove" title="Tag entfernen" aria-label="Entfernen">×</button>'
                . '</span>',
                htmlspecialchars($tag['slug']),
                htmlspecialchars($tag['color']),
                $this->colorBg($tag['color']),
                $this->colorFg($tag['color']),
                htmlspecialchars($tag['label']),
            );
        }

        // Empty-Hint wenn keine Tags
        $emptyHint = $assigned === []
            ? '<span class="vstag-empty">Noch keine Tags vergeben — tippe unten einen Begriff ein.</span>'
            : '';

        $pageIdJs = (int) $pageId;

        // Field-Wrapper im Contao-Standard-Layout. tl_box gibt Contao-typische
        // Padding/Border/Background — sieht aus wie ein normaler Feld-Block.
        return <<<HTML
<div class="widget clr vstag-widget">
    <h3 class="vstag-widget-title"><label for="vstag-input-{$pageIdJs}">Such-Tags</label></h3>
    <div class="vstag-page-field" id="vstag-page-field-{$pageIdJs}" data-page-id="{$pageIdJs}">
        <div class="vstag-chips-container">
            {$chips}
            {$emptyHint}
        </div>
        <div class="vstag-input-row">
            <input type="text" id="vstag-input-{$pageIdJs}" class="vstag-input tl_text" placeholder="Tag suchen oder neu anlegen (z.B. Versammlung)…" autocomplete="off">
            <div class="vstag-suggest-list"></div>
        </div>
        <div class="vstag-available-row">
            <span class="vstag-available-label">Vorhandene Tags:</span>
            <div class="vstag-available-chips" data-vstag-available></div>
        </div>
    </div>
    <p class="tl_help tl_tip vstag-widget-help">Tags für die Volltextsuche. Beispiel: Tag „Versammlung" → diese Seite landet bei der Suche nach „Versammlung" weit oben, auch wenn das Wort nicht im Inhalt steht. <strong>Neue Tags entstehen</strong> indem du den Begriff tippst und auf „+ als neuen Tag anlegen" klickst. Änderungen wirken sofort — kein zusätzliches Speichern nötig.</p>
</div>

<style>
/* Sieht aus wie ein normales Contao-DCA-Feld — kein eigener Container,
   keine extra Box. Padding links/rechts wie Contao's tl_box (sonst
   klebt der Inhalt am linken Rand des Form-Sections). */
.vstag-widget {
    margin:0;
    padding:1rem 1.2rem;
    background:transparent;
    border:0;
}
.vstag-widget-title {
    margin:0 0 .5rem 0 !important;
    font-size:.9rem;
    font-weight:600;
}
.vstag-widget-title label { font-weight:600; }
.vstag-widget-help {
    margin-top:.5rem !important;
    margin-bottom:0 !important;
    font-size:.82rem !important;
    line-height:1.5 !important;
    color:#6b7280 !important;
    /* Contao 5.x klemmt tl_tip/tl_help auf 1 Zeile mit ellipsis.
       Wir wollen den Text aber komplett sehen — override mit !important. */
    white-space:normal !important;
    overflow:visible !important;
    text-overflow:clip !important;
    max-width:none !important;
    display:block !important;
}

.vstag-page-field { margin:0; }
.vstag-page-field .vstag-chips-container {
    display:flex;flex-wrap:wrap;gap:.45rem;
    padding:.7rem .85rem;min-height:48px;
    background:#fff;border:1px solid #d1d5db;border-radius:6px;
    margin-bottom:.5rem;align-items:center;
    box-sizing:border-box;
}
.vstag-page-field .vstag-input-row {
    position:relative;
}
.vstag-page-field .vstag-input {
    width:100% !important;box-sizing:border-box;
    padding:.6rem .85rem;font-size:.95rem;
    border:1px solid #d1d5db;border-radius:6px;
    background:#fff;
}
.vstag-page-field .vstag-input:focus {
    outline:0;border-color:#4a8087;box-shadow:0 0 0 3px rgba(74,128,135,.15);
}
.vstag-page-field .vstag-suggest-list {
    position:absolute;top:calc(100% + 2px);left:0;right:0;
    background:#fff;border:1px solid #d1d5db;border-radius:6px;
    max-height:260px;overflow-y:auto;display:none;z-index:200;
    box-shadow:0 8px 24px -8px rgba(0,0,0,.18),0 2px 4px rgba(0,0,0,.05);
}

.vstag-chip {
    display:inline-flex;align-items:center;gap:.4rem;
    padding:.28rem .65rem .28rem .75rem;border-radius:999px;
    font-size:.82rem;font-weight:500;line-height:1.4;
}
.vstag-chip-remove {
    border:0 !important;background:transparent !important;
    color:inherit !important;opacity:.6;cursor:pointer;
    font-size:1.1rem;line-height:1;padding:0 0 0 .2rem !important;
    box-shadow:none !important;
}
.vstag-chip-remove:hover { opacity:1; }
.vstag-suggest-item {
    padding:.65rem .9rem;cursor:pointer;font-size:.9rem;
    border-bottom:1px solid #f3f4f6;
    display:flex;align-items:center;gap:.55rem;
}
.vstag-suggest-item:last-child { border-bottom:0; }
.vstag-suggest-item:hover, .vstag-suggest-item.vstag-suggest-active { background:#f9fafb; }
.vstag-suggest-create {
    color:#3a7178;font-weight:600;
    background:#f0f9ff;
}
.vstag-suggest-create:hover { background:#e0f2fe; }
.vstag-suggest-chip-preview {
    display:inline-block;width:14px;height:14px;border-radius:50%;flex-shrink:0;
}
.vstag-empty {
    color:#9ca3af;font-style:italic;font-size:.88rem;
    padding-left:.15rem;
}
.vstag-available-row {
    margin-top:.7rem;display:flex;align-items:flex-start;gap:.5rem;flex-wrap:wrap;
}
.vstag-available-label {
    font-size:.78rem;color:#6b7280;font-weight:500;padding-top:.3rem;flex-shrink:0;
}
.vstag-available-chips {
    display:flex;flex-wrap:wrap;gap:.3rem;flex:1;
}
.vstag-available-chip {
    display:inline-flex;align-items:center;gap:.25rem;
    padding:.2rem .55rem;border:1px solid #e5e7eb;border-radius:999px;
    font-size:.78rem;font-weight:500;cursor:pointer;
    background:#fff;transition:border-color .15s,background .15s;
}
.vstag-available-chip:hover { border-color:#3a7178;background:#f0fdfa; }
.vstag-available-chip[data-assigned="1"] { opacity:.4;cursor:default;background:#f3f4f6; }
.vstag-available-chip[data-assigned="1"]:hover { border-color:#e5e7eb;background:#f3f4f6; }
.vstag-available-empty { font-size:.78rem;color:#9ca3af;font-style:italic; }
.vstag-toast {
    position:fixed;bottom:1.2rem;right:1.2rem;
    padding:.75rem 1.15rem;border-radius:6px;background:#1f2937;color:#fff;
    font-size:.88rem;box-shadow:0 8px 24px rgba(0,0,0,.25);z-index:99999;
    opacity:0;transition:opacity .2s,transform .2s;transform:translateY(8px);
}
.vstag-toast.vstag-toast--show { opacity:1;transform:none; }
.vstag-toast--ok { background:#065f46; }
.vstag-toast--err { background:#991b1b; }
</style>

<script>
(function () {
    'use strict';
    var field = document.getElementById('vstag-page-field-{$pageIdJs}');
    if (!field || field.dataset.vsInit) return;
    field.dataset.vsInit = '1';

    var pageId = parseInt(field.dataset.pageId, 10);
    var container = field.querySelector('.vstag-chips-container');
    var input = field.querySelector('.vstag-input');
    var suggestList = field.querySelector('.vstag-suggest-list');
    var emptyHint = field.querySelector('.vstag-empty');
    var availableBox = field.querySelector('[data-vstag-available]');

    var debounceTimer = null;
    var activeIdx = -1;

    function toast(msg, kind) {
        var t = document.createElement('div');
        t.className = 'vstag-toast vstag-toast--' + (kind || 'ok');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('vstag-toast--show'); }, 10);
        setTimeout(function () {
            t.classList.remove('vstag-toast--show');
            setTimeout(function () { t.remove(); }, 250);
        }, 2200);
    }

    function colorBg(color) {
        return ({
            blue:'#dbeafe',green:'#d1fae5',red:'#fee2e2',orange:'#fed7aa',
            purple:'#e9d5ff',pink:'#fce7f3',gray:'#e5e7eb',teal:'#ccfbf1'
        })[color] || '#dbeafe';
    }
    function colorFg(color) {
        return ({
            blue:'#1e40af',green:'#065f46',red:'#991b1b',orange:'#9a3412',
            purple:'#6b21a8',pink:'#9d174d',gray:'#374151',teal:'#115e59'
        })[color] || '#1e40af';
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function renderChip(tag) {
        var chip = document.createElement('span');
        chip.className = 'vstag-chip';
        chip.dataset.tagSlug = tag.slug;
        chip.dataset.color = tag.color || 'blue';
        chip.style.background = colorBg(tag.color);
        chip.style.color = colorFg(tag.color);
        chip.innerHTML = '<span class="vstag-chip-label">' + escapeHtml(tag.label) + '</span>'
            + '<button type="button" class="vstag-chip-remove" title="Tag entfernen" aria-label="Entfernen">×</button>';
        return chip;
    }

    function hideEmptyHint() {
        if (emptyHint) emptyHint.style.display = 'none';
    }

    function showEmptyHintIfNeeded() {
        if (emptyHint && container.querySelectorAll('.vstag-chip').length === 0) {
            emptyHint.style.display = '';
        }
    }

    function hideSuggest() {
        suggestList.style.display = 'none';
        suggestList.innerHTML = '';
        activeIdx = -1;
    }

    function getAssignedSlugs() {
        return Array.from(container.querySelectorAll('.vstag-chip')).map(function (c) {
            return c.dataset.tagSlug;
        });
    }

    // Dauerhaft sichtbare „Vorhandene Tags"-Leiste: alle existierenden Tags
    // als anklickbare Chips. Bereits zugewiesene sind ausgegraut. Click = assign.
    function refreshAvailable() {
        if (!availableBox) return;
        fetch('/contao/venne-search/tag/suggest', {credentials:'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (list) {
                var arr = Array.isArray(list) ? list : (list.tags || []);
                if (arr.length === 0) {
                    availableBox.innerHTML = '<span class="vstag-available-empty">Noch keine Tags angelegt — tippe oben einen Begriff ein.</span>';
                    return;
                }
                var assigned = getAssignedSlugs();
                availableBox.innerHTML = arr.map(function (t) {
                    var isAssigned = assigned.indexOf(t.slug) !== -1;
                    return '<button type="button" class="vstag-available-chip" '
                        + 'data-slug="' + escapeHtml(t.slug) + '" '
                        + 'data-label="' + escapeHtml(t.label) + '" '
                        + 'data-color="' + escapeHtml(t.color || 'blue') + '" '
                        + 'data-assigned="' + (isAssigned ? '1' : '0') + '" '
                        + 'style="color:' + colorFg(t.color) + ';">'
                        + '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + colorFg(t.color) + ';"></span>'
                        + escapeHtml(t.label) + '</button>';
                }).join('');
                Array.from(availableBox.querySelectorAll('.vstag-available-chip')).forEach(function (chip) {
                    chip.addEventListener('click', function () {
                        if (chip.dataset.assigned === '1') return;
                        assignTag({tagSlug: chip.dataset.slug, label: chip.dataset.label, color: chip.dataset.color});
                    });
                });
            }).catch(function () {});
    }

    function fetchSuggest(query) {
        var url = '/contao/venne-search/tag/suggest?q=' + encodeURIComponent(query);
        return fetch(url, {credentials:'same-origin'}).then(function (r) { return r.json(); });
    }

    function renderSuggest(query, data) {
        var assigned = getAssignedSlugs();
        // Endpoint liefert direkt ein Array (kein .tags-Wrapper).
        var rawList = Array.isArray(data) ? data : (data.tags || []);
        var existing = rawList.filter(function (t) { return assigned.indexOf(t.slug) === -1; });
        var exactMatch = existing.some(function (t) {
            return t.label.toLowerCase() === query.toLowerCase()
                || t.slug.toLowerCase() === query.toLowerCase();
        });

        var html = '';
        existing.forEach(function (t) {
            html += '<div class="vstag-suggest-item" data-tag-slug="' + escapeHtml(t.slug) + '" data-color="' + escapeHtml(t.color || 'blue') + '" data-label="' + escapeHtml(t.label) + '">'
                + '<span class="vstag-suggest-chip-preview" style="background:' + colorFg(t.color) + ';"></span>'
                + '<span>' + escapeHtml(t.label) + '</span>'
                + '</div>';
        });
        if (query.length >= 1 && !exactMatch) {
            html += '<div class="vstag-suggest-item vstag-suggest-create" data-create="1" data-label="' + escapeHtml(query) + '">'
                + '+ „' + escapeHtml(query) + '" als neuen Tag anlegen'
                + '</div>';
        }
        if (html === '') {
            hideSuggest();
            return;
        }
        suggestList.innerHTML = html;
        suggestList.style.display = '';
        activeIdx = -1;

        Array.from(suggestList.querySelectorAll('.vstag-suggest-item')).forEach(function (item, idx) {
            item.addEventListener('mouseenter', function () {
                setActive(idx);
            });
            item.addEventListener('click', function () {
                selectSuggestItem(item);
            });
        });
    }

    function setActive(idx) {
        Array.from(suggestList.querySelectorAll('.vstag-suggest-item')).forEach(function (el, i) {
            el.classList.toggle('vstag-suggest-active', i === idx);
        });
        activeIdx = idx;
    }

    function selectSuggestItem(item) {
        if (item.dataset.create) {
            assignTag({createLabel: item.dataset.label, createColor: 'blue'});
        } else {
            assignTag({tagSlug: item.dataset.tagSlug, label: item.dataset.label, color: item.dataset.color});
        }
    }

    function assignTag(payload) {
        var body = Object.assign({targetType:'page', targetId: String(pageId)}, payload);
        fetch('/contao/venne-search/tag/assign', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            credentials:'same-origin',
            body: JSON.stringify(body),
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) {
                toast('Fehler: ' + (d.error || 'unbekannt'), 'err');
                return;
            }
            // Endpoint liefert {ok, slug, label, color, created} flach (kein .tag-Wrapper).
            var tag = {
                slug: d.slug || payload.tagSlug,
                label: d.label || payload.label || payload.createLabel,
                color: d.color || payload.color || 'blue'
            };
            hideEmptyHint();
            container.appendChild(renderChip(tag));
            input.value = '';
            hideSuggest();
            refreshAvailable();
            toast('Tag „' + tag.label + '" zugewiesen');
        }).catch(function () {
            toast('Netzwerk-Fehler beim Zuweisen', 'err');
        });
    }

    function unassignTag(chip) {
        var slug = chip.dataset.tagSlug;
        fetch('/contao/venne-search/tag/unassign', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            credentials:'same-origin',
            body: JSON.stringify({targetType:'page', targetId: String(pageId), tagSlug: slug}),
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) {
                toast('Fehler: ' + (d.error || 'unbekannt'), 'err');
                return;
            }
            chip.remove();
            showEmptyHintIfNeeded();
            refreshAvailable();
            toast('Tag entfernt');
        }).catch(function () {
            toast('Netzwerk-Fehler beim Entfernen', 'err');
        });
    }

    // Initial die „Vorhandene Tags"-Leiste laden.
    refreshAvailable();

    // Input-Handling
    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var q = input.value.trim();
        if (q.length < 1) {
            // Bei leerem Input: ALLE bisher vorhandenen Tags listen — der User
            // sieht direkt was schon existiert, kein blindes Tippen noetig.
            fetchSuggest('').then(function (d) { renderSuggest('', d); }).catch(function () {});
            return;
        }
        debounceTimer = setTimeout(function () {
            fetchSuggest(q).then(function (d) { renderSuggest(q, d); }).catch(function () {});
        }, 180);
    });

    // Focus zeigt direkt alle existierenden Tags (Discovery).
    input.addEventListener('focus', function () {
        if (input.value.trim().length >= 1) return;
        fetchSuggest('').then(function (d) { renderSuggest('', d); }).catch(function () {});
    });

    input.addEventListener('keydown', function (e) {
        var items = suggestList.querySelectorAll('.vstag-suggest-item');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(Math.min(items.length - 1, activeIdx + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(Math.max(0, activeIdx - 1));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIdx >= 0 && items[activeIdx]) {
                selectSuggestItem(items[activeIdx]);
            } else if (items.length > 0) {
                selectSuggestItem(items[0]);
            } else if (input.value.trim().length >= 1) {
                assignTag({createLabel: input.value.trim(), createColor: 'blue'});
            }
        } else if (e.key === 'Escape') {
            hideSuggest();
        }
    });

    document.addEventListener('click', function (e) {
        if (!field.contains(e.target)) hideSuggest();
    });

    // Chip-Remove-Delegation
    container.addEventListener('click', function (e) {
        var btn = e.target.closest('.vstag-chip-remove');
        if (!btn) return;
        var chip = btn.closest('.vstag-chip');
        if (chip) unassignTag(chip);
    });
})();
</script>
HTML;
    }

    private function resolvePageId(mixed $dc): int
    {
        if (\is_object($dc) && property_exists($dc, 'id') && $dc->id) {
            return (int) $dc->id;
        }
        // Fallback: aktuelles Request-id-Param (act=edit&id=N)
        try {
            $request = System::getContainer()->get('request_stack')?->getCurrentRequest();
            if ($request !== null) {
                $id = (int) $request->query->get('id', 0);
                if ($id > 0) {
                    return $id;
                }
            }
        } catch (\Throwable) {
        }
        return 0;
    }

    private function colorBg(string $color): string
    {
        return match ($color) {
            'green'  => '#d1fae5',
            'red'    => '#fee2e2',
            'orange' => '#fed7aa',
            'purple' => '#e9d5ff',
            'pink'   => '#fce7f3',
            'gray'   => '#e5e7eb',
            'teal'   => '#ccfbf1',
            default  => '#dbeafe',
        };
    }

    private function colorFg(string $color): string
    {
        return match ($color) {
            'green'  => '#065f46',
            'red'    => '#991b1b',
            'orange' => '#9a3412',
            'purple' => '#6b21a8',
            'pink'   => '#9d174d',
            'gray'   => '#374151',
            'teal'   => '#115e59',
            default  => '#1e40af',
        };
    }
}
