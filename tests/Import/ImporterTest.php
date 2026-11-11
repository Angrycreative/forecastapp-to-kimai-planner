<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Tests\Import;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\ForecastImportBundle\Entity\ImportedPlanning;
use KimaiPlugin\ForecastImportBundle\Import\Importer;
use KimaiPlugin\ForecastImportBundle\Import\Plan;
use KimaiPlugin\ForecastImportBundle\Import\PlannerCapabilities;
use KimaiPlugin\ForecastImportBundle\Import\ReservationCatalogue;
use KimaiPlugin\ForecastImportBundle\Repository\ImportedPlanningRepository;
use KimaiPlugin\PlannerBundle\Entity\PlannedActivity;
use KimaiPlugin\PlannerBundle\Entity\Reservation;
use KimaiPlugin\PlannerBundle\Repository\ReservationRepository;
use PHPUnit\Framework\TestCase;

class ImporterTest extends TestCase
{
    public function testRemovedReservedTimeIsDescribedByTheReservationName(): void
    {
        if (!class_exists(Reservation::class)) {
            $this->markTestSkipped('Needs a Weekly Planner with built-in reserved time (2.2.0 or later).');
        }

        $user = new User();
        $user->setAlias('Ada');
        $activity = new PlannedActivity();
        $activity->setUser($user);
        $activity->setBegin(new \DateTime('2026-11-16'));
        $activity->setEnd(new \DateTime('2026-11-20'));
        $activity->setReservation((new Reservation())->setName('Agency buffer'));

        // imported by an earlier run, and the assignment is gone from Forecast now
        $link = new ImportedPlanning(42, $activity, new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-20'), 'fingerprint');
        $links = $this->createStub(ImportedPlanningRepository::class);
        $links->method('findAllByAssignment')->willReturn([42 => [$link]]);
        $links->method('findUnlinkedParts')->willReturn([]);

        $capabilities = new PlannerCapabilities(true, true, true);
        $importer = new Importer(
            $this->createStub(EntityManagerInterface::class),
            $links,
            $capabilities,
            new ReservationCatalogue($capabilities, $this->createStub(ReservationRepository::class)),
        );

        $result = $importer->import(new Plan(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30')), true);

        $this->assertCount(1, $result->removedPlannings);
        $this->assertSame('Agency buffer', $result->removedPlannings[0]['label']);
    }
}
