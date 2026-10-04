<?php

use App\Services\TransactionService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
require_once __DIR__ . '/StoreOfferingIntegrationCases.php';
require_once __DIR__ . '/StoreTypeScenarioCases.php';

/**
 * @internal
 */
final class TransactionServiceTest extends CIUnitTestCase
{
    use StoreOfferingIntegrationCases;
    use StoreTypeScenarioCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
        Database::connect()->resetDataCache();
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
            'cash_received' => 500,
            'customer_type' => 'walk_in',
            'items' => [
                ['product_id' => 101, 'qty' => 2],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status'], json_encode($result));
        $this->assertArrayHasKey('transaction_id', $result);
        $this->assertSame(100.0, (float) $result['total_amount']);
        $this->assertSame(400.0, (float) $result['change_due']);

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

    public function testUntrackedServiceSaleKeepsStockAndWritesCatalogSnapshot(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedProduct(101, 0, 35, $now, 'service', 'untracked', 'gallon');
        $this->seedDebtCustomer(501, 'staff', 0, 0, '1234', $now);
        (new \App\Models\StoreCapabilityModel())->syncForStore(1, ['refill_service']);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'cash',
            'cash_received' => 100,
            'customer_type' => 'staff',
            'customer_user_id' => 501,
            'items' => [['product_id' => 101, 'qty' => 2]],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status'], json_encode($result));
        $this->assertSame(0, (int) $db->table('products')->where('id', 101)->get()->getRowArray()['stock_qty']);
        $this->assertSame(0, $db->table('inventory_movements')->countAllResults());
        $item = $db->table('transaction_items')->get()->getRowArray();
        $this->assertSame('Coffee', $item['item_name_snapshot'] ?? null);
        $this->assertSame('SKU-101', $item['sku_snapshot'] ?? null);
        $this->assertSame('gallon', $item['unit_code_snapshot'] ?? null);
        $this->assertSame('service', $item['item_type_snapshot'] ?? null);
    }

    public function testWaterStationRejectsWalkInAndForgedInternalTypeForEveryProductType(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedProduct(101, 3, 50, $now, 'stock_item', 'tracked', 'bottle');
        $this->seedProduct(102, 0, 25, $now, 'deposit', 'untracked', 'container');
        (new \App\Models\StoreCapabilityModel())->syncForStore(1, ['retail', 'refill_service', 'container_deposits']);

        $payload = [
            'store_id' => 1,
            'payment_method' => 'cash',
            'cash_received' => 50,
            'customer_type' => 'walk_in',
            'items' => [['product_id' => 101, 'qty' => 1]],
        ];
        $service = new TransactionService();
        $walkIn = $service->createTransaction($payload, 7, 'STORE_SYSTEM');
        $this->assertSame('error', $walkIn['status']);
        $this->assertStringContainsString('selected university customer', $walkIn['message']);

        $payload['items'] = [['product_id' => 102, 'qty' => 1]];
        $depositWalkIn = $service->createTransaction($payload, 7, 'STORE_SYSTEM');
        $this->assertSame('error', $depositWalkIn['status']);
        $payload['items'] = [['product_id' => 101, 'qty' => 1]];

        $payload['customer_type'] = 'staff';
        $forged = $service->createTransaction($payload, 7, 'STORE_SYSTEM');
        $this->assertSame('error', $forged['status']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());
        $this->assertSame(3, (int) $db->table('products')->where('id', 101)->get()->getRowArray()['stock_qty']);

        $this->seedDebtCustomer(501, 'staff', 0, 0, '1234', $now);
        $payload['customer_user_id'] = 501;
        $accepted = $service->createTransaction($payload, 7, 'STORE_SYSTEM');
        $this->assertSame('success', $accepted['status'], json_encode($accepted));
        $this->assertSame(2, (int) $db->table('products')->where('id', 101)->get()->getRowArray()['stock_qty']);
    }

    public function testWaterStationAcceptsRegisteredStudentCashSaleWithoutDebtBalance(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedProduct(101, 2, 25, $now, 'stock_item', 'tracked', 'bottle');
        (new \App\Models\StoreCapabilityModel())->syncForStore(1, ['retail', 'refill_service']);
        $db->table('users')->insert([
            'id' => 502, 'employee_id' => 'STU-502', 'name' => 'Student Example',
            'email' => 'student@example.test', 'role' => 'USER', 'user_type' => 'student',
            'base_salary' => 0, 'is_active' => 1, 'created_at' => $now,
        ]);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1, 'payment_method' => 'cash', 'cash_received' => 25,
            'customer_type' => 'walk_in', 'customer_user_id' => 502,
            'items' => [['product_id' => 101, 'qty' => 1]],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status'], json_encode($result));
        $transaction = $db->table('transactions')->get()->getRowArray();
        $this->assertSame('student', $transaction['customer_type']);
        $this->assertSame(502, (int) $transaction['user_id']);
    }

    public function testWaterStationDepartmentChargeRequiresHeadPinAndRecordsInternalPurchase(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedProduct(101, 2, 50, $now, 'stock_item', 'tracked', 'bottle');
        (new \App\Models\StoreCapabilityModel())->syncForStore(1, ['retail', 'refill_service']);
        $tn = static fn (string $name): string => $db->getPrefix() . $name;
        $db->query('CREATE TABLE ' . $tn('departments') . ' (id INTEGER PRIMARY KEY, name TEXT, head_user_id INTEGER, is_active INTEGER)');
        $db->query('CREATE TABLE ' . $tn('department_authorization_pins') . ' (user_id INTEGER PRIMARY KEY, pin_hash TEXT, failed_attempts INTEGER, window_started_at TEXT, locked_until TEXT, last_failed_at TEXT, last_success_at TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('department_debt_periods') . ' (id INTEGER PRIMARY KEY, department_id INTEGER, period_month TEXT, allocation_amount REAL, used_amount REAL, outstanding_amount REAL, status TEXT, updated_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('department_debt_entries') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, department_id INTEGER, period_id INTEGER, entry_type TEXT, direction TEXT, amount REAL, allocation_before REAL, allocation_after REAL, used_before REAL, used_after REAL, outstanding_before REAL, outstanding_after REAL, transaction_id INTEGER, requester_user_id INTEGER, requester_name TEXT, approved_by_user_id INTEGER, actor_id INTEGER, remarks TEXT, meta_json TEXT, created_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('department_debt_transactions') . ' (transaction_id INTEGER PRIMARY KEY, department_id INTEGER, period_id INTEGER, entry_id INTEGER, debt_amount REAL, requester_user_id INTEGER, requester_name TEXT, approved_by_user_id INTEGER, created_at TEXT)');
        $db->table('users')->insert(['id' => 600, 'employee_id' => 'HEAD-600', 'name' => 'Department Head', 'email' => 'head@example.test', 'role' => 'USER', 'user_type' => 'staff', 'base_salary' => 0, 'is_active' => 1]);
        $db->table('departments')->insert(['id' => 10, 'name' => 'Library', 'head_user_id' => 600, 'is_active' => 1]);
        $db->table('department_authorization_pins')->insert(['user_id' => 600, 'pin_hash' => password_hash('2468', PASSWORD_DEFAULT), 'failed_attempts' => 0]);
        $db->table('department_debt_periods')->insert(['id' => 10, 'department_id' => 10, 'period_month' => substr(ibems_business_date(), 0, 7) . '-01', 'allocation_amount' => 500, 'used_amount' => 0, 'outstanding_amount' => 0, 'status' => 'open']);

        $payload = [
            'store_id' => 1, 'payment_method' => 'debt', 'debt_account_type' => 'department',
            'department_id' => 10, 'department_requester_name' => 'Library Staff',
            'department_approver_user_id' => 600, 'department_pin' => '0000',
            'customer_type' => 'walk_in', 'items' => [['product_id' => 101, 'qty' => 1]],
        ];
        $service = new TransactionService();
        $denied = $service->createTransaction($payload, 7, 'STORE_SYSTEM');
        $this->assertSame('error', $denied['status']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());

        $payload['department_pin'] = '2468';
        $accepted = $service->createTransaction($payload, 7, 'STORE_SYSTEM');
        $this->assertSame('success', $accepted['status'], json_encode($accepted));
        $this->assertSame(1, $db->table('department_debt_transactions')->countAllResults());
        $this->assertSame(50.0, (float) $db->table('department_debt_periods')->where('id', 10)->get()->getRowArray()['used_amount']);
        $this->assertSame(1, (int) $db->table('products')->where('id', 101)->get()->getRowArray()['stock_qty']);
    }

    public static function departmentPurchaseMonthBoundaries(): array
    {
        return ['before month'=>['2026-09-30 15:59:59','2026-09-01','2026-10-01'],
            'at month'=>['2026-09-30 16:00:00','2026-10-01','2026-09-01'],
            'before year'=>['2026-12-31 15:59:59','2026-12-01','2027-01-01'],
            'at year'=>['2026-12-31 16:00:00','2027-01-01','2026-12-01']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('departmentPurchaseMonthBoundaries')]
    public function testDepartmentPurchaseSelectsManilaMonthAndPreservesUtcTimestamp(string $utc,string $month,string $otherMonth): void
    {
        $db=Database::connect(); $tn=static fn(string $name):string=>$db->getPrefix().$name;
        $db->query('CREATE TABLE '.$tn('departments').' (id INTEGER PRIMARY KEY,name TEXT,head_user_id INTEGER,is_active INTEGER)');
        $db->query('CREATE TABLE '.$tn('department_debt_periods').' (id INTEGER PRIMARY KEY,department_id INTEGER,period_month TEXT,allocation_amount REAL,used_amount REAL,outstanding_amount REAL,status TEXT,updated_at TEXT)');
        $db->query('CREATE TABLE '.$tn('department_debt_entries').' (id INTEGER PRIMARY KEY AUTOINCREMENT,department_id INTEGER,period_id INTEGER,entry_type TEXT,direction TEXT,amount REAL,allocation_before REAL,allocation_after REAL,used_before REAL,used_after REAL,outstanding_before REAL,outstanding_after REAL,transaction_id INTEGER,requester_user_id INTEGER,requester_name TEXT,approved_by_user_id INTEGER,actor_id INTEGER,remarks TEXT,meta_json TEXT,created_at TEXT)');
        $db->query('CREATE TABLE '.$tn('department_debt_transactions').' (transaction_id INTEGER PRIMARY KEY,department_id INTEGER,period_id INTEGER,entry_id INTEGER,debt_amount REAL,requester_user_id INTEGER,requester_name TEXT,approved_by_user_id INTEGER,created_at TEXT)');
        $db->table('users')->insert(['id'=>600,'employee_id'=>'QA-HEAD','name'=>'Synthetic Head','email'=>'head@example.test','role'=>'USER','user_type'=>'staff','is_active'=>1]);
        $db->table('departments')->insert(['id'=>10,'name'=>'Synthetic Library','head_user_id'=>600,'is_active'=>1]);
        foreach([1=>$month,2=>$otherMonth] as $id=>$periodMonth) $db->table('department_debt_periods')->insert(['id'=>$id,'department_id'=>10,'period_month'=>$periodMonth,'allocation_amount'=>500,'used_amount'=>0,'outstanding_amount'=>0,'status'=>'open']);
        $id=(new \App\Services\DepartmentDebtService($db))->recordPurchase(10,999,50,null,'Synthetic requester',600,7,1,$utc);
        $entry=$db->table('department_debt_entries')->where('id',$id)->get()->getRowArray();
        $this->assertSame(1,(int)$entry['period_id']);
        $this->assertSame($utc,$entry['created_at']);
        $this->assertSame(50.0,(float)$db->table('department_debt_periods')->where('id',1)->get()->getRow('used_amount'));
        $this->assertSame(50.0,(float)$db->table('department_debt_periods')->where('id',1)->get()->getRow('outstanding_amount'));
        $this->assertSame(0.0,(float)$db->table('department_debt_periods')->where('id',2)->get()->getRow('used_amount'));
        $this->assertSame(0.0,(float)$db->table('department_debt_periods')->where('id',2)->get()->getRow('outstanding_amount'));
        $link=$db->table('department_debt_transactions')->where('transaction_id',999)->get()->getRowArray();
        $this->assertSame(1,(int)$link['period_id']);
        $this->assertSame($utc,$link['created_at']);
    }

    public function testCapabilityChangesAreStoreScopedAndBlockStaleCheckoutWithoutLosingStock(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedProduct(101, 3, 35, $now);
        $caps = new \App\Models\StoreCapabilityModel();
        $caps->syncForStore(1, ['food_service']);
        $caps->syncForStore(2, ['retail']);
        $payload = ['store_id' => 1, 'payment_method' => 'cash', 'cash_received' => 100, 'customer_type' => 'walk_in', 'items' => [['product_id' => 101, 'qty' => 1]]];
        $service = new TransactionService();
        $result = $service->createTransaction($payload, 7, 'STORE_SYSTEM');
        $this->assertSame('error', $result['status']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());
        $this->assertSame(3, (int) $db->table('products')->where('id', 101)->get()->getRowArray()['stock_qty']);
        $caps->syncForStore(1, ['retail']);
        $this->assertSame(['retail'], $caps->getForStore(2));
        $this->assertSame('success', $service->createTransaction($payload, 7, 'STORE_SYSTEM')['status']);
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
            'cash_received' => 100,
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

    public function testCashTransactionCanBeRecordedForEmployeeCustomer(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedProduct(101, 3, 50, $now);
        $this->seedDebtCustomer(501, 'faculty', 500, 0, '1234', $now);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'cash',
            'cash_received' => 100,
            'customer_user_id' => 501,
            'items' => [
                ['product_id' => 101, 'qty' => 1],
            ],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status']);
        $transaction = $db->table('transactions')->where('id', $result['transaction_id'])->get()->getRowArray();
        $this->assertSame(501, (int) ($transaction['user_id'] ?? 0));
        $this->assertSame('faculty', $transaction['customer_type'] ?? null);
        $this->assertSame(50.0, (float) $result['change_due']);
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
            'cash_received' => 500,
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
            'cash_received' => 100,
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
        $this->assertSame(0, $db->table('transaction_items')->countAllResults());
        $this->assertSame(0, $db->table('inventory_movements')->countAllResults());
        $this->assertSame(0, $db->table('debt_cashbook_entries')->countAllResults());
        $this->assertSame(0, $db->table('audit_logs')->where('action', 'CREATE_TRANSACTION')->countAllResults());

        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(75.0, (float) ($balance['current_debt'] ?? 0));
        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertSame(3, (int) ($product['stock_qty'] ?? 0));
    }

    public function testDebtPaymentAtExactCreditLimitSucceedsWithoutGoingOver(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedProduct(101, 3, 25, $now);
        $this->seedDebtCustomer(501, 'faculty', 100, 75, '1234', $now);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'debt',
            'customer_user_id' => 501,
            'debt_pin' => '1234',
            'items' => [['product_id' => 101, 'qty' => 1]],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status']);
        $balance = $db->table('balances')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(100.0, (float) ($balance['current_debt'] ?? 0));
        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertSame(2, (int) ($product['stock_qty'] ?? 0));
        $this->assertSame(1, $db->table('transactions')->countAllResults());
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

    public function testFiveInvalidDebtPinsLockTheEmployeeAcrossRequests(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedProduct(101, 5, 50, $now);
        $this->seedDebtCustomer(501, 'faculty', 500, 25, '1234', $now);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $result = $this->createDebtTransaction('9999');
        }

        $this->assertSame('error', $result['status']);
        $this->assertSame(423, $result['code'] ?? null);
        $this->assertStringContainsString('temporarily locked', $result['message']);
        $this->assertIsInt($result['locked_until_epoch'] ?? null);

        $state = $db->table('debt_pin_security')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(5, (int) ($state['failed_attempts'] ?? 0));
        $this->assertNotEmpty($state['locked_until'] ?? null);
        $this->assertSame(4, $db->table('audit_logs')->where('action', 'FAILED_DEBT_PIN')->countAllResults());
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'DEBT_PIN_LOCKED')->countAllResults());

        $correctPinResult = $this->createDebtTransaction('1234');
        $this->assertSame(423, $correctPinResult['code'] ?? null);
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'BLOCKED_DEBT_PIN')->countAllResults());
        $this->assertSame(0, $db->table('transactions')->countAllResults());
    }

