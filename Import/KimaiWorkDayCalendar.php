<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use App\Entity\User;
use App\WorkingTime\Calculator\WorkingTimeCalculator;
use App\WorkingTime\WorkingTimeService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Working days from the user's work contract in Kimai, minus public holidays when the installed planner knows them.
 *
 * The holiday provider is typed loosely on purpose: it only exists in planner versions with public holidays, and this
 * bundle must also run next to those without.
 */
final class KimaiWorkDayCalendar implements WorkDayCalendar, ResetInterface
{
    /** @var array<int, WorkingTimeCalculator> */
    private array $calculators = [];

    /** @var array<string, array<string, mixed>> holidays by user id and year */
    private array $holidays = [];

    public function __construct(
        private readonly WorkingTimeService $workingTimeService,
        private readonly ?object $holidayProvider = null,
    ) {
    }

    public function reset(): void
    {
        $this->calculators = [];
        $this->holidays = [];
    }

    public function isWorkDay(User $user, \DateTimeImmutable $day): bool
    {
        $calculator = $this->calculators[(int) $user->getId()] ??= $this->workingTimeService->getContractMode($user)->getCalculator($user);
        if ($calculator->getWorkHoursForDay($day) <= 0) {
            return false;
        }

        return !\array_key_exists($day->format('Y-m-d'), $this->holidaysOf($user, $day));
    }

    /**
     * @return array<string, mixed> keyed by Y-m-d
     */
    private function holidaysOf(User $user, \DateTimeImmutable $day): array
    {
        if ($this->holidayProvider === null || !method_exists($this->holidayProvider, 'getHolidays')) {
            return [];
        }

        $key = $user->getId() . '-' . $day->format('Y');

        return $this->holidays[$key] ??= (array) $this->holidayProvider->getHolidays(
            $user,
            new \DateTimeImmutable($day->format('Y') . '-01-01'),
            new \DateTimeImmutable($day->format('Y') . '-12-31'),
        );
    }
}
