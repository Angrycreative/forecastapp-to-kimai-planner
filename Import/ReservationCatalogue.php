<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

/**
 * The Weekly Planner's reserved time (hours held for something that is not a customer or project), as far as the
 * import needs it. The planner has none of its own: another plugin offers them. Older planners know nothing of
 * reserved time, so everything here works without it and then offers none.
 */
class ReservationCatalogue
{
    /**
     * @param object|null $reservations the planner's KimaiPlugin\PlannerBundle\Reservation\PlanningReservations, when it has one
     */
    public function __construct(
        private readonly PlannerCapabilities $capabilities,
        private readonly ?object $reservations = null,
    ) {
    }

    public function isSupported(): bool
    {
        return $this->reservations !== null && $this->capabilities->supportsReservations();
    }

    /**
     * The reservations that can be chosen for new planning.
     *
     * @return array<string, string> name by key, in the planner's order
     */
    public function selectable(): array
    {
        if (!$this->isSupported()) {
            return [];
        }

        $names = [];
        /** @phpstan-ignore method.notFound */
        foreach ($this->reservations->getSelectable() as $key => $reservation) {
            $names[(string) $key] = (string) $reservation->name;
        }

        return $names;
    }

    /**
     * Whether the planner knows the key, archived (hidden) ones included.
     */
    public function exists(string $key): bool
    {
        /** @phpstan-ignore method.notFound */
        return $this->isSupported() && $this->reservations->get($key) !== null;
    }

    /**
     * The name to show, the key itself for one the planner does not know.
     */
    public function name(string $key): string
    {
        /** @phpstan-ignore method.notFound */
        return $this->isSupported() ? ($this->reservations->get($key)?->name ?? $key) : $key;
    }

    /**
     * The selectable reservation whose name is the Forecast project's, ignoring case and surrounding spaces.
     */
    public function keyByName(string $name): ?string
    {
        $wanted = mb_strtolower(trim($name));
        foreach ($this->selectable() as $key => $reservationName) {
            if (mb_strtolower(trim($reservationName)) === $wanted) {
                return $key;
            }
        }

        return null;
    }
}
