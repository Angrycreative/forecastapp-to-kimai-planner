<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

/**
 * What an import did, or with a dry run would do, counted in Forecast assignments and in plannings.
 */
final class ImportResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $removed = 0;
    public int $planningsCreated = 0;
    public int $planningsRemoved = 0;
    public int $planningsShortened = 0;

    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    public const REMOVED = 'removed';

    /** @var array<int, string> what happens to each Forecast assignment */
    public array $statuses = [];

    /**
     * Earlier imported plannings that are removed or shortened, for assignments no longer in the plan.
     *
     * @var list<array{assignment: int, user: string, label: string, begin: \DateTimeInterface, end: \DateTimeInterface, hoursPerDay: ?float, timeOff: bool}>
     */
    public array $removedPlannings = [];

    public function __construct(public readonly bool $dryRun)
    {
    }

    public function hasChanges(): bool
    {
        return $this->created + $this->updated + $this->removed > 0;
    }
}
