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

/**
 * Links each imported planning to the planner's table, for installs where this plugin's tables were created first.
 *
 * Kimai installs plugins in alphabetical order, so on a fresh install this plugin's migrations run before the
 * Weekly Planner has created its table. Until the planner's table exists this migration stops without being recorded,
 * so the next "kimai:plugins --install" (or the next deploy) runs it again and adds the key.
 */
final class Version20261109000000 extends AbstractMigration
{
    private const LINKS = 'kimai2_forecast_import_planning';
    private const PLANNINGS = 'kimai2_planned_activities';
    private const KEY = 'FK_FORECAST_IMPORT_ACTIVITY';

    public function getDescription(): string
    {
        return 'Forecast import: link imported plannings to the Weekly Planner once its table exists';
    }

    public function preUp(Schema $schema): void
    {
        parent::preUp($schema);

        if (!$this->needsKey($schema)) {
            return;
        }

        $this->abortIf(
            !$schema->hasTable(self::PLANNINGS),
            'The Weekly Planner has not created its tables yet. Run "bin/console kimai:plugins --install" once more to finish installing the Forecast import.'
        );

        // without the key, plannings deleted in the meantime left their id behind: forget it, as the key would have
        $this->connection->executeStatement(
            'UPDATE ' . self::LINKS . ' SET planned_activity_id = NULL WHERE planned_activity_id IS NOT NULL'
            . ' AND planned_activity_id NOT IN (SELECT id FROM ' . self::PLANNINGS . ')'
        );
    }

    public function up(Schema $schema): void
    {
        if (!$this->needsKey($schema)) {
            $this->preventEmptyMigrationWarning();

            return;
        }

        $schema->getTable(self::LINKS)->addForeignKeyConstraint(self::PLANNINGS, ['planned_activity_id'], ['id'], ['onDelete' => 'SET NULL'], self::KEY);
    }

    public function down(Schema $schema): void
    {
        // the key belongs to the links table, which Version20261106000000 drops on the way down
        $this->preventEmptyMigrationWarning();
    }

    private function needsKey(Schema $schema): bool
    {
        return $schema->hasTable(self::LINKS) && !$schema->getTable(self::LINKS)->hasForeignKey(self::KEY);
    }
}
