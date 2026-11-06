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
 * A Kimai project as the suggester sees it: ids and names only, so suggestions are computed without entities.
 */
final class ProjectCandidate
{
    public function __construct(
        public readonly int $customerId,
        public readonly string $customerName,
        public readonly int $projectId,
        public readonly string $projectName,
    ) {
    }
}
