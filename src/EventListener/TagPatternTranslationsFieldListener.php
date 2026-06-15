<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Doctrine\DBAL\Connection;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Rendert pro aktive Sprache eine eigene URL-Pattern-Textarea fuer das
 * Auto-Match-Feld. Erlaubt sprachspezifische Patterns, z.B.
 *   de: *pressemitteilungen-detailseite*
 *   en: *press-release-detail*
 *
 * Speicherformat in der DB-Spalte: JSON-Map locale → patterns-string.
 * Beim Save wandelt das onsubmit-Hook POST['auto_match_pattern_translations']
 * in JSON um.
 */
final class TagPatternTranslationsFieldListener
{
    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
    ) {
    }

    public static function render(/** @phpstan-ignore-next-line */ $dc): string
    {
        try {
            $listener = \Contao\System::getContainer()?->get(self::class);
        } catch (\Throwable) {
            return '<p class="tl_help">Pattern-Uebersetzungs-Feld konnte nicht geladen werden.</p>';
        }
        return $listener?->renderField($dc) ?? '';
    }

    public function renderField(/** @phpstan-ignore-next-line */ $dc): string
    {
        $tagId = (int) ($dc->id ?? 0);
        $current = [];
        $defaultPattern = '';
        if ($tagId > 0) {
            try {
                $row = $this->db->fetchAssociative(
                    'SELECT auto_match_pattern, auto_match_pattern_translations FROM tl_venne_search_tag WHERE id = ?',
                    [$tagId],
                );
                if ($row !== false) {
                    $defaultPattern = (string) ($row['auto_match_pattern'] ?? '');
                    $raw = (string) ($row['auto_match_pattern_translations'] ?? '');
                    if ($raw !== '') {
                        $decoded = json_decode($raw, true);
                        if (\is_array($decoded)) {
                            foreach ($decoded as $k => $v) {
                                $current[strtolower((string) $k)] = (string) $v;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        $locales = ['de', 'en'];
        try {
            if ($this->settings->isConfigured()) {
                $config = $this->settings->load();
                if ($config->enabledLocales !== []) {
                    $locales = $config->enabledLocales;
                }
            }
        } catch (\Throwable) {
        }

        $localeNames = [
            'de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'it' => 'Italiano',
            'es' => 'Español', 'nl' => 'Nederlands', 'pt' => 'Português', 'pl' => 'Polski',
            'cs' => 'Čeština', 'tr' => 'Türkçe',
        ];

        $rows = '';
        foreach ($locales as $loc) {
            $loc = strtolower(substr((string) $loc, 0, 5));
            $val = (string) ($current[$loc] ?? '');
            $name = $localeNames[$loc] ?? strtoupper($loc);
            $placeholder = $defaultPattern !== '' ? $defaultPattern : '*meine-detailseite*';
            $rows .= '<div class="vsearch-translation-row" style="display:flex;align-items:flex-start;gap:.6rem;margin-bottom:.6rem;">'
                . '<label style="min-width:8.5rem;padding-top:.4rem;font-weight:600;color:#94a3b8;text-transform:none;letter-spacing:0;">'
                . '<span style="display:inline-block;min-width:2rem;padding:.15rem .4rem;background:rgba(255,255,255,0.08);border-radius:4px;font-family:monospace;font-size:.78rem;color:#cbd5e1;margin-right:.4rem;">'
                . htmlspecialchars($loc, ENT_QUOTES) . '</span>'
                . htmlspecialchars($name) . '</label>'
                . '<textarea name="auto_match_pattern_translations[' . htmlspecialchars($loc, ENT_QUOTES) . ']" '
                . 'rows="3" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES) . '" '
                . 'class="tl_textarea" style="flex:1;font-family:monospace;font-size:.85rem;">' . htmlspecialchars($val) . '</textarea>'
                . '</div>';
        }

        return '<div class="vsearch-translations-wrap" style="margin-bottom:.6rem;">'
            . '<p class="tl_help tl_tip" style="margin-bottom:.8rem;">'
            . 'Eine URL-Pattern-Liste pro Sprache (eine Zeile pro Pattern). Wenn ein Sprach-Feld leer ist, faellt der Auto-Match auf das Standard-Pattern oben zurueck.<br>'
            . 'Beispiel — Deutsch: <code>*pressemitteilungen-detailseite*</code>, Englisch: <code>*press-release-detail*</code>'
            . '</p>'
            . $rows
            . '</div>';
    }
}
