<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Form;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use KimaiPlugin\ForecastImportBundle\Import\Target;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the mapping dialogs edit.
 */
final class MappingData
{
    public ?string $action = null;
    public ?User $user = null;
    public ?Customer $customer = null;
    public ?Project $project = null;
    public ?string $reservation = null;

    public static function fromTarget(?Target $target, string $default): self
    {
        $data = new self();
        $data->action = $target?->kind ?? $default;
        $data->user = $target?->user;
        $data->customer = $target?->customer;
        $data->project = $target?->project;
        $data->reservation = $target?->reservation;

        return $data;
    }

    public function toTarget(): Target
    {
        return match ($this->action) {
            Target::USER => Target::user($this->user ?? throw new \LogicException('validated')),
            Target::WORK => Target::work($this->customer ?? throw new \LogicException('validated'), $this->project),
            Target::TIME_OFF => Target::timeOff(),
            Target::RESERVATION => Target::reservation($this->reservation ?? throw new \LogicException('validated')),
            default => Target::skip(),
        };
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->action === Target::USER && $this->user === null) {
            $context->buildViolation('forecast_import.choose_user')->atPath('user')->setTranslationDomain('messages')->addViolation();
        }
        if ($this->action === Target::WORK && $this->customer === null) {
            $context->buildViolation('forecast_import.choose_customer')->atPath('customer')->setTranslationDomain('messages')->addViolation();
        }
        if ($this->action === Target::RESERVATION && ($this->reservation === null || $this->reservation === '')) {
            $context->buildViolation('forecast_import.choose_reservation')->atPath('reservation')->setTranslationDomain('messages')->addViolation();
        }
        if ($this->action === Target::WORK && $this->project !== null && $this->project->getCustomer() !== $this->customer) {
            $context->buildViolation('forecast_import.project_of_other_customer')->atPath('project')->setTranslationDomain('messages')->addViolation();
        }
    }
}
