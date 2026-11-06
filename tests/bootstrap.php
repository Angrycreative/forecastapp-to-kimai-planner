<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Kimai supplies its own classes and Symfony; the bundle and the planner are loaded from their directories.
$kimai = getenv('KIMAI_DIR') ?: dirname(__DIR__, 2) . '/kimai';
if (!is_file($kimai . '/vendor/autoload.php')) {
    fwrite(\STDERR, "Set KIMAI_DIR to a Kimai checkout with its vendor directory installed.\n");
    exit(1);
}
require $kimai . '/vendor/autoload.php';

$namespaces = [
    'KimaiPlugin\\ForecastImportBundle\\Tests\\' => __DIR__,
    'KimaiPlugin\\ForecastImportBundle\\' => dirname(__DIR__),
    'KimaiPlugin\\PlannerBundle\\' => $kimai . '/var/plugins/PlannerBundle',
];
spl_autoload_register(static function (string $class) use ($namespaces): void {
    foreach ($namespaces as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . '/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
