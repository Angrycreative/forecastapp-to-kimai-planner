<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Command;

use KimaiPlugin\ForecastImportBundle\Forecast\ForecastApiException;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastCredentials;
use KimaiPlugin\ForecastImportBundle\Import\ForecastImport;
use KimaiPlugin\ForecastImportBundle\Import\ImportResult;
use KimaiPlugin\ForecastImportBundle\Import\MappingService;
use KimaiPlugin\ForecastImportBundle\Import\Plan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The import without a browser, for large accounts or a scripted cutover. Uses the mapping decisions made on the
 * import screen; people are still matched by email and Forecast's time off is still recognised without them.
 */
#[AsCommand(name: 'kimai:forecast-import', description: 'Import planning from Harvest Forecast into the Weekly Planner')]
final class ImportCommand extends Command
{
    public function __construct(
        private readonly ForecastImport $import,
        private readonly MappingService $mappings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fetch', null, InputOption::VALUE_NONE, 'Read Forecast first; otherwise the last data read is used')
            ->addOption('account-id', null, InputOption::VALUE_REQUIRED, 'Forecast account ID, with --fetch')
            ->addOption('begin', null, InputOption::VALUE_REQUIRED, 'First day to import, with --fetch', 'today')
            ->addOption('end', null, InputOption::VALUE_REQUIRED, 'Last day to import, with --fetch', 'today +12 months')
            ->addOption('accept-suggestions', null, InputOption::VALUE_NONE, 'Map unmapped projects to their confident suggestions')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen without writing anything')
            ->setHelp(<<<'HELP'
                The Harvest personal access token is read from the FORECAST_TOKEN environment variable, or asked for
                without echoing it. It is never stored.

                  FORECAST_TOKEN=... bin/console kimai:forecast-import --fetch --account-id=123456 --dry-run
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->import->capabilities()->isPlannerInstalled()) {
            $io->error('The Weekly Planner (PlannerBundle) is not installed.');

            return Command::FAILURE;
        }

        try {
            if ($input->getOption('fetch')) {
                $token = getenv('FORECAST_TOKEN') ?: (string) $io->askHidden('Harvest personal access token');
                $data = $this->import->fetch(
                    new ForecastCredentials((string) $input->getOption('account-id'), $token),
                    new \DateTimeImmutable((string) $input->getOption('begin') . ' midnight'),
                    new \DateTimeImmutable((string) $input->getOption('end') . ' midnight'),
                );
                $io->success(\sprintf('Read %d assignments between %s and %s.', \count($data->assignments), $data->begin->format('Y-m-d'), $data->end->format('Y-m-d')));
            } else {
                $data = $this->import->data();
                if ($data === null) {
                    $io->error('Nothing has been read from Forecast yet. Run with --fetch.');

                    return Command::FAILURE;
                }
                $io->note(\sprintf('Using Forecast data read %s.', $data->fetchedAt->format('Y-m-d H:i')));
            }
        } catch (ForecastApiException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('accept-suggestions')) {
            $io->note(\sprintf('%d project suggestions accepted.', $this->mappings->acceptSuggestions($data)));
        }

        $plan = $this->import->plan($data);
        $this->printProblems($io, $plan);

        $dryRun = (bool) $input->getOption('dry-run');
        $result = $dryRun ? $this->import->preview($data) : $this->import->import($data);
        $this->printResult($io, $result, $plan);

        return Command::SUCCESS;
    }

    private function printProblems(SymfonyStyle $io, Plan $plan): void
    {
        foreach ($plan->problems() as $problem => $subjects) {
            $io->section(\in_array($problem, Plan::BLOCKING, true) ? "Left out: $problem" : "Note: $problem");
            $io->listing(array_map(static fn (string $subject, int $count): string => "$subject ($count)", array_keys($subjects), $subjects));
        }
    }

    private function printResult(SymfonyStyle $io, ImportResult $result, Plan $plan): void
    {
        $io->table(['', 'Assignments'], [
            ['New', $result->created],
            ['Changed', $result->updated],
            ['Unchanged', $result->unchanged],
            ['Removed or skipped', $result->removed],
        ]);
        $message = \sprintf(
            '%s %d plannings, removed %d, shortened %d (%d planned hours).',
            $result->dryRun ? 'Would create' : 'Created',
            $result->planningsCreated,
            $result->planningsRemoved,
            $result->planningsShortened,
            round($plan->totalHours()),
        );
        $result->dryRun ? $io->note('Dry run: ' . $message) : $io->success($message);
    }
}
