<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/** @internal */
final class AuthRoleSelectionIntegrationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
        $this->seedThreeRoleUser();
        $this->withRoutes([
            ['GET', 'test/select-role', 'AuthController::selectRolePage'],
            ['POST', 'test/select-role', 'AuthController::selectRole'],
        ]);
        putenv('IBEMS_ROLE_REFRESH_INTERVAL=30');
    }

    protected function tearDown(): void
    {
        putenv('IBEMS_ROLE_REFRESH_INTERVAL');
        parent::tearDown();
    }

    public function testSelectionPageRefreshesRecentlyChangedAssignments(): void
    {
        $result = $this->withSession($this->staleTwoRoleSession())
            ->get('test/select-role');

        $result->assertOK();
        $html = (string) $result->getBody();
        $this->assertStringContainsString('value="ADMIN"', $html);
        $this->assertStringContainsString('value="STORE_SYSTEM"', $html);
        $this->assertStringContainsString('Store Cashier', $html);
        $this->assertStringContainsString('value="STORE_SUPERVISOR"', $html);
        $this->assertStringContainsString('value="USER"', $html);
        $this->assertStringContainsString('Dashboard Demo Store', $html);
        $this->assertStringContainsString('2 assigned stores: Main Campus Store, Tech Annex Store', $html);
    }

    public function testSelectionPostAcceptsARecentlyAssignedRole(): void
    {
        $security = service('security');
        $payload = [
            'role' => 'USER',
            $security->getTokenName() => $security->getHash(),
        ];
        $result = $this->withSession($this->staleTwoRoleSession())
            ->withBodyFormat('json')
            ->post('test/select-role', $payload);

        $result->assertOK();
        $body = json_decode((string) $result->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertSame('success', $body['status'] ?? null);
        $this->assertSame('USER', $body['role'] ?? null);
        $this->assertSame('/user/dashboard', $body['redirect_to'] ?? null);
    }

    private function staleTwoRoleSession(): array
    {
        return [
            'logged_in' => true,
            'user_id' => 1,
            'name' => 'Three Role User',
            'email' => 'three.roles@example.test',
            'role' => 'ADMIN',
            'available_roles' => ['ADMIN', 'STORE_SYSTEM'],
            'roles_refreshed_at' => time(),
        ];
    }

    private function seedThreeRoleUser(): void
    {
        $now = date('Y-m-d H:i:s');
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => 1,
            'name' => 'Three Role User',
            'email' => 'three.roles@example.test',
            'role' => 'ADMIN',
            'user_type' => 'staff',
            'is_active' => 1,
            'created_at' => $now,
        ]);
        foreach (['ADMIN', 'STORE_SYSTEM', 'STORE_SUPERVISOR', 'USER'] as $role) {
            $db->table('user_roles')->insert([
                'user_id' => 1,
                'role' => $role,
                'created_at' => $now,
            ]);
        }
        $db->table('stores')->insertBatch([
            ['id' => 10, 'store_name' => 'Dashboard Demo Store', 'officer_id' => 1, 'is_active' => 1],
            ['id' => 11, 'store_name' => 'Main Campus Store', 'officer_id' => null, 'is_active' => 1],
            ['id' => 12, 'store_name' => 'Tech Annex Store', 'officer_id' => null, 'is_active' => 1],
            ['id' => 13, 'store_name' => 'Inactive Store', 'officer_id' => 1, 'is_active' => 0],
        ]);
        $db->table('store_supervisors')->insertBatch([
            ['store_id' => 11, 'user_id' => 1],
            ['store_id' => 12, 'user_id' => 1],
            ['store_id' => 13, 'user_id' => 1],
        ]);
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $tn = static fn (string $name): string => $prefix . $name;
        foreach (['store_supervisors', 'stores', 'user_roles', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }
        $db->query('CREATE TABLE ' . $tn('users') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            role TEXT NOT NULL,
            user_type TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');
        $db->query('CREATE TABLE ' . $tn('user_roles') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            created_at TEXT
        )');
        $db->query('CREATE TABLE ' . $tn('stores') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_name TEXT NOT NULL,
            officer_id INTEGER,
            is_active INTEGER NOT NULL DEFAULT 1
        )');
        $db->query('CREATE TABLE ' . $tn('store_supervisors') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL
        )');
    }
}
