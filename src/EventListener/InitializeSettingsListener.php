<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Doctrine\DBAL\Connection;

/**
 * Stellt sicher, dass beim ersten Backend-Aufruf von „Venne Search"
 * IMMER eine Settings-Row mit id=1 existiert. Das macht Contao's
 * "closed + notCreatable" DCA möglich, ohne dass der User auf eine leere
 * Liste schaut und nicht weiß, was zu tun ist.
 */
#[AsHook('initializeSystem')]
final class InitializeSettingsListener
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function __invoke(): void
    {
        // Nur im Backend-Kontext und nur wenn die Tabelle existiert.
        if (!isset($_GET['do']) || $_GET['do'] !== 'venne_search') {
            return;
        }

        try {
            $exists = (bool) $this->db->fetchOne(
                'SELECT 1 FROM tl_venne_search_settings WHERE id = 1'
            );
        } catch (\Throwable) {
            // Tabelle existiert noch nicht (Migration nicht gelaufen) — schweigen.
            return;
        }

        if ($exists) {
            return;
        }

        $this->db->insert('tl_venne_search_settings', [
            'id' => 1,
            'tstamp' => time(),
            'api_key' => '',
            'enabled_locales' => 'de',
            'endpoint' => '',
            'index_prefix' => '',
            'platform_url' => '',
            'index_pdfs' => '1',
            'max_file_size_mb' => 25,
        ]);
    }
}
