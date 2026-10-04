<?php

use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;

/** @internal */
final class FreshMigrationSmokeTest extends CIUnitTestCase
{
    public function testAllAppMigrationsBuildPortalTablesOnAnIsolatedDatabase(): void
    {
        $db = Database::connect([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
            'DBDebug' => true,
            'foreignKeys' => true,
            'busyTimeout' => 1000,
        ], false);

        try {
            $runner = new MigrationRunner(config(Migrations::class), $db);
            $this->assertTrue($runner->latest());
            foreach (['users', 'user_roles', 'deduction_periods', 'deduction_batches',
                'deduction_batch_items', 'daily_store_reports', 'daily_store_report_events',
                'scheduled_salary_profiles'] as $table) {
                $this->assertTrue($db->tableExists($table), $table . ' was not created');
            }
            $this->assertTrue($db->fieldExists('role', 'users'));
            $this->assertTrue($db->fieldExists('revision', 'daily_store_reports'));
            $this->assertTrue($db->fieldExists('effective_date', 'scheduled_salary_profiles'));
        } finally {
            $db->close();
        }
    }
}
