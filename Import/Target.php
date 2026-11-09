<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;

/**
 * Where a Forecast person, placeholder or project goes in Kimai: a user, a customer (and optionally a project), time
 * off, reserved time (the planner's reservations, by key), or nowhere.
 */
final class Target
{
    public const USER = 'user';
    public const WORK = 'work';
    public const TIME_OFF = 'time_off';
    public const RESERVATION = 'reservation';
    public const SKIP = 'skip';

    private function __construct(
        public readonly string $kind,
        public readonly ?User $user = null,
        public readonly ?Customer $customer = null,
        public readonly ?Project $project = null,
        public readonly bool $automatic = false,
        public readonly ?string $reservation = null,
    ) {
    }

    public static function user(User $user, bool $automatic = false): self
    {
        return new self(self::USER, user: $user, automatic: $automatic);
    }

    public static function work(Customer $customer, ?Project $project = null): self
    {
        if ($project !== null && $project->getCustomer() !== $customer) {
            throw new \InvalidArgumentException('The project belongs to another customer.');
        }

        return new self(self::WORK, customer: $customer, project: $project);
    }

    public static function timeOff(bool $automatic = false): self
    {
        return new self(self::TIME_OFF, automatic: $automatic);
    }

    /**
     * @param string $reservation the key of one of the planner's reservations
     */
    public static function reservation(string $reservation, bool $automatic = false): self
    {
        if ($reservation === '') {
            throw new \InvalidArgumentException('A reservation needs its key.');
        }

        return new self(self::RESERVATION, automatic: $automatic, reservation: $reservation);
    }

    public static function skip(): self
    {
        return new self(self::SKIP);
    }

    public function isSkip(): bool
    {
        return $this->kind === self::SKIP;
    }
}
