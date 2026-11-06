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
    public readonly string $fingerprint;

    /**
     * @param list<PlannedPart> $parts
     */
    public function __construct(
        public readonly int $forecastId,
        public readonly array $parts,
    ) {
        $this->fingerprint = sha1((string) json_encode(array_map(static fn (PlannedPart $part): array => $part->fingerprintData(), $parts)));
    }

    /**
     * Parts of one assignment share a recurrence group, so the planner treats them as one series ("all future
     * instances") the way it treats plannings it split into working days itself.
     */
    public function recurrenceGroup(): ?string
    {
        return \count($this->parts) > 1 ? 'forecast-' . $this->forecastId : null;
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
