<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Nur-Lese-Schalter für Staging-/Testkopien einer Installation.
 *
 * Steht in der Umgebung VENNE_SEARCH_READ_ONLY=1 (z. B. in .env.local der Staging-Kopie),
 * führt das Bundle keinerlei Schreiboperationen auf dem Meilisearch-Index aus (kein Upsert,
 * kein Delete, kein Purge, keine Synonym-/Settings-Updates) und sendet keine Analytics.
 * Suchanfragen funktionieren weiterhin – so lässt sich die Suche einer Kopie gegen den
 * produktiven Index testen, ohne ihn beschädigen oder mit Staging-URLs überschreiben zu können.
 */
final class ReadOnlyGuard
{
    public const ENV = 'VENNE_SEARCH_READ_ONLY';

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    public function isReadOnly(): bool
    {
        $value = $_SERVER[self::ENV] ?? $_ENV[self::ENV] ?? getenv(self::ENV);

        if ($value === false || $value === null) {
            return false;
        }

        return \in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * true = Schreibzugriff ist gesperrt (und wurde protokolliert), false = darf schreiben.
     */
    public function blocks(string $operation): bool
    {
        if (!$this->isReadOnly()) {
            return false;
        }

        $this->logger->warning('venne_search.read_only.blocked', ['operation' => $operation]);

        return true;
    }
}
