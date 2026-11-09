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
 * What an import would do, before anything is written: the plannings per assignment, the assignments deliberately
 * left out, and the problems worth showing the person running it.
 */
final class Plan
{
    public const PERSON_UNMAPPED = 'person_unmapped';
    public const PLACEHOLDER_UNMAPPED = 'placeholder_unmapped';
    public const PROJECT_UNMAPPED = 'project_unmapped';
    public const USER_DISABLED = 'user_disabled';
    public const TIME_OFF_UNSUPPORTED = 'time_off_unsupported';
    public const RESERVATION_UNSUPPORTED = 'reservation_unsupported';
    public const TENTATIVE_UNSUPPORTED = 'tentative_unsupported';
    public const NO_WORK_CONTRACT = 'no_work_contract';
    public const FOR_EVERYONE = 'for_everyone';

    /** Problems that mean an assignment is not imported, as opposed to imported with a caveat. */
    public const BLOCKING = [self::PERSON_UNMAPPED, self::PLACEHOLDER_UNMAPPED, self::PROJECT_UNMAPPED, self::TIME_OFF_UNSUPPORTED, self::RESERVATION_UNSUPPORTED];

    /** @var array<int, PlannedAssignment> */
    private array $assignments = [];

    /** @var array<int, true> assignments skipped on purpose: earlier imports of them are removed */
    private array $skipped = [];

    /** @var array<int, true> assignments that could not be resolved: earlier imports of them are left alone */
    private array $unresolved = [];

    /** @var array<string, array<string, int>> by problem, then by what it concerns, how many assignments */
    private array $problems = [];

    public function __construct(
        public readonly \DateTimeImmutable $begin,
        public readonly \DateTimeImmutable $end,
    ) {
    }

    public function add(PlannedAssignment $assignment): void
    {
        $this->assignments[$assignment->forecastId] = $assignment;
    }

    public function skip(int $forecastId): void
    {
        $this->skipped[$forecastId] = true;
    }

    public function unresolved(int $forecastId, string $problem, string $subject): void
    {
        $this->unresolved[$forecastId] = true;
        $this->problem($problem, $subject);
    }

    public function problem(string $problem, string $subject): void
    {
        $this->problems[$problem][$subject] = ($this->problems[$problem][$subject] ?? 0) + 1;
    }

    /**
     * @return array<int, PlannedAssignment>
     */
    public function assignments(): array
    {
        return $this->assignments;
    }

    /**
     * @return list<int>
     */
    public function skippedIds(): array
    {
        return array_keys($this->skipped);
    }

    /**
     * @return list<int>
     */
    public function unresolvedIds(): array
    {
        return array_keys($this->unresolved);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    public function hasBlockingProblems(): bool
    {
        return array_intersect(array_keys($this->problems), self::BLOCKING) !== [];
    }

    public function partCount(): int
    {
        return array_sum(array_map(static fn (PlannedAssignment $a): int => \count($a->parts), $this->assignments));
    }

    public function totalHours(): float
    {
        return array_sum(array_map(static fn (PlannedAssignment $a): float => $a->totalHours(), $this->assignments));
    }
}
