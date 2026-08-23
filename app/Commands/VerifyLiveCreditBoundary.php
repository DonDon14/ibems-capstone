<?php

namespace App\Commands;

use App\Services\TransactionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class VerifyLiveCreditBoundary extends BaseCommand
{
    protected $group = 'IBEMS';
    protected $name = 'ibems:verify-live-credit-boundary';
    protected $description = 'Run and clean up an isolated exact-limit and over-limit debt purchase against PostgreSQL staging or an explicitly approved local development database.';
    protected $options = [
        '--allow-local' => 'Explicitly allow the isolated, self-cleaning trial on a non-production local database.',
    ];

    public function run(array $params)
    {
        $db = Database::connect();
        $isPostgreSql = stripos((string) ($db->DBDriver ?? ''), 'Postgre') !== false;
        $allowLocal = array_key_exists('allow-local', $params) || CLI::getOption('allow-local') !== null;
        if (!$isPostgreSql && (!$allowLocal || ENVIRONMENT === 'production')) {
            CLI::error('Non-PostgreSQL execution requires --allow-local and a non-production environment.');
            return EXIT_ERROR;
        }
        if (!$isPostgreSql) {
            CLI::write('[LOCAL] Running an isolated development trial; synthetic records will be removed.', 'yellow');
        }

        $marker = 'QA-CREDIT-' . gmdate('YmdHis');
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $actorId = $customerId = $storeId = $productId = $transactionId = null;
        $passed = false;

        try {
            $db->table('users')->insert([
                'employee_id' => $marker . '-OFFICER', 'name' => $marker . ' Store Officer',
                'email' => strtolower($marker) . '-officer@example.test', 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'role' => 'STORE_SYSTEM', 'user_type' => 'staff', 'qr_token' => $marker . '-OFFICER-QR',
                'base_salary' => 0, 'is_active' => true, 'created_at' => $now,
            ]);
            $actorId = (int) $db->insertID();

            $db->table('stores')->insert([
                'store_name' => $marker . ' Store', 'officer_id' => $actorId, 'is_active' => true, 'created_at' => $now,
            ]);
            $storeId = (int) $db->insertID();

            $db->table('users')->insert([
                'employee_id' => $marker . '-EMPLOYEE', 'name' => $marker . ' Employee',
                'email' => strtolower($marker) . '-employee@example.test', 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'debt_pin_hash' => password_hash('2468', PASSWORD_BCRYPT), 'role' => 'USER', 'user_type' => 'staff',
                'qr_token' => $marker . '-EMPLOYEE-QR', 'base_salary' => 0, 'is_active' => true, 'created_at' => $now,
            ]);
            $customerId = (int) $db->insertID();
            $db->table('balances')->insert(['user_id' => $customerId, 'credit_limit' => 100, 'current_debt' => 75, 'updated_at' => $now]);

            $db->table('products')->insert([
                'store_id' => $storeId, 'sku' => $marker . '-PRODUCT', 'name' => $marker . ' Boundary Product',
                'category' => 'QA', 'price' => 25, 'stock_qty' => 3, 'low_stock_threshold' => 0,
                'location_bin' => 'QA', 'is_active' => true, 'updated_at' => $now,
            ]);
            $productId = (int) $db->insertID();
            $db->table('store_day_sessions')->insert([
                'store_id' => $storeId, 'business_date' => $today, 'status' => 'open',
                'opening_cash' => 0, 'opening_ecash' => 0, 'opening_note' => $marker,
                'opened_by' => $actorId, 'opened_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $service = new TransactionService();
            $exact = $service->createTransaction([
                'store_id' => $storeId, 'payment_method' => 'debt', 'customer_user_id' => $customerId,
                'customer_type' => 'staff', 'debt_pin' => '2468', 'items' => [['product_id' => $productId, 'qty' => 1]],
            ], $actorId, 'STORE_SYSTEM');
            if (($exact['status'] ?? '') !== 'success') throw new \RuntimeException('Exact-limit purchase failed: ' . ($exact['message'] ?? 'unknown error'));
            $transactionId = (int) ($exact['transaction_id'] ?? 0);

            $exactBalance = $db->table('balances')->where('user_id', $customerId)->get()->getRowArray();
            $exactProduct = $db->table('products')->where('id', $productId)->get()->getRowArray();
            if ((float) ($exactBalance['current_debt'] ?? 0) !== 100.0 || (int) ($exactProduct['stock_qty'] ?? 0) !== 2) {
                throw new \RuntimeException('Exact-limit purchase did not update debt and stock as expected.');
            }

            $beforeOver = $this->snapshot($db, $storeId, $customerId, $productId, $actorId);
            $db->table('products')->where('id', $productId)->update(['price' => 0.01, 'updated_at' => $now]);
            $beforeOver['price'] = 0.01;
            $over = $service->createTransaction([
                'store_id' => $storeId, 'payment_method' => 'debt', 'customer_user_id' => $customerId,
                'customer_type' => 'staff', 'debt_pin' => '2468', 'items' => [['product_id' => $productId, 'qty' => 1]],
            ], $actorId, 'STORE_SYSTEM');
            $afterOver = $this->snapshot($db, $storeId, $customerId, $productId, $actorId);

            if (($over['status'] ?? '') !== 'error' || ($over['message'] ?? '') !== 'Insufficient credit.') {
                throw new \RuntimeException('Over-limit purchase was not rejected with Insufficient credit.');
            }
            if ($beforeOver !== $afterOver) {
                throw new \RuntimeException('Over-limit rejection changed connected financial or inventory state.');
            }

            CLI::write('[OK] Exact remaining credit PHP 25.00 committed.', 'green');
            CLI::write('[OK] Debt reached exactly PHP 100.00 and stock decreased from 3 to 2.', 'green');
            CLI::write('[OK] Additional PHP 0.01 purchase was rejected with Insufficient credit.', 'green');
            CLI::write('[OK] Rejected attempt created no transaction, item, payment, stock movement, debt cashbook, or CREATE_TRANSACTION audit.', 'green');
            $passed = true;
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
        } finally {
            if ($transactionId) {
                $db->table('audit_logs')->where('entity', 'transactions')->where('entity_id', $transactionId)->delete();
                $db->table('debt_cashbook_entries')->where('reference_type', 'transaction')->where('reference_id', $transactionId)->delete();
                if ($db->tableExists('transaction_payments')) $db->table('transaction_payments')->where('transaction_id', $transactionId)->delete();
                $db->table('inventory_movements')->where('txn_id', $transactionId)->delete();
                $db->table('transaction_items')->where('transaction_id', $transactionId)->delete();
                $db->table('transactions')->where('id', $transactionId)->delete();
            }
            if ($customerId) {
                if ($db->tableExists('debt_pin_security')) $db->table('debt_pin_security')->where('user_id', $customerId)->delete();
                $db->table('audit_logs')->where('actor_id', $customerId)->delete();
            }
            if ($actorId) $db->table('audit_logs')->where('actor_id', $actorId)->delete();
            if ($productId) $db->table('products')->where('id', $productId)->delete();
            if ($storeId) {
                $db->table('store_day_sessions')->where('store_id', $storeId)->delete();
                $db->table('store_payment_methods')->where('store_id', $storeId)->delete();
                $db->table('stores')->where('id', $storeId)->delete();
            }
            if ($customerId) $db->table('users')->where('id', $customerId)->delete();
            if ($actorId) $db->table('users')->where('id', $actorId)->delete();
            CLI::write('[OK] Synthetic live-test records cleaned up.', 'green');
        }

        return $passed ? EXIT_SUCCESS : EXIT_ERROR;
    }

    private function snapshot($db, int $storeId, int $customerId, int $productId, int $actorId): array
    {
        $balance = $db->table('balances')->where('user_id', $customerId)->get()->getRowArray();
        $product = $db->table('products')->where('id', $productId)->get()->getRowArray();
        return [
            'transactions' => $db->table('transactions')->where('store_id', $storeId)->countAllResults(),
            'items' => $db->table('transaction_items ti')->join('transactions t', 't.id = ti.transaction_id')->where('t.store_id', $storeId)->countAllResults(),
            'payments' => $db->tableExists('transaction_payments') ? $db->table('transaction_payments tp')->join('transactions t', 't.id = tp.transaction_id')->where('t.store_id', $storeId)->countAllResults() : 0,
            'movements' => $db->table('inventory_movements')->where('store_id', $storeId)->countAllResults(),
            'cashbook' => $db->table('debt_cashbook_entries')->where('user_id', $customerId)->countAllResults(),
            'audits' => $db->table('audit_logs')->where('actor_id', $actorId)->where('action', 'CREATE_TRANSACTION')->countAllResults(),
            'debt' => (float) ($balance['current_debt'] ?? 0),
            'stock' => (int) ($product['stock_qty'] ?? 0),
            'price' => (float) ($product['price'] ?? 0),
        ];
    }
}
