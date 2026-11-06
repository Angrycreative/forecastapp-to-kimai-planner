<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Forecast;

/**
 * Everything read from one Forecast account for one date range: people, placeholders, clients, projects and the
 * assignments overlapping the range. Records stay the arrays Forecast sent, keyed by their id, after checking that
 * every field the import relies on is present, so a change on Forecast's side stops the import instead of producing
 * half a plan.
 *
 * Forecast has no official public API; the field names below are the ones its own web app receives.
 */
final class ForecastData
{
    /** Fields each record must carry, per collection. */
    private const REQUIRED = [
        'people' => ['id', 'first_name', 'last_name', 'email', 'archived'],
        'placeholders' => ['id', 'name', 'archived'],
        'clients' => ['id', 'name'],
        'projects' => ['id', 'name', 'client_id', 'tags', 'archived'],
        'assignments' => ['id', 'start_date', 'end_date', 'allocation', 'project_id', 'person_id', 'placeholder_id', 'updated_at'],
    ];

    /**
     * @param array<int, array<string, mixed>> $people
     * @param array<int, array<string, mixed>> $placeholders
     * @param array<int, array<string, mixed>> $clients
     * @param array<int, array<string, mixed>> $projects
     * @param array<int, array<string, mixed>> $assignments
     */
    private function __construct(
        public readonly string $accountId,
        public readonly \DateTimeImmutable $begin,
        public readonly \DateTimeImmutable $end,
        public readonly \DateTimeImmutable $fetchedAt,
        public readonly array $people,
        public readonly array $placeholders,
        public readonly array $clients,
        public readonly array $projects,
        public readonly array $assignments,
    ) {
    }

    /**
     * @param array<string, list<array<string, mixed>>> $collections the lists Forecast returned, by collection name
     */
    public static function fromForecast(string $accountId, \DateTimeImmutable $begin, \DateTimeImmutable $end, \DateTimeImmutable $fetchedAt, array $collections): self
    {
        $indexed = [];
        foreach (self::REQUIRED as $name => $fields) {
            $indexed[$name] = [];
            foreach ($collections[$name] ?? [] as $record) {
                if (!\is_array($record)) {
                    throw new ForecastApiException(\sprintf('Forecast sent a %s entry that is not a record. Its API may have changed.', $name));
                }
                foreach ($fields as $field) {
                    if (!\array_key_exists($field, $record)) {
                        throw new ForecastApiException(\sprintf('Forecast\'s %s no longer carry the field "%s", which the import needs. Its API may have changed; nothing was imported.', $name, $field));
                    }
                }
                if ($name === 'assignments') {
                    self::checkAssignment($record);
                }
                $indexed[$name][(int) $record['id']] = $record;
            }
        }

        return new self($accountId, $begin, $end, $fetchedAt, $indexed['people'], $indexed['placeholders'], $indexed['clients'], $indexed['projects'], $indexed['assignments']);
    }

