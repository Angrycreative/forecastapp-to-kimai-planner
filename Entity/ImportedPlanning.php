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
 * One planning the import created: which Forecast assignment it came from, the dates the import gave it and the
 * fingerprint of the assignment it was made from. This is what makes importing again safe: an assignment whose
 * fingerprint is unchanged and whose days are already covered is left alone, a changed one is replaced, and none is
 * created twice.
 *
 * The row outlives its planning: when someone deletes the planning in Kimai, the link stays with an empty planning,
 * so the import remembers the deletion instead of bringing the planning back.
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
        #[ORM\JoinColumn(name: 'planned_activity_id', nullable: true, onDelete: 'SET NULL')]
        private ?PlannedActivity $plannedActivity,
        #[ORM\Column(name: 'part_begin', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $partBegin,
        #[ORM\Column(name: 'part_end', type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $partEnd,
        #[ORM\Column(name: 'fingerprint', type: Types::STRING, length: 40)]
        private string $fingerprint,
    ) {
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getForecastAssignmentId(): int
    {
        return (int) $this->forecastAssignmentId;
    }

    /**
     * Null when the planning has been deleted in Kimai since.
     */
    public function getPlannedActivity(): ?PlannedActivity
    {
        return $this->plannedActivity;
    }

    public function getPartBegin(): \DateTimeImmutable
    {
        return $this->partBegin;
    }

    public function getPartEnd(): \DateTimeImmutable
    {
        return $this->partEnd;
    }

    /**
     * After the import shortened the planning, the link describes what is left of it.
     */
    public function moveTo(\DateTimeImmutable $begin, \DateTimeImmutable $end): void
    {
        $this->partBegin = $begin;
        $this->partEnd = $end;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }
}
