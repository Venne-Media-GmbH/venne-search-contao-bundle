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

    /**
     * Wird vom DCA `tl_venne_search_tag.assignments_panel` aufgerufen,
     * wenn der User einen Tag bearbeitet. Zeigt alle Seiten/Dateien die
     * diesen Tag tragen — mit Entfernen-Knopf pro Eintrag.
     */
    public static function renderAssignmentsPanel(): string
    {
        $container = \Contao\System::getContainer();
        if ($container === null) {
            return '';
        }
        try {
            $listener = $container->get(self::class);
        } catch (\Throwable) {
            return '';
        }
        $tagId = (int) (\Contao\Input::get('id') ?? 0);
        return $listener?->buildAssignmentsHtml($tagId) ?? '';
    }

    public function buildAssignmentsHtml(int $tagId): string
    {
        if ($tagId <= 0 || !$this->tags->tablesExist()) {
            return '';
        }

        $targets = $this->tags->targetsForTag($tagId);
        // Auch bei leerer Liste: weiter, damit der Picker gerendert wird.

        // Page-Pfade auflösen für die Anzeige (Breadcrumb).
        $pageIds = [];
        $filePaths = [];
        foreach ($targets as $t) {
            if ($t['targetType'] === 'page') {
                $pageIds[] = (int) $t['targetId'];
            } elseif ($t['targetType'] === 'file') {
                $filePaths[] = (string) $t['targetId'];
            }
        }
        $pageInfo = $this->resolvePageBreadcrumbs($pageIds);

        $rows = '';
        if ($targets === []) {
            $rows = '<tr><td colspan="3" style="padding:14px;color:#6b7280;font-style:italic;text-align:center;">Diesem Tag ist noch nichts zugewiesen — Seiten unten auswählen.</td></tr>';
        }
        foreach ($targets as $t) {
            $type = $t['targetType'];
            $tid = $t['targetId'];
            if ($type === 'page') {
                $info = $pageInfo[(int) $tid] ?? null;
                if ($info === null) {
                    continue;
                }
                $editUrl = htmlspecialchars($this->buildBackendUrl(
                    'do=page&act=edit&id=' . (int) $tid
                ));
                $rows .= sprintf(
                    '<tr style="border-bottom:1px solid #f3f4f6;">'
                    . '<td style="padding:8px 6px;width:24px;color:#6b7280;">%s</td>'
                    . '<td style="padding:8px 6px;">'
                    . '<a href="%s" style="color:#1f2937;text-decoration:none;font-weight:500;">%s</a>'
                    . '<div style="color:#94a3b8;font-size:.78rem;margin-top:2px;">%s</div>'
                    . '</td>'
                    . '<td style="padding:8px 6px;text-align:right;width:140px;">'
                    . '<button type="button" class="vstag-unassign-btn" data-type="page" data-id="%d" data-tag-id="%d" '
                    . 'style="background:transparent;border:1px solid #fca5a5;color:#b91c1c;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:.8rem;">Entfernen</button>'
                    . '</td>'
                    . '</tr>',
                    self::iconPage(),
                    $editUrl,
                    htmlspecialchars($info['title']),
                    htmlspecialchars($info['breadcrumb']),
                    (int) $tid,
                    $tagId,
                );
            } elseif ($type === 'file') {
                $rows .= sprintf(
                    '<tr style="border-bottom:1px solid #f3f4f6;">'
                    . '<td style="padding:8px 6px;width:24px;color:#6b7280;">%s</td>'
                    . '<td style="padding:8px 6px;">'
                    . '<span style="color:#1f2937;font-weight:500;">%s</span>'
                    . '<div style="color:#94a3b8;font-size:.78rem;margin-top:2px;font-family:monospace;">%s</div>'
                    . '</td>'
                    . '<td style="padding:8px 6px;text-align:right;width:140px;">'
                    . '<button type="button" class="vstag-unassign-btn" data-type="file" data-id="%s" data-tag-id="%d" '
                    . 'style="background:transparent;border:1px solid #fca5a5;color:#b91c1c;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:.8rem;">Entfernen</button>'
                    . '</td>'
                    . '</tr>',
                    self::iconFile(),
                    htmlspecialchars(basename($tid)),
                    htmlspecialchars($tid),
                    htmlspecialchars($tid),
                    $tagId,
                );
            }
        }

        // Tag-Slug + Tag-Label/Farbe für die Hinzu-API-Calls.
        $tagSlug = '';
        $tagLabel = '';
        $tagColor = 'blue';
        try {
            $row = $this->db->fetchAssociative('SELECT slug, label, color FROM tl_venne_search_tag WHERE id = ?', [$tagId]);
            if (\is_array($row)) {
                $tagSlug = (string) ($row['slug'] ?? '');
                $tagLabel = (string) ($row['label'] ?? '');
                $tagColor = (string) ($row['color'] ?? 'blue');
            }
        } catch (\Throwable) {
        }
        $tagSlugJs = json_encode($tagSlug);
        $tagLabelJs = json_encode($tagLabel);
        $tagColorJs = json_encode($tagColor);

        // Page-Tree für den Picker bauen, schon zugewiesene Pages markiert.
        $assignedPageIds = array_map(static fn (array $t): int => (int) $t['targetId'],
            array_filter($targets, static fn (array $t): bool => $t['targetType'] === 'page'));
        $assignedSet = array_flip($assignedPageIds);
        $tree = $this->buildPageTree();
        $pickerTreeHtml = '';
        foreach ($tree as $rootNode) {
            $pickerTreeHtml .= $this->renderPickerNode($rootNode, $assignedSet, 0);
        }

        return <<<HTML
<div style="margin:14px 18px;padding:18px 22px;border:1px solid #d1d5db;border-radius:8px;background:#fff;">
    <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
        <thead>
            <tr style="border-bottom:1px solid #d1d5db;">
                <th style="text-align:left;padding:8px 6px;font-weight:600;color:#6b7280;text-transform:uppercase;font-size:.7rem;letter-spacing:.05em;"></th>
                <th style="text-align:left;padding:8px 6px;font-weight:600;color:#6b7280;text-transform:uppercase;font-size:.7rem;letter-spacing:.05em;">Inhalt</th>
                <th style="text-align:right;padding:8px 6px;"></th>
            </tr>
        </thead>
        <tbody id="vstag-assignments-tbody">
            {$rows}
        </tbody>
    </table>

    <div style="margin-top:18px;">
        <button type="button" id="vstag-assign-toggle" class="tl_submit" style="background:#3a7178;color:#fff;border:0;padding:8px 16px;border-radius:5px;cursor:pointer;font-weight:500;">+ Seiten zuweisen</button>
    </div>

    <div id="vstag-assign-picker" style="display:none;margin-top:14px;border:1px solid #d1d5db;border-radius:6px;background:#f9fafb;padding:14px 16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
            <div>
                <strong style="color:#1f2937;">Seiten auswählen</strong>
                <div style="font-size:.8rem;color:#6b7280;margin-top:3px;">Schon zugewiesene Seiten sind ausgegraut.</div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="button" id="vstag-picker-apply" class="tl_submit" style="background:#3a7178;color:#fff;border:0;padding:6px 14px;border-radius:5px;cursor:pointer;">Auswahl zuweisen</button>
                <button type="button" id="vstag-picker-cancel" style="background:transparent;color:#6b7280;border:1px solid #d1d5db;padding:5px 12px;border-radius:5px;cursor:pointer;">Abbrechen</button>
            </div>
        </div>
        <div id="vstag-picker-tree" style="background:#fff;border:1px solid #e5e7eb;border-radius:4px;padding:6px 10px;max-height:480px;overflow-y:auto;">
            {$pickerTreeHtml}
        </div>
        <div id="vstag-picker-status" style="margin-top:8px;font-size:.85rem;color:#6b7280;"></div>
    </div>
</div>
<style>
.vstag-picker-row { display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:.88rem; }
.vstag-picker-row:last-child { border-bottom:0; }
.vstag-picker-row.vstag-picker-locked { opacity:.45;cursor:not-allowed; }
.vstag-picker-row .vstag-picker-icon { color:#6b7280;display:inline-flex;flex-shrink:0; }
.vstag-picker-row .vstag-picker-title { color:#1f2937; }
.vstag-picker-row .vstag-picker-assigned { color:#10b981;font-size:.72rem;font-weight:600;margin-left:auto; }
</style>
<script>
(function(){
    var TAG_SLUG = {$tagSlugJs};
    var TAG_LABEL = {$tagLabelJs};
    var TAG_COLOR = {$tagColorJs};

    var tbody = document.getElementById('vstag-assignments-tbody');
    var toggleBtn = document.getElementById('vstag-assign-toggle');
    var picker = document.getElementById('vstag-assign-picker');
    var pickerCancel = document.getElementById('vstag-picker-cancel');
    var pickerApply = document.getElementById('vstag-picker-apply');
    var pickerStatus = document.getElementById('vstag-picker-status');

    // Hilfsfunktion: ausgewählte Page-IDs lesen
    function selectedPageIds() {
        return Array.from(picker.querySelectorAll('input.vstag-picker-cb:checked')).map(function (cb) { return cb.dataset.id; });
    }

    // Entfernen-Buttons (Bestand)
    if (tbody) tbody.addEventListener('click', function (e) {
        var btn = e.target.closest('.vstag-unassign-btn');
        if (!btn) return;
        if (!confirm('Tag von diesem Eintrag entfernen?')) return;
        var row = btn.closest('tr');
        btn.disabled = true;
        btn.textContent = '...';
        fetch('/contao/venne-search/tag/unassign', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({
                targetType: btn.dataset.type,
                targetId: btn.dataset.id,
                tagSlug: TAG_SLUG,
            }),
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.ok) row.remove();
            else { btn.disabled = false; btn.textContent = 'Entfernen'; alert('Fehler: ' + (d.error || 'unbekannt')); }
        });
    });

    // Picker-Toggle
    if (toggleBtn) toggleBtn.addEventListener('click', function (e) {
        e.preventDefault();
        picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
        toggleBtn.textContent = picker.style.display === 'block' ? '− Picker schließen' : '+ Seiten zuweisen';
    });
    if (pickerCancel) pickerCancel.addEventListener('click', function (e) {
        e.preventDefault();
        picker.querySelectorAll('input.vstag-picker-cb:checked').forEach(function (cb) { cb.checked = false; });
        picker.style.display = 'none';
        toggleBtn.textContent = '+ Seiten zuweisen';
        pickerStatus.textContent = '';
    });

    // Bulk-Assign API-Helper
    function bulkAssign(ids) {
        return fetch('/contao/venne-search/tag/bulk-assign', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({
                targetType: 'page',
                targetIds: ids,
                tagSlug: TAG_SLUG,
                createLabel: TAG_LABEL,
                createColor: TAG_COLOR,
            }),
        }).then(function (r) { return r.json(); });
    }

    // Auswahl anwenden (manuell per Apply-Button)
    if (pickerApply) pickerApply.addEventListener('click', function (e) {
        e.preventDefault();
        var ids = selectedPageIds();
        if (ids.length === 0) {
            pickerStatus.style.color = '#dc2626';
            pickerStatus.textContent = 'Mindestens eine Seite auswählen.';
            return;
        }
        pickerApply.disabled = true;
        pickerStatus.style.color = '#6b7280';
        pickerStatus.textContent = 'Weise zu …';
        bulkAssign(ids).then(function (d) {
            pickerApply.disabled = false;
            if (d.ok) {
                pickerStatus.style.color = '#10b981';
                pickerStatus.textContent = '✓ ' + d.assigned + ' Seiten zugewiesen, ' + d.reindexed + ' neu indexiert. Seite wird neu geladen…';
                setTimeout(function () { window.location.reload(); }, 900);
            } else {
                pickerStatus.style.color = '#dc2626';
                pickerStatus.textContent = '✗ ' + (d.error || 'unbekannter Fehler');
            }
        }).catch(function () {
            pickerApply.disabled = false;
            pickerStatus.style.color = '#dc2626';
            pickerStatus.textContent = '✗ Netzwerk-Fehler';
        });
    });

    // Auto-Apply beim Save: wenn der User Pages markiert UND auf Speichern
    // klickt ohne vorher Apply zu drücken, weisen wir die Pages trotzdem
    // automatisch zu. So kann der User einfach "auswählen → Speichern" denken.
    var tagForm = document.getElementById('tl_venne_search_tag');
    if (tagForm) {
        tagForm.addEventListener('submit', function (e) {
            // Nur wenn echtes Save geklickt wurde (nicht z.B. nur Form-validation-Trigger)
            var ids = selectedPageIds();
            if (ids.length === 0) return; // nichts zu tun, Form-Submit normal
            if (tagForm.dataset.vstagAssigned === '1') return; // schon erledigt
            // Submit blocken, erst zuweisen, dann Form erneut submitten.
            e.preventDefault();
            // Welcher Submit-Button wurde geklickt?
            var submitter = e.submitter;
            bulkAssign(ids).then(function (d) {
                if (d.ok) {
                    tagForm.dataset.vstagAssigned = '1';
                    if (submitter && submitter.name) {
                        // Einen versteckten Eintrag mit dem Button-Wert anhängen,
                        // damit Contao den richtigen Action-Mode bekommt
                        // (saveNclose vs. save vs. saveNcreate).
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = submitter.name;
                        hidden.value = submitter.value || '1';
                        tagForm.appendChild(hidden);
                    }
                    tagForm.submit();
                } else {
                    alert('Konnte Seiten nicht zuweisen: ' + (d.error || 'unbekannter Fehler'));
                }
            }).catch(function () {
                alert('Netzwerk-Fehler beim Zuweisen — versuch es nochmal.');
            });
        });
    }
})();
</script>
HTML;
    }

    /**
     * Picker-Zeile (für das Tag-Edit-Panel) — nur Title + Checkbox,
     * keine Tag-Chips. Schon zugewiesene Pages werden gelockt.
     *
     * @param array{id:int,title:string,type:string,children:list<array>} $node
     * @param array<int,int> $assignedSet
     */
    private function renderPickerNode(array $node, array $assignedSet, int $depth): string
    {
        $isRoot = $node['type'] === 'root';
        $hasKids = $node['children'] !== [];
        $icon = $isRoot ? self::iconRoot() : ($hasKids ? self::iconFolder() : self::iconPage());
        $titleHtml = htmlspecialchars($node['title'] ?: ('Seite #' . $node['id']));
        $isAssigned = isset($assignedSet[$node['id']]);
        $indent = $depth * 18;

        if ($isRoot) {
            $cb = '<span style="width:16px;display:inline-block;"></span>';
            $rowClass = 'vstag-picker-row vstag-picker-locked';
        } elseif ($isAssigned) {
            $cb = '<input type="checkbox" disabled aria-label="Bereits zugewiesen">';
            $rowClass = 'vstag-picker-row vstag-picker-locked';
        } else {
            $cb = sprintf('<input type="checkbox" class="vstag-picker-cb" data-id="%d">', $node['id']);
            $rowClass = 'vstag-picker-row';
        }

        $assignedBadge = $isAssigned ? '<span class="vstag-picker-assigned">bereits zugewiesen</span>' : '';

        $html = sprintf(
            '<div class="%s" style="padding-left:%dpx;">%s<span class="vstag-picker-icon">%s</span><span class="vstag-picker-title">%s</span>%s</div>',
            $rowClass, $indent, $cb, $icon, $titleHtml, $assignedBadge,
        );
        foreach ($node['children'] as $child) {
            $html .= $this->renderPickerNode($child, $assignedSet, $depth + 1);
        }
        return $html;
    }

    /**
     * Liefert pro Page-ID ein Pfad-Display "Root → Eltern → Page".
     *
     * @param list<int> $pageIds
     * @return array<int, array{title:string, breadcrumb:string}>
     */
    private function resolvePageBreadcrumbs(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, pid, title, alias FROM tl_page',
            );
        } catch (\Throwable) {
            return [];
        }
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = [
                'pid' => (int) $r['pid'],
                'title' => (string) ($r['title'] ?? ''),
                'alias' => (string) ($r['alias'] ?? ''),
            ];
        }

        $out = [];
        foreach ($pageIds as $pid) {
            if (!isset($byId[$pid])) {
                continue;
            }
            $chain = [];
            $current = $pid;
            $depth = 0;
            while ($current > 0 && $depth < 30) {
                if (!isset($byId[$current])) {
                    break;
                }
                $node = $byId[$current];
                $chain[] = $node['title'] !== '' ? $node['title'] : ('#' . $current);
                $current = $node['pid'];
                $depth++;
            }
            $chain = array_reverse($chain);
            $title = end($chain) ?: '';
            $bcrumb = \count($chain) > 1
                ? implode(' › ', \array_slice($chain, 0, -1))
                : '';
            $out[$pid] = [
                'title' => (string) $title,
                'breadcrumb' => $bcrumb,
            ];
        }
        return $out;
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

        // Contao 4.13/5.x: Backend-Links brauchen den Request-Token (rt-Parameter),
        // sonst bringt Contao eine "Ungültiges Token"-Bestätigungsseite.
        $manageUrl = $this->buildBackendUrl('do=venne_search&table=tl_venne_search_tag');

        return <<<HTML
