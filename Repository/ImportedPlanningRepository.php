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
use KimaiPlugin\ForecastImportBundle\Import\PlannedAssignment;
use KimaiPlugin\PlannerBundle\Entity\PlannedActivity;

/**
 * @extends ServiceEntityRepository<ImportedPlanning>
 */
class ImportedPlanningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
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
     * planning someone split or cut a day out of in the planner, which copies the recurrence group but knows nothing
     * of this import.
     *
     * @return array<int, list<PlannedActivity>> by Forecast assignment
     */
    public function findUnlinkedParts(): array
    {
        $activities = $this->getEntityManager()->createQueryBuilder()
            ->select('a')
            ->from(PlannedActivity::class, 'a')
            ->where('a.recurrenceGroup LIKE :prefix')
            ->andWhere('NOT EXISTS (SELECT i.id FROM ' . ImportedPlanning::class . ' i WHERE i.plannedActivity = a)')
            ->setParameter('prefix', PlannedAssignment::GROUP_PREFIX . '%')
            ->getQuery()
            ->getResult();

        $byAssignment = [];
        foreach ($activities as $activity) {
            $id = PlannedAssignment::idFromGroup((string) $activity->getRecurrenceGroup());
            if ($id !== null) {
                $byAssignment[$id][] = $activity;
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