    /**
     * Presence alone is not enough for the fields that decide dates and hours: an empty date would read as "today".
     *
     * @param array<string, mixed> $assignment
     */
    private static function checkAssignment(array $assignment): void
    {
        foreach (['start_date', 'end_date'] as $field) {
            if (!\is_string($assignment[$field]) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignment[$field]) !== 1) {
                throw new ForecastApiException(\sprintf('Forecast sent an assignment with "%s" that is not a date. Its API may have changed; nothing was imported.', $field));
            }
        }
        if ($assignment['end_date'] < $assignment['start_date']) {
            throw new ForecastApiException('Forecast sent an assignment that ends before it starts. Nothing was imported.');
        }
        if ($assignment['allocation'] !== null && !\is_int($assignment['allocation'])) {
            throw new ForecastApiException('Forecast sent an assignment whose "allocation" is not a number of seconds. Its API may have changed; nothing was imported.');
        }
        if ($assignment['person_id'] === null && $assignment['placeholder_id'] === null) {
            throw new ForecastApiException('Forecast sent an assignment for nobody. Its API may have changed; nothing was imported.');
        }
    }

    /**
     * @param array<string, mixed> $stored what {@see toArray()} returned
     */
    public static function fromArray(array $stored): self
    {
        return self::fromForecast(
            (string) $stored['account_id'],
            new \DateTimeImmutable((string) $stored['begin']),
            new \DateTimeImmutable((string) $stored['end']),
            new \DateTimeImmutable((string) $stored['fetched_at']),
            array_map('array_values', array_intersect_key($stored, self::REQUIRED)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'begin' => $this->begin->format('Y-m-d'),
            'end' => $this->end->format('Y-m-d'),
            'fetched_at' => $this->fetchedAt->format(\DATE_ATOM),
            'people' => array_values($this->people),
            'placeholders' => array_values($this->placeholders),
            'clients' => array_values($this->clients),
            'projects' => array_values($this->projects),
            'assignments' => array_values($this->assignments),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function project(int $id): ?array
    {
        return $this->projects[$id] ?? null;
    }

    public function clientName(?int $clientId): ?string
    {
        return $clientId === null ? null : (isset($this->clients[$clientId]) ? (string) $this->clients[$clientId]['name'] : null);
    }

    public function personName(int $id): string
    {
        $person = $this->people[$id] ?? null;

        return $person === null ? '#' . $id : trim($person['first_name'] . ' ' . $person['last_name']);
    }

    /**
     * Forecast keeps time off as assignments on a project of its own, named "Time Off" and without a client.
     */
    public function isTimeOffProject(int $projectId): bool
    {
        $project = $this->projects[$projectId] ?? null;

        return $project !== null && $project['client_id'] === null && strcasecmp(trim((string) $project['name']), 'Time Off') === 0;
    }

    /**
     * How many of the assignments each project and each person or placeholder has, to show what a mapping affects.
     *
     * @return array{projects: array<int, int>, people: array<int, int>, placeholders: array<int, int>}
     */
    public function assignmentCounts(): array
    {
        $counts = ['projects' => [], 'people' => [], 'placeholders' => []];
        foreach ($this->assignments as $assignment) {
            $projectId = (int) $assignment['project_id'];
            $counts['projects'][$projectId] = ($counts['projects'][$projectId] ?? 0) + 1;
            if ($assignment['person_id'] !== null) {
                $personId = (int) $assignment['person_id'];
                $counts['people'][$personId] = ($counts['people'][$personId] ?? 0) + 1;
            } elseif ($assignment['placeholder_id'] !== null) {
                $placeholderId = (int) $assignment['placeholder_id'];
                $counts['placeholders'][$placeholderId] = ($counts['placeholders'][$placeholderId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * What is planned in Forecast per project and per person or placeholder within this range, for people to judge a
     * row by: hours (Forecast books working days only, so weekdays count unless an assignment is active on days off),
     * whole days for bookings without hours, the number of bookings and the first and last day.
     *
     * @return array{projects: array<int, array{hours: float, days: int, bookings: int, first: string, last: string}>, people: array<int, array{hours: float, days: int, bookings: int, first: string, last: string}>, placeholders: array<int, array{hours: float, days: int, bookings: int, first: string, last: string}>}
     */
    public function plannedTotals(): array
    {
        $totals = ['projects' => [], 'people' => [], 'placeholders' => []];
        $rangeBegin = $this->begin->format('Y-m-d');
        $rangeEnd = $this->end->format('Y-m-d');
        foreach ($this->assignments as $assignment) {
            $first = max((string) $assignment['start_date'], $rangeBegin);
            $last = min((string) $assignment['end_date'], $rangeEnd);
            if ($last < $first) {
                continue;
            }
            $days = 0;
            $allDays = (bool) ($assignment['active_on_days_off'] ?? false);
            for ($day = new \DateTimeImmutable($first); $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
                if ($allDays || (int) $day->format('N') <= 5) {
                    ++$days;
                }
            }
            $hours = $assignment['allocation'] !== null ? $days * ((int) $assignment['allocation']) / 3600 : 0.0;
            $wholeDays = $assignment['allocation'] === null ? $days : 0;

            $keys = [['projects', (int) $assignment['project_id']]];
            if ($assignment['person_id'] !== null) {
                $keys[] = ['people', (int) $assignment['person_id']];
            } elseif ($assignment['placeholder_id'] !== null) {
                $keys[] = ['placeholders', (int) $assignment['placeholder_id']];
            }
            foreach ($keys as [$kind, $id]) {
                $row = $totals[$kind][$id] ?? ['hours' => 0.0, 'days' => 0, 'bookings' => 0, 'first' => $first, 'last' => $last];
                $row['hours'] += $hours;
                $row['days'] += $wholeDays;
                ++$row['bookings'];
                $row['first'] = min($row['first'], $first);
                $row['last'] = max($row['last'], $last);
                $totals[$kind][$id] = $row;
            }
        }

        return $totals;
    }

    /**
     * Tags of the projects that have assignments in this range, for choosing which tags mean "tentative".
     *
     * @return list<string>
     */
    public function projectTags(): array
    {
        $tags = [];
        foreach (array_keys($this->assignmentCounts()['projects']) as $projectId) {
            foreach ((array) ($this->projects[$projectId]['tags'] ?? []) as $tag) {
                $tags[(string) $tag] = true;
            }
        }
        $tags = array_keys($tags);
        natcasesort($tags);

        return array_values(array_map('strval', $tags));
    }
}
