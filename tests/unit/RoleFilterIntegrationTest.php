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
            ]), ['filter' => 'role:ADMIN']],
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

        $result->assertRedirectTo('/store/dashboard');
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
