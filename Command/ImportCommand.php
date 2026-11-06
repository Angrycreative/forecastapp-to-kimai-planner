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
use KimaiPlugin\ForecastImportBundle\Import\TokenVault;
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
        private readonly TokenVault $vault,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fetch', null, InputOption::VALUE_NONE, 'Read Forecast first; otherwise the last data read is used')
            ->addOption('account-id', null, InputOption::VALUE_REQUIRED, 'Forecast account ID, with --fetch (default: the saved token\'s account)')
            ->addOption('begin', null, InputOption::VALUE_REQUIRED, 'First day to import, with --fetch', 'today')
            ->addOption('end', null, InputOption::VALUE_REQUIRED, 'Last day to import, with --fetch', 'today +12 months')
            ->addOption('accept-suggestions', null, InputOption::VALUE_NONE, 'Map unmapped projects to their confident suggestions')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen without writing anything')
            ->setHelp(<<<'HELP'
                The Harvest personal access token is read from the FORECAST_TOKEN environment variable, else the token
                saved (encrypted) on the import screen is used, else it is asked for without echoing it. The command
                never saves a token itself. --account-id defaults to the account of the saved token.

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
                // FORECAST_TOKEN first, then the token saved on the import screen, then ask
                $saved = $this->vault->load();
                $accountId = (string) ($input->getOption('account-id') ?? $saved?->accountId ?? '');
                $token = getenv('FORECAST_TOKEN') ?: ($saved !== null && $saved->accountId === $accountId ? $saved->token() : (string) $io->askHidden('Harvest personal access token'));
                $read = $this->import->fetch(
                    new ForecastCredentials($accountId, $token),
                    new \DateTimeImmutable((string) $input->getOption('begin') . ' midnight'),
                    new \DateTimeImmutable((string) $input->getOption('end') . ' midnight'),
                );
                $io->success(\sprintf(
                    'Read %d assignments (%d of them time off) for %d people on %d projects, %s to %s.%s',
                    $read->assignments,
                    $read->timeOff,
                    $read->people,
                    $read->projects,
                    $read->begin,
                    $read->end,
                    $read->hasPrevious ? \sprintf(' Since the previous read%s: %d new, %d changed, %d gone.', $read->rangeChanged ? ' (dates differ, only the overlap compared)' : '', $read->new, $read->changed, $read->gone) : ''
                ));
                $data = $this->import->data() ?? throw new \LogicException('Forecast data was just stored.');
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

        $dryRun = (bool) $input->getOption('dry-run');
        if ($input->getOption('accept-suggestions')) {
            $accepted = $this->mappings->acceptSuggestions($data, null, save: !$dryRun);
            $io->note($dryRun
                ? \sprintf('%d project suggestions would be accepted. Dry run: not saved, so the figures below leave them out.', $accepted)
                : \sprintf('%d project suggestions accepted.', $accepted));
        }

        $plan = $this->import->plan($data);
        $this->printProblems($io, $plan);

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
