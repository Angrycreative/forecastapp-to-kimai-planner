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
 * What the installed Weekly Planner can hold. Time off, tentative planning and reserved time are newer than the
 * planner's first releases, so the import checks for them instead of assuming them.
 */
class PlannerCapabilities
{
    public const PLANNED_ACTIVITY = 'KimaiPlugin\PlannerBundle\Entity\PlannedActivity';

    public function __construct(
        private readonly ?bool $timeOff = null,
        private readonly ?bool $tentative = null,
        private readonly ?bool $reservations = null,
    ) {
    }

    public function isPlannerInstalled(): bool
    {
        return class_exists(self::PLANNED_ACTIVITY);
    }

    public function supportsTimeOff(): bool
    {
        return $this->timeOff ?? ($this->isPlannerInstalled() && method_exists(self::PLANNED_ACTIVITY, 'setTimeOff'));
    }

    public function supportsTentative(): bool
    {
        return $this->tentative ?? ($this->isPlannerInstalled() && method_exists(self::PLANNED_ACTIVITY, 'setTentative'));
    }

    /**
     * Reserved time: hours held for something that is not a customer or project, offered by another plugin.
     */
    public function supportsReservations(): bool
    {
        return $this->reservations ?? ($this->isPlannerInstalled() && method_exists(self::PLANNED_ACTIVITY, 'setReservation'));
    }
}
