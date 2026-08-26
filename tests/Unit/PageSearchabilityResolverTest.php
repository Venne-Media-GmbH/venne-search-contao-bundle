<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VenneMedia\VenneSearchContaoBundle\Service\Page\PageSearchabilityResolver;

/**
 * Ticket FFA: Root „FFA Filmförderungsanstalt (ALT)" steht auf noSearch=1
 * (und ist unveröffentlicht), die 106 Unterseiten aber nicht — „Richtlinien"
 * aus dem alten Baum tauchte in der Suche auf. noSearch muss über die
 * Hierarchie vererbt werden; eine unveröffentlichte Root sperrt (wie in
 * Contaos PublishedFilter) den ganzen Baum.
 */
final class PageSearchabilityResolverTest extends TestCase
{
    /**
     * Baum:
     *   1 root "Live"            (published)
     *   ├─ 10 "Die FFA"
     *   │   └─ 11 "Richtlinien"
     *   ├─ 12 "Intern"           (noSearch)
     *   │   └─ 13 "Intern-Kind"
     *   │       └─ 14 "Intern-Enkel"
     *   └─ 15 "Eigenes Flag"     (noSearch)
     *   2 root "(ALT)"           (noSearch + unpublished)
     *   └─ 20 "ALT Das FFG"
     *       └─ 21 "ALT Richtlinien"
     *   3 root "Nur unpublished" (unpublished)
     *   └─ 30 "Kind von unpublished Root"
     *
     * @return array<int, array{pid:int,type:string,title:string,noSearch:bool,published:bool}>
     */
    private static function tree(): array
    {
        $n = static fn (int $pid, string $type, string $title, bool $noSearch = false, bool $published = true): array =>
            ['pid' => $pid, 'type' => $type, 'title' => $title, 'noSearch' => $noSearch, 'published' => $published];

        return [
            1 => $n(0, 'root', 'Live'),
            10 => $n(1, 'regular', 'Die FFA'),
            11 => $n(10, 'regular', 'Richtlinien'),
            12 => $n(1, 'regular', 'Intern', true),
            13 => $n(12, 'regular', 'Intern-Kind'),
            14 => $n(13, 'regular', 'Intern-Enkel'),
            15 => $n(1, 'regular', 'Eigenes Flag', true),
            2 => $n(0, 'root', '(ALT)', true, false),
            20 => $n(2, 'regular', 'ALT Das FFG'),
            21 => $n(20, 'regular', 'ALT Richtlinien'),
            3 => $n(0, 'root', 'Nur unpublished', false, false),
            30 => $n(3, 'regular', 'Kind von unpublished Root'),
        ];
    }

    public function testNormalPageIsSearchable(): void
    {
        $r = PageSearchabilityResolver::resolveFromTree(self::tree(), 11);
        self::assertNull($r['reason']);
        self::assertNull($r['sourceId']);
    }

    public function testOwnNoSearchFlagWins(): void
    {
        $r = PageSearchabilityResolver::resolveFromTree(self::tree(), 15);
        self::assertSame(PageSearchabilityResolver::REASON_OWN_FLAG, $r['reason']);
        self::assertSame(15, $r['sourceId']);
    }

    public function testNoSearchIsInheritedFromParent(): void
    {
        $r = PageSearchabilityResolver::resolveFromTree(self::tree(), 13);
        self::assertSame(PageSearchabilityResolver::REASON_INHERITED, $r['reason']);
        self::assertSame(12, $r['sourceId']);
        self::assertSame('Intern', $r['sourceTitle']);
    }

    public function testNoSearchIsInheritedOverMultipleLevels(): void
    {
        $r = PageSearchabilityResolver::resolveFromTree(self::tree(), 14);
        self::assertSame(PageSearchabilityResolver::REASON_INHERITED, $r['reason']);
        self::assertSame(12, $r['sourceId']);
    }

    public function testNoSearchOnRootExcludesWholeTree(): void
    {
        // Das FFA-Szenario: Root „(ALT)" noSearch=1, Unterseite „Richtlinien" ohne Flag.
        $r = PageSearchabilityResolver::resolveFromTree(self::tree(), 21);
        self::assertSame(PageSearchabilityResolver::REASON_INHERITED, $r['reason']);
        self::assertSame(2, $r['sourceId']);
        self::assertSame('(ALT)', $r['sourceTitle']);
    }

    public function testUnpublishedRootExcludesWholeTree(): void
    {
        $r = PageSearchabilityResolver::resolveFromTree(self::tree(), 30);
        self::assertSame(PageSearchabilityResolver::REASON_ROOT_UNPUBLISHED, $r['reason']);
        self::assertSame(3, $r['sourceId']);
    }

    public function testUnknownPageIsNotBlocked(): void
    {
        // Unbekannte ID → keine Aussage; die Aufrufer prüfen selbst weiter.
        $r = PageSearchabilityResolver::resolveFromTree(self::tree(), 999);
        self::assertNull($r['reason']);
    }

    public function testCycleInTreeDoesNotLoopForever(): void
    {
        $tree = [
            40 => ['pid' => 41, 'type' => 'regular', 'title' => 'A', 'noSearch' => false, 'published' => true],
            41 => ['pid' => 40, 'type' => 'regular', 'title' => 'B', 'noSearch' => false, 'published' => true],
        ];
        $r = PageSearchabilityResolver::resolveFromTree($tree, 40);
        self::assertNull($r['reason']);
    }

    public function testDescendantIdsCollectsWholeSubtree(): void
    {
        $ids = PageSearchabilityResolver::descendantIdsFromTree(self::tree(), 12);
        sort($ids);
        self::assertSame([13, 14], $ids);

        $ids = PageSearchabilityResolver::descendantIdsFromTree(self::tree(), 2);
        sort($ids);
        self::assertSame([20, 21], $ids);

        self::assertSame([], PageSearchabilityResolver::descendantIdsFromTree(self::tree(), 11));
    }
}
