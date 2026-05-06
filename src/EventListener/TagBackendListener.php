<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Doctrine\DBAL\Connection;
use VenneMedia\VenneSearchContaoBundle\Service\Tag\TagRepository;

/**
 * Rendert Tag-Tree-Picker + Tag-Übersicht für die DCA-Pseudofelder.
 * Wird vom BackendActionListener aufgerufen (über Container).
 *
 * Tree-Picker-UI:
 *  - Liest tl_page hierarchisch (rekursive pid-Walks)
 *  - Pro Page: aktuelle Tag-Chips + "+"-Button öffnet Combobox
 *  - Drag-und-Drop: Tag-Chip vom einen Knoten auf einen anderen ziehen =
 *    Bulk-Assign (Strg/Shift gedrückt = mehrere Pages auf einmal)
 *  - AJAX-Endpoints unter /contao/venne-search/tag/* — siehe routes.yaml
 *
 * Übersicht: tabellarisch alle Tags mit Counts + Drill-Down-Link.
 */
final class TagBackendListener
{
    public function __construct(
        private readonly Connection $db,
        private readonly TagRepository $tags,
    ) {
    }

    public function renderTreePanel(): string
    {
        if (!$this->tags->tablesExist()) {
            return $this->infoBox('Tag-System nicht installiert', 'Bitte erst die Migration ausführen (Backend → System → Migrationen).');
        }

        $allTags = $this->tags->findAll();
        $tree = $this->buildPageTree();
        if ($tree === []) {
            return $this->infoBox('Kein Seitenbaum gefunden', 'Lege zuerst Seiten unter „Layout → Site-Strukturen" an.');
        }

        $allPageIds = $this->collectAllPageIds($tree);
        $assignmentsMap = $this->tags->bulkTagsForTargets(
            array_map(static fn (int $id): array => ['type' => 'page', 'id' => (string) $id], $allPageIds),
        );

        $treeHtml = '';
        foreach ($tree as $rootNode) {
            $treeHtml .= $this->renderNode($rootNode, $assignmentsMap, 0);
        }

        $tagOptionsJson = json_encode(array_map(
            static fn (array $t): array => ['slug' => $t['slug'], 'label' => $t['label'], 'color' => $t['color']],
            $allTags,
        ), \JSON_UNESCAPED_UNICODE);

        return <<<HTML
<div style="margin:14px 18px;padding:18px 22px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <div>
            <strong style="color:#1f2937;font-size:1rem;">Seiten taggen</strong>
            <div style="font-size:.8rem;color:#6b7280;margin-top:4px;">
                Klicke auf "+" neben einer Seite. Drag-&-Drop: Tag-Chip auf eine andere Seite ziehen = mit-zuweisen.
            </div>
        </div>
        <a href="contao/main.php?do=venne_search&amp;table=tl_venne_search_tag" class="tl_submit" style="padding:6px 12px;text-decoration:none;display:inline-block;">Tags verwalten</a>
    </div>
    <div id="vsearch-tag-tree" style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:8px 12px;max-height:600px;overflow-y:auto;">
        {$treeHtml}
    </div>
    <div id="vsearch-tag-toast" style="position:fixed;bottom:20px;right:20px;padding:10px 16px;background:#10b981;color:#fff;border-radius:6px;display:none;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.15);"></div>
</div>
<style>
.vstag-row { display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f3f4f6; }
.vstag-row:last-child { border-bottom:none; }
.vstag-label { flex:1;font-size:.9rem;color:#1f2937;display:flex;align-items:center;gap:6px; }
.vstag-label.vstag-locked { color:#94a3b8; }
.vstag-chips { display:flex;flex-wrap:wrap;gap:4px;align-items:center; }
.vstag-chip { display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:12px;font-size:.78rem;background:#dbeafe;color:#1e40af;cursor:grab;user-select:none; }
.vstag-chip[data-color="green"]  { background:#d1fae5;color:#065f46; }
.vstag-chip[data-color="red"]    { background:#fee2e2;color:#991b1b; }
.vstag-chip[data-color="orange"] { background:#fed7aa;color:#9a3412; }
.vstag-chip[data-color="purple"] { background:#e9d5ff;color:#6b21a8; }
.vstag-chip[data-color="pink"]   { background:#fce7f3;color:#9d174d; }
.vstag-chip[data-color="gray"]   { background:#e5e7eb;color:#374151; }
.vstag-chip[data-color="teal"]   { background:#ccfbf1;color:#115e59; }
.vstag-chip-x { cursor:pointer;font-size:.9rem;line-height:1;opacity:.6; }
.vstag-chip-x:hover { opacity:1; }
.vstag-add { background:none;border:1px dashed #9ca3af;color:#6b7280;padding:1px 8px;border-radius:12px;cursor:pointer;font-size:.78rem; }
.vstag-add:hover { color:#3a7178;border-color:#3a7178; }
.vstag-row.vstag-drop-target { background:#dbeafe; }
.vstag-popover { position:absolute;background:#fff;border:1px solid #d1d5db;border-radius:6px;box-shadow:0 6px 16px rgba(0,0,0,.12);padding:8px;z-index:1000;min-width:240px; }
.vstag-popover input { width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;margin-bottom:6px;font-size:.85rem; }
.vstag-popover-list { max-height:200px;overflow-y:auto; }
.vstag-popover-item { padding:5px 8px;cursor:pointer;border-radius:4px;font-size:.85rem; }
.vstag-popover-item:hover { background:#f3f4f6; }
.vstag-popover-item.vstag-popover-create { color:#3a7178;font-style:italic; }
</style>
<script>
(function(){
    var TAGS = {$tagOptionsJson};
    var tree = document.getElementById('vsearch-tag-tree');
    if (!tree) return;
    var toast = document.getElementById('vsearch-tag-toast');
    function showToast(msg, error) {
        toast.textContent = msg;
        toast.style.background = error ? '#dc2626' : '#10b981';
        toast.style.display = 'block';
        setTimeout(function(){ toast.style.display = 'none'; }, 2400);
    }
    function api(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify(body),
        }).then(function(r){ return r.json(); });
    }
    function colorFor(slug) {
        for (var i=0;i<TAGS.length;i++) if (TAGS[i].slug === slug) return TAGS[i].color || 'blue';
        return 'blue';
    }
    function labelFor(slug) {
        for (var i=0;i<TAGS.length;i++) if (TAGS[i].slug === slug) return TAGS[i].label;
        return slug;
    }
    function renderChip(slug) {
        var c = colorFor(slug);
        var l = labelFor(slug);
        return '<span class="vstag-chip" data-color="'+c+'" data-slug="'+slug+'" draggable="true" title="'+l+'">'
             +  '<span class="vstag-chip-label">'+l+'</span>'
             +  '<span class="vstag-chip-x" data-action="remove">×</span>'
             + '</span>';
    }

    // Click → Add-Tag-Popover
    tree.addEventListener('click', function(e){
        var btn = e.target.closest('.vstag-add');
        if (btn) {
            e.preventDefault();
            openAddPopover(btn);
            return;
        }
        var x = e.target.closest('.vstag-chip-x[data-action="remove"]');
        if (x) {
            var chip = x.closest('.vstag-chip');
            var row = x.closest('.vstag-row');
            api('/contao/venne-search/tag/unassign', {
                targetType: row.dataset.type,
                targetId: row.dataset.id,
                tagSlug: chip.dataset.slug,
            }).then(function(d){
                if (d.ok) {
                    chip.remove();
                    showToast('Tag entfernt');
                } else {
                    showToast('Fehler: ' + (d.error || 'unbekannt'), true);
                }
            });
        }
    });

    function openAddPopover(btn) {
        var existing = document.querySelector('.vstag-popover');
        if (existing) existing.remove();

        var pop = document.createElement('div');
        pop.className = 'vstag-popover';
        pop.innerHTML = '<input type="text" placeholder="Tag suchen oder neu erstellen…" autofocus><div class="vstag-popover-list"></div>';
        document.body.appendChild(pop);
        var rect = btn.getBoundingClientRect();
        pop.style.left = (rect.left + window.scrollX) + 'px';
        pop.style.top = (rect.bottom + window.scrollY + 4) + 'px';

        var input = pop.querySelector('input');
        var list = pop.querySelector('.vstag-popover-list');
        var row = btn.closest('.vstag-row');
        var existingSlugs = Array.from(row.querySelectorAll('.vstag-chip')).map(function(c){return c.dataset.slug;});

        function refresh(q){
            list.innerHTML = '';
            q = (q||'').toLowerCase().trim();
            var hits = TAGS.filter(function(t){
                if (existingSlugs.indexOf(t.slug) !== -1) return false;
                if (!q) return true;
                return t.label.toLowerCase().indexOf(q) !== -1 || t.slug.indexOf(q) !== -1;
            }).slice(0, 30);
            hits.forEach(function(t){
                var item = document.createElement('div');
                item.className = 'vstag-popover-item';
                item.textContent = t.label + ' (' + t.slug + ')';
                item.addEventListener('click', function(){ assignTag(row, t.slug, t.label, t.color); pop.remove(); });
                list.appendChild(item);
            });
            if (q && !hits.some(function(h){return h.label.toLowerCase()===q;})) {
                var item = document.createElement('div');
                item.className = 'vstag-popover-item vstag-popover-create';
                item.textContent = '+ "'+q+'" als neuen Tag anlegen';
                item.addEventListener('click', function(){ assignTag(row, null, q, 'blue'); pop.remove(); });
                list.appendChild(item);
            }
        }
        input.addEventListener('input', function(){ refresh(input.value); });
        refresh('');
        document.addEventListener('click', function close(e){
            if (!pop.contains(e.target)) { pop.remove(); document.removeEventListener('click', close, true); }
        }, true);
    }

    function assignTag(row, slug, label, color) {
        api('/contao/venne-search/tag/assign', {
            targetType: row.dataset.type,
            targetId: row.dataset.id,
            tagSlug: slug,
            createLabel: label,
            createColor: color,
        }).then(function(d){
            if (d.ok) {
                if (d.created) {
                    TAGS.push({slug: d.slug, label: d.label, color: d.color});
                }
                var chips = row.querySelector('.vstag-chips');
                var addBtn = chips.querySelector('.vstag-add');
                addBtn.insertAdjacentHTML('beforebegin', renderChip(d.slug));
                showToast('Tag zugewiesen');
            } else {
                showToast('Fehler: ' + (d.error || 'unbekannt'), true);
            }
        });
    }

    // Drag & Drop: Chip auf andere Row droppen → Bulk-Assign
    var draggedSlug = null;
    tree.addEventListener('dragstart', function(e){
        var chip = e.target.closest('.vstag-chip');
        if (!chip) return;
        draggedSlug = chip.dataset.slug;
        e.dataTransfer.effectAllowed = 'copy';
        e.dataTransfer.setData('text/plain', chip.dataset.slug);
    });
    tree.addEventListener('dragover', function(e){
        var row = e.target.closest('.vstag-row');
        if (row && draggedSlug) {
            e.preventDefault();
            row.classList.add('vstag-drop-target');
        }
    });
    tree.addEventListener('dragleave', function(e){
        var row = e.target.closest('.vstag-row');
        if (row) row.classList.remove('vstag-drop-target');
    });
    tree.addEventListener('drop', function(e){
        var row = e.target.closest('.vstag-row');
        if (!row || !draggedSlug) return;
        e.preventDefault();
        row.classList.remove('vstag-drop-target');
        var existing = Array.from(row.querySelectorAll('.vstag-chip')).map(function(c){return c.dataset.slug;});
        if (existing.indexOf(draggedSlug) !== -1) { draggedSlug = null; return; }
        var slug = draggedSlug;
        draggedSlug = null;
        api('/contao/venne-search/tag/assign', {
            targetType: row.dataset.type,
            targetId: row.dataset.id,
            tagSlug: slug,
        }).then(function(d){
            if (d.ok) {
                var chips = row.querySelector('.vstag-chips');
                var addBtn = chips.querySelector('.vstag-add');
                addBtn.insertAdjacentHTML('beforebegin', renderChip(d.slug));
                showToast('Tag zugewiesen (Drag & Drop)');
            } else {
                showToast('Fehler: ' + (d.error || 'unbekannt'), true);
            }
        });
    });
})();
</script>
HTML;
    }

    public function renderOverviewPanel(): string
    {
        if (!$this->tags->tablesExist()) {
            return '';
        }
        $tags = $this->tags->findAllWithCounts();
        if ($tags === []) {
            return $this->infoBox('Noch keine Tags', 'Lege Tags an unter "System → Venne Search → Tags".');
        }

        $rows = '';
        foreach ($tags as $tag) {
            $color = htmlspecialchars($tag['color']);
            $label = htmlspecialchars($tag['label']);
            $slug = htmlspecialchars($tag['slug']);
            $count = $tag['count'];
            $editUrl = 'contao/main.php?do=venne_search&amp;table=tl_venne_search_tag&amp;act=edit&amp;id=' . $tag['id'];
            $rows .= sprintf(
                '<tr style="border-bottom:1px solid #f3f4f6;">'
                . '<td style="padding:8px 6px;"><span class="vstag-chip" data-color="%s">%s</span></td>'
                . '<td style="padding:8px 6px;color:#6b7280;font-family:monospace;font-size:.8rem;">%s</td>'
                . '<td style="padding:8px 6px;text-align:right;font-weight:600;">%d</td>'
                . '<td style="padding:8px 6px;text-align:right;"><a href="%s" class="link">Bearbeiten</a></td>'
                . '</tr>',
                $color, $label, $slug, $count, $editUrl,
            );
        }

        return <<<HTML
<div style="margin:14px 18px;padding:18px 22px;border:1px solid #d1d5db;border-radius:8px;background:#fff;">
    <strong style="color:#1f2937;">Alle Tags</strong>
    <table style="width:100%;margin-top:10px;border-collapse:collapse;font-size:.9rem;">
        <thead>
            <tr style="border-bottom:1px solid #d1d5db;">
                <th style="text-align:left;padding:8px 6px;font-weight:600;color:#6b7280;text-transform:uppercase;font-size:.7rem;letter-spacing:.05em;">Tag</th>
                <th style="text-align:left;padding:8px 6px;font-weight:600;color:#6b7280;text-transform:uppercase;font-size:.7rem;letter-spacing:.05em;">Slug</th>
                <th style="text-align:right;padding:8px 6px;font-weight:600;color:#6b7280;text-transform:uppercase;font-size:.7rem;letter-spacing:.05em;">Zuweisungen</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            {$rows}
        </tbody>
    </table>
</div>
HTML;
    }

    /**
     * @return list<array{id:int, title:string, alias:string, type:string, children:list<array>}>
     */
    private function buildPageTree(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, pid, title, alias, type, sorting FROM tl_page ORDER BY pid ASC, sorting ASC',
            );
        } catch (\Throwable) {
            return [];
        }

        $byPid = [];
        foreach ($rows as $r) {
            $byPid[(int) $r['pid']][] = $r;
        }

        $build = function (int $pid) use (&$build, $byPid): array {
            $out = [];
            foreach ($byPid[$pid] ?? [] as $row) {
                $out[] = [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'alias' => (string) $row['alias'],
                    'type' => (string) $row['type'],
                    'children' => $build((int) $row['id']),
                ];
            }
            return $out;
        };
        return $build(0);
    }

    /**
     * @param array{id:int,title:string,type:string,children:list<array>} $node
     * @param array<string, list<array{slug:string,label:string,color:string}>> $assignments
     */
    private function renderNode(array $node, array $assignments, int $depth): string
    {
        $indent = $depth * 18;
        $key = 'page:' . $node['id'];
        $tags = $assignments[$key] ?? [];
        $chipsHtml = '';
        foreach ($tags as $t) {
            $chipsHtml .= sprintf(
                '<span class="vstag-chip" data-color="%s" data-slug="%s" draggable="true" title="%s">'
                . '<span class="vstag-chip-label">%s</span>'
                . '<span class="vstag-chip-x" data-action="remove">×</span>'
                . '</span>',
                htmlspecialchars($t['color']),
                htmlspecialchars($t['slug']),
                htmlspecialchars($t['label']),
                htmlspecialchars($t['label']),
            );
        }
        $isRoot = $node['type'] === 'root';
        $rowClass = $isRoot ? 'vstag-row vstag-locked' : 'vstag-row';
        $titleHtml = htmlspecialchars($node['title'] ?: ('Seite #' . $node['id']));
        $iconChar = $isRoot ? '🌐' : ($node['children'] !== [] ? '📁' : '📄');

        $html = sprintf(
            '<div class="%s" data-type="page" data-id="%d" style="padding-left:%dpx;">'
            . '<span class="vstag-label %s">%s %s</span>'
            . '<span class="vstag-chips">%s<button type="button" class="vstag-add">+ Tag</button></span>'
            . '</div>',
            $rowClass, $node['id'], $indent,
            $isRoot ? 'vstag-locked' : '',
            $iconChar, $titleHtml, $chipsHtml,
        );

        foreach ($node['children'] as $child) {
            $html .= $this->renderNode($child, $assignments, $depth + 1);
        }
        return $html;
    }

    /**
     * @param list<array{id:int, children:list<array>}> $tree
     * @return list<int>
     */
    private function collectAllPageIds(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            $out[] = $node['id'];
            $out = array_merge($out, $this->collectAllPageIds($node['children']));
        }
        return $out;
    }

    private function infoBox(string $title, string $hint): string
    {
        return sprintf(
            '<div style="margin:14px 18px;padding:18px 22px;border:1px solid #fcd34d;border-radius:8px;background:#fffbeb;color:#78350f;">'
            . '<strong>%s</strong>'
            . '<div style="margin-top:6px;font-size:.9rem;">%s</div>'
            . '</div>',
            htmlspecialchars($title),
            htmlspecialchars($hint),
        );
    }
}