    public function testExpiredDebtPinLockAllowsSuccessAndResetsAttempts(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedProduct(101, 5, 50, $now);
        $this->seedDebtCustomer(501, 'staff', 500, 25, '1234', $now);
        $db->table('debt_pin_security')->insert([
            'user_id' => 501,
            'failed_attempts' => 5,
            'window_started_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
            'locked_until' => date('Y-m-d H:i:s', strtotime('-1 minute')),
            'last_failed_at' => date('Y-m-d H:i:s', strtotime('-16 minutes')),
            'updated_at' => $now,
        ]);

        $result = $this->createDebtTransaction('1234');

        $this->assertSame('success', $result['status']);
        $state = $db->table('debt_pin_security')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(0, (int) ($state['failed_attempts'] ?? -1));
        $this->assertNull($state['window_started_at']);
        $this->assertNull($state['locked_until']);
        $this->assertNotEmpty($state['last_success_at'] ?? null);
        $this->assertSame(1, $db->table('audit_logs')->where('action', 'DEBT_PIN_AUTHORIZED')->countAllResults());
        $this->assertSame(1, $db->table('transactions')->countAllResults());

        $authorizationAudit = $db->table('audit_logs')
            ->where('action', 'DEBT_PIN_AUTHORIZED')
            ->get()
            ->getRowArray();
        $payload = (string) ($authorizationAudit['payload_json'] ?? '');
        $this->assertStringNotContainsString('1234', $payload);
        $this->assertStringNotContainsString('debt_pin', $payload);
        $this->assertStringNotContainsString('hash', $payload);
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
            'cash_received' => 100,
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
            'cash_received' => 100,
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
            'business_date' => ibems_business_date(),
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

    private function seedProduct(int $id, int $stockQty, float $price, string $now, string $itemType = 'stock_item', string $stockPolicy = 'tracked', string $unitCode = 'piece'): void
    {
        Database::connect()->table('products')->insert([
            'id' => $id,
            'store_id' => 1,
            'sku' => 'SKU-' . $id,
            'name' => 'Coffee',
            'price' => $price,
            'stock_qty' => $stockQty,
            'item_type' => $itemType,
            'stock_policy' => $stockPolicy,
            'unit_code' => $unitCode,
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

    private function createDebtTransaction(string $pin): array
    {
        return (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'debt',
            'customer_user_id' => 501,
            'debt_pin' => $pin,
            'items' => [
                ['product_id' => 101, 'qty' => 1],
            ],
        ], 7, 'STORE_SYSTEM');
    }

    public function testSplitCashAndGcashPersistsAtomicPaymentLines(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedPaymentMethod('gcash', true, $now);
        $this->seedProduct(101, 3, 100, $now);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'split',
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 50, 'cash_received' => 100],
                ['payment_method' => 'gcash', 'amount' => 50],
            ],
            'customer_type' => 'walk_in',
            'items' => [['product_id' => 101, 'qty' => 1]],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status'], json_encode($result));
        $this->assertSame(50.0, (float) $result['change_due']);
        $transaction = $db->table('transactions')->where('id', $result['transaction_id'])->get()->getRowArray();
        $this->assertSame('split', $transaction['payment_method'] ?? null);
        $payments = $db->table('transaction_payments')->where('transaction_id', $result['transaction_id'])->orderBy('id')->get()->getResultArray();
        $this->assertCount(2, $payments);
        $this->assertSame(['cash', 'gcash'], array_column($payments, 'payment_method'));
        $this->assertSame([50.0, 50.0], array_map('floatval', array_column($payments, 'amount')));
    }

    public function testSplitPaymentMustEqualTransactionTotal(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedPaymentMethod('gcash', true, $now);
        $this->seedProduct(101, 3, 100, $now);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 40, 'cash_received' => 40],
                ['payment_method' => 'gcash', 'amount' => 50],
            ],
            'items' => [['product_id' => 101, 'qty' => 1]],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Payment amounts must equal the transaction total.', $result['message']);
        $this->assertSame(0, Database::connect()->table('transactions')->countAllResults());
    }

    public function testEmployeeCanPayPartCashAndPostOnlyRemainderToDebt(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('cash', true, $now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedDebtCustomer(501, 'staff', 500, 25, '1234', $now);
        $this->seedProduct(101, 3, 100, $now);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 50, 'cash_received' => 50],
                ['payment_method' => 'debt', 'amount' => 50],
            ],
            'customer_user_id' => 501,
            'customer_type' => 'staff',
            'debt_pin' => '1234',
            'items' => [['product_id' => 101, 'qty' => 1]],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status'], json_encode($result));
        $this->assertSame(['cash', 'debt'], array_column($result['payments'], 'payment_method'));
        $this->assertSame(75.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRowArray()['current_debt']);
        $cashbook = $db->table('debt_cashbook_entries')->where('reference_id', $result['transaction_id'])->get()->getRowArray();
        $this->assertSame(50.0, (float) ($cashbook['amount'] ?? 0));
        $this->assertSame(25.0, (float) ($cashbook['debt_before'] ?? 0));
        $this->assertSame(75.0, (float) ($cashbook['debt_after'] ?? 0));
    }

    public function testLockedEmployeePurchaseCardBlocksDebtWithoutUsingPin(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedDebtCustomer(501, 'staff', 500, 0, '1234', $now);
        $this->seedProduct(101, 3, 50, $now);
        $this->createPurchaseCardTable();
        $this->assertTrue((new \App\Services\UserPurchaseCardService($db))->isAvailable());
        $db->table('user_purchase_cards')->insert(['user_id' => 501, 'is_locked' => 1, 'created_at' => $now, 'updated_at' => $now]);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'debt',
            'customer_user_id' => 501,
            'customer_type' => 'staff',
            'items' => [['product_id' => 101, 'qty' => 1]],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('error', $result['status']);
        $this->assertSame(423, $result['code']);
        $this->assertStringContainsString('purchase card is locked', $result['message']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());
    }

    public function testUnlockedEmployeePurchaseCardAuthorizesOneDebtPurchaseThenLocks(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedDebtCustomer(501, 'staff', 500, 0, '1234', $now);
        $this->seedProduct(101, 3, 50, $now);
        $this->createPurchaseCardTable();
        $this->assertTrue((new \App\Services\UserPurchaseCardService($db))->isAvailable());
        $db->table('user_purchase_cards')->insert([
            'user_id' => 501,
            'is_locked' => 0,
            'unlocked_until' => date('Y-m-d H:i:s', time() + 300),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'debt',
            'customer_user_id' => 501,
            'customer_type' => 'staff',
            'items' => [['product_id' => 101, 'qty' => 1]],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('success', $result['status'], json_encode($result));
        $card = $db->table('user_purchase_cards')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(1, (int) $card['is_locked']);
        $this->assertNull($card['unlocked_until']);
        $this->assertNotEmpty($card['last_transaction_at']);
        $this->assertSame(50.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRowArray()['current_debt']);
    }

    public function testPurchaseCardStorageFailureRollsBackAndCanRetry(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedDebtCustomer(501, 'staff', 500, 0, '1234', $now);
        $this->seedProduct(101, 3, 50, $now);
        $this->createPurchaseCardTable();
        $db->table('user_purchase_cards')->insert(['user_id' => 501, 'is_locked' => 0,
            'unlocked_until' => date('Y-m-d H:i:s', time() + 300), 'created_at' => $now, 'updated_at' => $now]);
        $request = ['store_id' => 1, 'payment_method' => 'debt', 'customer_user_id' => 501,
            'customer_type' => 'staff', 'items' => [['product_id' => 101, 'qty' => 1]]];
        $before = $db->table('user_purchase_cards')->get()->getResultArray();
        $db->query('CREATE TRIGGER qa_card_fault BEFORE UPDATE ON ' . $db->prefixTable('user_purchase_cards')
            . " BEGIN SELECT RAISE(ABORT, 'Synthetic card storage failure'); END");
        try {
            $failure = (new TransactionService())->createTransaction($request, 7, 'STORE_SYSTEM');
            $this->assertSame('error', $failure['status']);
            $this->assertSame(500, $failure['code']);
            $this->assertSame($before, $db->table('user_purchase_cards')->get()->getResultArray());
            $this->assertSame(0, $db->table('transactions')->countAllResults());
            $this->assertSame(0.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('current_debt'));
            $this->assertSame(3, (int) $db->table('products')->where('id', 101)->get()->getRow('stock_qty'));
        } finally {
            $db->query('DROP TRIGGER qa_card_fault');
            $db->resetTransStatus();
        }
        $retry = (new TransactionService())->createTransaction($request, 7, 'STORE_SYSTEM');
        $this->assertSame('success', $retry['status'], json_encode($retry));
        $this->assertSame(1, $db->table('transactions')->countAllResults());
        $this->assertSame(50.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('current_debt'));
    }

    public function testFailedDebtPurchaseDoesNotConsumePurchaseCardUnlock(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $unlockedUntil = date('Y-m-d H:i:s', time() + 300);
        $this->seedStore($now);
        $this->seedOpenStoreDay($now);
        $this->seedPaymentMethod('debt', true, $now);
        $this->seedDebtCustomer(501, 'staff', 500, 0, '1234', $now);
        $this->seedProduct(101, 1, 50, $now);
        $this->createPurchaseCardTable();
        $db->table('user_purchase_cards')->insert(['user_id' => 501, 'is_locked' => 0, 'unlocked_until' => $unlockedUntil, 'created_at' => $now, 'updated_at' => $now]);

        $result = (new TransactionService())->createTransaction([
            'store_id' => 1,
            'payment_method' => 'debt',
            'customer_user_id' => 501,
            'customer_type' => 'staff',
            'items' => [['product_id' => 101, 'qty' => 2]],
        ], 7, 'STORE_SYSTEM');

        $this->assertSame('error', $result['status']);
        $card = $db->table('user_purchase_cards')->where('user_id', 501)->get()->getRowArray();
        $this->assertSame(0, (int) $card['is_locked']);
        $this->assertSame($unlockedUntil, $card['unlocked_until']);
        $this->assertSame(0, $db->table('transactions')->countAllResults());
    }

    public function testCheckoutRetryReturnsOriginalReceiptWithoutDuplicateStockOrPayment(): void
    {
        require_once APPPATH.'Database/Migrations/2026-09-30-000001_AddPosRequestReceipts.php';
        (new \App\Database\Migrations\AddPosRequestReceipts())->up();
        $db=Database::connect(); $db->resetDataCache(); $now=date('Y-m-d H:i:s');
        $this->seedStore($now); $this->seedOpenStoreDay($now); $this->seedPaymentMethod('cash',true,$now); $this->seedProduct(101,3,100,$now);
        $service=new TransactionService();
        $request=['request_key'=>'retry-checkout-intent-0001','store_id'=>1,'payment_method'=>'cash','cash_received'=>100,'items'=>[['product_id'=>101,'qty'=>1]]];
        $sale=$service->createTransaction($request,7,'STORE_SYSTEM');
        $this->assertSame('success',$sale['status'],json_encode($sale));
        $this->assertSame($sale,$service->createTransaction($request,7,'STORE_SYSTEM'));
        $db->table('store_day_sessions')->update(['status'=>'closed']);
        $this->assertSame($sale,$service->createTransaction($request,7,'STORE_SYSTEM'));
        $this->assertSame(1,$db->table('transactions')->countAllResults());
        $this->assertSame(1,$db->table('transaction_payments')->countAllResults());
        $this->assertSame(2,(int)$db->table('products')->where('id',101)->get()->getRow('stock_qty'));
        $conflict=$service->createTransaction(array_replace($request,['cash_received'=>200]),7,'STORE_SYSTEM');
        $this->assertSame(409,$conflict['code']);
        $denied=$service->createTransaction($request,999,'STORE_SYSTEM');
        $this->assertSame(403,$denied['code']);
        $this->assertSame(1,$db->table('pos_request_receipts')->countAllResults());
    }

    public function testCheckoutReceiptFailureRollsBackSaleAndInventory(): void
    {
        require_once APPPATH.'Database/Migrations/2026-09-30-000001_AddPosRequestReceipts.php';
        (new \App\Database\Migrations\AddPosRequestReceipts())->up();
        $db=Database::connect(); $db->resetDataCache(); $now=date('Y-m-d H:i:s');
        $this->seedStore($now); $this->seedOpenStoreDay($now); $this->seedPaymentMethod('cash',true,$now); $this->seedProduct(101,3,100,$now);
        $db->query('CREATE TRIGGER reject_pos_receipt BEFORE INSERT ON '.$db->prefixTable('pos_request_receipts')." BEGIN SELECT RAISE(ABORT, 'synthetic receipt failure'); END");
        $result=(new TransactionService())->createTransaction(['request_key'=>'failed-checkout-intent-0001','store_id'=>1,'payment_method'=>'cash','cash_received'=>100,'items'=>[['product_id'=>101,'qty'=>1]]],7,'STORE_SYSTEM');
        $this->assertSame('error',$result['status']);
        $this->assertSame(0,$db->table('transactions')->countAllResults());
        $this->assertSame(0,$db->table('transaction_payments')->countAllResults());
        $this->assertSame(0,$db->table('inventory_movements')->countAllResults());
        $this->assertSame(3,(int)$db->table('products')->where('id',101)->get()->getRow('stock_qty'));
        $db->resetTransStatus();
    }

    public function testInvalidQuantityDoesNotPoisonNextValidCheckout(): void
    {
        $now=date('Y-m-d H:i:s');
        $this->seedStore($now); $this->seedOpenStoreDay($now); $this->seedPaymentMethod('cash',true,$now); $this->seedProduct(101,3,100,$now);
        $service=new TransactionService();
        $request=['store_id'=>1,'payment_method'=>'cash','cash_received'=>100,'items'=>[['product_id'=>101,'qty'=>-1]]];
        $this->assertSame('error',$service->createTransaction($request,7,'STORE_SYSTEM')['status']);
        $request['items'][0]['qty']=1;
        $this->assertSame('success',$service->createTransaction($request,7,'STORE_SYSTEM')['status']);
        $this->assertSame(1,Database::connect()->table('transactions')->countAllResults());
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $tn = static fn (string $name): string => $prefix . $name;

        $tables = [
            'store_repayment_receipts',
            'payment_destination_accounts',
            'pos_request_receipts',
            'department_debt_transactions', 'department_debt_entries', 'department_debt_periods', 'department_authorization_pins', 'departments',
            'rental_bookings', 'rental_packages', 'rental_assets', 'daily_food_offerings', 'store_cash_movements',
            'food_template_details','food_daily_batches','product_families',
            'store_capabilities',
            'user_purchase_cards',
            'debt_pin_security',
            'debt_cashbook_entries',
            'inventory_movements',
            'transaction_items',
            'transaction_payments',
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

        $db->query('CREATE TABLE ' . $tn('store_capabilities') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL, capability TEXT NOT NULL, created_at TEXT, updated_at TEXT)');
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

        $db->query('CREATE TABLE ' . $tn('debt_pin_security') . ' (
            user_id INTEGER PRIMARY KEY,
            failed_attempts INTEGER NOT NULL DEFAULT 0,
            window_started_at TEXT,
            locked_until TEXT,
            last_failed_at TEXT,
            last_success_at TEXT,
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
            low_stock_threshold INTEGER NOT NULL DEFAULT 0,
            item_type TEXT NOT NULL DEFAULT \'stock_item\',
            stock_policy TEXT NOT NULL DEFAULT \'tracked\',
            unit_code TEXT NOT NULL DEFAULT \'piece\',
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

        $db->query('CREATE TABLE ' . $tn('transaction_payments') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_id INTEGER NOT NULL,
            payment_method TEXT NOT NULL,
            amount REAL NOT NULL,
            cash_received REAL,
            change_due REAL,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('transaction_items') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            qty INTEGER NOT NULL,
            unit_price REAL NOT NULL DEFAULT 0,
            line_total REAL NOT NULL DEFAULT 0,
            item_name_snapshot TEXT,
            sku_snapshot TEXT,
            variant_snapshot TEXT,
            unit_code_snapshot TEXT,
            item_type_snapshot TEXT,
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

    public static function repaymentFailureTables(): array
    {
        return ['balance' => ['balances', 'UPDATE'], 'collection' => ['store_cash_movements', 'INSERT'],
            'cashbook' => ['debt_cashbook_entries', 'INSERT'], 'audit' => ['audit_logs', 'INSERT']];
    }

    public static function invalidRepaymentAmounts(): array
    {
        return [
            'numeric prefix' => ['20abc'], 'too many decimals' => ['20.001'],
            'scientific notation' => ['2e1'], 'boolean' => [true], 'array' => [[20]],
            'null' => [null], 'negative' => [-1], 'zero' => [0],
            'comma' => ['1,000'], 'missing integer' => ['.50'],
            'nonfinite' => [INF], 'nan' => [NAN],
        ];
    }

    public static function missingRepaymentKeys(): array
    {
        return ['omitted'=>[false,null], 'null'=>[true,null], 'empty'=>[true,''],
            'whitespace'=>[true,'   '], 'array'=>[true,['invalid']], 'boolean'=>[true,true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingRepaymentKeys')]
    public function testRepaymentRequiresAStringIntentKeyBeforeFinancialWrites(bool $present,mixed $key): void
    {
        $this->offeringSetup();
        require_once APPPATH . 'Database/Migrations/2026-09-30-000002_AddStoreRepaymentReceipts.php';
        (new \App\Database\Migrations\AddStoreRepaymentReceipts())->up();
        $db=Database::connect(); $db->resetDataCache();
        $this->seedDebtCustomer(501,'staff',1000,100,'1234',ibems_now()->format('Y-m-d H:i:s'));
        $request=['store_id'=>1,'user_id'=>501,'amount'=>20,'payment_method'=>'cash'];
        if($present) $request['request_key']=$key;
        $before=$db->table('balances')->get()->getResultArray();
        $result=(new \App\Services\StoreCashOperationService())->createDebtRepayment($request,7,'STORE_SYSTEM');
        $this->assertSame(400,$result['code']);
        $this->assertSame($before,$db->table('balances')->get()->getResultArray());
        foreach(['store_cash_movements','debt_cashbook_entries','audit_logs','store_repayment_receipts'] as $table)
            $this->assertSame(0,$db->table($table)->countAllResults(),$table);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRepaymentAmounts')]
    public function testRepaymentRejectsMalformedMoneyWithoutFinancialWrites(mixed $amount): void
    {
        $this->offeringSetup();
        require_once APPPATH . 'Database/Migrations/2026-09-30-000002_AddStoreRepaymentReceipts.php';
        (new \App\Database\Migrations\AddStoreRepaymentReceipts())->up();
        $db = Database::connect(); $db->resetDataCache();
        $this->seedDebtCustomer(501, 'staff', 1000, 100, '1234', ibems_now()->format('Y-m-d H:i:s'));
        $before = $db->table('balances')->get()->getResultArray();
        $result = (new \App\Services\StoreCashOperationService())->createDebtRepayment([
            'store_id'=>1,'user_id'=>501,'amount'=>$amount,'payment_method'=>'cash','request_key'=>'repayment-invalid-money-0001',
        ],7,'STORE_SYSTEM');
        $this->assertSame(400,$result['code']);
        $this->assertSame($before,$db->table('balances')->get()->getResultArray());
        foreach (['store_cash_movements','debt_cashbook_entries','audit_logs','store_repayment_receipts'] as $table)
            $this->assertSame(0,$db->table($table)->countAllResults(),$table);
    }

    public static function invalidLegacyRepaymentMethods(): array
    {
        return ['unknown'=>['invented',true], 'unnamed electronic'=>['ecash',true],
            'electronic without receiving account'=>['gcash',true], 'inactive cash'=>['cash',false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLegacyRepaymentMethods')]
    public function testLegacyRepaymentCannotBypassActiveMethodAndAccountChecks(string $channel,bool $cashActive): void
    {
        $this->offeringSetup();
        require_once APPPATH . 'Database/Migrations/2026-09-30-000002_AddStoreRepaymentReceipts.php';
        (new \App\Database\Migrations\AddStoreRepaymentReceipts())->up();
        $db = Database::connect(); $db->resetDataCache();
        $this->seedDebtCustomer(501, 'staff', 1000, 100, '1234', ibems_now()->format('Y-m-d H:i:s'));
        $db->table('store_payment_methods')->where(['store_id'=>1,'code'=>'cash'])->update(['is_active'=>$cashActive]);
        $before = $db->table('balances')->get()->getResultArray();
        $result = (new \App\Services\StoreCashOperationService())->createDebtRepayment([
            'store_id'=>1,'user_id'=>501,'amount'=>20,'channel'=>$channel,'request_key'=>'legacy-channel-validation-0001',
        ],7,'STORE_SYSTEM');
        $this->assertSame(400,$result['code']);
        $this->assertSame($before,$db->table('balances')->get()->getResultArray());
        foreach (['store_cash_movements','debt_cashbook_entries','audit_logs'] as $table)
            $this->assertSame(0,$db->table($table)->countAllResults(),$table);
    }

    public static function electronicRepaymentAccounts(): array
    {
        return ['valid explicit'=>[false,'valid',200], 'valid legacy'=>[true,'valid',200],
            'missing'=>[true,'missing',400], 'inactive method'=>[false,'inactive_method',400],
            'inactive account'=>[false,'inactive_account',400], 'other store'=>[true,'other_store',400],
            'other method'=>[true,'other_method',400]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('electronicRepaymentAccounts')]
    public function testElectronicRepaymentChecksAccountOwnershipAndPreservesReceipt(bool $legacy,string $case,int $expected): void
    {
        $this->offeringSetup();
        require_once APPPATH . 'Database/Migrations/2026-09-30-000002_AddStoreRepaymentReceipts.php';
        (new \App\Database\Migrations\AddStoreRepaymentReceipts())->up();
        $db=Database::connect(); $db->resetDataCache();
        $this->seedDebtCustomer(501,'staff',1000,100,'1234',ibems_now()->format('Y-m-d H:i:s'));
        $this->seedPaymentMethod('gcash',$case!=='inactive_method',ibems_now()->format('Y-m-d H:i:s'));
        $this->seedPaymentMethod('bank',true,ibems_now()->format('Y-m-d H:i:s'));
        $method=(int)$db->table('store_payment_methods')->where('code','gcash')->get()->getRow('id');
        $bank=(int)$db->table('store_payment_methods')->where('code','bank')->get()->getRow('id');
        $db->query('CREATE TABLE '.$db->prefixTable('payment_destination_accounts').' (id INTEGER PRIMARY KEY,store_id INTEGER,payment_method_id INTEGER,account_name TEXT,account_number TEXT,is_active INTEGER)');
        $db->table('payment_destination_accounts')->insert(['id'=>1,'store_id'=>$case==='other_store'?2:1,
            'payment_method_id'=>$case==='other_method'?$bank:$method,'account_name'=>'Synthetic GCash','account_number'=>'QA-ONLY','is_active'=>$case!=='inactive_account']);
        $request=['store_id'=>1,'user_id'=>501,'amount'=>'20.50',
            $legacy?'channel':'payment_method'=>'gcash','destination_account_id'=>$case==='missing'?0:1,
            'request_key'=>'repayment-account-check-0001'];
        $service=new \App\Services\StoreCashOperationService();
        $result=$service->createDebtRepayment($request,7,'STORE_SYSTEM');
        $this->assertSame($expected,$result['code']);
        $this->assertSame($expected===200?79.5:100.0,(float)$db->table('balances')->where('user_id',501)->get()->getRow('current_debt'));
        foreach(['store_cash_movements','debt_cashbook_entries','audit_logs','store_repayment_receipts'] as $table)
            $this->assertSame($expected===200?1:0,$db->table($table)->countAllResults(),$table);
        if($expected===200) {
            $this->assertSame(1,$result['payload']['payment']['destination_account_id']);
            $this->assertSame('ecash',$result['payload']['payment']['channel']);
            $before=[]; foreach(['balances','store_cash_movements','debt_cashbook_entries','audit_logs','store_repayment_receipts'] as $table) $before[$table]=$db->table($table)->get()->getResultArray();
            // Historical acknowledgement survives a later receiving-account disable.
            $db->table('payment_destination_accounts')->where('id',1)->update(['is_active'=>false]);
            $this->assertSame($result,$service->createDebtRepayment($request,7,'STORE_SYSTEM'));
            foreach($before as $table=>$rows) $this->assertSame($rows,$db->table($table)->get()->getResultArray());
        }
    }

    public function testRepaymentRetryReplaysOriginalResultAfterClosingAndRejectsChangedDetails(): void
    {
        $this->offeringSetup();
        require_once APPPATH . 'Database/Migrations/2026-09-30-000002_AddStoreRepaymentReceipts.php';
        (new \App\Database\Migrations\AddStoreRepaymentReceipts())->up();
        $db = Database::connect(); $db->resetDataCache();
        $this->seedDebtCustomer(501, 'staff', 1000, 100, '1234', ibems_now()->format('Y-m-d H:i:s'));
        $service = new \App\Services\StoreCashOperationService();
        $request = ['store_id' => 1, 'user_id' => 501, 'amount' => 20, 'channel' => 'cash',
            'request_key' => 'repayment-intent-0001'];
        $first = $service->createDebtRepayment($request, 7, 'STORE_SYSTEM');
        $this->assertSame('success', $first['payload']['status']);
        $this->assertSame($first, $service->createDebtRepayment($request, 7, 'STORE_SYSTEM'));
        $this->assertSame(80.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('current_debt'));
        $db->table('store_day_sessions')->where('store_id', 1)->update(['status' => 'closed']);
        $this->assertSame($first, $service->createDebtRepayment($request, 7, 'STORE_SYSTEM'));
        $request['amount'] = 21;
        $this->assertSame(409, $service->createDebtRepayment($request, 7, 'STORE_SYSTEM')['code']);
        foreach (['store_cash_movements', 'debt_cashbook_entries', 'audit_logs', 'store_repayment_receipts'] as $table)
            $this->assertSame(1, $db->table($table)->countAllResults(), $table);
    }

    public function testDefaultStoreRepaymentUsesTheResolvedStoreReceiptScope(): void
    {
        $this->offeringSetup();
        require_once APPPATH . 'Database/Migrations/2026-09-30-000002_AddStoreRepaymentReceipts.php';
        (new \App\Database\Migrations\AddStoreRepaymentReceipts())->up();
        $db=Database::connect(); $db->resetDataCache();
        $this->seedDebtCustomer(501,'staff',1000,100,'1234',ibems_now()->format('Y-m-d H:i:s'));
        $request=['user_id'=>501,'amount'=>20,'payment_method'=>'cash','request_key'=>'default-store-repayment-0001'];
        $service=new \App\Services\StoreCashOperationService();
        $first=$service->createDebtRepayment($request,7,'STORE_SYSTEM');
        $this->assertSame(200,$first['code']);
        $this->assertSame(1,$first['payload']['payment']['store_id']);
        $request['store_id']=0;
        $this->assertSame($first,$service->createDebtRepayment($request,7,'STORE_SYSTEM'));
        $request['store_id']=1;
        $this->assertSame($first,$service->createDebtRepayment($request,7,'STORE_SYSTEM'));
        $this->assertSame(80.0,(float)$db->table('balances')->where('user_id',501)->get()->getRow('current_debt'));
        foreach(['store_cash_movements','debt_cashbook_entries','audit_logs','store_repayment_receipts'] as $table)
            $this->assertSame(1,$db->table($table)->countAllResults(),$table);
        $this->assertSame(hash('sha256','1:7:default-store-repayment-0001'),$db->table('store_repayment_receipts')->get()->getRow('request_key'));
    }

    public function testRepaymentReceiptFailureRollsBackBalanceCollectionLedgerAndAudit(): void
    {
        $this->offeringSetup();
        require_once APPPATH . 'Database/Migrations/2026-09-30-000002_AddStoreRepaymentReceipts.php';
        (new \App\Database\Migrations\AddStoreRepaymentReceipts())->up();
        $db = Database::connect(); $db->resetDataCache();
        $this->seedDebtCustomer(501, 'staff', 1000, 100, '1234', ibems_now()->format('Y-m-d H:i:s'));
        $db->query('CREATE TRIGGER qa_receipt_fault BEFORE INSERT ON ' . $db->prefixTable('store_repayment_receipts')
            . " BEGIN SELECT RAISE(ABORT, 'Synthetic receipt failure'); END");
        $service = new \App\Services\StoreCashOperationService();
        $request = ['store_id' => 1, 'user_id' => 501, 'amount' => 20, 'channel' => 'cash', 'request_key' => 'repayment-intent-0002'];
        try {
            $this->assertSame('error', $service->createDebtRepayment($request, 7, 'STORE_SYSTEM')['payload']['status']);
            $this->assertSame(100.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('current_debt'));
            foreach (['store_cash_movements', 'debt_cashbook_entries', 'audit_logs', 'store_repayment_receipts'] as $table)
                $this->assertSame(0, $db->table($table)->countAllResults());
        } finally { $db->query('DROP TRIGGER qa_receipt_fault'); $db->resetTransStatus(); }
        $first = $service->createDebtRepayment($request, 7, 'STORE_SYSTEM');
        $this->assertSame('success', $first['payload']['status']);
        $this->assertSame($first, $service->createDebtRepayment($request, 7, 'STORE_SYSTEM'));
    }

    public function testRepaymentRetryRequiresMigrationAndPreservesCurrentActorAuthority(): void
    {
        $this->offeringSetup();
        $db = Database::connect();
        $this->seedDebtCustomer(501, 'staff', 1000, 100, '1234', ibems_now()->format('Y-m-d H:i:s'));
        $service = new \App\Services\StoreCashOperationService();
        $request = ['store_id' => 1, 'user_id' => 501, 'amount' => 20, 'channel' => 'cash', 'request_key' => 'repayment-actor-scope-0001'];
        $this->assertSame(503, $service->createDebtRepayment($request, 7, 'STORE_SYSTEM')['code']);
        $this->assertSame(0, $db->table('store_cash_movements')->countAllResults());
        $this->assertSame(100.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('current_debt'));
        require_once APPPATH . 'Database/Migrations/2026-09-30-000002_AddStoreRepaymentReceipts.php';
        (new \App\Database\Migrations\AddStoreRepaymentReceipts())->up();
        $db->resetDataCache();
        $bad = $request; $bad['request_key'] = 'bad';
        $this->assertSame(400, $service->createDebtRepayment($bad, 7, 'STORE_SYSTEM')['code']);
        $first = $service->createDebtRepayment($request, 7, 'STORE_SYSTEM');
        $this->assertSame('success', $first['payload']['status']);
        $db->table('stores')->where('id', 1)->update(['officer_id' => 8]);
        $this->assertSame(403, $service->createDebtRepayment($request, 7, 'STORE_SYSTEM')['code']);
        $second = $service->createDebtRepayment($request, 8, 'STORE_SYSTEM');
        $this->assertSame('success', $second['payload']['status']);
        $this->assertNotSame($first['payload']['payment']['movement_id'], $second['payload']['payment']['movement_id']);
        $this->assertSame(60.0, (float) $db->table('balances')->where('user_id', 501)->get()->getRow('current_debt'));
        $this->assertSame(2, $db->table('store_repayment_receipts')->countAllResults());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('repaymentFailureTables')]
    public function testRepaymentFailureRollsBackEveryFinancialRecordAndRetryReconciles(string $table, string $operation): void
    {
        $this->offeringSetup();
        require_once APPPATH . 'Database/Migrations/2026-09-30-000002_AddStoreRepaymentReceipts.php';
        (new \App\Database\Migrations\AddStoreRepaymentReceipts())->up();
        Database::connect()->resetDataCache();
        $this->seedDebtCustomer(501, 'staff', 1000, 100, '1234', ibems_now()->format('Y-m-d H:i:s'));
        $db = Database::connect();
        $state = static fn (): array => [
            $db->table('balances')->orderBy('user_id')->get()->getResultArray(),
            $db->table('store_cash_movements')->orderBy('id')->get()->getResultArray(),
            $db->table('debt_cashbook_entries')->orderBy('id')->get()->getResultArray(),
            $db->table('audit_logs')->orderBy('id')->get()->getResultArray(),
            $db->table('store_repayment_receipts')->orderBy('request_key')->get()->getResultArray(),
        ];
        $before = $state();
        $db->query('CREATE TRIGGER qa_repayment_fault BEFORE ' . $operation . ' ON ' . $db->prefixTable($table)
            . " BEGIN SELECT RAISE(ABORT, 'Synthetic repayment failure'); END");
        $service = new \App\Services\StoreCashOperationService();
        $request = ['store_id' => 1, 'user_id' => 501, 'amount' => 60, 'channel' => 'cash', 'reference_no' => 'QA-60','request_key'=>'fault-repayment-intent-0001'];
        try {
            $this->assertSame('error', $service->createDebtRepayment($request, 7, 'STORE_SYSTEM')['payload']['status']);
            $this->assertSame($before, $state());
        } finally {
            $db->query('DROP TRIGGER qa_repayment_fault');
            $db->resetTransStatus();
        }
        $paid = $service->createDebtRepayment($request, 7, 'STORE_SYSTEM');
        $this->assertSame('success', $paid['payload']['status']);
        $after = $state();
        $this->assertSame(40.0, (float) $after[0][0]['current_debt']);
        $this->assertCount(1, $after[1]);
        $this->assertCount(1, $after[2]);
        $this->assertCount(1, $after[3]);
        $this->assertCount(1, $after[4], 'A committed repayment requires its retry receipt.');
        $this->assertSame(100.0, (float) $after[2][0]['debt_before']);
        $this->assertSame(40.0, (float) $after[2][0]['debt_after']);
        $this->assertSame((int) $after[1][0]['id'], (int) $after[2][0]['reference_id']);
        $this->assertSame($paid, $service->createDebtRepayment($request, 7, 'STORE_SYSTEM'));
        $this->assertSame($after, $state(), 'Replaying the committed intent changes no financial records.');
        $request['request_key']='fault-repayment-distinct-0002';
        $this->assertSame('error', $service->createDebtRepayment($request, 7, 'STORE_SYSTEM')['payload']['status']);
        $this->assertSame($after, $state(), 'A payment exceeding the remaining debt must preserve all financial records.');
    }

    private function createPurchaseCardTable(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $db->query('CREATE TABLE ' . $prefix . 'user_purchase_cards (
            user_id INTEGER PRIMARY KEY,
            is_locked INTEGER NOT NULL DEFAULT 1,
            unlocked_until TEXT,
            last_unlocked_at TEXT,
            last_locked_at TEXT,
            last_transaction_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
    }
}
