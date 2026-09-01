<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verhindert Template-Drift: Modul + Inhaltselement müssen IDENTISCH bleiben,
 * sonst sieht ein User je nach Einbindungs-Modus unterschiedliches Layout.
 *
 * Gab's mal als Bug: ce_venne_search blieb auf v0.23 stehen während mod_*
 * schon auf v2.2.0 war — composer update hat das ce_-Template zwar mit-
 * deployt, aber inhaltlich war es Monate veraltet, weil UX-Changes nur
 * in mod_ gepflegt wurden.
 */
final class TemplateSyncTest extends TestCase
{
    public function testModuleAndContentElementTemplatesAreIdentical(): void
    {
        $base = \dirname(__DIR__, 2) . '/Resources/contao/templates';
        $modPath = $base . '/mod_venne_search.html5';
        $cePath  = $base . '/ce_venne_search.html5';

        self::assertFileExists($modPath, 'mod_venne_search.html5 fehlt im Bundle');
        self::assertFileExists($cePath, 'ce_venne_search.html5 fehlt im Bundle');

        $modHash = md5_file($modPath);
        $ceHash  = md5_file($cePath);

        self::assertSame(
            $modHash,
            $ceHash,
            'mod_venne_search.html5 und ce_venne_search.html5 sind nicht mehr identisch. '
            . 'User die das Bundle als Inhaltselement einbinden sehen ein anderes Layout '
            . 'als User die es als Modul einbinden. Beide Templates immer 1:1 synchron halten — '
            . 'cp Resources/contao/templates/mod_venne_search.html5 Resources/contao/templates/ce_venne_search.html5',
        );
    }
}
