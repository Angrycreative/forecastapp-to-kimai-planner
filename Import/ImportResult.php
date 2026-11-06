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
 * What an import did, or with a dry run would do, counted in Forecast assignments and in plannings.
 */
final class ImportResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $removed = 0;
    public int $planningsCreated = 0;
    public int $planningsRemoved = 0;
    public int $planningsShortened = 0;

    public function __construct(public readonly bool $dryRun)
    {
    }

    public function hasChanges(): bool
    {
        return $this->created + $this->updated + $this->removed > 0;
    }
}
