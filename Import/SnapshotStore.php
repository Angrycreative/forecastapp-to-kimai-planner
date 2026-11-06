<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\ForecastImportBundle\Entity\Snapshot;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastData;

/**
 * Keeps the last data read from Forecast, so the mapping and preview screens need no token.
 */
class SnapshotStore
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function replace(ForecastData $data): Snapshot
    {
        $snapshot = new Snapshot($data);
        $this->entityManager->wrapInTransaction(function () use ($snapshot): void {
            $this->entityManager->createQuery('DELETE FROM ' . Snapshot::class . ' s')->execute();
            $this->entityManager->persist($snapshot);
        });

        return $snapshot;
    }

    public function latest(): ?Snapshot
    {
        return $this->entityManager->getRepository(Snapshot::class)->findOneBy([], ['id' => 'DESC']);
    }
}
