<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\MessageHandler;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use VenneMedia\VenneSearchContaoBundle\Message\IndexFileMessage;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\DocumentIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\SearchDocument;
use VenneMedia\VenneSearchContaoBundle\Service\Pdf\PdfExtractor;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;
use VenneMedia\VenneSearchContaoBundle\Service\Text\TextNormalizer;

/**
 * Indexiert eine Datei (Pfad relativ zur Contao-files/-Wurzel).
 *
 * - PDF: PdfExtractor zieht Textebene
 * - TXT/MD: file_get_contents direkt
 * - DOCX/RTF/ODT: Phase-2-Erweiterung (mit phpoffice/phpword)
 *
 * Async via Symfony-Messenger, weil große PDFs mehrere Sekunden brauchen
 * und das Backend-UI nicht blockieren soll.
 */
#[AsMessageHandler]
final class IndexFileHandler
{
    private const PROJECT_DIR_FALLBACK = '/var/www/contao';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly DocumentIndexer $indexer,
        private readonly PdfExtractor $pdfExtractor,
        private readonly TextNormalizer $normalizer,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(IndexFileMessage $msg): void
    {
        $this->framework->initialize();

        $relativePath = ltrim($msg->relativePath, '/');
        $absolutePath = $this->resolveAbsolutePath($relativePath);

        if (!is_file($absolutePath)) {
            $this->logger->warning('venne_search.indexer.file_missing', ['path' => $absolutePath]);

            return;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $config = $this->settings->load();
        $rawText = $this->extractText($absolutePath, $extension, $config->maxFileSizeMb);

        if ($rawText === '') {
            $this->logger->info('venne_search.indexer.file_skipped', [
                'path' => $relativePath,
                'reason' => 'no_text_extracted',
            ]);

            return;
        }

        $fileModel = FilesModel::findByPath($relativePath);
        $documentId = $fileModel !== null && $fileModel->uuid !== null
            ? \sprintf('file-%s', bin2hex($fileModel->uuid))
            : \sprintf('file-%s', md5($relativePath));

        $title = pathinfo($relativePath, PATHINFO_FILENAME);

        $doc = new SearchDocument(
            id: $documentId,
            type: 'file',
            locale: $config->enabledLocales[0] ?? 'de',
            title: $this->humanizeFilename($title),
            url: '/'.$relativePath,
            content: $this->normalizer->normalize($rawText),
            tags: [$extension],
            publishedAt: $fileModel !== null ? (int) $fileModel->tstamp : null,
            weight: 0.8, // Datei-Treffer leicht unter Page-Treffern
        );

        $this->indexer->upsert($doc);

        $this->logger->info('venne_search.indexer.file_indexed', [
            'path' => $relativePath,
            'document_id' => $documentId,
            'text_chars' => mb_strlen($rawText),
        ]);
    }

    private function extractText(string $absolutePath, string $extension, int $maxFileSizeMb): string
    {
        return match ($extension) {
            'pdf' => $this->pdfExtractor->extract($absolutePath, $maxFileSizeMb)->text,
            'txt', 'md' => (string) file_get_contents($absolutePath),
            // DOCX/RTF/ODT folgen in Phase 2 (phpoffice/phpword).
            default => '',
        };
    }

    private function resolveAbsolutePath(string $relativePath): string
    {
        // Symfony-Container hat das normalerweise injiziert; als Notfall
        // versuchen wir's via $_SERVER oder Konstante.
        $projectDir = $_SERVER['SYMFONY_PROJECT_DIR'] ?? null;
        if ($projectDir === null && \defined('TL_ROOT')) {
            $projectDir = \TL_ROOT;
        }
        $projectDir ??= self::PROJECT_DIR_FALLBACK;

        return rtrim((string) $projectDir, '/').'/'.$relativePath;
    }

    private function humanizeFilename(string $filename): string
    {
        // "kunden-flyer_2024-final" → "Kunden Flyer 2024 Final"
        $cleaned = preg_replace('/[-_]+/', ' ', $filename) ?? $filename;
        $cleaned = trim($cleaned);

        return ucwords(mb_strtolower($cleaned));
    }
}
