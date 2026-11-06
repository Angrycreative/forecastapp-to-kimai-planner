<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;

/**
 * One planning to create in the Weekly Planner.
 */
final class PlannedPart
{
    public function __construct(
        public readonly User $user,
        public readonly ?Customer $customer,
        public readonly ?Project $project,
        public readonly \DateTimeImmutable $begin,
        public readonly \DateTimeImmutable $end,
        public readonly ?float $hoursPerDay,
        public readonly bool $timeOff,
        public readonly bool $tentative,
        public readonly ?string $comment,
    ) {
    }
}
