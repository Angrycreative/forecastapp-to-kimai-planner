<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\EventSubscriber;

use App\Event\PermissionSectionsEvent;
use App\Model\PermissionSection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives the permission its own section on System > Role permissions.
 */
final class PermissionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PermissionSectionsEvent::class => ['onPermissionSections', 90],
        ];
    }

    public function onPermissionSections(PermissionSectionsEvent $event): void
    {
        $event->addSection(new PermissionSection('Forecast import', 'forecast_import'));
    }
}
