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

final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forecast import: the Harvest token, kept encrypted when someone chooses to save it';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_forecast_import_token')) {
            return;
        }

        $table = $schema->createTable('kimai2_forecast_import_token');
        $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('account_id', 'string', ['length' => 32, 'notnull' => true]);
        $table->addColumn('encrypted', 'text', ['notnull' => true]);
        $table->addColumn('saved_at', 'datetime_immutable', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_forecast_import_token')) {
            $schema->dropTable('kimai2_forecast_import_token');
        }
    }
}
