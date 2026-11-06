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
use KimaiPlugin\ForecastImportBundle\Entity\Mapping;

/**
 * @extends ServiceEntityRepository<Mapping>
 */
class MappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mapping::class);
    }

    /**
     * @return array<string, Mapping> by Forecast key
     */
    public function findByKind(string $kind): array
    {
        $byKey = [];
        foreach ($this->findBy(['kind' => $kind]) as $mapping) {
            $byKey[$mapping->getForecastKey()] = $mapping;
        }

        return $byKey;
    }

    public function findOrCreate(string $kind, string $forecastKey): Mapping
    {
        return $this->findOneBy(['kind' => $kind, 'forecastKey' => $forecastKey]) ?? new Mapping($kind, $forecastKey);
    }

    public function save(Mapping ...$mappings): void
    {
        $em = $this->getEntityManager();
        foreach ($mappings as $mapping) {
            $em->persist($mapping);
        }
        $em->flush();
    }

    public function remove(Mapping $mapping): void
    {
        if ($mapping->getId() !== null) {
            $this->getEntityManager()->remove($mapping);
            $this->getEntityManager()->flush();
        }
    }
}
