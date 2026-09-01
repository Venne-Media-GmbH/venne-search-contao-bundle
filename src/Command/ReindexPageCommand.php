<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\LivePageIndexer;

/**
 * Reindexiert eine einzelne Page direkt vom CLI — praktisch wenn man eine
 * spezifische Seite nach einem Content- oder Indexer-Fix neu in den
 * Suchindex schieben will, ohne den kompletten Reindex-Stream zu starten.
 *
 *   php bin/console venne-search:reindex-page 248
 */
#[AsCommand(name: 'venne-search:reindex-page', description: 'Reindexiert eine einzelne Contao-Page')]
final class ReindexPageCommand extends Command
{
    public function __construct(
        private readonly LivePageIndexer $indexer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pageId', InputArgument::REQUIRED, 'tl_page.id der zu reindexierenden Seite');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageId = (int) $input->getArgument('pageId');
        if ($pageId <= 0) {
            $io->error('pageId muss positive Ganzzahl sein.');

            return Command::FAILURE;
        }

        $this->indexer->indexPage($pageId);
        $io->success(\sprintf('Page %d reindexiert.', $pageId));

        return Command::SUCCESS;
    }
}
