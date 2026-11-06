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
 * The plan as people read it: per person, every assignment with its dates, hours and what the import does with it.
 * Changes come first, unchanged assignments last.
 */
final class Preview
{
    private const ORDER = [ImportResult::CREATED => 0, ImportResult::UPDATED => 1, ImportResult::REMOVED => 2, ImportResult::UNCHANGED => 3];

    /**
     * @return list<array{name: string, counts: array<string, int>, hours: float, rows: list<array{status: string, label: string, begin: \DateTimeInterface, end: \DateTimeInterface, hoursPerDay: ?float, timeOff: bool, tentative: bool, parts: int, comment: ?string}>}>
     */
    public static function byPerson(Plan $plan, ImportResult $result): array
    {
        $people = [];
        foreach ($plan->assignments() as $id => $assignment) {
            $first = $assignment->parts[0] ?? null;
            $last = $assignment->parts[\count($assignment->parts) - 1] ?? null;
            if ($first === null || $last === null) {
                continue;
            }
            $name = $first->user->getDisplayName();
            $status = $result->statuses[$id] ?? ImportResult::CREATED;
            $people[$name]['rows'][] = [
                'status' => $status,
                'label' => $first->timeOff ? '' : ($first->project !== null ? $first->customer?->getName() . ' / ' . $first->project->getName() : (string) $first->customer?->getName()),
                'begin' => $first->begin,
                'end' => $last->end,
                'hoursPerDay' => $first->hoursPerDay,
                'timeOff' => $first->timeOff,
                'tentative' => $first->tentative,
                'parts' => \count($assignment->parts),
                'comment' => $first->comment,
            ];
            $people[$name]['hours'] = ($people[$name]['hours'] ?? 0.0) + $assignment->totalHours();
        }

        foreach ($result->removedPlannings as $removed) {
            $people[$removed['user']]['rows'][] = [
                'status' => ImportResult::REMOVED,
                'label' => $removed['label'],
                'begin' => $removed['begin'],
                'end' => $removed['end'],
                'hoursPerDay' => $removed['hoursPerDay'],
                'timeOff' => $removed['timeOff'],
                'tentative' => false,
                'parts' => 1,
                'comment' => null,
            ];
            $people[$removed['user']]['hours'] ??= 0.0;
        }

        $groups = [];
        foreach ($people as $name => $person) {
            $rows = $person['rows'];
            usort($rows, static fn (array $a, array $b): int => [self::ORDER[$a['status']], $a['begin']] <=> [self::ORDER[$b['status']], $b['begin']]);
            $counts = array_fill_keys(array_keys(self::ORDER), 0);
            foreach ($rows as $row) {
                ++$counts[$row['status']];
            }
            $groups[] = ['name' => (string) $name, 'counts' => $counts, 'hours' => $person['hours'], 'rows' => $rows];
        }

        // people with changes first, then by name
        usort($groups, static fn (array $a, array $b): int => [
            $b['counts'][ImportResult::CREATED] + $b['counts'][ImportResult::UPDATED] + $b['counts'][ImportResult::REMOVED] > 0,
            $a['name'],
        ] <=> [
            $a['counts'][ImportResult::CREATED] + $a['counts'][ImportResult::UPDATED] + $a['counts'][ImportResult::REMOVED] > 0,
            $b['name'],
        ]);

        return $groups;
    }
}
