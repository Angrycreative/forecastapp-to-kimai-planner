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

final class Version20261111000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forecast import: a project mapping can carry a comment for the plannings imported through it';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_forecast_import_mapping')) {
            return;
        }

        $table = $schema->getTable('kimai2_forecast_import_mapping');
        if (!$table->hasColumn('comment')) {
            $table->addColumn('comment', 'text', ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_forecast_import_mapping')) {
            return;
        }

        $table = $schema->getTable('kimai2_forecast_import_mapping');
        if ($table->hasColumn('comment')) {
            $table->dropColumn('comment');
        }
    }
}
