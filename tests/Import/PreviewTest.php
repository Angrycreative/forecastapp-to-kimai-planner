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
use KimaiPlugin\ForecastImportBundle\Import\ImportResult;
use KimaiPlugin\ForecastImportBundle\Import\Plan;
use KimaiPlugin\ForecastImportBundle\Import\PlannedAssignment;
use KimaiPlugin\ForecastImportBundle\Import\PlannedPart;
use KimaiPlugin\ForecastImportBundle\Import\Preview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Preview::class)]
final class PreviewTest extends TestCase
{
    public function testGroupsByPersonWithChangesFirst(): void
    {
        $alex = self::user(1, 'Alex');
        $robin = self::user(2, 'Robin');
        $customer = new Customer('Acme');
        $project = (new Project())->setName('Webshop')->setCustomer($customer);

        $plan = new Plan(new \DateTimeImmutable('2026-11-09'), new \DateTimeImmutable('2027-11-08'));
        $plan->add(new PlannedAssignment(1, [self::part($alex, $customer, $project, '2026-11-16', '2026-11-20', 2.0)], 'f1'));
        $plan->add(new PlannedAssignment(2, [self::part($alex, $customer, $project, '2026-11-09', '2026-11-10', 4.0)], 'f2'));
        $plan->add(new PlannedAssignment(3, [
            self::part($robin, null, null, '2026-11-12', '2026-11-13', null, true),
            self::part($robin, null, null, '2026-11-16', '2026-11-16', null, true),
        ], 'f3'));

        $result = new ImportResult(true);
        $result->statuses = [1 => ImportResult::CREATED, 2 => ImportResult::UNCHANGED, 3 => ImportResult::UNCHANGED];
        $result->removedPlannings[] = ['assignment' => 9, 'userId' => 2, 'user' => 'Robin', 'label' => 'Acme / Old', 'begin' => new \DateTime('2026-12-01'), 'end' => new \DateTime('2026-12-01'), 'hoursPerDay' => 1.0, 'timeOff' => false];

        $people = Preview::byPerson($plan, $result);

        self::assertSame(['Alex', 'Robin'], array_column($people, 'name'));
        self::assertSame([ImportResult::CREATED, ImportResult::UNCHANGED], array_column($people[0]['rows'], 'status'));
        self::assertSame('Acme / Webshop', $people[0]['rows'][0]['label']);
        self::assertSame(18.0, $people[0]['hours']);
        self::assertSame([ImportResult::REMOVED, ImportResult::UNCHANGED], array_column($people[1]['rows'], 'status'));
        self::assertSame(2, $people[1]['rows'][1]['parts']);
        self::assertSame('2026-11-16', $people[1]['rows'][1]['end']->format('Y-m-d'));
        self::assertTrue($people[1]['rows'][1]['timeOff']);
        self::assertSame(1, $people[1]['counts'][ImportResult::REMOVED]);
    }

    private static function user(int $id, string $alias): User
    {
        $user = (new User())->setAlias($alias);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private static function part(User $user, ?Customer $customer, ?Project $project, string $begin, string $end, ?float $hours, bool $timeOff = false): PlannedPart
    {
        return new PlannedPart($user, $customer, $project, new \DateTimeImmutable($begin), new \DateTimeImmutable($end), $hours, $timeOff, false, null);
    }
}
