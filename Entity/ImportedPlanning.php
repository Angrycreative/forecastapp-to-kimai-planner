<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\PlannerBundle\Entity\PlannedActivity;

/**
 * Which Forecast assignment a planning came from, and what the import made of it. This is what makes importing again
 * safe: an unchanged assignment is left alone, a changed one is replaced, and none is created twice. Deleting the
 * planning in Kimai deletes this row with it.
 */
#[ORM\Table(name: 'kimai2_forecast_import_planning')]
#[ORM\Index(columns: ['forecast_assignment_id'], name: 'IDX_FORECAST_IMPORT_ASSIGNMENT')]
#[ORM\UniqueConstraint(name: 'UNIQ_FORECAST_IMPORT_ACTIVITY', columns: ['planned_activity_id'])]
#[ORM\Entity(repositoryClass: \KimaiPlugin\ForecastImportBundle\Repository\ImportedPlanningRepository::class)]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
class ImportedPlanning
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'imported_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $importedAt;

    public function __construct(
        #[ORM\Column(name: 'forecast_assignment_id', type: Types::BIGINT)]
        private string|int $forecastAssignmentId,
        #[ORM\ManyToOne(targetEntity: PlannedActivity::class)]
        #[ORM\JoinColumn(name: 'planned_activity_id', nullable: false, onDelete: 'CASCADE')]
        private PlannedActivity $plannedActivity,
        #[ORM\Column(name: 'fingerprint', type: Types::STRING, length: 40)]
        private string $fingerprint,
    ) {
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getForecastAssignmentId(): int
    {
        return (int) $this->forecastAssignmentId;
    }

    public function getPlannedActivity(): PlannedActivity
    {
        return $this->plannedActivity;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }
}
