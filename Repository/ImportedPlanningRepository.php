<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\ForecastImportBundle\Entity\ImportedPlanning;
use KimaiPlugin\ForecastImportBundle\Import\PlannerCapabilities;
use KimaiPlugin\ForecastImportBundle\Import\PlannedAssignment;
use KimaiPlugin\PlannerBundle\Entity\PlannedActivity;

/**
 * @extends ServiceEntityRepository<ImportedPlanning>
 */
class ImportedPlanningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly ?PlannerCapabilities $capabilities = null)
    {
        parent::__construct($registry, ImportedPlanning::class);
    }

    /**
     * Every link with its planning, grouped by Forecast assignment, including links whose planning was deleted.
     *
     * @return array<int, list<ImportedPlanning>>
     */
    public function findAllByAssignment(): array
    {
        $links = $this->createQueryBuilder('i')
            ->select('i', 'a')
            ->leftJoin('i.plannedActivity', 'a')
            ->getQuery()
            ->getResult();

        $byAssignment = [];
        foreach ($links as $link) {
            $byAssignment[$link->getForecastAssignmentId()][] = $link;
        }

        return $byAssignment;
    }

    /**
     * Plannings that belong to an imported assignment but have no link of their own: the other half of an imported
     * planning someone split, or cut a day out of, in the planner. The planner copies everything to that half except
     * this import's link, so it is recognised in two ways:
     *
     * - it has the same person, customer and project (and reserved time, where the planner has it) as an imported
     *   planning and lies within the dates the import gave that planning. Nothing else can: the planner refuses two plannings of one project for one person on the same
     *   day, so these days held the imported planning when the half was made;
     * - it carries the recurrence group of an assignment the import cut around days off, which also finds halves whose
     *   imported planning was deleted since.
     *
     * @return array<int, list<PlannedActivity>> by Forecast assignment
     */
    public function findUnlinkedParts(): array
    {
        $em = $this->getEntityManager();
        $unlinked = 'NOT EXISTS (SELECT i2.id FROM ' . ImportedPlanning::class . ' i2 WHERE i2.plannedActivity = a)';

        $byAssignment = [];
        $seen = [];
        $add = static function (int $assignment, PlannedActivity $activity) use (&$byAssignment, &$seen): void {
            if (!isset($seen[$activity->getId()])) {
                $seen[$activity->getId()] = true;
                $byAssignment[$assignment][] = $activity;
            }
        };

        $qb = $em->createQueryBuilder()
            ->select('a', 'l.forecastAssignmentId AS assignment')
            ->from(ImportedPlanning::class, 'l')
            ->join('l.plannedActivity', 'la')
            ->join(PlannedActivity::class, 'a', 'WITH', 'a.user = la.user AND a.id <> la.id')
            ->where('(a.customer = la.customer OR (a.customer IS NULL AND la.customer IS NULL))')
            ->andWhere('(a.project = la.project OR (a.project IS NULL AND la.project IS NULL))')
            ->andWhere('a.begin >= l.partBegin')
            ->andWhere('a.end <= l.partEnd')
            ->andWhere($unlinked);
        // reserved time has no customer either: without this, time off or another reservation planned by hand within
        // an imported one's dates would be taken for part of it, and removed or cut with it
        if ($this->capabilities?->supportsReservations() ?? false) {
            $qb->andWhere('(a.reservation = la.reservation OR (a.reservation IS NULL AND la.reservation IS NULL))');
        }
        $rows = $qb->getQuery()->getResult();
        foreach ($rows as $row) {
            $add((int) $row['assignment'], $row[0]);
        }

        $grouped = $em->createQueryBuilder()
            ->select('a')
            ->from(PlannedActivity::class, 'a')
            ->where('a.recurrenceGroup LIKE :prefix')
            ->andWhere($unlinked)
            ->setParameter('prefix', PlannedAssignment::GROUP_PREFIX . '%')
            ->getQuery()
            ->getResult();
        foreach ($grouped as $activity) {
            $id = PlannedAssignment::idFromGroup((string) $activity->getRecurrenceGroup());
            if ($id !== null) {
                $add($id, $activity);
            }
        }

        return $byAssignment;
    }

    public function countPlannings(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.plannedActivity IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
