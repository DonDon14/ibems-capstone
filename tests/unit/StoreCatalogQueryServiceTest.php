<?php

use App\Services\StoreCatalogQueryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

final class StoreCatalogQueryServiceTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $originalPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $this->originalPrefix = $db->getPrefix();
        $db->setPrefix('');
        $prefix = '';
        foreach (['store_capabilities', 'stores', 'user_purchase_cards', 'balances', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $prefix . $table);
        }
        $db->query('CREATE TABLE ' . $prefix . 'users (
            id INTEGER PRIMARY KEY, employee_id TEXT, qr_token TEXT, name TEXT, email TEXT,
            profile_image_url TEXT, user_type TEXT, is_active INTEGER, debt_pin_hash TEXT
        )');
        $db->query('CREATE TABLE ' . $prefix . 'balances (user_id INTEGER PRIMARY KEY, credit_limit REAL, current_debt REAL)');
        $db->query('CREATE TABLE ' . $prefix . 'user_purchase_cards (
            user_id INTEGER PRIMARY KEY, is_locked INTEGER NOT NULL DEFAULT 1, unlocked_until TEXT,
            last_unlocked_at TEXT, last_locked_at TEXT, last_transaction_at TEXT, created_at TEXT, updated_at TEXT
        )');
        $db->query('CREATE TABLE ' . $prefix . 'stores (id INTEGER PRIMARY KEY, store_name TEXT, officer_id INTEGER, is_active INTEGER)');
        $db->query('CREATE TABLE ' . $prefix . 'store_capabilities (id INTEGER PRIMARY KEY, store_id INTEGER, capability TEXT, created_at TEXT, updated_at TEXT)');
        $db->table('users')->insert([
            'id' => 501, 'employee_id' => 'FAC001', 'qr_token' => 'QR-EMP-501', 'name' => 'Maria Santos',
            'email' => 'maria@example.test', 'user_type' => 'faculty', 'is_active' => 1, 'debt_pin_hash' => password_hash('1234', PASSWORD_DEFAULT),
        ]);
        $db->table('balances')->insert(['user_id' => 501, 'credit_limit' => 1000, 'current_debt' => 25]);
        $db->table('user_purchase_cards')->insert(['user_id' => 501, 'is_locked' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $this->withRoutes([['GET', 'test/store/customers', 'StoreController::debtCustomers']]);
    }

    protected function tearDown(): void
    {
        Database::connect()->setPrefix($this->originalPrefix);
        parent::tearDown();
    }

    public function testEmployeeQrTokenIdentifiesCustomerWithoutExposingTokenAndReturnsLockState(): void
    {
        $result = (new StoreCatalogQueryService())->debtCustomers(Database::connect(), 'QR-EMP-501', 1, 20, 'name', 'asc');

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $result['customers']);
        $customer = $result['customers'][0];
        $this->assertSame(501, (int) $customer['id']);
        $this->assertTrue($customer['qr_match']);
        $this->assertSame('card_unlock', $customer['authorization_mode']);
        $this->assertTrue($customer['purchase_card_locked']);
        $this->assertArrayNotHasKey('qr_token', $customer);
        $this->assertArrayNotHasKey('debt_pin_hash', $customer);
    }

    public function testActivePhoneUnlockIsReturnedAsReadyForPos(): void
    {
        Database::connect()->table('user_purchase_cards')->where('user_id', 501)->update([
            'is_locked' => 0,
            'unlocked_until' => date('Y-m-d H:i:s', time() + 300),
        ]);

        $result = (new StoreCatalogQueryService())->debtCustomers(Database::connect(), 'FAC001', 1, 20, 'name', 'asc');
        $customer = $result['customers'][0];
        $this->assertFalse($customer['purchase_card_locked']);
        $this->assertNotEmpty($customer['purchase_card_unlocked_until']);
    }

    public function testWaterStationCustomerSearchIncludesStudentWithoutDebtAccount(): void
    {
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => 502, 'employee_id' => 'STU002', 'qr_token' => 'QR-STU-502',
            'name' => 'Student Example', 'email' => 'student@example.test',
            'user_type' => 'student', 'is_active' => 1,
        ]);

        $ordinary = (new StoreCatalogQueryService())->debtCustomers($db, 'Student', 1, 20, 'name', 'asc');
        $this->assertCount(0, $ordinary['customers']);

        $waterStation = (new StoreCatalogQueryService())->debtCustomers($db, 'Student', 1, 20, 'name', 'asc', true);
        $this->assertCount(1, $waterStation['customers']);
        $this->assertSame('student', $waterStation['customers'][0]['user_type']);
        $this->assertEqualsWithDelta(0.0, (float) $waterStation['customers'][0]['available_credit'], 0.001);
        $this->assertArrayNotHasKey('qr_token', $waterStation['customers'][0]);
    }

    public function testStudentSearchEndpointRequiresAssignedWaterStation(): void
    {
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => 502, 'employee_id' => 'STU002', 'qr_token' => 'QR-STU-502',
            'name' => 'Student Example', 'email' => 'student@example.test',
            'user_type' => 'student', 'is_active' => 1,
        ]);
        $db->table('stores')->insert(['id' => 91, 'store_name' => 'Water Station', 'officer_id' => 777777, 'is_active' => 1]);
        $db->table('stores')->insert(['id' => 92, 'store_name' => 'Other Store', 'officer_id' => 888888, 'is_active' => 1]);
        $db->table('store_capabilities')->insert(['store_id' => 91, 'capability' => 'refill_service']);

        $session = ['logged_in' => true, 'user_id' => 777777, 'role' => 'STORE_SYSTEM', 'available_roles' => ['STORE_SYSTEM']];
        $allowed = $this->withSession($session)->get('test/store/customers?store_id=91&include_students=1&q=Student');
        $allowed->assertOK();
        $this->assertStringContainsString('"user_type": "student"', (string) $allowed->getBody());

        $unassigned = $this->withSession($session)->get('test/store/customers?store_id=92&include_students=1&q=Student');
        $unassigned->assertStatus(403);

        $db->table('store_capabilities')->where('store_id', 91)->delete();
        $notWaterStation = $this->withSession($session)->get('test/store/customers?store_id=91&include_students=1&q=Student');
        $notWaterStation->assertStatus(403);
    }
}
