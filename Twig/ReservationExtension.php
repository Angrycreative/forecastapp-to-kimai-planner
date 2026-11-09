<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Twig;

use KimaiPlugin\ForecastImportBundle\Import\ReservationCatalogue;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ReservationExtension extends AbstractExtension
{
    public function __construct(private readonly ReservationCatalogue $reservations)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('forecast_import_reservation_name', $this->reservations->name(...)),
        ];
    }
}
