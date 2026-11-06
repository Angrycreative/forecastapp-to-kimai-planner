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
 * - an assignment imported before with the same result is left alone, including any edits made to it in Kimai since;
 * - a changed assignment has its earlier plannings replaced;
 * - an assignment deleted in Forecast, or skipped since, loses its plannings;
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
    ) {
    }

    public function import(Plan $plan, bool $dryRun): ImportResult
    {
        $result = new ImportResult($dryRun);
        $run = function () use ($plan, $result, $dryRun): void {
            $links = $this->links->findAllByAssignment();
            $unresolved = array_flip($plan->unresolvedIds());

            foreach ($plan->assignments() as $id => $assignment) {
                $existing = $links[$id] ?? [];
                $inRange = $this->inRange($existing, $plan);
                if ($inRange !== [] && $this->isUnchanged($inRange, $assignment->fingerprint)) {
                    ++$result->unchanged;
                    $result->statuses[$id] = ImportResult::UNCHANGED;

                    continue;
                }
                $inRange === [] ? ++$result->created : ++$result->updated;
                $result->statuses[$id] = $inRange === [] ? ImportResult::CREATED : ImportResult::UPDATED;
                $this->clear($existing, $plan, $result, $dryRun);
                $this->create($assignment, $result, $dryRun);
            }

            foreach ($links as $id => $existing) {
                if (isset($plan->assignments()[$id]) || isset($unresolved[$id])) {
                    continue;
                }
                $before = $result->planningsRemoved + $result->planningsShortened;
                foreach ($this->inRange($existing, $plan) as $link) {
                    $activity = $link->getPlannedActivity();
                    $result->removedPlannings[] = [
                        'assignment' => $id,
                        'user' => (string) $activity->getUser()?->getDisplayName(),
                        'label' => $activity->getProject() !== null
                            ? $activity->getCustomer()?->getName() . ' / ' . $activity->getProject()->getName()
                            : (string) ($activity->getCustomer()?->getName() ?? ''),
                        'begin' => $activity->getBegin(),
                        'end' => $activity->getEnd(),
                        'hoursPerDay' => $activity->getHoursPerDay(),
                        'timeOff' => $this->capabilities->supportsTimeOff() && $activity->isTimeOff(),
                    ];
                }
                $this->clear($existing, $plan, $result, $dryRun);
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
     * Removes every imported planning, for starting over. Plannings made in Kimai are not touched.
     */
    public function removeAll(): int
    {
        $count = 0;
        $this->entityManager->wrapInTransaction(function () use (&$count): void {
            foreach ($this->links->findAllByAssignment() as $existing) {
                foreach ($existing as $link) {
                    $this->entityManager->remove($link);
                    $this->entityManager->remove($link->getPlannedActivity());
                    ++$count;
                }
            }
            $this->entityManager->flush();
        });

        return $count;
    }

    /**
     * The links whose planning lies at least partly within the import range; older ones are history and stay.
     *
     * @param list<ImportedPlanning> $existing
     * @return list<ImportedPlanning>
     */
    private function inRange(array $existing, Plan $plan): array
    {
        $rangeBegin = $plan->begin->format('Y-m-d');
        $rangeEnd = $plan->end->format('Y-m-d');

        return array_values(array_filter($existing, static function (ImportedPlanning $link) use ($rangeBegin, $rangeEnd): bool {
            $activity = $link->getPlannedActivity();

            return $activity->getEnd()?->format('Y-m-d') >= $rangeBegin && $activity->getBegin()?->format('Y-m-d') <= $rangeEnd;
        }));
    }

    /**
     * @param list<ImportedPlanning> $existing
     */
    private function isUnchanged(array $existing, string $fingerprint): bool
    {
        foreach ($existing as $link) {
            if ($link->getFingerprint() !== $fingerprint) {
                return false;
            }
        }

        return true;
    }

    /**
     * Takes earlier plannings of an assignment out of the import range.
     *
     * @param list<ImportedPlanning> $existing
     */
    private function clear(array $existing, Plan $plan, ImportResult $result, bool $dryRun): void
    {
        $rangeBegin = $plan->begin->format('Y-m-d');
        $rangeEnd = $plan->end->format('Y-m-d');

        foreach ($existing as $link) {
            $activity = $link->getPlannedActivity();
            $begin = $activity->getBegin()?->format('Y-m-d');
            $end = $activity->getEnd()?->format('Y-m-d');
            if ($begin === null || $end === null || $end < $rangeBegin || $begin > $rangeEnd) {
                continue;
            }

            $keepBefore = $begin < $rangeBegin;
            $keepAfter = $end > $rangeEnd;
            if (!$keepBefore && !$keepAfter) {
                ++$result->planningsRemoved;
                if (!$dryRun) {
                    $this->entityManager->remove($link);
                    $this->entityManager->remove($activity);
                }

                continue;
            }

            ++$result->planningsShortened;
            if ($dryRun) {
                continue;
            }
            if ($keepBefore && $keepAfter) {
                $after = $this->copy($activity);
                $after->setBegin(new \DateTime($plan->end->modify('+1 day')->format('Y-m-d')));
                $this->entityManager->persist($after);
                $this->entityManager->persist(new ImportedPlanning($link->getForecastAssignmentId(), $after, $link->getFingerprint()));
            }
            if ($keepBefore) {
                $activity->setEnd(new \DateTime($plan->begin->modify('-1 day')->format('Y-m-d')));
            } else {
                $activity->setBegin(new \DateTime($plan->end->modify('+1 day')->format('Y-m-d')));
            }
            $this->entityManager->persist($activity);
        }
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
            $this->entityManager->persist(new ImportedPlanning($assignment->forecastId, $activity, $assignment->fingerprint));
        }
    }

    private function copy(PlannedActivity $activity): PlannedActivity
    {
        $copy = new PlannedActivity();
        $copy->setUser($activity->getUser());
        $copy->setBegin($activity->getBegin());
        $copy->setEnd($activity->getEnd());
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

        return $copy;
    }
}
