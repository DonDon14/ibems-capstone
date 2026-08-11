<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class InitialSeeder extends Seeder
{
    public function run()
    {
        $db = $this->db;

        $existingUsers = (int) $db->table('users')->countAllResults();
        if ($existingUsers > 0) {
            $isRecoverablePostgrePartialSeed = $db->DBDriver === 'Postgre'
                && $existingUsers === 8
                && (int) $db->table('stores')->countAllResults() === 2
                && (int) $db->table('balances')->countAllResults() === 0
                && (int) $db->table('products')->countAllResults() === 0;

            if (! $isRecoverablePostgrePartialSeed) {
                return;
            }

            // Recover only the known partial demo seed left by an interrupted
            // PostgreSQL staging run. The empty production baseline was
            // verified before this seed was attempted.
            $db->query('TRUNCATE TABLE users RESTART IDENTITY CASCADE');
        }

        $db->transStart();

        $now = date('Y-m-d H:i:s');
        $passwordHash = password_hash('123456', PASSWORD_DEFAULT);
        $debtPinHash = password_hash('1234', PASSWORD_BCRYPT);

        $users = [
            [
                'employee_id' => 'ADM001',
                'name' => 'System Administrator',
                'email' => 'admin@ibems.local',
                'password_hash' => $passwordHash,
                'debt_pin_hash' => null,
                'role' => 'ADMIN',
                'user_type' => 'staff',
                'qr_token' => 'QR-ADM-001',
                'base_salary' => 55000,
                'is_active' => true,
                'created_at' => $now,
            ],
            [
                'employee_id' => 'ACC001',
                'name' => 'Accounting Officer',
                'email' => 'accounting@ibems.local',
                'password_hash' => $passwordHash,
                'debt_pin_hash' => null,
                'role' => 'ACCOUNTING_OFFICE',
                'user_type' => 'staff',
                'qr_token' => 'QR-ACC-001',
                'base_salary' => 42000,
                'is_active' => true,
                'created_at' => $now,
            ],
            [
                'employee_id' => 'STR001',
                'name' => 'Main Store Officer',
                'email' => 'store.main@ibems.local',
                'password_hash' => $passwordHash,
                'debt_pin_hash' => null,
                'role' => 'STORE_SYSTEM',
                'user_type' => 'staff',
                'qr_token' => 'QR-STR-001',
                'base_salary' => 28000,
                'is_active' => true,
                'created_at' => $now,
            ],
            [
                'employee_id' => 'STR002',
                'name' => 'Tech Store Officer',
                'email' => 'store.tech@ibems.local',
                'password_hash' => $passwordHash,
                'debt_pin_hash' => null,
                'role' => 'STORE_SYSTEM',
                'user_type' => 'staff',
                'qr_token' => 'QR-STR-002',
                'base_salary' => 28000,
                'is_active' => true,
                'created_at' => $now,
            ],
            [
                'employee_id' => 'FAC001',
                'name' => 'Prof. Maria Santos',
                'email' => 'maria.santos@ibems.local',
                'password_hash' => $passwordHash,
                'debt_pin_hash' => $debtPinHash,
                'role' => 'USER',
                'user_type' => 'faculty',
                'qr_token' => 'QR-FAC-001',
                'base_salary' => 38000,
                'is_active' => true,
                'created_at' => $now,
            ],
            [
                'employee_id' => 'STF001',
                'name' => 'Mark Dela Cruz',
                'email' => 'mark.delacruz@ibems.local',
                'password_hash' => $passwordHash,
                'debt_pin_hash' => $debtPinHash,
                'role' => 'USER',
                'user_type' => 'staff',
                'qr_token' => 'QR-STF-001',
                'base_salary' => 22000,
                'is_active' => true,
                'created_at' => $now,
            ],
            [
                'employee_id' => 'STD001',
                'name' => 'Jane Estrella',
                'email' => 'jane.estrella@ibems.local',
                'password_hash' => $passwordHash,
                'debt_pin_hash' => $debtPinHash,
                'role' => 'USER',
                'user_type' => 'student',
                'qr_token' => 'QR-STD-001',
                'base_salary' => 0,
                'is_active' => true,
                'created_at' => $now,
            ],
            [
                'employee_id' => 'STF002',
                'name' => 'Leo Mercado',
                'email' => 'leo.mercado@ibems.local',
                'password_hash' => $passwordHash,
                'debt_pin_hash' => $debtPinHash,
                'role' => 'USER',
                'user_type' => 'staff',
                'qr_token' => 'QR-STF-002',
                'base_salary' => 24000,
                'is_active' => true,
                'created_at' => $now,
            ],
        ];

        $db->table('users')->insertBatch($users);

        $userRows = $db->table('users')
            ->select('id, email')
            ->whereIn('email', array_column($users, 'email'))
            ->get()
            ->getResultArray();
        $userIdsByEmail = [];
        foreach ($userRows as $row) {
            $userIdsByEmail[(string) $row['email']] = (int) $row['id'];
        }

        $roles = [
            ['email' => 'admin@ibems.local', 'role' => 'ADMIN'],
            ['email' => 'accounting@ibems.local', 'role' => 'ACCOUNTING_OFFICE'],
            ['email' => 'store.main@ibems.local', 'role' => 'STORE_SYSTEM'],
            ['email' => 'store.tech@ibems.local', 'role' => 'STORE_SYSTEM'],
            ['email' => 'store.main@ibems.local', 'role' => 'USER'],
            ['email' => 'maria.santos@ibems.local', 'role' => 'USER'],
            ['email' => 'mark.delacruz@ibems.local', 'role' => 'USER'],
            ['email' => 'jane.estrella@ibems.local', 'role' => 'USER'],
            ['email' => 'leo.mercado@ibems.local', 'role' => 'USER'],
            ['email' => 'admin@ibems.local', 'role' => 'ACCOUNTING_OFFICE'],
        ];
        $roleRows = [];
        foreach ($roles as $role) {
            $userId = $userIdsByEmail[$role['email']] ?? 0;
            if ($userId <= 0) {
                continue;
            }
            $roleRows[] = [
                'user_id' => $userId,
                'role' => $role['role'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($roleRows !== []) {
            $db->table('user_roles')->insertBatch($roleRows);
        }

        $stores = [
            [
                'store_name' => 'Main Campus Store',
                'officer_id' => $userIdsByEmail['store.main@ibems.local'] ?? null,
                'is_active' => true,
                'created_at' => $now,
            ],
            [
                'store_name' => 'Tech Annex Store',
                'officer_id' => $userIdsByEmail['store.tech@ibems.local'] ?? null,
                'is_active' => true,
                'created_at' => $now,
            ],
        ];
        $db->table('stores')->insertBatch($stores);

        $storeRows = $db->table('stores')
            ->select('id, store_name')
            ->whereIn('store_name', array_column($stores, 'store_name'))
            ->get()
            ->getResultArray();
        $storeIdsByName = [];
        foreach ($storeRows as $row) {
            $storeIdsByName[(string) $row['store_name']] = (int) $row['id'];
        }

        $db->table('store_opening_balances')->insertBatch([
            [
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'business_date' => date('Y-m-d', strtotime('-30 days')),
                'opening_balance' => 5000,
                'note' => 'Initial deployment float',
                'opened_by' => $userIdsByEmail['admin@ibems.local'] ?? null,
                'opened_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'store_id' => $storeIdsByName['Tech Annex Store'] ?? 0,
                'business_date' => date('Y-m-d', strtotime('-30 days')),
                'opening_balance' => 3500,
                'note' => 'Initial deployment float',
                'opened_by' => $userIdsByEmail['admin@ibems.local'] ?? null,
                'opened_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $db->table('balances')->insertBatch([
            [
                'user_id' => $userIdsByEmail['maria.santos@ibems.local'] ?? 0,
                'credit_limit' => 7000,
                'current_debt' => 850,
                'updated_at' => $now,
            ],
            [
                'user_id' => $userIdsByEmail['mark.delacruz@ibems.local'] ?? 0,
                'credit_limit' => 5000,
                'current_debt' => 320,
                'updated_at' => $now,
            ],
            [
                'user_id' => $userIdsByEmail['jane.estrella@ibems.local'] ?? 0,
                'credit_limit' => 1000,
                'current_debt' => 0,
                'updated_at' => $now,
            ],
            [
                'user_id' => $userIdsByEmail['leo.mercado@ibems.local'] ?? 0,
                'credit_limit' => 1500,
                'current_debt' => 1250,
                'updated_at' => $now,
            ],
            [
                'user_id' => $userIdsByEmail['store.main@ibems.local'] ?? 0,
                'credit_limit' => 2500,
                'current_debt' => 120,
                'updated_at' => $now,
            ],
        ]);

        $products = [
            [
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'sku' => 'MCS-COF-001',
                'name' => 'Brewed Coffee',
                'variant_label' => '12oz',
                'category' => 'Beverages',
                'supplier' => 'USTP Cafeteria Supply',
                'barcode' => '100000000001',
                'price' => 45,
                'stock_qty' => 110,
                'low_stock_threshold' => 20,
                'location_bin' => 'Counter A',
                'is_active' => true,
                'updated_at' => $now,
            ],
            [
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'sku' => 'MCS-BRD-002',
                'name' => 'Cheese Bread',
                'variant_label' => 'Regular',
                'category' => 'Snacks',
                'supplier' => 'Campus Bakery',
                'barcode' => '100000000002',
                'price' => 35,
                'stock_qty' => 92,
                'low_stock_threshold' => 15,
                'location_bin' => 'Rack B1',
                'is_active' => true,
                'updated_at' => $now,
            ],
            [
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'sku' => 'MCS-WTR-003',
                'name' => 'Bottled Water',
                'variant_label' => '500ml',
                'category' => 'Beverages',
                'supplier' => 'Claveria Water Depot',
                'barcode' => '100000000003',
                'price' => 20,
                'stock_qty' => 185,
                'low_stock_threshold' => 30,
                'location_bin' => 'Cooler 1',
                'is_active' => true,
                'updated_at' => $now,
            ],
            [
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'sku' => 'MCS-JCE-004',
                'name' => 'Mango Juice',
                'variant_label' => '250ml',
                'category' => 'Beverages',
                'supplier' => 'Claveria Beverage Supply',
                'barcode' => '100000000004',
                'price' => 30,
                'stock_qty' => 4,
                'low_stock_threshold' => 12,
                'location_bin' => 'Cooler 2',
                'is_active' => true,
                'updated_at' => $now,
            ],
            [
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'sku' => 'MCS-PEN-005',
                'name' => 'Black Ballpen',
                'variant_label' => '0.5mm',
                'category' => 'School Supplies',
                'supplier' => 'School Supplies Hub',
                'barcode' => '100000000005',
                'price' => 12,
                'stock_qty' => 0,
                'low_stock_threshold' => 25,
                'location_bin' => 'Shelf S1',
                'is_active' => true,
                'updated_at' => $now,
            ],
            [
                'store_id' => $storeIdsByName['Tech Annex Store'] ?? 0,
                'sku' => 'TAS-USB-001',
                'name' => 'USB-C Cable',
                'variant_label' => '1m',
                'category' => 'Accessories',
                'supplier' => 'Tech Essentials PH',
                'barcode' => '200000000001',
                'price' => 180,
                'stock_qty' => 58,
                'low_stock_threshold' => 10,
                'location_bin' => 'Cabinet T2',
                'is_active' => true,
                'updated_at' => $now,
            ],
            [
                'store_id' => $storeIdsByName['Tech Annex Store'] ?? 0,
                'sku' => 'TAS-NBK-002',
                'name' => 'Notebook',
                'variant_label' => 'A5',
                'category' => 'School Supplies',
                'supplier' => 'School Supplies Hub',
                'barcode' => '200000000002',
                'price' => 55,
                'stock_qty' => 140,
                'low_stock_threshold' => 20,
                'location_bin' => 'Shelf S3',
                'is_active' => true,
                'updated_at' => $now,
            ],
            [
                'store_id' => $storeIdsByName['Tech Annex Store'] ?? 0,
                'sku' => 'TAS-MSE-003',
                'name' => 'Wireless Mouse',
                'variant_label' => 'Basic',
                'category' => 'Accessories',
                'supplier' => 'Tech Essentials PH',
                'barcode' => '200000000003',
                'price' => 350,
                'stock_qty' => 2,
                'low_stock_threshold' => 8,
                'location_bin' => 'Cabinet T3',
                'is_active' => true,
                'updated_at' => $now,
            ],
            [
                'store_id' => $storeIdsByName['Tech Annex Store'] ?? 0,
                'sku' => 'TAS-INK-004',
                'name' => 'Printer Ink',
                'variant_label' => 'Black',
                'category' => 'Accessories',
                'supplier' => 'Tech Essentials PH',
                'barcode' => '200000000004',
                'price' => 420,
                'stock_qty' => 0,
                'low_stock_threshold' => 6,
                'location_bin' => 'Cabinet T4',
                'is_active' => true,
                'updated_at' => $now,
            ],
        ];
        $db->table('products')->insertBatch($products);

        $productRows = $db->table('products')
            ->select('id, sku')
            ->whereIn('sku', array_column($products, 'sku'))
            ->get()
            ->getResultArray();
        $productIdsBySku = [];
        foreach ($productRows as $row) {
            $productIdsBySku[(string) $row['sku']] = (int) $row['id'];
        }

        $transactions = [
            [
                'client_txn_id' => 'seed-txn-001',
                'user_id' => null,
                'customer_type' => 'walk_in',
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'amount' => 110,
                'payment_method' => 'cash',
                'status' => 'completed',
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 days 09:15:00')),
            ],
            [
                'client_txn_id' => 'seed-txn-002',
                'user_id' => $userIdsByEmail['maria.santos@ibems.local'] ?? null,
                'customer_type' => 'faculty',
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'amount' => 100,
                'payment_method' => 'debt',
                'status' => 'completed',
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 days 11:40:00')),
            ],
            [
                'client_txn_id' => 'seed-txn-003',
                'user_id' => $userIdsByEmail['mark.delacruz@ibems.local'] ?? null,
                'customer_type' => 'staff',
                'store_id' => $storeIdsByName['Tech Annex Store'] ?? 0,
                'amount' => 235,
                'payment_method' => 'gcash',
                'status' => 'completed',
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days 14:05:00')),
            ],
        ];
        $db->table('transactions')->insertBatch($transactions);

        $transactionRows = $db->table('transactions')
            ->select('id, client_txn_id')
            ->whereIn('client_txn_id', array_column($transactions, 'client_txn_id'))
            ->get()
            ->getResultArray();
        $transactionIdsByClientId = [];
        foreach ($transactionRows as $row) {
            $transactionIdsByClientId[(string) $row['client_txn_id']] = (int) $row['id'];
        }

        $transactionItems = [
            [
                'transaction_id' => $transactionIdsByClientId['seed-txn-001'] ?? 0,
                'product_id' => $productIdsBySku['MCS-COF-001'] ?? 0,
                'qty' => 2,
                'unit_price' => 45,
                'line_total' => 90,
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 days 09:15:00')),
            ],
            [
                'transaction_id' => $transactionIdsByClientId['seed-txn-001'] ?? 0,
                'product_id' => $productIdsBySku['MCS-WTR-003'] ?? 0,
                'qty' => 1,
                'unit_price' => 20,
                'line_total' => 20,
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 days 09:15:00')),
            ],
            [
                'transaction_id' => $transactionIdsByClientId['seed-txn-002'] ?? 0,
                'product_id' => $productIdsBySku['MCS-BRD-002'] ?? 0,
                'qty' => 1,
                'unit_price' => 35,
                'line_total' => 35,
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 days 11:40:00')),
            ],
            [
                'transaction_id' => $transactionIdsByClientId['seed-txn-002'] ?? 0,
                'product_id' => $productIdsBySku['MCS-COF-001'] ?? 0,
                'qty' => 1,
                'unit_price' => 45,
                'line_total' => 45,
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 days 11:40:00')),
            ],
            [
                'transaction_id' => $transactionIdsByClientId['seed-txn-002'] ?? 0,
                'product_id' => $productIdsBySku['MCS-WTR-003'] ?? 0,
                'qty' => 1,
                'unit_price' => 20,
                'line_total' => 20,
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 days 11:40:00')),
            ],
            [
                'transaction_id' => $transactionIdsByClientId['seed-txn-003'] ?? 0,
                'product_id' => $productIdsBySku['TAS-USB-001'] ?? 0,
                'qty' => 1,
                'unit_price' => 180,
                'line_total' => 180,
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days 14:05:00')),
            ],
            [
                'transaction_id' => $transactionIdsByClientId['seed-txn-003'] ?? 0,
                'product_id' => $productIdsBySku['TAS-NBK-002'] ?? 0,
                'qty' => 1,
                'unit_price' => 55,
                'line_total' => 55,
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days 14:05:00')),
            ],
        ];
        $db->table('transaction_items')->insertBatch($transactionItems);

        $inventoryMovements = [
            [
                'product_id' => $productIdsBySku['MCS-COF-001'] ?? 0,
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'type' => 'restock',
                'qty' => 120,
                'unit_cost' => 20,
                'total_cost' => 2400,
                'expected_profit' => 3000,
                'reason' => 'Initial stock load',
                'txn_id' => null,
                'created_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
            ],
            [
                'product_id' => $productIdsBySku['MCS-COF-001'] ?? 0,
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'type' => 'sale',
                'qty' => 3,
                'unit_cost' => null,
                'total_cost' => null,
                'expected_profit' => null,
                'reason' => 'Seed sales',
                'txn_id' => $transactionIdsByClientId['seed-txn-001'] ?? null,
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 days 09:15:00')),
            ],
            [
                'product_id' => $productIdsBySku['MCS-BRD-002'] ?? 0,
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'type' => 'sale',
                'qty' => 1,
                'unit_cost' => null,
                'total_cost' => null,
                'expected_profit' => null,
                'reason' => 'Seed sales',
                'txn_id' => $transactionIdsByClientId['seed-txn-002'] ?? null,
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 days 11:40:00')),
            ],
            [
                'product_id' => $productIdsBySku['TAS-USB-001'] ?? 0,
                'store_id' => $storeIdsByName['Tech Annex Store'] ?? 0,
                'type' => 'sale',
                'qty' => 1,
                'unit_cost' => null,
                'total_cost' => null,
                'expected_profit' => null,
                'reason' => 'Seed sales',
                'txn_id' => $transactionIdsByClientId['seed-txn-003'] ?? null,
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days 14:05:00')),
            ],
        ];
        $db->table('inventory_movements')->insertBatch($inventoryMovements);

        $db->table('debt_cashbook_entries')->insert([
            'user_id' => $userIdsByEmail['maria.santos@ibems.local'] ?? 0,
            'entry_type' => 'debt_purchase',
            'direction' => 'debit',
            'amount' => 100,
            'debt_before' => 750,
            'debt_after' => 850,
            'credit_limit_snapshot' => 7000,
            'available_credit_snapshot' => 6150,
            'reference_type' => 'transaction',
            'reference_id' => $transactionIdsByClientId['seed-txn-002'] ?? null,
            'actor_id' => $userIdsByEmail['store.main@ibems.local'] ?? null,
            'remarks' => 'POS debt purchase',
            'meta_json' => json_encode(['store_id' => $storeIdsByName['Main Campus Store'] ?? 0]),
            'created_at' => date('Y-m-d H:i:s', strtotime('-3 days 11:40:00')),
            'updated_at' => date('Y-m-d H:i:s', strtotime('-3 days 11:40:00')),
        ]);

        $db->table('store_cash_movements')->insertBatch([
            [
                'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                'business_date' => date('Y-m-d', strtotime('-1 day')),
                'channel' => 'cash',
                'movement_type' => 'cash_in',
                'amount' => 500,
                'reason' => 'Cash replenishment',
                'created_by' => $userIdsByEmail['store.main@ibems.local'] ?? null,
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day 08:00:00')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-1 day 08:00:00')),
            ],
            [
                'store_id' => $storeIdsByName['Tech Annex Store'] ?? 0,
                'business_date' => date('Y-m-d', strtotime('-1 day')),
                'channel' => 'ecash',
                'movement_type' => 'cash_in',
                'amount' => 320,
                'reason' => 'Digital wallet top up',
                'created_by' => $userIdsByEmail['store.tech@ibems.local'] ?? null,
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day 08:30:00')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-1 day 08:30:00')),
            ],
        ]);

        $db->table('audit_logs')->insertBatch([
            [
                'actor_id' => $userIdsByEmail['admin@ibems.local'] ?? null,
                'action' => 'LOGIN',
                'entity' => 'users',
                'entity_id' => $userIdsByEmail['admin@ibems.local'] ?? null,
                'payload_json' => json_encode(['email' => 'admin@ibems.local']),
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day 07:55:00')),
            ],
            [
                'actor_id' => $userIdsByEmail['store.main@ibems.local'] ?? null,
                'action' => 'CREATE_TRANSACTION',
                'entity' => 'transactions',
                'entity_id' => $transactionIdsByClientId['seed-txn-002'] ?? null,
                'payload_json' => json_encode([
                    'store_id' => $storeIdsByName['Main Campus Store'] ?? 0,
                    'payment_method' => 'debt',
                    'amount' => 100,
                ]),
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 days 11:40:00')),
            ],
            [
                'actor_id' => $userIdsByEmail['store.tech@ibems.local'] ?? null,
                'action' => 'ADJUST_PRODUCT_STOCK',
                'entity' => 'products',
                'entity_id' => $productIdsBySku['TAS-NBK-002'] ?? null,
                'payload_json' => json_encode([
                    'store_id' => $storeIdsByName['Tech Annex Store'] ?? 0,
                    'reason' => 'Stock recount during closing',
                    'diff_qty' => -2,
                ]),
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days 17:15:00')),
            ],
        ]);

        $db->transComplete();
    }
}
