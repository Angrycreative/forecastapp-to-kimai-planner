<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Entity;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\ForecastImportBundle\Import\Target;

/**
 * One decision made while preparing the import: where a Forecast person, placeholder or project goes, or whether a
 * project tag means tentative work. Kept between runs, so importing again picks up where the last run stopped.
 */
#[ORM\Table(name: 'kimai2_forecast_import_mapping')]
#[ORM\UniqueConstraint(name: 'UNIQ_FORECAST_IMPORT_MAPPING', columns: ['kind', 'forecast_key'])]
#[ORM\Index(columns: ['user_id'], name: 'IDX_FORECAST_IMPORT_MAPPING_USER')]
#[ORM\Index(columns: ['customer_id'], name: 'IDX_FORECAST_IMPORT_MAPPING_CUSTOMER')]
#[ORM\Index(columns: ['project_id'], name: 'IDX_FORECAST_IMPORT_MAPPING_PROJECT')]
#[ORM\Entity(repositoryClass: \KimaiPlugin\ForecastImportBundle\Repository\MappingRepository::class)]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
class Mapping
{
    public const PERSON = 'person';
    public const PLACEHOLDER = 'placeholder';
    public const PROJECT = 'project';
    public const TAG = 'tag';

    /** The tag marks tentative work. */
    public const TENTATIVE = 'tentative';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'action', type: Types::STRING, length: 20)]
    private string $action = Target::SKIP;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(name: 'kind', type: Types::STRING, length: 20)]
        private string $kind,
        #[ORM\Column(name: 'forecast_key', type: Types::STRING, length: 150)]
        private string $forecastKey,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getForecastKey(): string
    {
        return $this->forecastKey;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function apply(Target $target): void
    {
        $this->action = $target->kind;
        $this->user = $target->user;
        $this->customer = $target->customer;
        $this->project = $target->project;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markTentative(bool $tentative): void
    {
        $this->action = $tentative ? self::TENTATIVE : Target::SKIP;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * The decision as a target, or null when what it pointed at has been deleted in Kimai since.
     */
    public function toTarget(): ?Target
    {
        return match ($this->action) {
            Target::USER => $this->user === null ? null : Target::user($this->user),
            Target::WORK => $this->customer === null ? null : Target::work($this->customer, $this->project?->getCustomer() === $this->customer ? $this->project : null),
            Target::TIME_OFF => Target::timeOff(),
            Target::SKIP => Target::skip(),
            default => null,
        };
    }
}
