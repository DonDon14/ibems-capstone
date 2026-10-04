<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

final class RoleRevalidationSecurityTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string|false $originalRefreshInterval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRefreshInterval = getenv('IBEMS_ROLE_REFRESH_INTERVAL');
        putenv('IBEMS_ROLE_REFRESH_INTERVAL=300');
        $db = Database::connect();
        $prefix = $db->getPrefix();
        foreach (['user_roles', 'users'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $prefix . $table);
        }
        $db->query('CREATE TABLE ' . $prefix . 'users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, role TEXT, is_active INTEGER)');
        $db->query('CREATE TABLE ' . $prefix . 'user_roles (user_id INTEGER, role TEXT)');
        $db->table('users')->insert(['id' => 900001, 'name' => 'Synthetic Admin', 'email' => 'admin@example.test', 'role' => 'ADMIN', 'is_active' => 1]);
        $db->table('user_roles')->insert(['user_id' => 900001, 'role' => 'ADMIN']);
        $this->withRoutes([
            ['GET', 'test/revalidated-me', 'AuthController::me'],
            ['GET', 'test/revalidated-admin', static fn () => service('response')->setJSON(['status' => 'success']), ['filter' => 'access:system.manage']],
            ['POST', 'test/revalidated-admin', static fn () => service('response')->setJSON(['status' => 'success']), ['filter' => 'access:system.manage']],
        ]);
    }

    protected function tearDown(): void
    {
        putenv($this->originalRefreshInterval === false ? 'IBEMS_ROLE_REFRESH_INTERVAL' : 'IBEMS_ROLE_REFRESH_INTERVAL=' . $this->originalRefreshInterval);
        parent::tearDown();
    }

    public static function requestMethods(): array
    {
        return ['expired read cache' => ['GET'], 'write with fresh cache' => ['POST']];
    }

    #[DataProvider('requestMethods')]
    public function testFailedAuthoritativeLookupInvalidatesCachedAdmin(string $method): void
    {
        Database::connect()->query('DROP TABLE ' . Database::connect()->getPrefix() . 'user_roles');

        $this->requestAdmin($method)->assertStatus(401);
        $this->assertNull(session()->get('logged_in'));
        $this->assertNull(session()->get('user_id'));
        $this->assertNull(session()->get('role'));
        $this->assertSame([], ibems_available_roles());
        $this->assertNull(session()->get('roles_refreshed_at'));

        // A later request cannot use the former session while lookup is unavailable.
        $this->withSession()->withHeaders(['Accept' => 'application/json'])->get('test/revalidated-admin')->assertStatus(401);
    }

    #[DataProvider('requestMethods')]
    public function testRevokedAdminCannotUseCachedPrivilege(string $method): void
    {
        $db = Database::connect();
        $db->table('users')->where('id', 900001)->update(['role' => 'USER']);
        $db->table('user_roles')->where('user_id', 900001)->update(['role' => 'USER']);

        $this->requestAdmin($method)->assertStatus(403);
        $this->assertSame('USER', ibems_current_role());
        $this->assertSame(['USER'], ibems_available_roles());
    }

    public function testInactiveAccountInvalidatesCurrentRequest(): void
    {
        Database::connect()->table('users')->where('id', 900001)->update(['is_active' => 0]);
        $this->requestAdmin('POST')->assertStatus(401);
        $this->assertSame([], ibems_available_roles());
    }

    public function testMalformedAuthenticatedSessionCannotKeepCachedRole(): void
    {
        $session = $this->cachedAdmin('POST');
        $session['user_id'] = 0;
        $this->withSession($session)->withHeaders(['Accept' => 'application/json'])->get('test/revalidated-admin')->assertStatus(401);
    }

    public function testLookupFailureRedirectsDocumentRequestToLogin(): void
    {
        Database::connect()->query('DROP TABLE ' . Database::connect()->getPrefix() . 'user_roles');
        $this->withSession($this->cachedAdmin('GET'))->withHeaders(['Accept' => 'text/html'])->get('test/revalidated-admin')->assertRedirectTo('/login');
    }

    public function testSessionIntrospectionFailsClosedWhenRoleLookupFails(): void
    {
        Database::connect()->query('DROP TABLE ' . Database::connect()->getPrefix() . 'user_roles');
        $result = $this->withSession($this->cachedAdmin('POST'))->withHeaders(['Accept' => 'application/json'])->get('test/revalidated-me');
        $result->assertStatus(401);
        $this->assertArrayNotHasKey('user', json_decode((string) $result->getJSON(), true));
    }

    private function cachedAdmin(string $method): array
    {
        return ['logged_in' => true, 'user_id' => 900001, 'role' => 'ADMIN', 'available_roles' => ['ADMIN'],
            'roles_refreshed_at' => $method === 'GET' ? time() - 301 : time()];
    }

    private function requestAdmin(string $method)
    {
        $this->withSession($this->cachedAdmin($method))->withHeaders(['Accept' => 'application/json']);
        if ($method === 'POST') {
            $security = service('security');
            return $this->withBodyFormat('json')->post('test/revalidated-admin', [$security->getTokenName() => $security->getHash()]);
        }
        return $this->get('test/revalidated-admin');
    }
}
