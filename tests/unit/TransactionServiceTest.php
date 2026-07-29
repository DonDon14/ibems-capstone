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

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedProduct(101, 3, 50, $now);

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

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedProduct(101, 1, 50, $now);

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

    public function testDisabledPaymentMethodIsRejectedBeforeWritingTransaction(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', false, $now);
        $this->seedProduct(101, 3, 50, $now);

        $service = new TransactionService();
        $result = $service->createTransaction([
            'store_id' => 1,
            'payment_method' => 'cash',
            'customer_type' => 'walk_in',
            'items' => [
                ['product_id' => 101, 'qty' => 1],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Invalid or disabled payment method.', $result['message']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());
    }

    public function testClosedStoreDayRejectsTransaction(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedProduct(101, 3, 50, $now);

        $service = new TransactionService();
        $result = $service->createTransaction([
            'store_id' => 1,
            'payment_method' => 'cash',
            'customer_type' => 'walk_in',
            'items' => [
                ['product_id' => 101, 'qty' => 1],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Store day is not open', (string) $result['message']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());
    }

    public function testDebtPaymentRejectsInvalidCustomerType(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedProduct(101, 3, 50, $now);
        $this->seedDebtCustomer(501, 'student', 500, 0, '1234', $now);

        $service = new TransactionService();
        $result = $service->createTransaction([
            'store_id' => 1,
            'payment_method' => 'debt',
            'customer_user_id' => 501,
            'debt_pin' => '1234',
            'items' => [
                ['product_id' => 101, 'qty' => 1],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Only faculty/staff can use debt payment.', $result['message']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());
    }

    public function testDebtPaymentRejectsInsufficientCredit(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedProduct(101, 3, 50, $now);
        $this->seedDebtCustomer(501, 'faculty', 100, 75, '1234', $now);

        $service = new TransactionService();
        $result = $service->createTransaction([
            'store_id' => 1,
            'payment_method' => 'debt',
            'customer_user_id' => 501,
            'debt_pin' => '1234',
            'items' => [
                ['product_id' => 101, 'qty' => 1],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Insufficient credit.', $result['message']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());

        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(75.0, (float) ($balance['current_debt'] ?? 0));
    }

    public function testDebtPaymentUpdatesBalanceCashbookStockAndAuditAtomically(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedProduct(101, 5, 50, $now);
        $this->seedDebtCustomer(501, 'faculty', 500, 75, '1234', $now);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'debt',
            'customer_user_id' => 501,
            'debt_pin' => '1234',
            'items' => [
                ['product_id' => 101, 'qty' => 2],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status']);
        $this->assertSame(100.0, (float) $result['total_amount']);

        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(175.0, (float) ($balance['current_debt'] ?? 0));

        $cashbook = $db->table('debt_cashbook_entries')
            ->where('user_id', 501)
            ->where('entry_type', 'debt_purchase')
            ->get()
            ->getRowArray();
        $this->assertNotNull($cashbook);
        $this->assertSame('debit', $cashbook['direction'] ?? null);
        $this->assertSame(75.0, (float) ($cashbook['debt_before'] ?? 0));
        $this->assertSame(175.0, (float) ($cashbook['debt_after'] ?? 0));
        $this->assertSame(325.0, (float) ($cashbook['available_credit_snapshot'] ?? 0));

        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertSame(3, (int) ($product['stock_qty'] ?? 0));
        $this->assertSame(1, $db->table('transactions')->countAllResults());
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'CREATE_TRANSACTION')->countAllResults());
    }

    public function testInvalidDebtPinWritesSecurityAuditWithoutCreatingSale(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedProduct(101, 5, 50, $now);
        $this->seedDebtCustomer(501, 'staff', 500, 25, '1234', $now);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'debt',
            'customer_user_id' => 501,
            'debt_pin' => '9999',
            'items' => [
                ['product_id' => 101, 'qty' => 1],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Invalid debt PIN.', $result['message']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'FAILED_DEBT_PIN')->countAllResults());

        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(5, (int) ($product['stock_qty'] ?? 0));
        $this->assertSame(25.0, (float) ($balance['current_debt'] ?? 0));
    }

    public function testDuplicateProductLinesAreAggregatedBeforeStockDeduction(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedProduct(101, 5, 20, $now);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'cash',
            'customer_type' => 'walk_in',
            'items' => [
                ['product_id' => 101, 'qty' => 1],
                ['product_id' => 101, 'qty' => 2],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status']);
        $this->assertSame(60.0, (float) $result['total_amount']);
        $this->assertSame(1, $db->table('transaction_items')->countAllResults());

        $item = $db->table('transaction_items')->get()->getRowArray();
        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertSame(3, (int) ($item['qty'] ?? 0));
        $this->assertSame(2, (int) ($product['stock_qty'] ?? 0));
    }

    public function testStoreOfficerCannotCreateTransactionForAnotherOfficersStore(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedProduct(101, 5, 20, $now);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'cash',
            'customer_type' => 'walk_in',
            'items' => [
                ['product_id' => 101, 'qty' => 1],
            ],
        ], 99, 'STORE_SYSTEM');

        $this->assertSame('error', $result['status']);
        $this->assertSame(403, $result['code'] ?? null);
        $this->assertSame('You cannot create transactions for this store.', $result['message']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());

        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertSame(5, (int) ($product['stock_qty'] ?? 0));
    }

    private function seedStore(string $now): void
    {
        Database::connect()->table('stores')->insert([
            'id' => 1,
            'store_name' => 'Test Store',
            'officer_id' => 7,
            'is_active' => 1,
            'created_at' => $now,
        ]);
    }

    private function seedOpenStoreDay(string $now): void
    {
        Database::connect()->table('store_day_sessions')->insert([
            'store_id' => 1,
            'business_date' => date('Y-m-d'),
            'status' => 'open',
            'opening_cash' => 1000,
            'opening_ecash' => 500,
            'opening_note' => 'Test open day',
            'opened_by' => 7,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedPaymentMethod(string $code, bool $isActive, string $now): void
    {
        Database::connect()->table('store_payment_methods')->insert([
            'store_id' => 1,
            'code' => $code,
            'label' => ucfirst(str_replace('_', ' ', $code)),
            'icon_class' => 'bi bi-cash',
            'sort_order' => 10,
            'is_active' => $isActive ? 1 : 0,
            'is_system_reserved' => $code === 'debt' ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedProduct(int $id, int $stockQty, float $price, string $now): void
    {
        Database::connect()->table('products')->insert([
            'id' => $id,
            'store_id' => 1,
            'sku' => 'SKU-' . $id,
            'name' => 'Coffee',
            'price' => $price,
            'stock_qty' => $stockQty,
            'is_active' => 1,
            'updated_at' => $now,
        ]);
    }

    private function seedDebtCustomer(int $userId, string $userType, float $creditLimit, float $currentDebt, string $pin, string $now): void
    {
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => $userId,
            'employee_id' => 'EMP-' . $userId,
            'name' => 'Debt Customer',
            'email' => 'debt' . $userId . '@example.test',
            'password_hash' => password_hash('123456', PASSWORD_BCRYPT),
            'debt_pin_hash' => password_hash($pin, PASSWORD_BCRYPT),
            'role' => 'USER',
            'user_type' => $userType,
            'base_salary' => 0,
            'is_active' => 1,
            'created_at' => $now,
        ]);

        $db->table('balances')->insert([
            'user_id' => $userId,
            'credit_limit' => $creditLimit,
            'current_debt' => $currentDebt,
            'updated_at' => $now,
        ]);
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
            'balances',
            'users',
            'products',
            'store_payment_methods',
            'store_day_sessions',
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

        $db->query('CREATE TABLE ' . $tn('store_day_sessions') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            status TEXT NOT NULL,
            opening_cash REAL NOT NULL DEFAULT 0,
            opening_ecash REAL NOT NULL DEFAULT 0,
            opening_note TEXT,
            opened_by INTEGER,
            opened_at TEXT,
            closed_by INTEGER,
            closed_at TEXT,
            counted_cash REAL,
            counted_ecash REAL,
            expected_cash REAL,
            expected_ecash REAL,
            cash_variance REAL,
            ecash_variance REAL,
            closing_note TEXT,
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

        $db->query('CREATE TABLE ' . $tn('users') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id TEXT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            password_hash TEXT,
            debt_pin_hash TEXT,
            role TEXT NOT NULL,
            user_type TEXT NOT NULL,
            qr_token TEXT,
            profile_image_url TEXT,
            base_salary REAL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('balances') . ' (
            user_id INTEGER PRIMARY KEY,
            credit_limit REAL NOT NULL DEFAULT 0,
            current_debt REAL NOT NULL DEFAULT 0,
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
