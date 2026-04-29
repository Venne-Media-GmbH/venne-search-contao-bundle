<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Message;

/**
 * Async-Job: indexiere einen einzelnen Page/Article/Content-Datensatz.
 *
 * Wird vom PageSaveListener dispatched, vom IndexPageHandler bearbeitet.
 */
final class IndexPageMessage
{
    public function __construct(
        public readonly string $table,
        public readonly int $id,
    ) {
    }
}
