<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Venne Search Contao Bundle.
 *
 * Hochperformante Volltextsuche für Contao 5 — durchsucht Pages, Articles,
 * Content-Elemente und Dateien (inkl. PDF-Textebene). Live-Indexing über
 * Contao-DCA-Hooks, Tippfehler-Toleranz, Diakritik-Folding (`café` ↔ `cafe`),
 * mehrsprachiger Tokenizer.
 *
 * Backend: System → Venne Search (Settings + globale Backend-Suche).
 * Frontend: ModuleVenneSearch (Search-Form mit AJAX-Live-Vorschau).
 */
final class VenneSearchContaoBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
