<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VenneMedia\VenneSearchContaoBundle\Migration\Version200\Mig02_AddTagSystem;

final class SlugifyTest extends TestCase
{
    /**
     * @dataProvider provideSlugCases
     */
    public function testSlugify(string $input, string $expected): void
    {
        self::assertSame($expected, Mig02_AddTagSystem::slugify($input));
    }

    /** @return iterable<array{string,string}> */
    public static function provideSlugCases(): iterable
    {
        yield ['Spongebob', 'spongebob'];
        yield ['Krabbenburger XXL', 'krabbenburger-xxl'];
        yield ['Über uns', 'ueber-uns'];
        yield ['Café', 'cafe'];
        yield ['  Whitespace  ', 'whitespace'];
        yield ['UPPERCASE', 'uppercase'];
        yield ['multi---dash', 'multi-dash'];
        yield ['Maße & Gewichte', 'masse-gewichte'];
        yield ['', ''];
    }

    public function testSlugifyTruncatesLongValues(): void
    {
        $long = str_repeat('a', 200);
        $slug = Mig02_AddTagSystem::slugify($long);
        self::assertLessThanOrEqual(64, mb_strlen($slug));
    }
}
