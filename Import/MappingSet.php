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
 * The decisions for one import: where each Forecast person, placeholder and project goes, and which project tags mean
 * tentative work. Anything missing here is unmapped.
 */
final class MappingSet
{
    /**
     * @param array<int, Target> $people        by Forecast person id: a user or skip
     * @param array<int, Target> $placeholders  by Forecast placeholder id: a user or skip
     * @param array<int, Target> $projects      by Forecast project id: work, time off or skip
     * @param list<string> $tentativeTags       project tags that make an assignment tentative
     */
    public function __construct(
        public readonly array $people = [],
        public readonly array $placeholders = [],
        public readonly array $projects = [],
        public readonly array $tentativeTags = [],
    ) {
    }

    public function person(int $id): ?Target
    {
        return $this->people[$id] ?? null;
    }

    public function placeholder(int $id): ?Target
    {
        return $this->placeholders[$id] ?? null;
    }

    public function project(int $id): ?Target
    {
        return $this->projects[$id] ?? null;
    }

    /**
     * @param array<mixed> $tags
     */
    public function isTentative(array $tags): bool
    {
        foreach ($tags as $tag) {
            foreach ($this->tentativeTags as $tentative) {
                if (strcasecmp(trim((string) $tag), trim($tentative)) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
