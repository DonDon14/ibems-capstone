<?php

use App\Models\StoreModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

final class StoreCustomerAuthorizationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $originalPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $this->originalPrefix = $db->getPrefix();
        // Match the catalog-query fixtures: its cloned count query uses unprefixed aliases.
        $db->setPrefix('');
        $prefix = $db->getPrefix();
        foreach (['store_supervisors', 'stores', 'balances', 'user_roles', 'users', 'user_purchase_cards'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $prefix . $table);
        }
        $db->query('CREATE TABLE ' . $prefix . 'users (id INTEGER PRIMARY KEY, employee_id TEXT, qr_token TEXT, name TEXT, email TEXT, profile_image_url TEXT, role TEXT, user_type TEXT, is_active INTEGER, debt_pin_hash TEXT)');
        $db->query('CREATE TABLE ' . $prefix . 'user_roles (user_id INTEGER, role TEXT)');
        $db->query('CREATE TABLE ' . $prefix . 'balances (user_id INTEGER PRIMARY KEY, credit_limit REAL, current_debt REAL)');
        $db->query('CREATE TABLE ' . $prefix . 'stores (id INTEGER PRIMARY KEY, store_name TEXT, officer_id INTEGER, is_active INTEGER)');
        $db->query('CREATE TABLE ' . $prefix . 'store_supervisors (store_id INTEGER, user_id INTEGER)');
        $db->table('users')->insert(['id' => 501, 'employee_id' => 'STAFF-501', 'name' => 'Synthetic Employee', 'email' => 'employee@example.test', 'role' => 'USER', 'user_type' => 'staff', 'is_active' => 1]);
        $db->table('balances')->insert(['user_id' => 501, 'credit_limit' => 1000, 'current_debt' => 175]);
        // Feature requests in one PHP process must not share an earlier fixture's cache.
        (new ReflectionProperty(StoreModel::class, 'accessibleStoresRequestCache'))->setValue(null, []);
        $this->withRoutes([['GET', 'test/assigned-customers', 'StoreController::debtCustomers', ['filter' => 'access:store.inspect']]]);
    }

    protected function tearDown(): void
    {
        Database::connect()->setPrefix($this->originalPrefix);
        (new ReflectionProperty(StoreModel::class, 'accessibleStoresRequestCache'))->setValue(null, []);
        parent::tearDown();
    }

    public static function storeRoles(): array
    {
        return ['cashier' => ['STORE_SYSTEM'], 'supervisor' => ['STORE_SUPERVISOR']];
    }

    #[DataProvider('storeRoles')]
    public function testStoreRoleWithoutAssignmentCannotEnumerateFinancialRecords(string $role): void
    {
        $session = $this->seedActor($role);
        $result = $this->withSession($session)->withHeaders(['Accept' => 'application/json'])->get('test/assigned-customers?page_size=100');
        $result->assertStatus(403);
        $this->assertArrayNotHasKey('customers', json_decode((string) $result->getJSON(), true));
        $this->assertStringNotContainsString('employee@example.test', (string) $result->getBody());
    }

    #[DataProvider('storeRoles')]
    public function testRemovingLastAssignmentDeniesPreviouslyAllowedOperator(string $role): void
    {
        $session = $this->seedActor($role);
        $this->assignStore($role, true);
        $this->assertGlobalBalances($session);

        $db = Database::connect();
        if ($role === 'STORE_SYSTEM') {
            $db->table('stores')->where('id', 91)->update(['officer_id' => null]);
        } else {
            $db->table('store_supervisors')->where('user_id', 900002)->delete();
        }
        // Keep the earlier store cache deliberately: authorization must check live access.
        $this->withSession($session)->withHeaders(['Accept' => 'application/json'])->get('test/assigned-customers')->assertStatus(403);
    }

    #[DataProvider('storeRoles')]
    public function testInactiveAssignedStoreDoesNotConferFinancialLookup(string $role): void
    {
        $session = $this->seedActor($role);
        $this->assignStore($role, false);
        $this->withSession($session)->withHeaders(['Accept' => 'application/json'])->get('test/assigned-customers')->assertStatus(403);
    }

    #[DataProvider('storeRoles')]
    public function testAssignedOperatorRetainsGlobalEmployeeBalances(string $role): void
    {
        $session = $this->seedActor($role);
        $this->assignStore($role, true);
        // No purchase at the assigned store is needed to see the employee's global balance.
        $this->assertGlobalBalances($session);
    }

    public function testAdministratorInspectionDoesNotRequireStoreAssignment(): void
    {
        $this->assertGlobalBalances($this->seedActor('ADMIN'));
    }

    private function seedActor(string $role): array
    {
        $db = Database::connect();
        $db->table('users')->insert(['id' => 900002, 'name' => 'Synthetic Operator', 'email' => 'operator@example.test', 'role' => $role, 'user_type' => 'staff', 'is_active' => 1]);
        $db->table('user_roles')->insert(['user_id' => 900002, 'role' => $role]);
        return ['logged_in' => true, 'user_id' => 900002, 'role' => $role, 'available_roles' => [$role]];
    }

    private function assignStore(string $role, bool $active): void
    {
        $db = Database::connect();
        $db->table('stores')->insert(['id' => 91, 'store_name' => 'Synthetic Store', 'officer_id' => $role === 'STORE_SYSTEM' ? 900002 : null, 'is_active' => $active ? 1 : 0]);
        if ($role === 'STORE_SUPERVISOR') {
            $db->table('store_supervisors')->insert(['store_id' => 91, 'user_id' => 900002]);
        }
    }

    private function assertGlobalBalances(array $session): void
    {
        $result = $this->withSession($session)->withHeaders(['Accept' => 'application/json'])->get('test/assigned-customers');
        $result->assertOK();
        $customers = json_decode((string) $result->getJSON(), true)['customers'];
        $this->assertCount(1, $customers);
        $this->assertSame(501, (int) $customers[0]['id']);
        $this->assertSame(175.0, (float) $customers[0]['current_debt']);
        $this->assertSame(825.0, (float) $customers[0]['available_credit']);
    }
}
