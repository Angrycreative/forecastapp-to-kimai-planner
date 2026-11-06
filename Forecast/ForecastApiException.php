<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Forecast;

/**
 * Anything that went wrong while reading Forecast, worded for the person running the import.
 */
final class ForecastApiException extends \RuntimeException
{
}
