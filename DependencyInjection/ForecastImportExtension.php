<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\DependencyInjection;

use App\Plugin\AbstractPluginExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class ForecastImportExtension extends AbstractPluginExtension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    /**
     * One permission, held by super admins by default because importing writes planning for everyone. Any role can be
     * given it under System > Role permissions. The name ends in "_planner" so it is listed in the Weekly Planner's
     * section there, next to the permissions of the planner it writes to.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'sets' => [
                    'FORECAST_IMPORT' => ['import_forecast_planner'],
                ],
                'roles' => [
                    'ROLE_SUPER_ADMIN' => ['import_forecast_planner'],
                ],
            ],
        ]);
    }
}
