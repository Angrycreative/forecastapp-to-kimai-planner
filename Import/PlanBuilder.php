<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use App\Entity\User;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastData;

/**
 * Turns Forecast assignments into plannings, without touching the database.
 *
 * - Dates are trimmed to the import range, so nothing before its start is created.
 * - An allocation is seconds per day; none means a whole day, which is how Forecast stores most time off.
 * - Forecast books working days only, unless an assignment is "active on days off", so assignments are cut into runs
 *   of the user's working days. An assignment without a single working day is kept as it is, like the planner does.
 */
final class PlanBuilder
{
    /** Hours of a whole working day when the Forecast person has no weekly capacity to derive them from. */
    private const DEFAULT_DAY_HOURS = 8.0;

    public function __construct(
        private readonly WorkDayCalendar $calendar,
        private readonly PlannerCapabilities $capabilities,
    ) {
    }

    public function build(ForecastData $data, MappingSet $mappings): Plan
    {
        $plan = new Plan($data->begin, $data->end);

        foreach ($data->assignments as $id => $assignment) {
            $begin = max(new \DateTimeImmutable((string) $assignment['start_date']), $data->begin);
            $end = min(new \DateTimeImmutable((string) $assignment['end_date']), $data->end);
            if ($end < $begin) {
                continue;
            }

            // a day off for everyone at once is left to the planner's public holidays, and shown in the preview
            if (ForecastData::isForEveryone($assignment)) {
                $label = $begin == $end ? $begin->format('Y-m-d') : $begin->format('Y-m-d') . ' – ' . $end->format('Y-m-d');
                $plan->problem(Plan::FOR_EVERYONE, $label . ' (' . $this->projectLabel($data, (int) $assignment['project_id']) . ')');

                continue;
            }

            $user = $this->resolveUser($data, $mappings, $plan, $assignment);
            if ($user === false) {
                continue;
            }
            if ($user === null) {
                $plan->skip($id);

                continue;
            }

            $projectId = (int) $assignment['project_id'];
            $project = $data->project($projectId);
            $target = $mappings->project($projectId) ?? ($data->isTimeOffProject($projectId) ? Target::timeOff(true) : null);
            $projectLabel = $this->projectLabel($data, $projectId);
            if ($target === null) {
                $plan->unresolved($id, Plan::PROJECT_UNMAPPED, $projectLabel);

                continue;
            }
            if ($target->isSkip()) {
                $plan->skip($id);

                continue;
            }

            $timeOff = $target->kind === Target::TIME_OFF;
            if ($timeOff && !$this->capabilities->supportsTimeOff()) {
                $plan->unresolved($id, Plan::TIME_OFF_UNSUPPORTED, $projectLabel);

                continue;
            }

            $tentative = !$timeOff && $mappings->isTentative((array) ($project['tags'] ?? []));
            if ($tentative && !$this->capabilities->supportsTentative()) {
                $plan->problem(Plan::TENTATIVE_UNSUPPORTED, $projectLabel);
                $tentative = false;
            }

            if (!$user->isEnabled()) {
                $plan->problem(Plan::USER_DISABLED, $user->getDisplayName());
            }

            $hours = $this->hoursPerDay($data, $assignment, $timeOff);
            $comment = trim((string) ($assignment['notes'] ?? ''));
            $comment = $comment === '' ? null : $comment;

            $activeOnDaysOff = (bool) ($assignment['active_on_days_off'] ?? false);
            $ranges = $activeOnDaysOff ? [[$begin, $end]] : $this->workingRuns($data, $assignment, $user, $begin, $end, $plan);

            $parts = [];
            foreach ($ranges as [$partBegin, $partEnd]) {
                $parts[] = new PlannedPart(
                    $user,
                    $timeOff ? null : $target->customer,
                    $timeOff ? null : $target->project,
                    $partBegin,
                    $partEnd,
                    $hours,
                    $timeOff,
                    $tentative,
                    $comment,
                );
            }

            $fingerprint = sha1((string) json_encode([
                (string) $assignment['start_date'],
                (string) $assignment['end_date'],
                $assignment['allocation'],
                $comment,
                $activeOnDaysOff,
                $user->getId(),
                $timeOff ? null : $target->customer?->getId(),
                $timeOff ? null : $target->project?->getId(),
                $timeOff,
                $tentative,
                $hours,
            ]));

            $plan->add(new PlannedAssignment($id, $parts, $fingerprint));
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $assignment
     * @return User|null|false the user, null when skipped on purpose, false when it could not be resolved
     */
    private function resolveUser(ForecastData $data, MappingSet $mappings, Plan $plan, array $assignment): User|null|false
    {
        $id = (int) $assignment['id'];
        if ($assignment['person_id'] !== null) {
            $personId = (int) $assignment['person_id'];
            $target = $mappings->person($personId);
            if ($target === null) {
                $plan->unresolved($id, Plan::PERSON_UNMAPPED, $data->personName($personId));

                return false;
            }

            return $target->isSkip() ? null : $target->user;
        }

        $placeholderId = (int) $assignment['placeholder_id'];
        $target = $mappings->placeholder($placeholderId);
        if ($target === null) {
            $plan->unresolved($id, Plan::PLACEHOLDER_UNMAPPED, (string) ($data->placeholders[$placeholderId]['name'] ?? '#' . $placeholderId));

            return false;
        }

        return $target->isSkip() ? null : $target->user;
    }

    /**
     * @param array<string, mixed> $assignment
     */
    private function hoursPerDay(ForecastData $data, array $assignment, bool $timeOff): ?float
    {
        $allocation = $assignment['allocation'];
        if ($allocation !== null && (int) $allocation > 0) {
            return min(24.0, round(((int) $allocation) / 3600, 2));
        }

        // a whole day: time off can say so itself, work needs a number of hours
        if ($timeOff && $this->capabilities->supportsTimeOff()) {
            return null;
        }

        $person = $assignment['person_id'] !== null ? ($data->people[(int) $assignment['person_id']] ?? null) : null;
        $weekly = (int) ($person['weekly_capacity'] ?? 0);
        $days = \count(array_filter((array) ($person['working_days'] ?? [])));

        return $weekly > 0 && $days > 0 ? min(24.0, round($weekly / $days / 3600, 2)) : self::DEFAULT_DAY_HOURS;
    }

    /**
     * The runs of days the user works within the dates. Someone without a work contract in Kimai works no day at all,
     * which would turn a Monday to Friday booking over four weeks into one planning straight through the weekends;
     * for them Forecast's own working days for the person are used instead, and the preview says so.
     *
     * @param array<string, mixed> $assignment
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function workingRuns(ForecastData $data, array $assignment, User $user, \DateTimeImmutable $begin, \DateTimeImmutable $end, Plan $plan): array
    {
        $isWorkDay = fn (\DateTimeImmutable $day): bool => $this->calendar->isWorkDay($user, $day);
        if (!$this->hasWorkingWeek($user, $begin)) {
            $forecastDays = $this->forecastWorkingDays($data, $assignment);
            if ($forecastDays !== []) {
                $plan->problem(Plan::NO_WORK_CONTRACT, $user->getDisplayName());
                $isWorkDay = static fn (\DateTimeImmutable $day): bool => \in_array(strtolower($day->format('l')), $forecastDays, true);
            }
        }

        $runs = [];
        $runBegin = null;
        $runEnd = null;
        for ($day = $begin; $day <= $end; $day = $day->modify('+1 day')) {
            if ($isWorkDay($day)) {
                $runBegin ??= $day;
                $runEnd = $day;

                continue;
            }
            if ($runBegin !== null && $runEnd !== null) {
                $runs[] = [$runBegin, $runEnd];
                $runBegin = null;
            }
        }
        if ($runBegin !== null && $runEnd !== null) {
            $runs[] = [$runBegin, $runEnd];
        }

        return $runs === [] ? [[$begin, $end]] : $runs;
    }

    /**
     * Whether the user works at least one day in the week from this date, by their Kimai work contract.
     */
    private function hasWorkingWeek(User $user, \DateTimeImmutable $from): bool
    {
        for ($i = 0; $i < 7; ++$i) {
            if ($this->calendar->isWorkDay($user, $from->modify('+' . $i . ' days'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $assignment
     * @return list<string> lower-case English day names the Forecast person works
     */
    private function forecastWorkingDays(ForecastData $data, array $assignment): array
    {
        $person = $assignment['person_id'] !== null ? ($data->people[(int) $assignment['person_id']] ?? null) : null;

        return array_values(array_map('strval', array_keys(array_filter((array) ($person['working_days'] ?? [])))));
    }

    private function projectLabel(ForecastData $data, int $projectId): string
    {
        $project = $data->project($projectId);
        if ($project === null) {
            return '#' . $projectId;
        }
        $client = $data->clientName($project['client_id'] === null ? null : (int) $project['client_id']);

        return $client === null ? (string) $project['name'] : $client . ' / ' . $project['name'];
    }
}
