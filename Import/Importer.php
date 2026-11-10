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
use KimaiPlugin\ForecastImportBundle\Entity\ImportedPlanning;
use KimaiPlugin\ForecastImportBundle\Repository\ImportedPlanningRepository;
use KimaiPlugin\PlannerBundle\Entity\PlannedActivity;

/**
 * Writes a plan into the Weekly Planner. Safe to run again:
 *
 * - an assignment imported before, unchanged in Forecast and in its mapping, whose days in the range are already
 *   covered, is left alone: edits made to its plannings in Kimai stay, and so does a planning deleted in Kimai;
 * - a changed assignment has its plannings in the range replaced, including halves someone split off in the planner;
 * - an assignment deleted in Forecast, or skipped since, loses its plannings in the range;
 * - an assignment that could not be resolved this time (unmapped) keeps whatever an earlier run created.
 *
 * Only the import range is touched: plannings before its start or after its end stay as they are, and one reaching
 * across a boundary is shortened to the part outside the range.
 */
class Importer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportedPlanningRepository $links,
        private readonly PlannerCapabilities $capabilities,
        private readonly ?ReservationCatalogue $reservations = null,
    ) {
    }

    public function import(Plan $plan, bool $dryRun): ImportResult
    {
        $result = new ImportResult($dryRun);
        $run = function () use ($plan, $result, $dryRun): void {
            $links = $this->links->findAllByAssignment();
            $unlinked = $this->links->findUnlinkedParts();
            $unresolved = array_flip($plan->unresolvedIds());

            foreach ($plan->assignments() as $id => $assignment) {
                $relevant = $this->inRange($links[$id] ?? [], $plan);
                $strays = $this->activitiesInRange($unlinked[$id] ?? [], $plan);
                // a split made in Kimai since (an unlinked half) is an edit like any other: it stays while Forecast does not change
                if ($relevant !== [] && $this->isUnchanged($relevant, $assignment)) {
                    ++$result->unchanged;
                    $result->statuses[$id] = ImportResult::UNCHANGED;

                    continue;
                }

                $new = $relevant === [] && $strays === [];
                $new ? ++$result->created : ++$result->updated;
                $result->statuses[$id] = $new ? ImportResult::CREATED : ImportResult::UPDATED;
                $this->clear($links[$id] ?? [], $unlinked[$id] ?? [], $plan, $result, $dryRun);
                $this->create($assignment, $result, $dryRun);
            }

            foreach (array_unique(array_merge(array_keys($links), array_keys($unlinked))) as $id) {
                if (isset($plan->assignments()[$id]) || isset($unresolved[$id])) {
                    continue;
                }
                $activities = array_merge(
                    array_filter(array_map(static fn (ImportedPlanning $link): ?PlannedActivity => $link->getPlannedActivity(), $links[$id] ?? [])),
                    $unlinked[$id] ?? []
                );
                foreach ($this->activitiesInRange($activities, $plan) as $activity) {
                    $result->removedPlannings[] = $this->describe($id, $activity, $plan);
                }
                $before = $result->planningsRemoved + $result->planningsShortened;
                $this->clear($links[$id] ?? [], $unlinked[$id] ?? [], $plan, $result, $dryRun);
                if ($result->planningsRemoved + $result->planningsShortened > $before) {
                    ++$result->removed;
                    $result->statuses[$id] = ImportResult::REMOVED;
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }
        };

        if ($dryRun) {
            $run();
        } else {
            $this->entityManager->wrapInTransaction($run);
        }

        return $result;
    }

    /**
     * Removes every imported planning, including halves split off in the planner, for starting over. Plannings made
     * in Kimai are not touched, and neither are the mapping decisions.
     */
    public function removeAll(): int
    {
        $count = 0;
        $this->entityManager->wrapInTransaction(function () use (&$count): void {
            foreach ($this->links->findAllByAssignment() as $existing) {
                foreach ($existing as $link) {
                    if ($link->getPlannedActivity() !== null) {
                        $this->entityManager->remove($link->getPlannedActivity());
                        ++$count;
                    }
                    $this->entityManager->remove($link);
                }
            }
            foreach ($this->links->findUnlinkedParts() as $activities) {
                foreach ($activities as $activity) {
                    $this->entityManager->remove($activity);
                    ++$count;
                }
            }
            $this->entityManager->flush();
        });

        return $count;
    }

    /**
     * Links that lie at least partly within the import range, by the dates the import gave them; older ones are
     * history and play no part.
     *
     * @param list<ImportedPlanning> $links
     * @return list<ImportedPlanning>
     */
    private function inRange(array $links, Plan $plan): array
    {
        return array_values(array_filter($links, static fn (ImportedPlanning $link): bool => $link->getPartEnd() >= $plan->begin && $link->getPartBegin() <= $plan->end));
    }

    /**
     * @param list<PlannedActivity> $activities
     * @return list<PlannedActivity>
     */
    private function activitiesInRange(array $activities, Plan $plan): array
    {
        return array_values(array_filter($activities, static fn (PlannedActivity $activity): bool => $activity->getEnd() !== null && $activity->getBegin() !== null
            && $activity->getEnd()->format('Y-m-d') >= $plan->begin->format('Y-m-d') && $activity->getBegin()->format('Y-m-d') <= $plan->end->format('Y-m-d')));
    }

    /**
     * Unchanged when the assignment and its mapping are as they were, and every day it needs in the range was planned
     * by an earlier import, whatever has happened to those plannings in Kimai since.
     *
     * @param list<ImportedPlanning> $relevant
     */
    private function isUnchanged(array $relevant, PlannedAssignment $assignment): bool
    {
        $covered = [];
        foreach ($relevant as $link) {
            if ($link->getFingerprint() !== $assignment->fingerprint) {
                return false;
            }
            for ($day = $link->getPartBegin(); $day <= $link->getPartEnd(); $day = $day->modify('+1 day')) {
                $covered[$day->format('Y-m-d')] = true;
            }
        }
        foreach ($assignment->parts as $part) {
            for ($day = $part->begin; $day <= $part->end; $day = $day->modify('+1 day')) {
                if (!isset($covered[$day->format('Y-m-d')])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Takes earlier plannings of an assignment out of the import range: those the import linked, links whose planning
     * was deleted in Kimai, and unlinked halves split off in the planner.
     *
     * @param list<ImportedPlanning> $links
     * @param list<PlannedActivity> $unlinked
     */
    private function clear(array $links, array $unlinked, Plan $plan, ImportResult $result, bool $dryRun): void
    {
        foreach ($links as $link) {
            $activity = $link->getPlannedActivity();
            if ($activity === null) {
                // deleted in Kimai: forget the parts of it that lie in the range
                $outside = $this->outside($link->getPartBegin(), $link->getPartEnd(), $plan);
                if ($dryRun || $outside === null) {
                    continue;
                }
                if ($outside === []) {
                    $this->entityManager->remove($link);
                } else {
                    $link->moveTo($outside[0][0], $outside[0][1]);
                    $this->entityManager->persist($link);
                }

                continue;
            }
            $this->cut($activity, $link, $plan, $result, $dryRun);
        }
        foreach ($unlinked as $activity) {
            $this->cut($activity, null, $plan, $result, $dryRun);
        }
    }

    /**
     * Removes the part of a planning within the import range, or the whole planning when nothing is left outside it.
     */
    private function cut(PlannedActivity $activity, ?ImportedPlanning $link, Plan $plan, ImportResult $result, bool $dryRun): void
    {
        if ($activity->getBegin() === null || $activity->getEnd() === null) {
            return;
        }
        $outside = $this->outside(
            new \DateTimeImmutable($activity->getBegin()->format('Y-m-d')),
            new \DateTimeImmutable($activity->getEnd()->format('Y-m-d')),
            $plan
        );
        if ($outside === null) {
            return;
        }
        if ($outside === []) {
            ++$result->planningsRemoved;
            if (!$dryRun) {
                if ($link !== null) {
                    $this->entityManager->remove($link);
                }
                $this->entityManager->remove($activity);
            }

            return;
        }

        ++$result->planningsShortened;
        if ($dryRun) {
            return;
        }
        [$first] = $outside;
        if (isset($outside[1])) {
            $after = $this->copy($activity);
            $after->setBegin(new \DateTime($outside[1][0]->format('Y-m-d')));
            $after->setEnd(new \DateTime($outside[1][1]->format('Y-m-d')));
            $this->entityManager->persist($after);
            if ($link !== null) {
                $this->entityManager->persist(new ImportedPlanning($link->getForecastAssignmentId(), $after, $outside[1][0], $outside[1][1], $link->getFingerprint()));
            }
        }
        $activity->setBegin(new \DateTime($first[0]->format('Y-m-d')));
        $activity->setEnd(new \DateTime($first[1]->format('Y-m-d')));
        $this->entityManager->persist($activity);
        if ($link !== null) {
            $link->moveTo($first[0], $first[1]);
            $this->entityManager->persist($link);
        }
    }

    /**
     * What remains of a date range once the import range is taken out of it: null when they do not overlap, an empty
     * list when nothing remains, otherwise the part before and/or the part after the import range.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|null
     */
    private function outside(\DateTimeImmutable $begin, \DateTimeImmutable $end, Plan $plan): ?array
    {
        if ($end < $plan->begin || $begin > $plan->end) {
            return null;
        }
        $remaining = [];
        if ($begin < $plan->begin) {
            $remaining[] = [$begin, $plan->begin->modify('-1 day')];
        }
        if ($end > $plan->end) {
            $remaining[] = [$plan->end->modify('+1 day'), $end];
        }

        return $remaining;
    }

    private function create(PlannedAssignment $assignment, ImportResult $result, bool $dryRun): void
    {
        $result->planningsCreated += \count($assignment->parts);
        if ($dryRun) {
            return;
        }

        foreach ($assignment->parts as $part) {
            $activity = new PlannedActivity();
            $activity->setUser($part->user);
            $activity->setBegin(new \DateTime($part->begin->format('Y-m-d')));
            $activity->setEnd(new \DateTime($part->end->format('Y-m-d')));
            if ($part->timeOff) {
                $activity->setTimeOff(true);
            } elseif ($part->reservation !== null) {
                // the plan only holds reservations that exist, see PlanBuilder
                $activity->setReservation($this->reservations?->find($part->reservation));
            } else {
                $activity->setCustomer($part->customer);
                $activity->setProject($part->project);
                if ($part->tentative && $this->capabilities->supportsTentative()) {
                    $activity->setTentative(true);
                }
            }
            $activity->setHoursPerDay($part->hoursPerDay);
            $activity->setComment($part->comment);
            $activity->setRecurrenceGroup($assignment->recurrenceGroup());

            $this->entityManager->persist($activity);
            $this->entityManager->persist(new ImportedPlanning($assignment->forecastId, $activity, $part->begin, $part->end, $assignment->fingerprint));
        }
    }

    /**
     * A planning as the preview lists it when it is removed, with only the dates the import takes away.
     *
     * @return array{assignment: int, userId: ?int, user: string, label: string, begin: \DateTimeInterface, end: \DateTimeInterface, hoursPerDay: ?float, timeOff: bool}
     */
    private function describe(int $id, PlannedActivity $activity, Plan $plan): array
    {
        $begin = max(new \DateTimeImmutable((string) $activity->getBegin()?->format('Y-m-d')), $plan->begin);
        $end = min(new \DateTimeImmutable((string) $activity->getEnd()?->format('Y-m-d')), $plan->end);

        return [
            'assignment' => $id,
            'userId' => $activity->getUser()?->getId(),
            'user' => (string) $activity->getUser()?->getDisplayName(),
            'label' => $this->label($activity),
            'begin' => $begin,
            'end' => $end,
            'hoursPerDay' => $activity->getHoursPerDay(),
            'timeOff' => $this->capabilities->supportsTimeOff() && $activity->isTimeOff(),
        ];
    }

    private function label(PlannedActivity $activity): string
    {
        if ($this->capabilities->supportsReservations() && $activity->getReservation() !== null) {
            return $this->reservations?->name($activity->getReservation()) ?? $activity->getReservation();
        }

        return $activity->getProject() !== null
            ? $activity->getCustomer()?->getName() . ' / ' . $activity->getProject()->getName()
            : (string) ($activity->getCustomer()?->getName() ?? '');
    }

    private function copy(PlannedActivity $activity): PlannedActivity
    {
        $copy = new PlannedActivity();
        $copy->setUser($activity->getUser());
        $copy->setCustomer($activity->getCustomer());
        $copy->setProject($activity->getProject());
        $copy->setHoursPerDay($activity->getHoursPerDay());
        $copy->setComment($activity->getComment());
        $copy->setRecurrenceGroup($activity->getRecurrenceGroup());
        $copy->setColor($activity->getColor());
        if ($this->capabilities->supportsTimeOff()) {
            $copy->setTimeOff($activity->isTimeOff());
        }
        if ($this->capabilities->supportsTentative()) {
            $copy->setTentative($activity->isTentative());
        }
        if ($this->capabilities->supportsReservations()) {
            $copy->setReservation($activity->getReservation());
        }

        return $copy;
    }
}
