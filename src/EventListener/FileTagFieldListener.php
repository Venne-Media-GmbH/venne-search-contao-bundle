<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Doctrine\DBAL\Connection;
use VenneMedia\VenneSearchContaoBundle\Service\Tag\TagRepository;

/**
 * Tag-Picker-Box im tl_files-Edit-Form. Analog zu PageTagFieldListener,
 * aber Target-ID ist der Datei-Pfad (z.B. „files/foo/bar.pdf") — der
 * Backend-Tag-Assign-Endpoint erwartet das so. Contao gibt uns im DC die
 * UUID-Hex der Datei; wir loesen die zum Pfad ueber tl_files auf.
 */
final class FileTagFieldListener
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly Connection $db,
    ) {
    }

    public static function render(/** @phpstan-ignore-next-line */ $dc = null): string
    {
        try {
            $listener = \Contao\System::getContainer()?->get(self::class);
        } catch (\Throwable) {
            return '<p class="tl_help">Tag-Feld konnte nicht geladen werden.</p>';
        }
        return $listener?->renderField($dc) ?? '';
    }

    public function renderField(/** @phpstan-ignore-next-line */ $dc = null): string
    {
        $filePath = $this->resolveFilePath($dc);
        if ($filePath === '') {
            return '<p class="tl_help">Tags können erst nach dem ersten Speichern der Datei vergeben werden.</p>';
        }
        if (!$this->isIndexableFile($filePath)) {
            return '<p class="tl_help">Diese Datei ist kein indexierbares Dokument (PDF, DOCX, XLSX, TXT, MD, RTF, ODT). Tags werden nur fuer Dokumente unterstuetzt, die im Volltext-Index liegen.</p>';
        }

        $assigned = $this->tags->tagsForTarget('file', $filePath);

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

        $emptyHint = $assigned === []
            ? '<span class="vstag-empty">Noch keine Tags vergeben — tippe unten einen Begriff ein.</span>'
            : '';

        $pathEsc = htmlspecialchars($filePath, ENT_QUOTES);
        $pathJs = json_encode($filePath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rowId = md5($filePath);

        return <<<HTML
<style>
.vstag-widget { margin:0; padding:1rem 1.2rem; background:transparent; border:0; }
.vstag-widget-title { margin:0 0 .5rem 0 !important; font-size:.9rem; font-weight:600; }
.vstag-widget-title label { font-weight:600; }
.vstag-widget-help { margin-top:.5rem !important; margin-bottom:0 !important; font-size:.82rem !important; line-height:1.5 !important; color:#6b7280 !important; white-space:normal !important; overflow:visible !important; text-overflow:clip !important; max-width:none !important; display:block !important; }
.vstag-page-field { margin:0; }
.vstag-page-field .vstag-chips-container { display:flex; flex-wrap:wrap; gap:.45rem; padding:.7rem .85rem; min-height:48px; background:#fff; border:1px solid #d1d5db; border-radius:6px; margin-bottom:.5rem; align-items:center; box-sizing:border-box; }
.vstag-page-field .vstag-input-row { position:relative; }
.vstag-page-field .vstag-input { width:100% !important; box-sizing:border-box; padding:.6rem .85rem; font-size:.95rem; border:1px solid #d1d5db; border-radius:6px; background:#fff; }
.vstag-page-field .vstag-input:focus { outline:0; border-color:#4a8087; box-shadow:0 0 0 3px rgba(74,128,135,.15); }
.vstag-page-field .vstag-suggest-list { position:absolute; top:calc(100% + 2px); left:0; right:0; background:#fff; border:1px solid #d1d5db; border-radius:6px; max-height:260px; overflow-y:auto; display:none; z-index:200; box-shadow:0 8px 24px -8px rgba(0,0,0,.18),0 2px 4px rgba(0,0,0,.05); }
.vstag-chip { display:inline-flex; align-items:center; gap:.4rem; padding:.28rem .65rem .28rem .75rem; border-radius:999px; font-size:.82rem; font-weight:500; line-height:1.4; }
.vstag-chip-remove { border:0 !important; background:transparent !important; color:inherit !important; opacity:.6; cursor:pointer; font-size:1.1rem; line-height:1; padding:0 0 0 .2rem !important; box-shadow:none !important; }
.vstag-chip-remove:hover { opacity:1; }
.vstag-suggest-item { padding:.65rem .9rem; cursor:pointer; font-size:.9rem; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; gap:.55rem; }
.vstag-suggest-item:last-child { border-bottom:0; }
.vstag-suggest-item:hover, .vstag-suggest-item.vstag-suggest-active { background:#f9fafb; }
.vstag-suggest-create { color:#3a7178; font-weight:600; background:#f0f9ff; }
.vstag-suggest-create:hover { background:#e0f2fe; }
.vstag-suggest-chip-preview { display:inline-block; width:14px; height:14px; border-radius:50%; flex-shrink:0; }
.vstag-empty { color:#9ca3af; font-style:italic; font-size:.88rem; padding-left:.15rem; }
.vstag-toast { position:fixed; bottom:1.2rem; right:1.2rem; padding:.75rem 1.15rem; border-radius:6px; background:#1f2937; color:#fff; font-size:.88rem; box-shadow:0 8px 24px rgba(0,0,0,.25); z-index:99999; opacity:0; transition:opacity .2s,transform .2s; transform:translateY(8px); }
.vstag-toast.vstag-toast--show { opacity:1; transform:none; }
.vstag-toast--ok { background:#065f46; }
.vstag-toast--err { background:#991b1b; }
</style>

<div class="widget clr vstag-widget">
    <h3 class="vstag-widget-title"><label for="vstag-input-{$rowId}">Such-Tags</label></h3>
    <div class="vstag-page-field" id="vstag-file-field-{$rowId}" data-target-type="file" data-target-id="{$pathEsc}">
        <div class="vstag-chips-container">
            {$chips}
            {$emptyHint}
        </div>
        <div class="vstag-input-row">
            <input type="text" id="vstag-input-{$rowId}" class="vstag-input tl_text" placeholder="Tag suchen oder neu anlegen…" autocomplete="off">
            <div class="vstag-suggest-list"></div>
        </div>
    </div>
    <p class="tl_help tl_tip vstag-widget-help">
        Tags fuer die Volltextsuche. Beispiel: Tag „Formular" → diese Datei landet bei der Suche nach „Formular" weit oben, auch wenn das Wort nicht im Inhalt steht.
        <strong>Neue Tags entstehen</strong> indem du den Begriff tippst und auf „+ als neuen Tag anlegen" klickst.
        Aenderungen wirken sofort — kein zusaetzliches Speichern noetig.
    </p>
</div>
<script>
(function () {
    'use strict';
    var field = document.getElementById('vstag-file-field-{$rowId}');
    if (!field || field.dataset.vsInit) return;
    field.dataset.vsInit = '1';

    var targetId = {$pathJs};
    var targetType = 'file';
    var container = field.querySelector('.vstag-chips-container');
    var input = field.querySelector('.vstag-input');
    var suggestList = field.querySelector('.vstag-suggest-list');
    var emptyHint = field.querySelector('.vstag-empty');

    var debounceTimer = null;
    var activeIdx = -1;

    function toast(msg, kind) {
        var t = document.createElement('div');
        t.className = 'vstag-toast vstag-toast--' + (kind || 'ok');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('vstag-toast--show'); }, 10);
        setTimeout(function () { t.classList.remove('vstag-toast--show'); setTimeout(function () { t.remove(); }, 250); }, 2200);
    }
    function colorBg(c) { return ({blue:'#dbeafe',green:'#d1fae5',red:'#fee2e2',orange:'#fed7aa',purple:'#e9d5ff',pink:'#fce7f3',gray:'#e5e7eb',teal:'#ccfbf1'})[c] || '#dbeafe'; }
    function colorFg(c) { return ({blue:'#1e40af',green:'#065f46',red:'#991b1b',orange:'#9a3412',purple:'#6b21a8',pink:'#9d174d',gray:'#374151',teal:'#115e59'})[c] || '#1e40af'; }
    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function renderChip(tag) {
        var chip = document.createElement('span');
        chip.className = 'vstag-chip';
        chip.dataset.tagSlug = tag.slug;
        chip.dataset.color = tag.color || 'blue';
        chip.style.background = colorBg(tag.color);
        chip.style.color = colorFg(tag.color);
        chip.innerHTML = '<span class="vstag-chip-label">' + escapeHtml(tag.label) + '</span><button type="button" class="vstag-chip-remove" title="Tag entfernen" aria-label="Entfernen">×</button>';
        return chip;
    }
    function hideEmptyHint() { if (emptyHint) emptyHint.style.display = 'none'; }
    function showEmptyHintIfNeeded() { if (emptyHint && container.querySelectorAll('.vstag-chip').length === 0) emptyHint.style.display = ''; }
    function hideSuggest() { suggestList.style.display = 'none'; suggestList.innerHTML = ''; activeIdx = -1; }
    function getAssignedSlugs() { return Array.from(container.querySelectorAll('.vstag-chip')).map(function (c) { return c.dataset.tagSlug; }); }
    function fetchSuggest(q) { return fetch('/contao/venne-search/tag/suggest?q=' + encodeURIComponent(q), {credentials:'same-origin'}).then(function (r) { return r.json(); }); }
    function renderSuggest(q, data) {
        var assigned = getAssignedSlugs();
        var rawList = Array.isArray(data) ? data : (data.tags || []);
        var existing = rawList.filter(function (t) { return assigned.indexOf(t.slug) === -1; });
        var exactMatch = existing.some(function (t) { return t.label.toLowerCase() === q.toLowerCase() || t.slug.toLowerCase() === q.toLowerCase(); });
        var html = '';
        existing.forEach(function (t) {
            html += '<div class="vstag-suggest-item" data-tag-slug="' + escapeHtml(t.slug) + '" data-color="' + escapeHtml(t.color || 'blue') + '" data-label="' + escapeHtml(t.label) + '">'
                + '<span class="vstag-suggest-chip-preview" style="background:' + colorFg(t.color) + ';"></span>'
                + '<span>' + escapeHtml(t.label) + '</span></div>';
        });
        if (q.length >= 1 && !exactMatch) {
            html += '<div class="vstag-suggest-item vstag-suggest-create" data-create="1" data-label="' + escapeHtml(q) + '">+ „' + escapeHtml(q) + '" als neuen Tag anlegen</div>';
        }
        if (html === '') { hideSuggest(); return; }
        suggestList.innerHTML = html;
        suggestList.style.display = '';
        activeIdx = -1;
        Array.from(suggestList.querySelectorAll('.vstag-suggest-item')).forEach(function (item, idx) {
            item.addEventListener('mouseenter', function () { setActive(idx); });
            item.addEventListener('click', function () { selectSuggestItem(item); });
        });
    }
    function setActive(idx) { Array.from(suggestList.querySelectorAll('.vstag-suggest-item')).forEach(function (el, i) { el.classList.toggle('vstag-suggest-active', i === idx); }); activeIdx = idx; }
    function selectSuggestItem(item) {
        if (item.dataset.create) assignTag({createLabel: item.dataset.label, createColor: 'blue'});
        else assignTag({tagSlug: item.dataset.tagSlug, label: item.dataset.label, color: item.dataset.color});
    }
    function assignTag(payload) {
        var body = Object.assign({targetType: targetType, targetId: targetId}, payload);
        fetch('/contao/venne-search/tag/assign', {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin', body: JSON.stringify(body)})
            .then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { toast('Fehler: ' + (d.error || 'unbekannt'), 'err'); return; }
            var tag = { slug: d.slug || payload.tagSlug, label: d.label || payload.label || payload.createLabel, color: d.color || payload.color || 'blue' };
            hideEmptyHint();
            container.appendChild(renderChip(tag));
            input.value = '';
            hideSuggest();
            toast('Tag „' + tag.label + '" zugewiesen');
        }).catch(function () { toast('Netzwerk-Fehler beim Zuweisen', 'err'); });
    }
    function unassignTag(chip) {
        var slug = chip.dataset.tagSlug;
        fetch('/contao/venne-search/tag/unassign', {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin', body: JSON.stringify({targetType: targetType, targetId: targetId, tagSlug: slug})})
            .then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { toast('Fehler: ' + (d.error || 'unbekannt'), 'err'); return; }
            chip.remove();
            showEmptyHintIfNeeded();
            toast('Tag entfernt');
        }).catch(function () { toast('Netzwerk-Fehler beim Entfernen', 'err'); });
    }
    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var q = input.value.trim();
        if (q.length < 1) { hideSuggest(); return; }
        debounceTimer = setTimeout(function () { fetchSuggest(q).then(function (d) { renderSuggest(q, d); }).catch(function () {}); }, 180);
    });
    input.addEventListener('keydown', function (e) {
        var items = suggestList.querySelectorAll('.vstag-suggest-item');
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(items.length - 1, activeIdx + 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(0, activeIdx - 1)); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIdx >= 0 && items[activeIdx]) selectSuggestItem(items[activeIdx]);
            else if (items.length > 0) selectSuggestItem(items[0]);
            else if (input.value.trim().length >= 1) assignTag({createLabel: input.value.trim(), createColor: 'blue'});
        } else if (e.key === 'Escape') { hideSuggest(); }
    });
    document.addEventListener('click', function (e) { if (!field.contains(e.target)) hideSuggest(); });
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

    private function resolveFilePath(/** @phpstan-ignore-next-line */ $dc = null): string
    {
        // Contao 4.13 tl_files: $dc->id ist UUID-Hex (32 Zeichen ohne -)
        // oder die UUID mit Bindestrichen. Beide Varianten supporten.
        $id = (string) ($dc->id ?? $_GET['id'] ?? '');
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        // Falls schon ein Pfad (enthaelt „/"), direkt nehmen.
        if (str_contains($id, '/')) {
            return $id;
        }
        // UUID-String mit oder ohne Bindestriche → binary → Pfad-Lookup
        $hex = preg_replace('/[^a-f0-9]/i', '', $id) ?? '';
        if (\strlen($hex) !== 32) {
            return '';
        }
        try {
            $bin = @hex2bin($hex);
            if ($bin === false || \strlen($bin) !== 16) {
                return '';
            }
            $row = $this->db->fetchAssociative('SELECT path FROM tl_files WHERE uuid = ? LIMIT 1', [$bin]);
            return \is_array($row) ? (string) ($row['path'] ?? '') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function isIndexableFile(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return \in_array($ext, ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'odt', 'rtf', 'txt', 'md'], true);
    }

    private function colorBg(string $color): string
    {
        return match ($color) {
            'green' => '#d1fae5', 'red' => '#fee2e2', 'orange' => '#fed7aa',
            'purple' => '#e9d5ff', 'pink' => '#fce7f3', 'gray' => '#e5e7eb',
            'teal' => '#ccfbf1', default => '#dbeafe',
        };
    }

    private function colorFg(string $color): string
    {
        return match ($color) {
            'green' => '#065f46', 'red' => '#991b1b', 'orange' => '#9a3412',
            'purple' => '#6b21a8', 'pink' => '#9d174d', 'gray' => '#374151',
            'teal' => '#115e59', default => '#1e40af',
        };
    }
}