<div style="margin:14px 18px;padding:18px 22px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <div>
            <strong style="color:#1f2937;font-size:1rem;">Seiten taggen</strong>
            <div style="font-size:.8rem;color:#6b7280;margin-top:4px;">
                Auf "+" klicken, um eine einzelne Seite zu taggen. Mehrere Seiten? Häkchen setzen → unten auswählen, was sie alle bekommen.
            </div>
        </div>
        <a href="{$manageUrl}" class="tl_submit" style="padding:6px 12px;text-decoration:none;display:inline-block;">Tags verwalten</a>
    </div>
    <div id="vsearch-tag-tree" style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:8px 12px;max-height:600px;overflow-y:auto;">
        {$treeHtml}
    </div>
    <div id="vsearch-tag-bulk" style="margin-top:12px;display:none;align-items:center;gap:10px;padding:10px 14px;background:#3a7178;color:#fff;border-radius:6px;flex-wrap:wrap;">
        <strong id="vsearch-tag-bulk-count">0 Seiten ausgewählt</strong>
        <span style="opacity:.85;">→ Tag zuweisen:</span>
        <input type="text" id="vsearch-tag-bulk-input" placeholder="Tag eingeben oder neu erstellen…" style="flex:1;min-width:200px;padding:5px 8px;border-radius:4px;border:0;color:#1f2937;">
        <button type="button" id="vsearch-tag-bulk-apply" class="tl_submit" style="padding:5px 14px;border:0;border-radius:4px;background:#fff;color:#3a7178;cursor:pointer;font-weight:600;">Anwenden</button>
        <button type="button" id="vsearch-tag-bulk-clear" style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,.4);padding:4px 10px;border-radius:4px;cursor:pointer;">Auswahl aufheben</button>
    </div>
    <div id="vsearch-tag-toast" style="position:fixed;bottom:20px;right:20px;padding:10px 16px;background:#10b981;color:#fff;border-radius:6px;display:none;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.15);"></div>
