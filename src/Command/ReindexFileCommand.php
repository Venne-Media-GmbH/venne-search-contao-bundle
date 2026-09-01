<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\IndexableItemProcessor;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Reindexiert eine einzelne Datei. Praktisch fuer Smoke-Tests nach Tag-
 * Zuweisung von der CLI oder nach Asset-Migration.
 *
 *   php bin/console venne-search:reindex-file "files/foo/bar.pdf"
 */
#[AsCommand(name: 'venne-search:reindex-file', description: 'Reindexiert eine einzelne Datei')]
final class ReindexFileCommand extends Command
{
    public function __construct(
        private readonly IndexableItemProcessor $processor,
        private readonly SettingsRepository $settings,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Relativer Pfad ab Projekt-Root (z.B. "files/foo/bar.pdf")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->settings->isConfigured()) {
            $io->error('Bundle nicht konfiguriert (kein API-Key).');
            return Command::FAILURE;
        }
        $path = trim((string) $input->getArgument('path'));
        if ($path === '') {
            $io->error('Pfad-Argument fehlt.');
            return Command::FAILURE;
        }
        $config = $this->settings->load();
        $result = $this->processor->processItem(
            ['type' => 'file', 'ref' => $path, 'docId' => 'file-path-' . md5($path)],
            $config,
            $this->projectDir,
        );
        $io->success(\sprintf('Datei reindexiert: %s', $path));
        $io->writeln('Result: ' . json_encode($result));
        return Command::SUCCESS;
    }
}
