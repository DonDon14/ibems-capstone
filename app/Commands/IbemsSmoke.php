<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class IbemsSmoke extends BaseCommand
{
    protected $group       = 'IBEMS';
    protected $name        = 'ibems:smoke';
    protected $description = 'Run basic IBEMS deployment smoke checks (DB + required tables + writable dirs).';
    protected $usage       = 'ibems:smoke';
    protected $arguments   = [];
    protected $options     = [];

    public function run(array $params): void
    {
        $ok = true;

        CLI::write('IBEMS Smoke Check', 'yellow');
        CLI::newLine();

        try {
            $db = Database::connect();
            $db->initialize();
            CLI::write('[OK] Database connection established', 'green');
        } catch (\Throwable $e) {
            CLI::write('[FAIL] Database connection failed: ' . $e->getMessage(), 'red');
            exit(1);
        }

        $requiredTables = [
            'users',
            'user_roles',
            'stores',
            'store_supervisors',
            'store_day_sessions',
            'balances',
            'products',
            'transactions',
            'transaction_items',
            'inventory_movements',
            'store_categories',
            'store_payment_methods',
            'store_opening_balances',
            'store_cash_movements',
            'debt_cashbook_entries',
            'settlement_runs',
            'audit_logs',
        ];

        foreach ($requiredTables as $table) {
            if ($db->tableExists($table)) {
                CLI::write("[OK] Table exists: {$table}", 'green');
            } else {
                CLI::write("[FAIL] Missing table: {$table}", 'red');
                $ok = false;
            }
        }

        $writableChecks = [
            WRITEPATH . 'logs',
            WRITEPATH . 'session',
            FCPATH . 'uploads',
        ];

        foreach ($writableChecks as $path) {
            if (is_dir($path) && is_writable($path)) {
                CLI::write('[OK] Writable path: ' . $path, 'green');
                continue;
            }

            CLI::write('[FAIL] Path missing or not writable: ' . $path, 'red');
            $ok = false;
        }

        try {
            $userCount = (int) $db->table('users')->countAllResults();
            $storeCount = (int) $db->table('stores')->countAllResults();
            CLI::write("[OK] users count: {$userCount}", 'green');
            CLI::write("[OK] stores count: {$storeCount}", 'green');
        } catch (\Throwable $e) {
            CLI::write('[FAIL] Failed counting baseline records: ' . $e->getMessage(), 'red');
            $ok = false;
        }

        CLI::newLine();
        if ($ok) {
            CLI::write('Smoke check passed.', 'green');
            return;
        }

        CLI::write('Smoke check failed. Fix the items above before deploy.', 'red');
        exit(1);
    }
}

