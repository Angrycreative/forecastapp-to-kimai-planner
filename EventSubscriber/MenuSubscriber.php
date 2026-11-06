<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * "Forecast import" under System, for those allowed to run it.
 */
final class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConfigureMainMenuEvent::class => ['onMenuConfigure', 90],
        ];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        if (!$this->security->isGranted('forecast_import')) {
            return;
        }

        $item = new MenuItemModel('forecast_import', 'forecast_import.title', 'forecast_import', [], 'fas fa-file-import');
        $item->setChildRoutes([
            'forecast_import_people', 'forecast_import_projects', 'forecast_import_preview',
            'forecast_import_edit_person', 'forecast_import_edit_project', 'forecast_import_tentative',
        ]);
        $event->getSystemMenu()->addChild($item);
    }
}
