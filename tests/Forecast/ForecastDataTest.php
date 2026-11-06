<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Tests\Forecast;

use KimaiPlugin\ForecastImportBundle\Forecast\ForecastApiException;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForecastData::class)]
final class ForecastDataTest extends TestCase
{
    public function testRefusesRecordsMissingAFieldTheImportNeeds(): void
    {
        $collections = self::collections();
        unset($collections['assignments'][0]['allocation']);

        $this->expectException(ForecastApiException::class);
        $this->expectExceptionMessage('"allocation"');
        ForecastData::fromForecast('1', new \DateTimeImmutable('2026-11-09'), new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable(), $collections);
    }

    public function testRefusesAnAssignmentWithoutARealDate(): void
    {
        $collections = self::collections();
        $collections['assignments'][0]['start_date'] = null;

        $this->expectException(ForecastApiException::class);
        $this->expectExceptionMessage('"start_date" that is not a date');
        ForecastData::fromForecast('1', new \DateTimeImmutable('2026-11-09'), new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable(), $collections);
    }

    public function testRefusesAnAllocationThatIsNotSeconds(): void
    {
        $collections = self::collections();
        $collections['assignments'][0]['allocation'] = '1h';

        $this->expectException(ForecastApiException::class);
        ForecastData::fromForecast('1', new \DateTimeImmutable('2026-11-09'), new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable(), $collections);
    }

    public function testSurvivesStorage(): void
    {
        $data = ForecastData::fromForecast('1', new \DateTimeImmutable('2026-11-09'), new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable('2026-11-06T10:00:00+00:00'), self::collections());
        $restored = ForecastData::fromArray(json_decode((string) json_encode($data->toArray()), true));

        self::assertEquals($data->assignments, $restored->assignments);
        self::assertSame('2026-11-09', $restored->begin->format('Y-m-d'));
        self::assertSame($data->fetchedAt->format(\DATE_ATOM), $restored->fetchedAt->format(\DATE_ATOM));
    }

    public function testKnowsTimeOffCountsAndTags(): void
    {
        $data = ForecastData::fromForecast('1', new \DateTimeImmutable('2026-11-09'), new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable(), self::collections());

        self::assertTrue($data->isTimeOffProject(99));
        self::assertFalse($data->isTimeOffProject(10));
        self::assertSame(['projects' => [10 => 2, 99 => 1], 'people' => [1 => 2], 'placeholders' => [5 => 1]], $data->assignmentCounts());
        self::assertSame(['not signed', 'Retainer'], $data->projectTags());
        self::assertSame('Alex Doe', $data->personName(1));
        self::assertSame('#404', $data->personName(404));
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private static function collections(): array
    {
        $assignment = ['start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'allocation' => 3600, 'person_id' => 1, 'placeholder_id' => null, 'updated_at' => 'x'];

        return [
            'people' => [['id' => 1, 'first_name' => 'Alex', 'last_name' => 'Doe', 'email' => 'a@example.com', 'archived' => false]],
            'placeholders' => [['id' => 5, 'name' => 'Designer', 'archived' => false]],
            'clients' => [['id' => 7, 'name' => 'Acme']],
            'projects' => [
                ['id' => 10, 'name' => 'Webshop', 'client_id' => 7, 'tags' => ['Retainer', 'not signed'], 'archived' => false],
                ['id' => 99, 'name' => 'Time Off', 'client_id' => null, 'tags' => [], 'archived' => false],
            ],
            'assignments' => [
                ['id' => 100, 'project_id' => 10] + $assignment,
                ['id' => 101, 'project_id' => 99] + $assignment,
                ['id' => 102, 'project_id' => 10, 'person_id' => null, 'placeholder_id' => 5] + $assignment,
            ],
        ];
    }
}
