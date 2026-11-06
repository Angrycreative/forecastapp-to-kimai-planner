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
     * Every link with its planning, grouped by Forecast assignment.
     *
     * @return array<int, list<ImportedPlanning>>
     */
    public function findAllByAssignment(): array
    {
        $links = $this->createQueryBuilder('i')
            ->select('i', 'a')
            ->join('i.plannedActivity', 'a')
            ->getQuery()
            ->getResult();

        $byAssignment = [];
        foreach ($links as $link) {
            $byAssignment[$link->getForecastAssignmentId()][] = $link;
        }

        return $byAssignment;
    }

    public function countPlannings(): int
    {
        return (int) $this->count([]);
    }
}
