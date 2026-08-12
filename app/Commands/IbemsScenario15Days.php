<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;

class IbemsScenario15Days extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'ibems:scenario-15-days';
    protected $description = 'Creates and verifies an idempotent local 15-day operations scenario.';

    private BaseConnection $db;
    private const PREFIX = 'SCN15-2026-07';

    public function run(array $params)
    {
        $this->db = db_connect();

        if ($this->db->table('transactions')->like('client_txn_id', self::PREFIX, 'after')->countAllResults() > 0) {
            CLI::write('Scenario already exists; running verification only.', 'yellow');
            return $this->verify();
        }

        $this->db->transException(true)->transStart();
        try {
            $actors = $this->actors();
            $stores = $this->storesWithOfficers($actors['admin']);
            $customers = $this->customers();
            $products = $this->products($stores);
            $this->simulateDays($stores, $products, $customers['employee']);
            $this->recordPinChanges($customers['employee']);
            $this->recordCreditLimitRejection($customers['employee'], $actors['admin']);
            $this->recordRepayment($customers['employee'], $actors['accounting']);
            $this->recordInvestigation($customers['employee'], $actors);
            $this->recordSalaryDeduction($customers['employee'], $actors);
            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            CLI::error('Scenario creation failed: ' . $e->getMessage());
            return EXIT_ERROR;
        }

        CLI::write('15-day operations scenario created.', 'green');
        return $this->verify();
    }

    private function actors(): array
    {
        $admin = $this->db->table('users')->where('role', 'ADMIN')->get()->getRowArray();
        $accounting = $this->db->table('users')->where('role', 'ACCOUNTING_OFFICE')->orderBy('id', 'ASC')->get()->getRowArray();
        $approver = $this->db->table('users')->where('role', 'ACCOUNTING_OFFICE')->where('id !=', (int) ($accounting['id'] ?? 0))->orderBy('id', 'ASC')->get()->getRowArray();
        if (!$admin || !$accounting || !$approver) {
            throw new \RuntimeException('Admin and two independent accounting users are required.');
        }
        return ['admin' => (int) $admin['id'], 'accounting' => (int) $accounting['id'], 'approver' => (int) $approver['id']];
    }

    private function storesWithOfficers(int $adminId): array
    {
        $stores = $this->db->table('stores')->orderBy('id', 'ASC')->get()->getResultArray();
        foreach ($stores as &$store) {
            if (empty($store['officer_id'])) {
                $employeeId = sprintf('SCN15-OFF-%03d', (int) $store['id']);
                $officer = $this->db->table('users')->where('employee_id', $employeeId)->get()->getRowArray();
                if (!$officer) {
                    $this->db->table('users')->insert([
                        'employee_id' => $employeeId,
                        'name' => 'Scenario Officer ' . $store['id'],
                        'email' => strtolower($employeeId) . '@example.test',
                        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                        'role' => 'STORE_SYSTEM', 'user_type' => 'staff',
                        'qr_token' => 'QR-' . $employeeId,
                        'base_salary' => 28000, 'is_active' => 1,
                        'created_at' => '2026-07-14 08:00:00',
                    ]);
                    $officerId = (int) $this->db->insertID();
                } else {
                    $officerId = (int) $officer['id'];
                }
                $this->db->table('stores')->where('id', (int) $store['id'])->update(['officer_id' => $officerId]);
                $store['officer_id'] = $officerId;
                $this->audit($adminId, 'ASSIGN_STORE_OFFICER', 'stores', (int) $store['id'], ['scenario' => self::PREFIX, 'officer_id' => $officerId]);
            }
        }
        unset($store);
        if (count($stores) < 1) {
            throw new \RuntimeException('At least one store is required.');
        }
        return $stores;
    }

    private function customers(): array
    {
        $employee = $this->db->table('users')->where('employee_id', 'SCN15-EMP-001')->get()->getRowArray();
        if (!$employee) {
            $this->db->table('users')->insert([
                'employee_id' => 'SCN15-EMP-001', 'name' => 'Scenario Employee',
                'email' => 'scn15.employee@example.test',
                'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'debt_pin_hash' => password_hash('2468', PASSWORD_BCRYPT),
                'role' => 'USER', 'user_type' => 'staff', 'qr_token' => 'QR-SCN15-EMP-001',
                'base_salary' => 24000, 'is_active' => 1, 'created_at' => '2026-07-14 08:05:00',
            ]);
            $employeeId = (int) $this->db->insertID();
            $this->db->table('balances')->insert(['user_id' => $employeeId, 'credit_limit' => 1000, 'current_debt' => 0, 'updated_at' => '2026-07-14 08:05:00']);
            $employee = $this->db->table('users')->where('id', $employeeId)->get()->getRowArray();
        }
        return ['employee' => (int) $employee['id']];
    }

    private function products(array $stores): array
    {
        $products = [];
        foreach ($stores as $store) {
            $storeId = (int) $store['id'];
            $sku = sprintf('SCN15-%03d', $storeId);
            $product = $this->db->table('products')->where(['store_id' => $storeId, 'sku' => $sku])->get()->getRowArray();
            if (!$product) {
                $this->db->table('products')->insert([
                    'store_id' => $storeId, 'sku' => $sku, 'name' => 'Scenario Essentials Pack',
                    'variant_label' => 'Acceptance', 'category' => 'Scenario Goods', 'supplier' => 'Local Test Supplier',
                    'barcode' => '915' . str_pad((string) $storeId, 9, '0', STR_PAD_LEFT),
                    'price' => 25, 'stock_qty' => 500, 'low_stock_threshold' => 20,
                    'location_bin' => 'QA-' . $storeId, 'is_active' => 1, 'updated_at' => '2026-07-14 08:10:00',
                ]);
                $product = $this->db->table('products')->where('id', (int) $this->db->insertID())->get()->getRowArray();
            }
            $products[$storeId] = $product;
        }
        return $products;
    }

    private function simulateDays(array $stores, array $products, int $customerId): void
    {
        $debt = 0.0;
        for ($day = 0; $day < 15; $day++) {
            $date = (new \DateTimeImmutable('2026-07-15'))->modify("+{$day} days")->format('Y-m-d');
            foreach ($stores as $storeIndex => $store) {
                $storeId = (int) $store['id'];
                $officerId = (int) $store['officer_id'];
                $productId = (int) $products[$storeId]['id'];
                $cashTxn = $this->transaction($storeId, $productId, $officerId, $date, $day, 'cash', null, 25, 'CASH');
                $cashIn = $day === 4 ? 100.0 : 0.0;
                if ($cashIn > 0) {
                    $this->db->table('store_cash_movements')->insert([
                        'store_id' => $storeId, 'business_date' => $date, 'channel' => 'cash',
                        'movement_type' => 'cash_in', 'amount' => $cashIn,
                        'reason' => self::PREFIX . ' replenishment', 'created_by' => $officerId,
                        'created_at' => $date . ' 12:00:00', 'updated_at' => $date . ' 12:00:00',
                    ]);
                }
                if ($day < 12 && $storeIndex === ($day % count($stores))) {
                    $debtBefore = $debt;
                    $debt += 50;
                    $debtTxn = $this->transaction($storeId, $productId, $officerId, $date, $day, 'debt', $customerId, 50, 'DEBT');
                    $this->db->table('debt_cashbook_entries')->insert([
                        'user_id' => $customerId, 'entry_type' => 'debt_purchase', 'direction' => 'debit', 'amount' => 50,
                        'debt_before' => $debtBefore, 'debt_after' => $debt, 'credit_limit_snapshot' => 1000,
                        'available_credit_snapshot' => 1000 - $debt, 'reference_type' => 'transaction', 'reference_id' => $debtTxn,
                        'actor_id' => $officerId, 'remarks' => self::PREFIX . ' debt purchase',
                        'meta_json' => json_encode(['store_id' => $storeId, 'scenario' => self::PREFIX]),
                        'created_at' => $date . ' 10:30:00', 'updated_at' => $date . ' 10:30:00',
                    ]);
                }
                $expected = 1000 + 25 + $cashIn;
                $variance = ($day === 9 && $storeIndex === count($stores) - 1) ? -20.0 : 0.0;
                $this->db->table('store_day_sessions')->insert([
                    'store_id' => $storeId, 'business_date' => $date, 'status' => 'closed',
                    'opening_cash' => 1000, 'opening_ecash' => 200, 'opening_note' => self::PREFIX,
                    'opened_by' => $officerId, 'opened_at' => $date . ' 07:50:00',
                    'expected_cash' => $expected, 'expected_ecash' => 200,
                    'counted_cash' => $expected + $variance, 'counted_ecash' => 200,
                    'variance_cash' => $variance, 'variance_ecash' => 0,
                    'variance_status' => $variance < 0 ? 'shortage' : 'balanced',
                    'review_status' => $variance < 0 ? 'needs_investigation' : 'not_required',
                    'closing_note' => $variance < 0 ? 'Scenario shortage retained for investigation.' : 'Scenario day balanced.',
                    'closed_by' => $officerId, 'closed_at' => $date . ' 17:10:00',
                    'created_at' => $date . ' 07:50:00', 'updated_at' => $date . ' 17:10:00',
                ]);
                $this->audit($officerId, 'CREATE_TRANSACTION', 'transactions', $cashTxn, ['scenario' => self::PREFIX, 'business_date' => $date]);
            }
        }
        $this->db->table('balances')->where('user_id', $customerId)->update(['current_debt' => $debt, 'updated_at' => '2026-07-26 10:31:00']);
    }

    private function transaction(int $storeId, int $productId, int $actorId, string $date, int $day, string $method, ?int $userId, float $amount, string $kind): int
    {
        $clientId = sprintf('%s-%s-S%03d-D%02d', self::PREFIX, $kind, $storeId, $day + 1);
        $this->db->table('transactions')->insert([
            'client_txn_id' => $clientId, 'user_id' => $userId,
            'customer_type' => $userId ? 'staff' : 'walk_in', 'store_id' => $storeId,
            'amount' => $amount, 'payment_method' => $method, 'status' => 'completed',
            'created_at' => $date . ($method === 'debt' ? ' 10:30:00' : ' 09:15:00'),
            'synced_at' => $date . ($method === 'debt' ? ' 10:30:02' : ' 09:15:02'),
        ]);
        $txnId = (int) $this->db->insertID();
        $qty = $amount === 50.0 ? 2 : 1;
        $this->db->table('transaction_items')->insert(['transaction_id' => $txnId, 'product_id' => $productId, 'qty' => $qty, 'unit_price' => 25, 'line_total' => $amount, 'created_at' => $date . ' 09:15:00']);
        $this->db->table('inventory_movements')->insert(['product_id' => $productId, 'store_id' => $storeId, 'type' => 'sale', 'qty' => -$qty, 'reason' => self::PREFIX, 'txn_id' => $txnId, 'created_at' => $date . ' 09:15:00']);
        $this->db->table('products')->where('id', $productId)->set('stock_qty', 'stock_qty - ' . $qty, false)->update(['updated_at' => $date . ' 10:30:00']);
        return $txnId;
    }

    private function recordPinChanges(int $userId): void
    {
        foreach ([['2026-07-17 18:00:00', '1357'], ['2026-07-21 18:00:00', '2468']] as [$at, $pin]) {
            $this->db->table('users')->where('id', $userId)->update(['debt_pin_hash' => password_hash($pin, PASSWORD_BCRYPT)]);
            $this->audit($userId, 'SET_DEBT_PIN', 'users', $userId, ['scenario' => self::PREFIX, 'changed_at' => $at], $at);
        }
    }

    private function recordCreditLimitRejection(int $userId, int $actorId): void
    {
        $this->audit($actorId, 'REJECT_CREDIT_LIMIT_EXCEEDED', 'balances', $userId, [
            'scenario' => self::PREFIX, 'attempted_amount' => 500, 'debt_before' => 600,
            'credit_limit' => 1000, 'available_credit' => 400,
        ], '2026-07-27 11:00:00');
    }

    private function recordRepayment(int $userId, int $actorId): void
    {
        $before = (float) $this->db->table('balances')->where('user_id', $userId)->get()->getRowArray()['current_debt'];
        $after = $before - 100;
        $this->db->table('debt_cashbook_entries')->insert([
            'user_id' => $userId, 'entry_type' => 'cash_repayment', 'direction' => 'credit', 'amount' => 100,
            'debt_before' => $before, 'debt_after' => $after, 'credit_limit_snapshot' => 1000,
            'available_credit_snapshot' => 1000 - $after, 'reference_type' => 'manual_repayment',
            'actor_id' => $actorId, 'remarks' => self::PREFIX . ' cash repayment',
            'meta_json' => json_encode(['scenario' => self::PREFIX]),
            'created_at' => '2026-07-29 13:00:00', 'updated_at' => '2026-07-29 13:00:00',
        ]);
        $this->db->table('balances')->where('user_id', $userId)->update(['current_debt' => $after, 'updated_at' => '2026-07-29 13:00:00']);
    }

    private function recordInvestigation(int $userId, array $actors): void
    {
        $txn = $this->db->table('transactions')->where('client_txn_id', self::PREFIX . '-DEBT-S001-D01')->get()->getRowArray();
        $before = (float) $this->db->table('balances')->where('user_id', $userId)->get()->getRowArray()['current_debt'];
        $after = $before - 50;
        $this->db->table('debt_cashbook_entries')->insert([
            'user_id' => $userId, 'entry_type' => 'investigation_reversal', 'direction' => 'credit', 'amount' => 50,
            'debt_before' => $before, 'debt_after' => $after, 'credit_limit_snapshot' => 1000,
            'available_credit_snapshot' => 1000 - $after, 'reference_type' => 'debt_investigation',
            'actor_id' => $actors['approver'], 'remarks' => self::PREFIX . ' approved duplicate-sale reversal',
            'meta_json' => json_encode(['scenario' => self::PREFIX]),
            'created_at' => '2026-07-30 09:30:00', 'updated_at' => '2026-07-30 09:30:00',
        ]);
        $entryId = (int) $this->db->insertID();
        $this->db->table('debt_investigations')->insert([
            'user_id' => $userId, 'transaction_id' => (int) $txn['id'], 'status' => 'closed',
            'issue_type' => 'duplicate_charge', 'summary' => 'Employee disputed a duplicate scenario charge.',
            'evidence_summary' => 'Receipt, transaction line, and store-day record were compared.',
            'findings' => 'The selected charge was duplicated during the scenario.',
            'recommended_action' => 'full_reversal', 'recommended_amount' => 50,
            'reversal_cashbook_entry_id' => $entryId, 'opened_by' => $actors['accounting'],
            'investigator_id' => $actors['accounting'], 'recommended_by' => $actors['accounting'],
            'approved_by' => $actors['approver'], 'posted_by' => $actors['approver'],
            'recommended_at' => '2026-07-30 09:10:00', 'approved_at' => '2026-07-30 09:30:00',
            'posted_at' => '2026-07-30 09:30:00', 'closed_at' => '2026-07-30 09:30:00',
            'created_at' => '2026-07-30 08:45:00', 'updated_at' => '2026-07-30 09:30:00',
        ]);
        $investigationId = (int) $this->db->insertID();
        $this->db->table('debt_cashbook_entries')->where('id', $entryId)->update(['reference_id' => $investigationId]);
        $this->db->table('balances')->where('user_id', $userId)->update(['current_debt' => $after, 'updated_at' => '2026-07-30 09:30:00']);
        $this->audit($actors['accounting'], 'OPEN_DEBT_INVESTIGATION', 'debt_investigations', $investigationId, ['scenario' => self::PREFIX]);
        $this->audit($actors['approver'], 'APPROVE_AND_POST_DEBT_REVERSAL', 'debt_investigations', $investigationId, ['scenario' => self::PREFIX, 'amount' => 50]);
    }

    private function recordSalaryDeduction(int $userId, array $actors): void
    {
        $before = (float) $this->db->table('balances')->where('user_id', $userId)->get()->getRowArray()['current_debt'];
        $deducted = min(300.0, $before);
        $after = $before - $deducted;
        $this->db->table('deduction_periods')->insert([
            'period_code' => self::PREFIX, 'label' => 'Scenario July 15-29 Salary Period', 'frequency' => 'semi_monthly',
            'date_start' => '2026-07-15', 'date_end' => '2026-07-29', 'expected_processing_date' => '2026-07-31',
            'preparation_deadline' => '2026-07-30', 'status' => 'finalized', 'notes' => '15-day operations scenario',
            'created_by' => $actors['accounting'], 'reviewed_by' => $actors['accounting'],
            'submitted_by' => $actors['accounting'], 'confirmed_by' => $actors['accounting'], 'finalized_by' => $actors['approver'],
            'reviewed_at' => '2026-07-31 08:10:00', 'submitted_at' => '2026-07-31 08:20:00',
            'confirmed_at' => '2026-07-31 09:00:00', 'finalized_at' => '2026-07-31 09:30:00',
            'created_at' => '2026-07-31 08:00:00', 'updated_at' => '2026-07-31 09:30:00',
        ]);
        $periodId = (int) $this->db->insertID();
        $this->db->table('deduction_batches')->insert([
            'period_id' => $periodId, 'status' => 'finalized', 'total_accounts' => 1,
            'total_requested' => $before, 'total_confirmed' => $deducted, 'total_carryover' => $after,
            'notes' => self::PREFIX, 'created_by' => $actors['accounting'],
            'created_at' => '2026-07-31 08:10:00', 'updated_at' => '2026-07-31 09:30:00',
        ]);
        $batchId = (int) $this->db->insertID();
        $this->db->table('deduction_batch_items')->insert([
            'batch_id' => $batchId, 'user_id' => $userId, 'debt_snapshot' => $before,
            'requested_amount' => $before, 'confirmed_amount' => $deducted, 'carryover_amount' => $after,
            'result_status' => $after > 0 ? 'partially_deducted' : 'deducted',
            'reason_code' => $after > 0 ? 'insufficient_salary' : null,
            'result_reference' => self::PREFIX . '-PAYROLL', 'result_notes' => 'Scenario payroll confirmation.',
            'confirmed_by' => $actors['accounting'], 'confirmed_at' => '2026-07-31 09:00:00',
            'created_at' => '2026-07-31 08:10:00', 'updated_at' => '2026-07-31 09:30:00',
        ]);
        if ($deducted > 0) {
            $this->db->table('debt_cashbook_entries')->insert([
                'user_id' => $userId, 'entry_type' => 'salary_deduction', 'direction' => 'credit', 'amount' => $deducted,
                'debt_before' => $before, 'debt_after' => $after, 'credit_limit_snapshot' => 1000,
                'available_credit_snapshot' => 1000 - $after, 'reference_type' => 'deduction_batch', 'reference_id' => $batchId,
                'actor_id' => $actors['approver'], 'remarks' => self::PREFIX . ' finalized salary deduction',
                'meta_json' => json_encode(['scenario' => self::PREFIX, 'period_id' => $periodId]),
                'created_at' => '2026-07-31 09:30:00', 'updated_at' => '2026-07-31 09:30:00',
            ]);
            $this->db->table('balances')->where('user_id', $userId)->update(['current_debt' => $after, 'updated_at' => '2026-07-31 09:30:00']);
        }
        $this->audit($actors['approver'], 'ACCOUNTING_FINALIZE_DEDUCTION_BATCH', 'deduction_batches', $batchId, ['scenario' => self::PREFIX]);
    }

    private function audit(int $actorId, string $action, string $entity, ?int $entityId, array $payload, ?string $at = null): void
    {
        $this->db->table('audit_logs')->insert([
            'actor_id' => $actorId, 'action' => $action, 'entity' => $entity, 'entity_id' => $entityId,
            'payload_json' => json_encode($payload), 'created_at' => $at ?? '2026-07-31 10:00:00',
        ]);
    }

    private function verify(): int
    {
        $stores = $this->db->table('stores')->countAllResults();
        $sessions = $this->db->table('store_day_sessions')->where('business_date >=', '2026-07-15')->where('business_date <=', '2026-07-29')->countAllResults();
        $coveredStores = $this->db->query("SELECT COUNT(DISTINCT store_id) AS total FROM store_day_sessions WHERE business_date BETWEEN '2026-07-15' AND '2026-07-29'")->getRowArray();
        $transactions = $this->db->table('transactions')->like('client_txn_id', self::PREFIX, 'after')->countAllResults();
        $scenarioProducts = $this->db->table('products')->like('sku', 'SCN15-', 'after')->countAllResults();
        $pinChanges = $this->db->table('audit_logs')->where('action', 'SET_DEBT_PIN')->like('payload_json', self::PREFIX)->countAllResults();
        $rejections = $this->db->table('audit_logs')->where('action', 'REJECT_CREDIT_LIMIT_EXCEEDED')->like('payload_json', self::PREFIX)->countAllResults();
        $period = $this->db->table('deduction_periods')->where('period_code', self::PREFIX)->get()->getRowArray();
        $investigation = $this->db->table('debt_investigations')->like('summary', 'scenario')->get()->getRowArray();
        $checks = [
            'all stores covered for 15 days' => $sessions === $stores * 15 && (int) ($coveredStores['total'] ?? 0) === $stores,
            'every store has a scenario product' => $scenarioProducts === $stores,
            'cash and debt transactions recorded' => $transactions >= ($stores * 15) + 12,
            'two PIN changes audited' => $pinChanges === 2,
            'credit-limit rejection audited' => $rejections === 1,
            'salary period finalized' => ($period['status'] ?? '') === 'finalized',
            'investigation independently approved' => $investigation && (int) $investigation['recommended_by'] !== (int) $investigation['approved_by'],
        ];
        foreach ($checks as $label => $ok) {
            CLI::write(($ok ? '[OK] ' : '[FAIL] ') . $label, $ok ? 'green' : 'red');
        }
        CLI::write(sprintf('Stores: %d | Store-days: %d | Scenario transactions: %d | Scenario products: %d', $stores, $sessions, $transactions, $scenarioProducts));
        return in_array(false, $checks, true) ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
