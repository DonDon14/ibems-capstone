<?php

use App\Services\TransactionService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class TransactionServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
    }

    public function testCreateTransactionWritesAuditAndDeductsStock(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->table('stores')->insert([
            'id' => 1,
            'store_name' => 'Test Store',
            'officer_id' => 7,
            'is_active' => 1,
            'created_at' => $now,
        ]);

        $db->table('store_opening_balances')->insert([
            'store_id' => 1,
            'business_date' => date('Y-m-d'),
            'opening_balance' => 1000,
            'note' => 'Initial',
            'opened_by' => 7,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $db->table('store_payment_methods')->insert([
            'store_id' => 1,
            'code' => 'cash',
            'label' => 'Cash',
            'icon_class' => 'bi bi-cash',
            'sort_order' => 10,
            'is_active' => 1,
            'is_system_reserved' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $db->table('products')->insert([
            'id' => 101,
            'store_id' => 1,
            'sku' => 'SKU-101',
            'name' => 'Coffee',
            'price' => 50,
            'stock_qty' => 3,
            'is_active' => 1,
            'updated_at' => $now,
        ]);

        $service = new TransactionService();
        $result = $service->createTransaction([
            'store_id' => 1,
            'payment_method' => 'cash',
            'customer_type' => 'walk_in',
            'items' => [
                ['product_id' => 101, 'qty' => 2],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('transaction_id', $result);
        $this->assertSame(100.0, (float) $result['total_amount']);

        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertNotNull($product);
        $this->assertSame(1, (int) ($product['stock_qty'] ?? 0));

        $audit = $db->table('audit_logs')
            ->where('action', 'CREATE_TRANSACTION')
            ->where('entity', 'transactions')
            ->where('entity_id', (int) $result['transaction_id'])
            ->get()
            ->getRowArray();
        $this->assertNotNull($audit);
        $this->assertSame(7, (int) ($audit['actor_id'] ?? 0));
        $this->assertNotEmpty($audit['created_at'] ?? '');
    }

    public function testInsufficientStockRollsBackTransactionAndAudit(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->table('stores')->insert([
            'id' => 1,
            'store_name' => 'Test Store',
            'officer_id' => 7,
            'is_active' => 1,
            'created_at' => $now,
        ]);

        $db->table('store_opening_balances')->insert([
            'store_id' => 1,
            'business_date' => date('Y-m-d'),
            'opening_balance' => 1000,
            'note' => 'Initial',
            'opened_by' => 7,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $db->table('store_payment_methods')->insert([
            'store_id' => 1,
            'code' => 'cash',
            'label' => 'Cash',
            'icon_class' => 'bi bi-cash',
            'sort_order' => 10,
            'is_active' => 1,
            'is_system_reserved' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $db->table('products')->insert([
            'id' => 101,
            'store_id' => 1,
            'sku' => 'SKU-101',
            'name' => 'Coffee',
            'price' => 50,
            'stock_qty' => 1,
            'is_active' => 1,
            'updated_at' => $now,
        ]);

        $service = new TransactionService();
        $result = $service->createTransaction([
            'store_id' => 1,
            'payment_method' => 'cash',
            'customer_type' => 'walk_in',
            'items' => [
                ['product_id' => 101, 'qty' => 2],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Insufficient stock', (string) ($result['message'] ?? ''));

        $txnCount = $db->table('transactions')->countAllResults();
        $auditCount = $db->table('audit_logs')->countAllResults();
        $this->assertSame(0, $txnCount);
        $this->assertSame(0, $auditCount);

        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertSame(1, (int) ($product['stock_qty'] ?? 0));
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $tn = static fn (string $name): string => $prefix . $name;

        $tables = [
            'debt_cashbook_entries',
            'inventory_movements',
            'transaction_items',
            'transactions',
            'audit_logs',
            'products',
            'store_payment_methods',
            'store_opening_balances',
            'stores',
        ];

        foreach ($tables as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }

        $db->query('CREATE TABLE ' . $tn('stores') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_name TEXT NOT NULL,
            officer_id INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('store_opening_balances') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            opening_balance REAL NOT NULL DEFAULT 0,
            note TEXT,
            opened_by INTEGER,
            opened_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('store_payment_methods') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            code TEXT NOT NULL,
            label TEXT NOT NULL,
            icon_class TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            is_system_reserved INTEGER NOT NULL DEFAULT 0,
            created_at TEXT,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('products') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            sku TEXT NOT NULL,
            name TEXT NOT NULL,
            variant_label TEXT,
            category TEXT,
            image_url TEXT,
            barcode TEXT,
            price REAL NOT NULL DEFAULT 0,
            stock_qty INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('transactions') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_txn_id TEXT NOT NULL,
            user_id INTEGER,
            customer_type TEXT NOT NULL,
            store_id INTEGER NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            payment_method TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('transaction_items') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            qty INTEGER NOT NULL,
            unit_price REAL NOT NULL DEFAULT 0,
            line_total REAL NOT NULL DEFAULT 0,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('inventory_movements') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            store_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            qty INTEGER NOT NULL,
            reason TEXT,
            txn_id INTEGER,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('audit_logs') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER,
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id INTEGER,
            payload_json TEXT,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('debt_cashbook_entries') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            entry_type TEXT,
            direction TEXT,
            amount REAL,
            debt_before REAL,
            debt_after REAL,
            credit_limit_snapshot REAL,
            available_credit_snapshot REAL,
            reference_type TEXT,
            reference_id INTEGER,
            actor_id INTEGER,
            remarks TEXT,
            meta_json TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
    }
}
