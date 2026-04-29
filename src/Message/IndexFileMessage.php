<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Message;

/**
 * Async-Job: indexiere eine Datei (PDF, DOCX, etc.) anhand ihres Pfads
 * relativ zur Contao-files/-Wurzel.
 */
final class IndexFileMessage
{
    public function __construct(
        public readonly string $relativePath,
    ) {
    }
}
