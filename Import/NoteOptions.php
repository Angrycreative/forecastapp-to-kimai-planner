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
 * Which Forecast notes go into the comments of the imported plannings, next to a mapping's own comment. By default the
 * notes written on each assignment do and a project's own notes, often budget remarks, do not.
 */
final class NoteOptions
{
    public function __construct(
        public readonly bool $projectNotes = false,
        public readonly bool $assignmentNotes = true,
    ) {
    }
}
