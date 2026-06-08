<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\DocumentIndexer;
use VenneMedia\VenneSearchContaoBundle\Service\Indexer\ReindexCatalog;
use VenneMedia\VenneSearchContaoBundle\Service\Settings\SettingsRepository;

/**
 * Reproduziert den Plan-Build vom Backend für CLI-Debugging.
 *
 *   php bin/console venne-search:test-plan
 */
#[AsCommand(name: 'venne-search:test-plan', description: 'Plan-Vorschau CLI-Test (Debug)')]
final class TestPlanCommand extends Command
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ReindexCatalog $catalog,
        private readonly DocumentIndexer $indexer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('=== START ===');

        try {
            $output->writeln('isConfigured: ' . ($this->settings->isConfigured() ? 'YES' : 'NO'));
            $config = $this->settings->load();
            $output->writeln("Settings: endpoint={$config->endpoint} indexPrefix={$config->indexPrefix} locales=" . implode(',', $config->enabledLocales));
        } catch (\Throwable $e) {
            $output->writeln('FAIL load(): ' . $e::class . ' — ' . $e->getMessage());
            $output->writeln('File: ' . $e->getFile() . ':' . $e->getLine());
            return Command::FAILURE;
        }

        foreach ($config->enabledLocales as $loc) {
            $t = microtime(true);
            try {
                $this->indexer->ensureIndex($loc);
                $output->writeln("ensureIndex($loc): OK in " . round((microtime(true) - $t) * 1000) . 'ms');
            } catch (\Throwable $e) {
                $output->writeln("FAIL ensureIndex($loc) nach " . round((microtime(true) - $t) * 1000) . 'ms: ' . $e::class . ' — ' . $e->getMessage());
                $output->writeln('File: ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        $output->writeln('=== buildPlan ===');
        $t = microtime(true);
        try {
            $plan = $this->catalog->buildPlan($config);
            $dur = round((microtime(true) - $t) * 1000);
            $output->writeln("buildPlan: OK in {$dur}ms");
            $output->writeln('stats: ' . json_encode($plan['stats']));
            $output->writeln('items count: ' . count($plan['items']));
        } catch (\Throwable $e) {
            $dur = round((microtime(true) - $t) * 1000);
            $output->writeln("FAIL buildPlan nach {$dur}ms");
            $output->writeln('Exception: ' . $e::class);
            $output->writeln('Message: ' . $e->getMessage());
            $output->writeln('File: ' . $e->getFile() . ':' . $e->getLine());
            $output->writeln('Trace:');
            $output->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
