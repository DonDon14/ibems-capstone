<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

class IbemsCleanupTestData extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'ibems:cleanup-test-data';
    protected $description = 'Dry-run or delete explicitly identified synthetic demo stores and users.';
    protected $usage = 'ibems:cleanup-test-data [--apply] [--backup path] [--demo-employees] [--live-credit-test]';

    public function run(array $params)
    {
        $apply = CLI::getOption('apply') !== null;
        $demoEmployees = CLI::getOption('demo-employees') !== null;
        $liveCreditTest = CLI::getOption('live-credit-test') !== null;
        $db = Database::connect();
        $backupPath = trim((string) (CLI::getOption('backup') ?? ''));
        if ($backupPath !== '') {
            $this->backupExistingTables($db, $backupPath);
            CLI::write('Backup created: ' . $backupPath, 'green');
        }

        $stores = $db->table('stores')
            ->select('id, store_name')
            ->groupStart()
                ->where('LOWER(store_name)', 'dashboard demo store')
                ->orLike('LOWER(store_name)', 'qa-day-', 'after')
            ->groupEnd()
            ->orderBy('id')
            ->get()->getResultArray();

        $userQuery = $db->table('users')
            ->select('id, employee_id, name, email')
            ->groupStart();
        if ($liveCreditTest) {
            $userQuery->where('employee_id', 'LIVE-CREDIT-TEST-001');
        } elseif ($demoEmployees) {
            $userQuery->whereIn('employee_id', [
                'FAC-2026-001', 'FAC-2026-002', 'FAC-2026-003', 'FAC-2026-004', 'FAC-2026-005',
                'STF-2026-001', 'STF-2026-002', 'STF-2026-003', 'STF-2026-004', 'STF-2026-005',
            ]);
        } else {
            $userQuery->where('LOWER(name)', 'pin ux test employee')
                ->orLike('LOWER(name)', 'qa-day-', 'after')
                ->orLike('LOWER(employee_id)', 'qa-day-', 'after')
                ->orLike('LOWER(email)', 'qa-day-', 'after');
        }
        $users = $userQuery->groupEnd()->orderBy('id')->get()->getResultArray();

        if ($demoEmployees || $liveCreditTest) {
            $stores = [];
        }

        $scope = $liveCreditTest ? ' - live credit test cleanup' : ($demoEmployees ? ' - imported demo employees cleanup' : ' - synthetic data cleanup');
        CLI::write(($apply ? 'APPLY' : 'DRY RUN') . $scope, $apply ? 'yellow' : 'cyan');
        CLI::write('Stores: ' . count($stores));
        foreach ($stores as $store) CLI::write('  #' . $store['id'] . ' ' . $store['store_name']);
        CLI::write('Users: ' . count($users));
        foreach ($users as $user) CLI::write('  #' . $user['id'] . ' ' . $user['name'] . ' | ' . $user['employee_id'] . ' | ' . $user['email']);

        if (!$apply) {
            CLI::write('No data changed. Re-run with --apply after reviewing these exact targets.', 'green');
            return;
        }
        if ($stores === [] && $users === []) {
            CLI::write('No matching synthetic records found.', 'green');
            return;
        }

        $storeIds = array_map('intval', array_column($stores, 'id'));
        $userIds = array_map('intval', array_column($users, 'id'));
        $db->transBegin();

        try {
            $transactionIds = $this->ids($db, 'transactions', 'id', 'store_id', $storeIds);
            $productIds = $this->ids($db, 'products', 'id', 'store_id', $storeIds);
            $sessionIds = $this->ids($db, 'store_day_sessions', 'id', 'store_id', $storeIds);
            $destinationIds = $this->ids($db, 'payment_destination_accounts', 'id', 'store_id', $storeIds);
            $batchIds = $this->ids($db, 'deduction_batch_items', 'batch_id', 'user_id', $userIds);

            $this->deleteIn($db, 'store_day_payment_balances', 'store_day_session_id', $sessionIds);
            $this->deleteIn($db, 'store_day_payment_balances', 'destination_account_id', $destinationIds);
            $this->deleteIn($db, 'transaction_payments', 'transaction_id', $transactionIds);
            $this->deleteIn($db, 'transaction_items', 'transaction_id', $transactionIds);
            $this->deleteIn($db, 'notifications', 'txn_id', $transactionIds);
            $this->deleteIn($db, 'inventory_movements', 'store_id', $storeIds);
            $this->deleteIn($db, 'inventory_movements', 'product_id', $productIds);
            $this->deleteIn($db, 'debt_investigations', 'transaction_id', $transactionIds);
            $this->deleteReferenceRows($db, 'debt_cashbook_entries', 'transaction', $transactionIds);
            $this->deleteIn($db, 'transactions', 'id', $transactionIds);
            $this->deleteIn($db, 'products', 'id', $productIds);
            foreach (['product_families', 'store_cash_movements', 'store_categories', 'store_opening_balances', 'store_day_sessions', 'payment_destination_accounts', 'store_payment_methods', 'store_supervisors', 'store_supervisor_assignment_history'] as $table) {
                $this->deleteIn($db, $table, 'store_id', $storeIds);
            }
            $this->deleteAuditRows($db, 'stores', $storeIds);
            $this->deleteIn($db, 'stores', 'id', $storeIds);

            if ($userIds !== []) {
                $db->table('stores')->whereIn('officer_id', $userIds)->update(['officer_id' => null]);
            }
            foreach (['deduction_batch_items', 'debt_investigations', 'debt_pin_attempts', 'debt_cashbook_entries', 'balances', 'user_roles', 'store_supervisors', 'store_supervisor_assignment_history'] as $table) {
                $this->deleteIn($db, $table, 'user_id', $userIds);
            }
            $this->deleteAuditRows($db, 'users', $userIds);
            $this->deleteIn($db, 'audit_logs', 'actor_id', $userIds);
            $this->deleteIn($db, 'users', 'id', $userIds);

            foreach ($batchIds as $batchId) {
                if ($db->table('deduction_batch_items')->where('batch_id', $batchId)->countAllResults() === 0) {
                    $period = $db->table('deduction_batches')->select('period_id')->where('id', $batchId)->get()->getRowArray();
                    $db->table('deduction_batches')->where('id', $batchId)->delete();
                    $periodId = (int) ($period['period_id'] ?? 0);
                    if ($periodId > 0 && $db->table('deduction_batches')->where('period_id', $periodId)->countAllResults() === 0) {
                        $db->table('deduction_periods')->where('id', $periodId)->delete();
                    }
                }
            }

            if (!$db->transStatus()) throw new \RuntimeException('Database rejected part of the cleanup transaction.');
            $db->transCommit();
            CLI::write('Synthetic cleanup committed successfully.', 'green');
        } catch (Throwable $exception) {
            $db->transRollback();
            CLI::error('Cleanup rolled back: ' . $exception->getMessage());
            throw $exception;
        }
    }

    private function ids($db, string $table, string $select, string $field, array $values): array
    {
        if ($values === [] || !$db->tableExists($table)) return [];
        return array_values(array_unique(array_map('intval', array_column(
            $db->table($table)->select($select)->whereIn($field, $values)->get()->getResultArray(),
            $select
        ))));
    }

    private function deleteIn($db, string $table, string $field, array $values): void
    {
        if ($values !== [] && $db->tableExists($table) && $db->fieldExists($field, $table)) {
            $db->table($table)->whereIn($field, $values)->delete();
        }
    }

    private function deleteReferenceRows($db, string $table, string $type, array $ids): void
    {
        if ($ids !== [] && $db->tableExists($table)) {
            $db->table($table)->where('reference_type', $type)->whereIn('reference_id', $ids)->delete();
        }
    }

    private function deleteAuditRows($db, string $entity, array $ids): void
    {
        if ($ids !== [] && $db->tableExists('audit_logs')) {
            $db->table('audit_logs')->where('entity', $entity)->whereIn('entity_id', $ids)->delete();
        }
    }

    private function backupExistingTables($db, string $path): void
    {
        $tables = [];
        foreach ($db->listTables() as $table) {
            if (str_starts_with((string) $table, 'pg_') || str_starts_with((string) $table, 'sql_')) continue;
            $rows = $db->table($table)->get()->getResultArray();
            $tables[$table] = ['count' => count($rows), 'rows' => $rows];
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create backup directory.');
        }
        $payload = json_encode([
            'created_at' => date(DATE_ATOM),
            'driver' => $db->DBDriver,
            'tables' => $tables,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false || file_put_contents($path, $payload) === false) {
            throw new \RuntimeException('Unable to write cleanup backup.');
        }
    }
}
