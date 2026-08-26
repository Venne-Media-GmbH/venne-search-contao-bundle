<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service\Page;

use Doctrine\DBAL\Connection;

/**
 * Entscheidet anhand der Seiten-HIERARCHIE, ob eine Page in den Suchindex
 * darf. Contao selbst prüft `noSearch` nur auf der Seite selbst — für die
 * Site-Betreiber ist das Lupen-Icon aber „dieser Zweig wird nicht
 * durchsucht" (Ticket FFA: Root „(ALT)" auf noSearch, 106 Unterseiten
 * trotzdem im Index, „Richtlinien" tauchte aus dem alten Baum auf).
 *
 * Regeln (erste die greift gewinnt, von der Seite nach oben gewandert):
 *   1. Seite selbst noSearch=1                → REASON_OWN_FLAG
 *   2. Irgendein Vorfahre noSearch=1          → REASON_INHERITED
 *   3. Root-Seite nicht veröffentlicht         → REASON_ROOT_UNPUBLISHED
 *      (Contaos PublishedFilter: !rootIsPublic → 404 für den ganzen Baum;
 *       Zwischenebenen zählen dort NICHT, deshalb auch hier nicht.)
 *
 * Der komplette Baum wird einmal pro Instanz geladen (id → pid/flags) —
 * ein Query statt einem pro Ebene pro Seite. Reicht locker für Sites mit
 * einigen tausend Seiten und macht das Icon in der Seitenstruktur billig.
 */
final class PageSearchabilityResolver
{
    public const REASON_OWN_FLAG = 'page_no_search_flag';
    public const REASON_INHERITED = 'page_no_search_inherited';
    public const REASON_ROOT_UNPUBLISHED = 'page_root_unpublished';

    /** Schutz gegen Zyklen in pid-Ketten (kaputte Daten). */
    private const MAX_DEPTH = 50;

    /** @var array<int, array{pid:int,type:string,title:string,noSearch:bool,published:bool}>|null */
    private ?array $tree = null;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @return array{reason:?string, sourceId:?int, sourceTitle:string}
     */
    public function resolve(int $pageId): array
    {
        return self::resolveFromTree($this->tree(), $pageId);
    }

    public function excludedReason(int $pageId): ?string
    {
        return $this->resolve($pageId)['reason'];
    }

    /**
     * Alle Nachfahren (rekursiv, ohne die Seite selbst) — für Toggle/Delete
     * ganzer Teilbäume.
     *
     * @return list<int>
     */
    public function descendantIds(int $pageId): array
    {
        return self::descendantIdsFromTree($this->tree(), $pageId);
    }

    /**
     * Baum-Cache verwerfen — nötig, wenn im selben Request tl_page geändert
     * wurde (Lupen-Toggle) und danach neu entschieden werden muss.
     */
    public function reset(): void
    {
        $this->tree = null;
    }

    /**
     * Reine Baum-Logik ohne DB — separat, damit sie unit-testbar ist.
     *
     * @param array<int, array{pid:int,type:string,title:string,noSearch:bool,published:bool}> $tree
     *
     * @return array{reason:?string, sourceId:?int, sourceTitle:string}
     */
    public static function resolveFromTree(array $tree, int $pageId): array
    {
        $none = ['reason' => null, 'sourceId' => null, 'sourceTitle' => ''];
        if (!isset($tree[$pageId])) {
            return $none;
        }

        $self = $tree[$pageId];
        if ($self['noSearch']) {
            return ['reason' => self::REASON_OWN_FLAG, 'sourceId' => $pageId, 'sourceTitle' => $self['title']];
        }
        if ($self['type'] === 'root') {
            return $self['published']
                ? $none
                : ['reason' => self::REASON_ROOT_UNPUBLISHED, 'sourceId' => $pageId, 'sourceTitle' => $self['title']];
        }

        $current = $self['pid'];
        $seen = [$pageId => true];
        for ($depth = 0; $depth < self::MAX_DEPTH && $current > 0 && !isset($seen[$current]); $depth++) {
            $seen[$current] = true;
            $node = $tree[$current] ?? null;
            if ($node === null) {
                break;
            }
            if ($node['noSearch']) {
                return ['reason' => self::REASON_INHERITED, 'sourceId' => $current, 'sourceTitle' => $node['title']];
            }
            if ($node['type'] === 'root') {
                if (!$node['published']) {
                    return ['reason' => self::REASON_ROOT_UNPUBLISHED, 'sourceId' => $current, 'sourceTitle' => $node['title']];
                }
                break;
            }
            $current = $node['pid'];
        }

        return $none;
    }

    /**
     * @param array<int, array{pid:int,type:string,title:string,noSearch:bool,published:bool}> $tree
     *
     * @return list<int>
     */
    public static function descendantIdsFromTree(array $tree, int $pageId): array
    {
        $children = [];
        foreach ($tree as $id => $node) {
            $children[$node['pid']][] = (int) $id;
        }

        $result = [];
        $frontier = [$pageId];
        $seen = [$pageId => true];
        while ($frontier !== []) {
            $next = [];
            foreach ($frontier as $pid) {
                foreach ($children[$pid] ?? [] as $childId) {
                    if (isset($seen[$childId])) {
                        continue;
                    }
                    $seen[$childId] = true;
                    $result[] = $childId;
                    $next[] = $childId;
                }
            }
            $frontier = $next;
        }

        return $result;
    }

    /**
     * @return array<int, array{pid:int,type:string,title:string,noSearch:bool,published:bool}>
     */
    private function tree(): array
    {
        if ($this->tree !== null) {
            return $this->tree;
        }

        // noSearch gibt es nur in Contao 4.13 — in 5.x ist die Spalte weg.
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, pid, type, title, published, start, stop, noSearch FROM tl_page'
            );
        } catch (\Throwable) {
            try {
                $rows = $this->db->fetchAllAssociative(
                    'SELECT id, pid, type, title, published, start, stop FROM tl_page'
                );
            } catch (\Throwable) {
                $rows = [];
            }
        }

        $now = time();
        $tree = [];
        foreach ($rows as $row) {
            $start = (int) ($row['start'] ?? 0);
            $stop = (int) ($row['stop'] ?? 0);
            $published = (string) ($row['published'] ?? '') === '1'
                && ($start === 0 || $start <= $now)
                && ($stop === 0 || $stop > $now);
            $tree[(int) $row['id']] = [
                'pid' => (int) ($row['pid'] ?? 0),
                'type' => (string) ($row['type'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'noSearch' => (string) ($row['noSearch'] ?? '') === '1',
                'published' => $published,
            ];
        }

        return $this->tree = $tree;
    }
}
