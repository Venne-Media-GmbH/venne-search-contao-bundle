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
 * Erst-Setup-Verifikation.
 *
 *   php bin/console venne-search:setup
 *
 * Prüft Verbindung zu Meilisearch, legt für jede aktive Locale einen Index an,
 * konfiguriert Ranking-Rules + Filterable/Sortable-Attribute.
 */
#[AsCommand(name: 'venne-search:setup', description: 'Verbindung zu Meilisearch testen und Indexes anlegen')]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly DocumentIndexer $indexer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->settings->load();

        if ($config->endpoint === '' || $config->apiKey === '') {
            $io->error('Endpoint und API-Key müssen erst im Backend (System → Venne Search → Einstellungen) gepflegt werden.');

            return Command::FAILURE;
        }

        $io->section('Konfiguration');
        $io->definitionList(
            ['Endpoint' => $config->endpoint],
            ['Index-Prefix' => $config->indexPrefix],
            ['Aktive Sprachen' => implode(', ', $config->enabledLocales)],
            ['PDF-Index' => $config->indexPdfs ? 'an' : 'aus'],
            ['Max-Datei-Größe' => $config->maxFileSizeMb.' MB'],
        );

        foreach ($config->enabledLocales as $locale) {
            try {
                $this->indexer->ensureIndex($locale);
                $io->success(\sprintf('Index "%s_%s" einsatzbereit.', $config->indexPrefix, $locale));
            } catch (\Throwable $e) {
                $io->error(\sprintf('Index "%s_%s" konnte nicht angelegt werden: %s', $config->indexPrefix, $locale, $e->getMessage()));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
