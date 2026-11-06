<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Tests\Import;

use KimaiPlugin\ForecastImportBundle\Forecast\ForecastData;
use KimaiPlugin\ForecastImportBundle\Import\ReadSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadSummary::class)]
final class ReadSummaryTest extends TestCase
{
    public function testCountsWhatChangedInForecastSinceThePreviousRead(): void
    {
        $previous = self::data('2026-11-09', '2026-12-31', [1 => 'a', 2 => 'a', 3 => 'a']);
        $current = self::data('2026-11-09', '2026-12-31', [1 => 'a', 2 => 'b', 4 => 'a']);

        $summary = ReadSummary::compare($current, $previous);

        self::assertSame([1, 1, 1], [$summary->new, $summary->changed, $summary->gone]);
        self::assertFalse($summary->rangeChanged);
        self::assertEquals($summary, ReadSummary::fromArray($summary->toArray()));
    }

    public function testADifferentDateRangeIsNotMistakenForChanges(): void
    {
        // assignment 3 only falls in the earlier, longer range; assignment 5 only in the new, earlier start
        $previous = self::data('2026-11-09', '2027-06-30', [1 => 'a', 3 => 'a'], [3 => '2027-03-01']);
        $current = self::data('2026-11-02', '2026-12-31', [1 => 'a', 5 => 'a'], [5 => '2026-11-03']);

        $summary = ReadSummary::compare($current, $previous);

        self::assertSame([0, 0, 0], [$summary->new, $summary->changed, $summary->gone]);
        self::assertTrue($summary->rangeChanged);
    }

    public function testAFirstReadHasNothingToCompare(): void
    {
        $summary = ReadSummary::compare(self::data('2026-11-09', '2026-12-31', [1 => 'a']), null);

        self::assertFalse($summary->hasPrevious);
        self::assertSame(1, $summary->assignments);
        self::assertSame(1, $summary->people);
    }

    /**
     * @param array<int, string> $assignments id => updated_at
     * @param array<int, string> $dates id => the one day it covers, default 2026-11-10
     */
    private static function data(string $begin, string $end, array $assignments, array $dates = []): ForecastData
    {
        $list = [];
        foreach ($assignments as $id => $updated) {
            $day = $dates[$id] ?? '2026-11-10';
            $list[] = ['id' => $id, 'start_date' => $day, 'end_date' => $day, 'allocation' => 3600, 'project_id' => 10, 'person_id' => 1, 'placeholder_id' => null, 'updated_at' => $updated];
        }

        return ForecastData::fromForecast('1', new \DateTimeImmutable($begin), new \DateTimeImmutable($end), new \DateTimeImmutable(), [
            'people' => [['id' => 1, 'first_name' => 'A', 'last_name' => 'B', 'email' => null, 'archived' => false]],
            'projects' => [['id' => 10, 'name' => 'P', 'client_id' => null, 'tags' => [], 'archived' => false]],
            'assignments' => $list,
        ]);
    }
}
