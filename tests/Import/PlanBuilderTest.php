<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Tests\Import;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastData;
use KimaiPlugin\ForecastImportBundle\Import\MappingSet;
use KimaiPlugin\ForecastImportBundle\Import\Plan;
use KimaiPlugin\ForecastImportBundle\Import\PlanBuilder;
use KimaiPlugin\ForecastImportBundle\Import\PlannerCapabilities;
use KimaiPlugin\ForecastImportBundle\Import\Target;
use KimaiPlugin\ForecastImportBundle\Import\WorkDayCalendar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanBuilder::class)]
#[CoversClass(Plan::class)]
final class PlanBuilderTest extends TestCase
{
    private const PERSON = 1;
    private const PROJECT = 10;
    private const TIME_OFF = 99;

    private User $user;
    private Customer $customer;
    private Project $project;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->user->setUserIdentifier('alex');
        $this->user->setEnabled(true);
        $this->customer = new Customer('Acme');
        $this->project = new Project();
        $this->project->setName('Webshop');
        $this->project->setCustomer($this->customer);
    }

    public function testTrimsToTheRangeAndConvertsSecondsToHours(): void
    {
        // Wednesday 2026-11-04 to Friday 2026-11-13, range starts Monday 2026-11-09
        $plan = $this->build([$this->assignment(1, '2026-11-04', '2026-11-13', 7200)]);

        $parts = $plan->assignments()[1]->parts;
        self::assertCount(1, $parts);
        self::assertSame('2026-11-09', $parts[0]->begin->format('Y-m-d'));
        self::assertSame('2026-11-13', $parts[0]->end->format('Y-m-d'));
        self::assertSame(2.0, $parts[0]->hoursPerDay);
        self::assertSame($this->customer, $parts[0]->customer);
        self::assertSame($this->project, $parts[0]->project);
        self::assertSame(10.0, $plan->totalHours());
    }

    public function testCutsIntoRunsOfWorkingDaysSharingARecurrenceGroup(): void
    {
        // Thursday to the next Tuesday: Thu-Fri and Mon-Tue
        $plan = $this->build([$this->assignment(1, '2026-11-12', '2026-11-17', 3600)]);

        $assignment = $plan->assignments()[1];
        self::assertSame(
            [['2026-11-12', '2026-11-13'], ['2026-11-16', '2026-11-17']],
            array_map(static fn ($part): array => [$part->begin->format('Y-m-d'), $part->end->format('Y-m-d')], $assignment->parts)
        );
        self::assertSame('forecast-1', $assignment->recurrenceGroup());
    }

    public function testSkipsPublicHolidaysLikeDaysOff(): void
    {
        $plan = $this->build([$this->assignment(1, '2026-11-09', '2026-11-11', 3600)], holidays: ['2026-11-10']);

        self::assertCount(2, $plan->assignments()[1]->parts);
    }

    public function testKeepsDaysOffWhenTheAssignmentIsActiveOnThem(): void
    {
        $plan = $this->build([$this->assignment(1, '2026-11-12', '2026-11-17', 3600, ['active_on_days_off' => true])]);

        $assignment = $plan->assignments()[1];
        self::assertCount(1, $assignment->parts);
    }

    public function testKeepsAWeekendOnlyAssignmentAsItIs(): void
    {
        $plan = $this->build([$this->assignment(1, '2026-11-14', '2026-11-15', 3600)]);

        $parts = $plan->assignments()[1]->parts;
        self::assertCount(1, $parts);
        self::assertSame('2026-11-14', $parts[0]->begin->format('Y-m-d'));
    }

    public function testRecognisesForecastTimeOffAsAWholeDayWithoutCustomer(): void
    {
        $plan = $this->build([$this->assignment(1, '2026-11-09', '2026-11-09', null, ['project_id' => self::TIME_OFF])]);

        $part = $plan->assignments()[1]->parts[0];
        self::assertTrue($part->timeOff);
        self::assertNull($part->hoursPerDay);
        self::assertNull($part->customer);
        self::assertSame(0.0, $plan->totalHours());
    }

    public function testLeavesTimeOffOutWhenThePlannerCannotHoldIt(): void
    {
        $plan = $this->build([$this->assignment(1, '2026-11-09', '2026-11-09', null, ['project_id' => self::TIME_OFF])], timeOff: false);

        self::assertSame([], $plan->assignments());
        self::assertSame([1], $plan->unresolvedIds());
        self::assertArrayHasKey(Plan::TIME_OFF_UNSUPPORTED, $plan->problems());
    }

    public function testDerivesAWholeWorkingDayFromTheWeeklyCapacity(): void
    {
        // 36 hours over five days
        $plan = $this->build([$this->assignment(1, '2026-11-09', '2026-11-09', null)]);

        self::assertSame(7.2, $plan->assignments()[1]->parts[0]->hoursPerDay);
    }

    public function testCapsADayAt24Hours(): void
    {
        $plan = $this->build([$this->assignment(1, '2026-11-09', '2026-11-09', 30 * 3600)]);

        self::assertSame(24.0, $plan->assignments()[1]->parts[0]->hoursPerDay);
    }

    public function testMarksAssignmentsOnTentativeTaggedProjects(): void
    {
        $plan = $this->build([$this->assignment(1, '2026-11-09', '2026-11-09', 3600)], tags: ['not signed'], tentativeTags: ['Not Signed']);

        self::assertTrue($plan->assignments()[1]->parts[0]->tentative);
    }

    public function testImportsTentativeWorkAsConfirmedWhenThePlannerHasNoTentative(): void
    {
        $plan = $this->build([$this->assignment(1, '2026-11-09', '2026-11-09', 3600)], tags: ['pitch'], tentativeTags: ['pitch'], tentative: false);

        self::assertFalse($plan->assignments()[1]->parts[0]->tentative);
        self::assertArrayHasKey(Plan::TENTATIVE_UNSUPPORTED, $plan->problems());
        self::assertFalse($plan->hasBlockingProblems());
    }

    public function testUnmappedPeopleAndProjectsAreUnresolvedNotSkipped(): void
    {
        $plan = $this->build(
            [
                $this->assignment(1, '2026-11-09', '2026-11-09', 3600, ['person_id' => 2]),
                $this->assignment(2, '2026-11-09', '2026-11-09', 3600, ['project_id' => 11]),
            ]
        );

        self::assertSame([], $plan->assignments());
        self::assertSame([1, 2], $plan->unresolvedIds());
        self::assertSame([], $plan->skippedIds());
        self::assertSame(['Sam Doe' => 1], $plan->problems()[Plan::PERSON_UNMAPPED]);
        self::assertSame(['Acme / Other' => 1], $plan->problems()[Plan::PROJECT_UNMAPPED]);
        self::assertTrue($plan->hasBlockingProblems());
    }

    public function testSkippedPeopleProjectsAndPlaceholdersAreSkipped(): void
    {
        $mappings = new MappingSet(
            [self::PERSON => Target::skip()],
            [5 => Target::skip()],
            [self::PROJECT => Target::work($this->customer, $this->project)],
        );
        $plan = $this->build(
            [
                $this->assignment(1, '2026-11-09', '2026-11-09', 3600),
                $this->assignment(2, '2026-11-09', '2026-11-09', 3600, ['person_id' => null, 'placeholder_id' => 5]),
            ],
            mappings: $mappings
        );

        self::assertSame([1, 2], $plan->skippedIds());
        self::assertSame([], $plan->problems());
    }

    public function testNotesBecomeTheComment(): void
    {
        $plan = $this->build([
            $this->assignment(1, '2026-11-09', '2026-11-09', 3600, ['notes' => "  Sprint 4  "]),
            $this->assignment(2, '2026-11-09', '2026-11-09', 3600, ['notes' => '   ']),
        ]);

        self::assertSame('Sprint 4', $plan->assignments()[1]->parts[0]->comment);
        self::assertNull($plan->assignments()[2]->parts[0]->comment);
    }

    public function testFingerprintIgnoresTheImportRangeAndForecastsEditTime(): void
    {
        // an assignment already running: tomorrow's run starts a day later, but the assignment has not changed
        $today = $this->build([$this->assignment(1, '2026-11-02', '2026-11-20', 3600)])->assignments()[1];
        $tomorrow = $this->build([$this->assignment(1, '2026-11-02', '2026-11-20', 3600, ['updated_at' => '2027-01-01T00:00:00Z'])], rangeBegin: '2026-11-10')->assignments()[1];

        self::assertSame($today->fingerprint, $tomorrow->fingerprint);
        self::assertNotEquals($today->parts[0]->begin, $tomorrow->parts[0]->begin);
    }

    public function testFingerprintChangesWithTheAssignmentOrItsMapping(): void
    {
        $base = $this->build([$this->assignment(1, '2026-11-09', '2026-11-10', 3600)])->assignments()[1]->fingerprint;
        $otherHours = $this->build([$this->assignment(1, '2026-11-09', '2026-11-10', 7200)])->assignments()[1]->fingerprint;
        $tentative = $this->build([$this->assignment(1, '2026-11-09', '2026-11-10', 3600)], tags: ['pitch'], tentativeTags: ['pitch'])->assignments()[1]->fingerprint;

        self::assertNotSame($base, $otherHours);
        self::assertNotSame($base, $tentative);
    }

    public function testEveryAssignmentGetsItsOwnRecurrenceGroup(): void
    {
        $assignment = $this->build([$this->assignment(42, '2026-11-09', '2026-11-09', 3600)])->assignments()[42];

        self::assertSame('forecast-42', $assignment->recurrenceGroup());
        self::assertSame(42, \KimaiPlugin\ForecastImportBundle\Import\PlannedAssignment::idFromGroup('forecast-42'));
        self::assertNull(\KimaiPlugin\ForecastImportBundle\Import\PlannedAssignment::idFromGroup('a1b2c3'));
    }

    public function testUsesForecastsWorkingDaysForSomeoneWithoutAWorkContract(): void
    {
        // Monday 9 to Friday 20 November: two weeks, not one planning through the weekend
        $plan = $this->build([$this->assignment(1, '2026-11-09', '2026-11-20', 3600)], contract: false);

        self::assertSame(
            [['2026-11-09', '2026-11-13'], ['2026-11-16', '2026-11-20']],
            array_map(static fn ($part): array => [$part->begin->format('Y-m-d'), $part->end->format('Y-m-d')], $plan->assignments()[1]->parts)
        );
        self::assertArrayHasKey(Plan::NO_WORK_CONTRACT, $plan->problems());
        self::assertFalse($plan->hasBlockingProblems());
    }

    public function testNotesAnInactiveUser(): void
    {
        $this->user->setEnabled(false);
        $plan = $this->build([$this->assignment(1, '2026-11-09', '2026-11-09', 3600)]);

        self::assertCount(1, $plan->assignments());
        self::assertArrayHasKey(Plan::USER_DISABLED, $plan->problems());
    }

    /**
     * @param list<array<string, mixed>> $assignments
     * @param list<string> $tags
     * @param list<string> $tentativeTags
     * @param list<string> $holidays
     */
    private function build(
        array $assignments,
        array $tags = [],
        array $tentativeTags = [],
        bool $timeOff = true,
        bool $tentative = true,
        array $holidays = [],
        ?MappingSet $mappings = null,
        string $rangeBegin = '2026-11-09',
        bool $contract = true,
    ): Plan {
        $data = ForecastData::fromForecast('123', new \DateTimeImmutable($rangeBegin), new \DateTimeImmutable('2027-11-08'), new \DateTimeImmutable(), [
            'people' => [
                ['id' => self::PERSON, 'first_name' => 'Alex', 'last_name' => 'Doe', 'email' => 'alex@example.com', 'archived' => false, 'weekly_capacity' => 36 * 3600, 'working_days' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => false, 'sunday' => false]],
                ['id' => 2, 'first_name' => 'Sam', 'last_name' => 'Doe', 'email' => 'sam@example.com', 'archived' => false],
            ],
            'placeholders' => [['id' => 5, 'name' => 'Designer', 'archived' => false]],
            'clients' => [['id' => 7, 'name' => 'Acme']],
            'projects' => [
                ['id' => self::PROJECT, 'name' => 'Webshop', 'client_id' => 7, 'tags' => $tags, 'archived' => false],
                ['id' => 11, 'name' => 'Other', 'client_id' => 7, 'tags' => [], 'archived' => false],
                ['id' => self::TIME_OFF, 'name' => 'Time Off', 'client_id' => null, 'tags' => [], 'archived' => false],
            ],
            'assignments' => $assignments,
        ]);

        $mappings ??= new MappingSet(
            [self::PERSON => Target::user($this->user)],
            [],
            [self::PROJECT => Target::work($this->customer, $this->project)],
            $tentativeTags,
        );

        $calendar = new class($holidays, $contract) implements WorkDayCalendar {
            /** @param list<string> $holidays */
            public function __construct(private readonly array $holidays, private readonly bool $contract)
            {
            }

            public function isWorkDay(User $user, \DateTimeImmutable $day): bool
            {
                return $this->contract && (int) $day->format('N') <= 5 && !\in_array($day->format('Y-m-d'), $this->holidays, true);
            }
        };

        return (new PlanBuilder($calendar, new PlannerCapabilities($timeOff, $tentative)))->build($data, $mappings);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function assignment(int $id, string $start, string $end, ?int $allocation, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'start_date' => $start,
            'end_date' => $end,
            'allocation' => $allocation,
            'notes' => null,
            'person_id' => self::PERSON,
            'placeholder_id' => null,
            'project_id' => self::PROJECT,
            'active_on_days_off' => false,
            'updated_at' => '2026-11-01T10:00:00Z',
        ], $overrides);
    }
}
