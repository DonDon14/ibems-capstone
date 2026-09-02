<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class IbemsHostedBootstrap extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'ibems:hosted-bootstrap';
    protected $description = 'Apply the idempotent PostgreSQL additions required by the hosted development service.';

    public function run(array $params)
    {
        $db = Database::connect();
        if (stripos((string) ($db->DBDriver ?? ''), 'Postgre') === false) {
            CLI::error('Hosted bootstrap requires PostgreSQL.');
            return EXIT_ERROR;
        }
        if (!$db->tableExists('users') || !$db->tableExists('balances') || !$db->tableExists('ibems_schema_meta')) {
            CLI::error('Hosted bootstrap stopped because the verified IBEMS PostgreSQL baseline is missing.');
            return EXIT_ERROR;
        }

        try {
            foreach (['010_salary_grade_profiles.sql', '011_render_hosted_sessions.sql', '012_dynamic_salary_schedules.sql', '014_department_debt_accounts.sql', '016_configurable_store_operations.sql'] as $file) {
                $path = ROOTPATH . 'database/postgresql/' . $file;
                $sql = file_get_contents($path);
                if ($sql === false) {
                    throw new \RuntimeException('Hosted migration SQL file not found: ' . $file);
                }
                $db->query($sql);
            }
            CLI::write('Hosted PostgreSQL additions verified.', 'green');
            return EXIT_SUCCESS;
        } catch (\Throwable $exception) {
            try {
                $db->query('rollback');
            } catch (\Throwable) {
            }
            CLI::error('Hosted bootstrap failed: ' . $exception->getMessage());
            return EXIT_ERROR;
        }
    }
}
