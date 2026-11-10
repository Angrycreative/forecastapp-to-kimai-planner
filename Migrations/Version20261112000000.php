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

final class Version20261112000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forecast import: a project mapped to reserved time refers to the planner reservation by its ID';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_forecast_import_mapping')) {
            return;
        }

        // no foreign key: plugins install in alphabetical order, so the planner's table may not exist yet. The old
        // reservation_key column stays until the reservations it names have been moved to the planner.
        $table = $schema->getTable('kimai2_forecast_import_mapping');
        if (!$table->hasColumn('reservation_id')) {
            $table->addColumn('reservation_id', 'integer', ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_forecast_import_mapping')) {
            return;
        }

        $table = $schema->getTable('kimai2_forecast_import_mapping');
        if ($table->hasColumn('reservation_id')) {
            $table->dropColumn('reservation_id');
        }
    }
}
