<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use KimaiPlugin\ForecastImportBundle\Forecast\ForecastData;

/**
 * What one read of Forecast brought in, and how it differs from the read before, so whoever runs the import sees what
 * happened instead of wondering whether anything did.
 */
final class ReadSummary
{
    public function __construct(
        public readonly string $begin,
        public readonly string $end,
        public readonly int $assignments,
        public readonly int $people,
        public readonly int $projects,
        public readonly int $timeOff,
        public readonly bool $hasPrevious,
        public readonly int $new,
        public readonly int $changed,
        public readonly int $gone,
        public readonly bool $rangeChanged = false,
    ) {
    }

    public static function compare(ForecastData $data, ?ForecastData $previous): self
    {
        $counts = $data->assignmentCounts();
        $timeOff = 0;
        foreach ($data->assignments as $assignment) {
            if ($data->isTimeOffProject((int) $assignment['project_id'])) {
                ++$timeOff;
            }
        }

        // only what both reads could have seen counts, so a different date range does not look like changes in Forecast
        $new = $changed = $gone = 0;
        if ($previous !== null) {
            $from = max($data->begin, $previous->begin)->format('Y-m-d');
            $until = min($data->end, $previous->end)->format('Y-m-d');
            $overlaps = static fn (array $assignment): bool => (string) $assignment['end_date'] >= $from && (string) $assignment['start_date'] <= $until;
            foreach ($data->assignments as $id => $assignment) {
                if (!isset($previous->assignments[$id])) {
                    $overlaps($assignment) && ++$new;
                } elseif ($previous->assignments[$id]['updated_at'] !== $assignment['updated_at']) {
                    ++$changed;
                }
            }
            foreach ($previous->assignments as $id => $assignment) {
                if (!isset($data->assignments[$id]) && $overlaps($assignment)) {
                    ++$gone;
                }
            }
        }

        return new self(
            $data->begin->format('Y-m-d'),
            $data->end->format('Y-m-d'),
            \count($data->assignments),
            \count($counts['people']),
            \count($counts['projects']),
            $timeOff,
            $previous !== null,
            $new,
            $changed,
            $gone,
            $previous !== null && ($previous->begin != $data->begin || $previous->end != $data->end),
        );
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            (string) $values['begin'],
            (string) $values['end'],
            (int) $values['assignments'],
            (int) $values['people'],
            (int) $values['projects'],
            (int) $values['timeOff'],
            (bool) $values['hasPrevious'],
            (int) $values['new'],
            (int) $values['changed'],
            (int) $values['gone'],
            (bool) ($values['rangeChanged'] ?? false),
        );
    }
}
