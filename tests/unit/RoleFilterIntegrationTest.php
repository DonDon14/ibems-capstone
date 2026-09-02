<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/**
 * @internal
 */
final class RoleFilterIntegrationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
        $this->withRoutes([
            ['GET', 'test/admin-only', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:system.manage']],
            ['POST', 'test/admin-only', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:system.manage']],
            ['OPTIONS', 'test/admin-only', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:system.manage']],
            ['GET', 'test/store-admin-only', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:store.review_assigned']],
            ['GET', 'test/store-checkout', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:store.checkout']],
            ['GET', 'test/store-manage', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:store.manage_assigned']],
            ['GET', 'test/unknown-policy', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:not.a.policy']],
        ]);
    }

    public function testUnauthenticatedApiRequestReturnsJsonUnauthorized(): void
    {
        $result = $this->withHeaders(['Accept' => 'application/json'])
            ->get('test/admin-only');

        $result->assertStatus(401);
        $body = $this->jsonBody($result);
        $this->assertSame('Unauthorized. Please login first.', $body['message'] ?? null);
    }

    public function testWrongRoleApiRequestReturnsForbidden(): void
    {
        $this->seedUser(7, 'STORE_SYSTEM');

        $result = $this->withSession([
            'logged_in' => true,
            'user_id' => 7,
            'role' => 'STORE_SYSTEM',
            'available_roles' => ['STORE_SYSTEM'],
        ])->withHeaders(['Accept' => 'application/json'])
            ->get('test/admin-only');

        $result->assertStatus(403);
        $body = $this->jsonBody($result);
        $this->assertSame('Forbidden: You do not have access to this area.', $body['message'] ?? null);
    }

    public function testAllowedRoleReachesProtectedHandler(): void
    {
        $this->seedUser(1, 'ADMIN');

        $result = $this->withSession([
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'ADMIN',
            'available_roles' => ['ADMIN'],
        ])->withHeaders(['Accept' => 'application/json'])
            ->get('test/admin-only');

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame('success', $body['status'] ?? null);
    }

    public function testWrongRoleDocumentRequestRedirectsToItsLandingPage(): void
    {
        $this->seedUser(7, 'STORE_SYSTEM');

        $result = $this->withSession([
            'logged_in' => true,
            'user_id' => 7,
            'role' => 'STORE_SYSTEM',
            'available_roles' => ['STORE_SYSTEM'],
        ])->withHeaders([
            'Accept' => 'text/html',
            'Sec-Fetch-Dest' => 'document',
        ])->get('test/admin-only');

        $result->assertRedirectTo('/store/pos');
    }

    public function testStoreAdministratorReachesScopedPortalButNotSystemAdministration(): void
    {
        $this->seedUser(8, 'STORE_SUPERVISOR');
        $session = [
            'logged_in' => true,
            'user_id' => 8,
            'role' => 'STORE_SUPERVISOR',
            'available_roles' => ['STORE_SUPERVISOR'],
        ];

        $this->withSession($session)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('test/store-admin-only')
            ->assertOK();

        $this->withSession($session)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('test/admin-only')
            ->assertStatus(403);
    }

    public function testCashierAndSupervisorPermissionsStaySeparated(): void
    {
        $this->seedUser(18, 'STORE_SYSTEM');
        $cashier = [
            'logged_in' => true,
            'user_id' => 18,
            'role' => 'STORE_SYSTEM',
            'available_roles' => ['STORE_SYSTEM'],
        ];
        $this->withSession($cashier)->withHeaders(['Accept' => 'application/json'])
            ->get('test/store-checkout')->assertOK();
        $this->withSession($cashier)->withHeaders(['Accept' => 'application/json'])
            ->get('test/store-manage')->assertStatus(403);

        $this->seedUser(19, 'STORE_SUPERVISOR');
        $supervisor = [
            'logged_in' => true,
            'user_id' => 19,
            'role' => 'STORE_SUPERVISOR',
            'available_roles' => ['STORE_SUPERVISOR'],
        ];
        $this->withSession($supervisor)->withHeaders(['Accept' => 'application/json'])
            ->get('test/store-manage')->assertOK();
        $this->withSession($supervisor)->withHeaders(['Accept' => 'application/json'])
            ->get('test/store-checkout')->assertStatus(403);
    }

    public function testSystemAdministratorDoesNotEnterStoreAdministratorPortalImplicitly(): void
    {
        $this->seedUser(1, 'ADMIN');

        $this->withSession([
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'ADMIN',
            'available_roles' => ['ADMIN'],
        ])->withHeaders(['Accept' => 'application/json'])
            ->get('test/store-admin-only')
            ->assertStatus(403);
    }

    public function testAssignedAdminRoleDoesNotOverrideTheSelectedOperationalRole(): void
    {
        $this->seedUser(9, 'STORE_SYSTEM');
        Database::connect()->table('user_roles')->insert([
            'user_id' => 9,
            'role' => 'ADMIN',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->withSession([
            'logged_in' => true,
            'user_id' => 9,
            'role' => 'STORE_SYSTEM',
            'available_roles' => ['STORE_SYSTEM', 'ADMIN'],
        ])->withHeaders(['Accept' => 'application/json'])
            ->get('test/admin-only')
            ->assertStatus(403);
    }

    public function testUnknownPolicyFailsClosed(): void
    {
        $this->seedUser(10, 'ADMIN');

        $this->withSession([
            'logged_in' => true,
            'user_id' => 10,
            'role' => 'ADMIN',
            'available_roles' => ['ADMIN'],
        ])->withHeaders(['Accept' => 'application/json'])
            ->get('test/unknown-policy')
            ->assertStatus(403);
    }

    public function testRecentReadRequestCanReuseVerifiedSessionRoles(): void
    {
        $this->seedUser(11, 'ADMIN');
        Database::connect()->table('users')->where('id', 11)->update(['role' => 'STORE_SYSTEM']);
        Database::connect()->table('user_roles')->where('user_id', 11)->delete();
        Database::connect()->table('user_roles')->insert([
            'user_id' => 11,
            'role' => 'STORE_SYSTEM',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        putenv('IBEMS_ROLE_REFRESH_INTERVAL=30');

        try {
            $this->withSession([
                'logged_in' => true,
                'user_id' => 11,
                'role' => 'ADMIN',
                'available_roles' => ['ADMIN'],
                'roles_refreshed_at' => time(),
            ])->withHeaders(['Accept' => 'application/json'])
                ->get('test/admin-only')
                ->assertOK();
        } finally {
            putenv('IBEMS_ROLE_REFRESH_INTERVAL');
        }
    }

    public function testWriteRequestAlwaysRefreshesRolesBeforeAuthorization(): void
    {
        $this->seedUser(12, 'ADMIN');
        Database::connect()->table('users')->where('id', 12)->update(['role' => 'STORE_SYSTEM']);
        Database::connect()->table('user_roles')->where('user_id', 12)->delete();
        Database::connect()->table('user_roles')->insert([
            'user_id' => 12,
            'role' => 'STORE_SYSTEM',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        putenv('IBEMS_ROLE_REFRESH_INTERVAL=30');

        try {
            $this->withSession([
                'logged_in' => true,
                'user_id' => 12,
                'role' => 'ADMIN',
                'available_roles' => ['ADMIN'],
                'roles_refreshed_at' => time(),
            ])->withHeaders(['Accept' => 'application/json'])
                ->call('options', 'test/admin-only')
                ->assertStatus(403);
        } finally {
            putenv('IBEMS_ROLE_REFRESH_INTERVAL');
        }
    }

    private function seedUser(int $id, string $role): void
    {
        $now = date('Y-m-d H:i:s');
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => $id,
            'name' => 'Test User',
            'email' => strtolower($role) . $id . '@example.test',
            'role' => $role,
            'user_type' => 'staff',
            'is_active' => 1,
            'created_at' => $now,
        ]);
        $db->table('user_roles')->insert([
            'user_id' => $id,
            'role' => $role,
            'created_at' => $now,
        ]);
    }

    private function jsonBody($result): array
    {
        $body = json_decode((string) $result->getJSON(), true);
        $this->assertIsArray($body);

        return $body;
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $tn = static fn (string $name): string => $prefix . $name;

        foreach (['user_roles', 'users'] as $table) {
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
    }
}
