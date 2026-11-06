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
 * What one Forecast assignment becomes: one planning, or several when it is cut into runs of working days.
 */
final class PlannedAssignment
{
    public const GROUP_PREFIX = 'forecast-';

    /**
     * @param list<PlannedPart> $parts
     * @param string $fingerprint the assignment as Forecast has it plus where the mapping sends it, independent of the
     *                            import range, so a later run with another range still recognises it as unchanged
     */
    public function __construct(
        public readonly int $forecastId,
        public readonly array $parts,
        public readonly string $fingerprint,
    ) {
    }

    /**
     * Every planning of one assignment shares a recurrence group, so the planner treats them as one series ("all future
     * instances") and so the halves the planner makes when someone splits an imported planning can still be found.
     */
    public function recurrenceGroup(): string
    {
        return self::GROUP_PREFIX . $this->forecastId;
    }

    public static function idFromGroup(string $group): ?int
    {
        return preg_match('/^' . preg_quote(self::GROUP_PREFIX, '/') . '(\d+)$/', $group, $matches) === 1 ? (int) $matches[1] : null;
    }

    public function totalHours(): float
    {
        $hours = 0.0;
        foreach ($this->parts as $part) {
            if ($part->hoursPerDay !== null && !$part->timeOff) {
                $hours += $part->hoursPerDay * ((int) $part->begin->diff($part->end)->days + 1);
            }
        }

        return $hours;
    }
}
