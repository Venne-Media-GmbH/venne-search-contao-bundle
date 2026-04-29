<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service;

use Meilisearch\Client;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;
use VenneMedia\VenneSearchContaoBundle\Service\Platform\ResolveException;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Erzeugt den Meilisearch-Client aus den vom Resolve-Endpoint gelieferten
 * Werten.
 *
 * Wichtig: create() darf NIEMALS werfen — sonst killt das den Container-
 * Build und das ganze Backend zeigt 500. Bei Resolve-Fehler liefern wir
 * einen Stub-Client mit leeren Werten zurück; das Backend kann dann
 * trotzdem die Edit-Form rendern, der User kann den Key korrigieren.
 * Erst wenn etwas tatsächlich versucht wird zu suchen/indizieren, schlägt
 * der Stub fehl — und das fangen die einzelnen Stellen ab.
 */
final class MeilisearchClientFactory
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly bool $verifyTls = true,
    ) {
    }

    public function create(): Client
    {
        $http = HttpClient::create([
            'verify_peer' => $this->verifyTls,
            'verify_host' => $this->verifyTls,
            'timeout' => 10,
        ]);
        $psr18 = new SymfonyPsr18Client($http);

        try {
            $config = $this->settings->load();
        } catch (ResolveException) {
            // Resolve-Fehler (Key ungültig, Plattform offline, etc.) —
            // Stub-Client zurückgeben damit der Container weiterbauen kann.
            // Der Key BLEIBT in der DB. Status-Panel zeigt grün/rot ob er
            // gerade gültig ist. User kann ihn jederzeit selbst überschreiben.
            return new Client('https://invalid.local', 'invalid', $psr18);
        }

        if ($config->endpoint === '' || $config->apiKey === '') {
            // Bundle nicht konfiguriert — selber Stub-Pfad.
            return new Client('https://invalid.local', 'invalid', $psr18);
        }

        return new Client($config->endpoint, $config->apiKey, $psr18);
    }
}
