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

/**
 * Whether a user works on a day. Forecast books only working days unless an assignment says otherwise, while a planning
 * in Kimai covers every day between its dates, so long assignments are cut into runs of working days.
 */
interface WorkDayCalendar
{
    public function isWorkDay(User $user, \DateTimeImmutable $day): bool;
}
