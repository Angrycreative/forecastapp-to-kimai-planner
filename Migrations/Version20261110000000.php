<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261110000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forecast import: a Forecast project can be mapped to reserved time (a reservation key of the Weekly Planner)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_forecast_import_mapping')) {
            return;
        }

        $table = $schema->getTable('kimai2_forecast_import_mapping');
        if (!$table->hasColumn('reservation_key')) {
            $table->addColumn('reservation_key', 'string', ['length' => 64, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_forecast_import_mapping')) {
            return;
        }

        $table = $schema->getTable('kimai2_forecast_import_mapping');
        if ($table->hasColumn('reservation_key')) {
            $table->dropColumn('reservation_key');
        }
    }
}