</div>
<style>
.vstag-row { display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f3f4f6; }
.vstag-select { cursor:pointer; }
.vstag-select:disabled { cursor:not-allowed;opacity:.4; }
.vstag-row:last-child { border-bottom:none; }
.vstag-label { flex:1;font-size:.9rem;color:#1f2937;display:flex;align-items:center;gap:8px; }
.vstag-icon { display:inline-flex;color:#6b7280;flex-shrink:0; }
.vstag-locked .vstag-icon { color:#94a3b8; }
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

    // === Multi-Select + Bulk-Apply ===
    var bulkBar = document.getElementById('vsearch-tag-bulk');
    var bulkCount = document.getElementById('vsearch-tag-bulk-count');
    var bulkInput = document.getElementById('vsearch-tag-bulk-input');
    var bulkApply = document.getElementById('vsearch-tag-bulk-apply');
    var bulkClear = document.getElementById('vsearch-tag-bulk-clear');

    function updateBulkBar() {
        var checked = tree.querySelectorAll('.vstag-select:checked');
        if (checked.length > 0) {
            bulkBar.style.display = 'flex';
            bulkCount.textContent = checked.length + (checked.length === 1 ? ' Seite ausgewählt' : ' Seiten ausgewählt');
        } else {
            bulkBar.style.display = 'none';
        }
    }
    tree.addEventListener('change', function (e) {
        if (e.target.classList.contains('vstag-select')) updateBulkBar();
    });
    bulkClear.addEventListener('click', function () {
        tree.querySelectorAll('.vstag-select:checked').forEach(function (cb) { cb.checked = false; });
        updateBulkBar();
    });
    bulkApply.addEventListener('click', function () {
        var label = bulkInput.value.trim();
        if (!label) {
            bulkInput.focus();
            return;
        }
        var ids = Array.from(tree.querySelectorAll('.vstag-select:checked')).map(function (cb) { return cb.dataset.id; });
        if (ids.length === 0) return;

        // Existing-Tag-Check (case-insensitive auf Label)
        var lower = label.toLowerCase();
        var existing = TAGS.find(function (t) { return t.label.toLowerCase() === lower; });
        var slug = existing ? existing.slug : '';
        var color = existing ? existing.color : 'blue';

        bulkApply.disabled = true;
        bulkApply.textContent = 'Wende an…';
        api('/contao/venne-search/tag/bulk-assign', {
            targetType: 'page',
            targetIds: ids,
            tagSlug: slug,
            createLabel: label,
            createColor: color,
        }).then(function (d) {
            if (d.ok) {
                showToast(d.assigned + ' Zuweisungen, ' + d.reindexed + ' Seiten neu indexiert');
                if (d.created && d.slug) TAGS.push({slug: d.slug, label: d.label || label, color: d.color || color});
                // Zeilen-DOM aktualisieren
                var resolvedSlug = (d.slug) || (existing && existing.slug);
                if (resolvedSlug) {
                    ids.forEach(function (pid) {
                        var row = tree.querySelector('.vstag-row[data-id="' + pid + '"]');
                        if (!row) return;
                        if (row.querySelector('.vstag-chip[data-slug="' + resolvedSlug + '"]')) return;
                        var chips = row.querySelector('.vstag-chips');
                        var addBtn = chips.querySelector('.vstag-add');
                        addBtn.insertAdjacentHTML('beforebegin', renderChip(resolvedSlug));
                    });
                }
                bulkInput.value = '';
                bulkClear.click();
            } else {
                showToast('Fehler: ' + (d.error || 'unbekannt'), true);
            }
        }).catch(function () {
            showToast('Netzwerk-Fehler', true);
        }).finally(function () {
            bulkApply.disabled = false;
            bulkApply.textContent = 'Anwenden';
        });
    });
    bulkInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            bulkApply.click();
        }
    });

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
            $editUrl = htmlspecialchars($this->buildBackendUrl(
                'do=venne_search&table=tl_venne_search_tag&act=edit&id=' . $tag['id']
            ));
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
        $hasKids = $node['children'] !== [];
        $icon = $isRoot ? self::iconRoot() : ($hasKids ? self::iconFolder() : self::iconPage());

        // Root-Pages bekommen keine Checkbox (nicht direkt indexierbar).
        $checkbox = $isRoot
            ? '<span style="width:16px;display:inline-block;"></span>'
            : sprintf('<input type="checkbox" class="vstag-select" data-id="%d" aria-label="Diese Seite auswählen">', $node['id']);

        $html = sprintf(
            '<div class="%s" data-type="page" data-id="%d" style="padding-left:%dpx;">'
            . '%s'
            . '<span class="vstag-label %s"><span class="vstag-icon">%s</span>%s</span>'
            . '<span class="vstag-chips">%s<button type="button" class="vstag-add">+ Tag</button></span>'
            . '</div>',
            $rowClass, $node['id'], $indent,
            $checkbox,
            $isRoot ? 'vstag-locked' : '',
            $icon, $titleHtml, $chipsHtml,
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

    /**
     * Baut einen Backend-Pfad mit Request-Token. Ohne den `rt`-Parameter
     * zeigt Contao eine "Ungültiges Token"-Bestätigungsseite.
     */
    private function buildBackendUrl(string $params): string
    {
        $token = '';
        try {
            $container = \Contao\System::getContainer();
            if ($container?->has('contao.csrf.token_manager')) {
                $manager = $container->get('contao.csrf.token_manager');
                $tokenName = $container->getParameter('contao.csrf_token_name') ?: 'contao_csrf_token';
                $token = (string) $manager->getToken($tokenName)->getValue();
            }
        } catch (\Throwable) {
        }
        $url = 'contao?' . $params;
        if ($token !== '') {
            $url .= '&rt=' . urlencode($token);
        }
        return $url;
    }

    private static function iconRoot(): string
    {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="10"/>'
            . '<line x1="2" y1="12" x2="22" y2="12"/>'
            . '<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'
            . '</svg>';
    }
    private static function iconFolder(): string
    {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'
            . '</svg>';
    }
    private static function iconPage(): string
    {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
            . '<polyline points="14 2 14 8 20 8"/>'
            . '<line x1="8" y1="13" x2="16" y2="13"/>'
            . '<line x1="8" y1="17" x2="13" y2="17"/>'
            . '</svg>';
    }
    private static function iconFile(): string
    {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M13 2H6a2 2 0 0 0-2 2v16c0 1.1.9 2 2 2h12a2 2 0 0 0 2-2V9z"/>'
            . '<polyline points="13 2 13 9 20 9"/>'
            . '</svg>';
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
