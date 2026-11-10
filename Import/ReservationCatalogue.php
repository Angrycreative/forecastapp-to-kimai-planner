<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The Weekly Planner's reserved time (hours held for something that is not a customer or project), built into the
 * planner since 2.2.0. The import refers to a reservation by its ID as text, the "key" everywhere else in the import:
 * mappings, plan parts and fingerprints. Older planners have no reserved time, so everything here works without it
 * and then offers none.
 */
class ReservationCatalogue
{
    /**
     * @param object|null $reservations the planner's KimaiPlugin\PlannerBundle\Repository\ReservationRepository, when it has one
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
     * @return array<string, string> name by key (the ID as text), in the planner's order
     */
    public function selectable(): array
    {
        if (!$this->isSupported()) {
            return [];
        }

        $names = [];
        /** @phpstan-ignore method.notFound */
        foreach ($this->reservations->findSelectable() as $reservation) {
            $names[(string) $reservation->getId()] = (string) $reservation->getName();
        }

        return $names;
    }

    /**
     * Whether the planner knows the key, archived ones included.
     */
    public function exists(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * The name to show, the key itself for one the planner does not know.
     */
    public function name(string $key): string
    {
        /** @phpstan-ignore method.notFound */
        return (string) ($this->find($key)?->getName() ?? $key);
    }

    /**
     * The planner's reservation for a key, to put on a planning; null when it does not exist (any more).
     */
    public function find(string $key): ?object
    {
        if (!$this->isSupported() || !ctype_digit($key)) {
            return null;
        }

        /** @phpstan-ignore method.notFound */
        return $this->reservations->find((int) $key);
    }

    /**
     * What an assignment's fingerprint holds for its reservation: the name as a slug ("Agency buffer" becomes
     * "agency-buffer"), not the ID, so the fingerprint means the same in every installation, and is what the
     * reservation's key was before reserved time was built into the planner, so those imports stay unchanged. A
     * reservation the planner does not know keeps its key.
     */
    public function fingerprintKey(string $key): string
    {
        /** @phpstan-ignore method.notFound */
        $name = $this->find($key)?->getName();
        if ($name === null || $name === '') {
            return $key;
        }

        $slug = (new AsciiSlugger())->slug((string) $name)->lower()->toString();

        return $slug === '' ? $key : substr($slug, 0, 64);
    }

    /**
     * The selectable reservation whose name is the Forecast project's, ignoring case and surrounding spaces.
     */
    public function keyByName(string $name): ?string
    {
        $wanted = mb_strtolower(trim($name));
        foreach ($this->selectable() as $key => $reservationName) {
            if (mb_strtolower(trim($reservationName)) === $wanted) {
                return (string) $key;
            }
        }

        return null;
    }
}
