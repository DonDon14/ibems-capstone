<?php

use App\Services\StoreCatalogQueryService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class StoreCatalogQueryServiceTest extends CIUnitTestCase
{
    private string $originalPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $this->originalPrefix = $db->getPrefix();
        $db->setPrefix('');
        $prefix = '';
        foreach (['user_purchase_cards', 'balances', 'users'] as $table) {
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
        $db->table('users')->insert([
            'id' => 501, 'employee_id' => 'FAC001', 'qr_token' => 'QR-EMP-501', 'name' => 'Maria Santos',
            'email' => 'maria@example.test', 'user_type' => 'faculty', 'is_active' => 1, 'debt_pin_hash' => password_hash('1234', PASSWORD_DEFAULT),
        ]);
        $db->table('balances')->insert(['user_id' => 501, 'credit_limit' => 1000, 'current_debt' => 25]);
        $db->table('user_purchase_cards')->insert(['user_id' => 501, 'is_locked' => 1, 'created_at' => date('Y-m-d H:i:s')]);
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
}
