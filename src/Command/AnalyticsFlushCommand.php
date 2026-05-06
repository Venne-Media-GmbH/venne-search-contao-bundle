<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use VenneMedia\VenneSearchContaoBundle\Service\Analytics\AnalyticsFlusher;

/**
 * Cron-Worker: schickt gepufferte Such-Events an die Plattform.
 *
 *   php vendor/bin/contao-console venne-search:analytics:flush
 *
 * Empfohlener Cron (alle 5 Minuten):
 *   *‍/5 * * * *  cd /var/www/site && php vendor/bin/contao-console venne-search:analytics:flush
 *
 * Exit-Codes:
 *   0  → ok (auch wenn 0 Events; oder transient retry)
 *   1  → mindestens eine Datei nach failed/ verschoben (Ops-Alert)
 */
#[AsCommand(
    name: 'venne-search:analytics:flush',
    description: 'Sendet gepufferte Such-Analytics-Events an die Plattform.',
)]
final class AnalyticsFlushCommand extends Command
{
    public function __construct(private readonly AnalyticsFlusher $flusher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('keep-flushed', null, InputOption::VALUE_NONE,
                'Erfolgreich verschickte Dateien nicht löschen, sondern nach flushed/ verschieben (Forensik).')
            ->setHelp(<<<'HELP'
Liest alle Tagespuffer-Files (außer dem heutigen, der noch in use ist),
postet die Events in 200er-Batches an die Plattform und rotiert die Files
nach erfolgreichem Versand. Bei 4xx → failed/, bei 5xx/Network → retry.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keep = (bool) $input->getOption('keep-flushed');

        $io->title('Venne Search · Analytics Flush');

        $result = $this->flusher->flush($keep);

        $io->definitionList(
            ['Verarbeitete Files' => (string) $result['processedFiles']],
            ['Versendete Events' => (string) $result['processedEvents']],
            ['Übersprungen (heute)' => (string) $result['skippedFiles']],
            ['Failed (4xx)' => (string) $result['failedFiles']],
        );

        if ($result['errors'] !== []) {
            $io->section('Fehler / Hinweise');
            foreach ($result['errors'] as $err) {
                $io->writeln('  · ' . $err);
            }
        }

        if ($result['failedFiles'] > 0) {
            $io->warning('Mindestens eine Datei wurde nach failed/ verschoben — bitte prüfen.');
            return Command::FAILURE;
        }

        if ($result['processedEvents'] > 0) {
            $io->success(sprintf('%d Events verschickt.', $result['processedEvents']));
        } else {
            $io->info('Nichts zu tun (kein Backlog).');
        }

        return Command::SUCCESS;
    }
}
