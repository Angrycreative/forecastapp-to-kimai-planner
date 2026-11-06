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

    /**
     * @return array<string, mixed> what decides whether an earlier import of the same assignment is still right
     */
    public function fingerprintData(): array
    {
        return [
            $this->user->getId(),
            $this->customer?->getId(),
            $this->project?->getId(),
            $this->begin->format('Y-m-d'),
            $this->end->format('Y-m-d'),
            $this->hoursPerDay,
            $this->timeOff,
            $this->tentative,
            $this->comment,
        ];
    }
}
