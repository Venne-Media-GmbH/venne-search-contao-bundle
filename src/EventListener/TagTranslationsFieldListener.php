<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Doctrine\DBAL\Connection;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * input_field_callback fuer tl_venne_search_tag.translations:
 * rendert pro aktivierter Sprache ein eigenes Text-Input statt einer rohen
 * Textarea. Speicherwert bleibt JSON-Map {locale: label}.
 */
final class TagTranslationsFieldListener
{
    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Statischer Wrapper, weil DCA-input_field_callback ohne DI instanziiert.
     * Holt den Service via Container und ruft die Instanz-Methode.
     */
    public static function render(/** @phpstan-ignore-next-line */ $dc): string
    {
        try {
            $listener = \Contao\System::getContainer()?->get(self::class);
        } catch (\Throwable) {
            return '<p class="tl_help">Uebersetzungs-Feld konnte nicht geladen werden.</p>';
        }
        return $listener?->renderField($dc) ?? '';
    }

    public function renderField(/** @phpstan-ignore-next-line */ $dc): string
    {
        $tagId = (int) ($dc->id ?? 0);
        $current = [];
        if ($tagId > 0) {
            try {
                $raw = (string) ($this->db->fetchOne(
                    'SELECT translations FROM tl_venne_search_tag WHERE id = ?',
                    [$tagId],
                ) ?: '');
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (\is_array($decoded)) {
                        foreach ($decoded as $k => $v) {
                            $current[strtolower((string) $k)] = (string) $v;
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

        $defaultLabel = '';
        try {
            $defaultLabel = (string) ($this->db->fetchOne(
                'SELECT label FROM tl_venne_search_tag WHERE id = ?',
                [$tagId],
            ) ?: '');
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
            $placeholder = $defaultLabel !== '' ? $defaultLabel : '';
            $rows .= '<div class="vsearch-translation-row" style="display:flex;align-items:center;gap:.6rem;margin-bottom:.4rem;">'
                . '<label style="min-width:8.5rem;font-weight:600;color:#94a3b8;text-transform:none;letter-spacing:0;">'
                . '<span style="display:inline-block;min-width:2rem;padding:.15rem .4rem;background:rgba(255,255,255,0.08);border-radius:4px;font-family:monospace;font-size:.78rem;color:#cbd5e1;margin-right:.4rem;">'
                . htmlspecialchars($loc, ENT_QUOTES) . '</span>'
                . htmlspecialchars($name) . '</label>'
                . '<input type="text" name="translations[' . htmlspecialchars($loc, ENT_QUOTES) . ']" '
                . 'value="' . htmlspecialchars($val, ENT_QUOTES) . '" '
                . 'placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES) . '" '
                . 'class="tl_text" style="flex:1;" />'
                . '</div>';
        }

        return '<div class="vsearch-translations-wrap" style="margin:.8rem 1rem 1.2rem;padding:0;box-sizing:border-box;">'
            . '<p class="tl_help tl_tip" style="margin:0 0 1rem;">'
            . 'Pro aktive Sprache eine Übersetzung. Felder leer lassen, wenn die Standard-Bezeichnung verwendet werden soll.'
            . '</p>'
            . $rows
            . '</div>';
    }
}
