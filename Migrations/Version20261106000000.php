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

final class Version20261106000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forecast import: the last Forecast snapshot, the mapping decisions and the link from each imported planning to its Forecast assignment';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_forecast_import_snapshot')) {
            $table = $schema->createTable('kimai2_forecast_import_snapshot');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('account_id', 'string', ['length' => 32, 'notnull' => true]);
            $table->addColumn('fetched_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('payload', 'text', ['length' => 4294967295, 'notnull' => true]);
            $table->setPrimaryKey(['id']);
        }

        if (!$schema->hasTable('kimai2_forecast_import_mapping')) {
            $table = $schema->createTable('kimai2_forecast_import_mapping');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('kind', 'string', ['length' => 20, 'notnull' => true]);
            $table->addColumn('forecast_key', 'string', ['length' => 150, 'notnull' => true]);
            $table->addColumn('action', 'string', ['length' => 20, 'notnull' => true]);
            $table->addColumn('user_id', 'integer', ['notnull' => false]);
            $table->addColumn('customer_id', 'integer', ['notnull' => false]);
            $table->addColumn('project_id', 'integer', ['notnull' => false]);
            $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['kind', 'forecast_key'], 'UNIQ_FORECAST_IMPORT_MAPPING');
            $table->addIndex(['user_id'], 'IDX_FORECAST_IMPORT_MAPPING_USER');
            $table->addIndex(['customer_id'], 'IDX_FORECAST_IMPORT_MAPPING_CUSTOMER');
            $table->addIndex(['project_id'], 'IDX_FORECAST_IMPORT_MAPPING_PROJECT');
            $table->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_FORECAST_IMPORT_MAPPING_USER');
            $table->addForeignKeyConstraint('kimai2_customers', ['customer_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_FORECAST_IMPORT_MAPPING_CUSTOMER');
            $table->addForeignKeyConstraint('kimai2_projects', ['project_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_FORECAST_IMPORT_MAPPING_PROJECT');
        }

        if (!$schema->hasTable('kimai2_forecast_import_planning')) {
            $table = $schema->createTable('kimai2_forecast_import_planning');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('forecast_assignment_id', 'bigint', ['notnull' => true]);
            $table->addColumn('planned_activity_id', 'integer', ['notnull' => true]);
            $table->addColumn('fingerprint', 'string', ['length' => 40, 'notnull' => true]);
            $table->addColumn('imported_at', 'datetime_immutable', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['forecast_assignment_id'], 'IDX_FORECAST_IMPORT_ASSIGNMENT');
            $table->addUniqueIndex(['planned_activity_id'], 'UNIQ_FORECAST_IMPORT_ACTIVITY');
            $table->addForeignKeyConstraint('kimai2_planned_activities', ['planned_activity_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_FORECAST_IMPORT_ACTIVITY');
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['kimai2_forecast_import_planning', 'kimai2_forecast_import_mapping', 'kimai2_forecast_import_snapshot'] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
}
