<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use KimaiPlugin\ForecastImportBundle\Forecast\ForecastClient;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastCredentials;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastData;

/**
 * The import as the screen and the console command use it: fetch from Forecast, plan, preview, import.
 */
class ForecastImport
{
    public function __construct(
        private readonly ForecastClient $client,
        private readonly SnapshotStore $snapshots,
        private readonly MappingService $mappings,
        private readonly PlanBuilder $planBuilder,
        private readonly Importer $importer,
        private readonly PlannerCapabilities $capabilities,
    ) {
    }

    public function capabilities(): PlannerCapabilities
    {
        return $this->capabilities;
    }

    public function fetch(ForecastCredentials $credentials, \DateTimeImmutable $begin, \DateTimeImmutable $end): ForecastData
    {
        $data = $this->client->fetch($credentials, $begin, $end);
        $this->snapshots->replace($data);

        return $data;
    }

    public function data(): ?ForecastData
    {
        return $this->snapshots->latest()?->getData();
    }

    public function plan(ForecastData $data): Plan
    {
        return $this->planBuilder->build($data, $this->mappings->mappingSet($data));
    }

    public function preview(ForecastData $data): ImportResult
    {
        return $this->importer->import($this->plan($data), true);
    }

    public function import(ForecastData $data): ImportResult
    {
        if (!$this->capabilities->isPlannerInstalled()) {
            throw new \RuntimeException('The Weekly Planner (PlannerBundle) is not installed.');
        }

        return $this->importer->import($this->plan($data), false);
    }

    public function removeAll(): int
    {
        return $this->importer->removeAll();
    }
}
