<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\DocumentIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Pusht die DB-Synonyme in alle aktiven Meilisearch-Indexes — manuelles
 * Sync, falls automatisches Push beim Save mal aussetzt.
 *
 *   php bin/console venne-search:sync-synonyms
 */
#[AsCommand(name: 'venne-search:sync-synonyms', description: 'Synonyme aus DB an Meilisearch pushen')]
final class SyncSynonymsCommand extends Command
{
    public function __construct(
        private readonly DocumentIndexer $indexer,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->settings->isConfigured()) {
            $io->error('Bundle nicht konfiguriert (kein API-Key).');
            return Command::FAILURE;
        }
        $config = $this->settings->load();
        foreach ($config->enabledLocales as $locale) {
            $this->indexer->pushSynonyms($locale);
            $io->writeln(\sprintf('Synonyme fuer Locale „%s" gepusht.', $locale));
        }
        $io->success('Done.');
        return Command::SUCCESS;
    }
}
