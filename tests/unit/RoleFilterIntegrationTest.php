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
            ['GET', 'test/store-report-submit', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:store.report_submit']],
            ['GET', 'test/store-checkout', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:store.checkout']],
            ['GET', 'test/store-manage', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:store.manage_assigned']],
            ['GET', 'test/hr-staff-create', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:hr.staff.create']],
            ['GET', 'test/shared-deduction', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:deduction.workflow']],
            ['GET', 'test/accounting-debt-manage', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:accounting.debt_manage']],
            ['GET', 'test/cashier-office', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:cashier.office']],
            ['GET', 'test/be-review', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:be.review']],
            ['GET', 'test/accounting-copy', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:report.accounting_copy']],
            ['GET', 'test/finance-reports', static fn () => service('response')->setJSON([
                'status' => 'success',
            ]), ['filter' => 'access:finance.reports']],
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

    public function testHrCanOnlyReachTheStaffCreationPolicy(): void
    {
        $this->seedUser(20, 'HR');
        $session = [
            'logged_in' => true,
            'user_id' => 20,
            'role' => 'HR',
            'available_roles' => ['HR'],
        ];

        $this->withSession($session)->withHeaders(['Accept' => 'application/json'])
            ->get('test/hr-staff-create')->assertOK();
        $this->withSession($session)->withHeaders(['Accept' => 'application/json'])
            ->get('test/admin-only')->assertStatus(403);
        $this->withSession($session)->withHeaders(['Accept' => 'application/json'])
            ->get('test/store-manage')->assertStatus(403);
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

    public function testNewOfficeRolesDoNotCrossThePayrollBoundary(): void
    {
        $this->seedUser(30, 'PAYROLL_CLERK');
        $payroll = [
            'logged_in' => true,
            'user_id' => 30,
            'role' => 'PAYROLL_CLERK',
            'available_roles' => ['PAYROLL_CLERK'],
        ];
        $this->withSession($payroll)->withHeaders(['Accept' => 'application/json'])
            ->get('test/shared-deduction')->assertOK();
        $this->withSession($payroll)->withHeaders(['Accept' => 'application/json'])
            ->get('test/accounting-debt-manage')->assertStatus(403);

        $this->seedUser(31, 'COLLECTING_OFFICER');
        $collector = [
            'logged_in' => true,
            'user_id' => 31,
            'role' => 'COLLECTING_OFFICER',
            'available_roles' => ['COLLECTING_OFFICER'],
        ];
        $this->withSession($collector)->withHeaders(['Accept' => 'application/json'])
            ->get('test/cashier-office')->assertOK();
        $this->withSession($collector)->withHeaders(['Accept' => 'application/json'])
            ->get('test/shared-deduction')->assertOK();
    }

    public function testCashierHeadInheritsCollectorPortalWithoutStoreOrAccountingManagement(): void
    {
        $this->seedUser(32, 'CASHIER_HEAD');
        $head = [
            'logged_in' => true,
            'user_id' => 32,
            'role' => 'CASHIER_HEAD',
            'available_roles' => ['CASHIER_HEAD'],
        ];

        $this->withSession($head)->withHeaders(['Accept' => 'application/json'])
            ->get('test/cashier-office')->assertOK();
        $this->withSession($head)->withHeaders(['Accept' => 'application/json'])
            ->get('test/shared-deduction')->assertOK();
        $this->withSession($head)->withHeaders(['Accept' => 'application/json'])
            ->get('test/store-checkout')->assertStatus(403);
        $this->withSession($head)->withHeaders(['Accept' => 'application/json'])
            ->get('test/store-manage')->assertStatus(403);
        $this->withSession($head)->withHeaders(['Accept' => 'application/json'])
            ->get('test/accounting-debt-manage')->assertStatus(403);
    }

    public function testSharedDeductionPolicyExcludesStoreAndSystemRoles(): void
    {
        $roles = (new \Config\Authorization())->rolesFor('deduction.workflow');
        $this->assertContains('CASHIER_HEAD', $roles);
        $this->assertContains('COLLECTING_OFFICER', $roles);
        $this->assertContains('PAYROLL_CLERK', $roles);
        $this->assertNotContains('STORE_SYSTEM', $roles);
        $this->assertNotContains('STORE_SUPERVISOR', $roles);
        $this->assertNotContains('ADMIN', $roles);
    }

    public function testOnlySupervisorRoleCanReachStoreReportSubmission(): void
    {
        foreach (['STORE_SUPERVISOR' => 51, 'STORE_SYSTEM' => 52, 'ADMIN' => 53] as $role => $id) {
            $this->seedUser($id, $role);
            $result = $this->withSession([
                'logged_in' => true, 'user_id' => $id, 'role' => $role, 'available_roles' => [$role],
            ])->withHeaders(['Accept' => 'application/json'])->get('test/store-report-submit');
            $result->assertStatus($role === 'STORE_SUPERVISOR' ? 200 : 403);
        }
    }

    public function testDailyReportInboxesAreDepartmentScoped(): void
    {
        foreach ([
            ['id' => 41, 'role' => 'BE_DIRECTOR', 'allowed' => 'test/be-review', 'denied' => 'test/cashier-office'],
            ['id' => 42, 'role' => 'CASHIER_HEAD', 'allowed' => 'test/cashier-office', 'denied' => 'test/be-review'],
            ['id' => 43, 'role' => 'ACCOUNTING_HEAD', 'allowed' => 'test/accounting-copy', 'denied' => 'test/be-review'],
        ] as $case) {
            $this->seedUser($case['id'], $case['role']);
            $session = [
                'logged_in' => true,
                'user_id' => $case['id'],
                'role' => $case['role'],
                'available_roles' => [$case['role']],
            ];
            $this->withSession($session)->withHeaders(['Accept' => 'application/json'])
                ->get($case['allowed'])->assertOK();
            $this->withSession($session)->withHeaders(['Accept' => 'application/json'])
                ->get('test/finance-reports')->assertOK();
            $this->withSession($session)->withHeaders(['Accept' => 'application/json'])
                ->get($case['denied'])->assertStatus(403);
        }
        $this->assertNotContains('PAYROLL_CLERK', (new \Config\Authorization())->rolesFor('report.accounting_copy'));
        $this->assertNotContains('PAYROLL_CLERK', (new \Config\Authorization())->rolesFor('finance.reports'));
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

    public function testEveryNamedPolicyHonorsEveryActiveRoleForReadsAndWrites(): void
    {
        $policies = config(\Config\Authorization::class)->policies;
        $roles = array_values(array_unique(array_merge(...array_values($policies))));
        foreach ($roles as $index => $role) {
            $id = 10000 + $index;
            $this->seedUser($id, $role);
            foreach ($policies as $policy => $allowed) {
                foreach (['GET', 'POST'] as $method) {
                    $uri = 'test/policy-' . $index . '-' . str_replace('.', '-', $policy) . '-' . strtolower($method);
                    $this->withRoutes([[$method, $uri, static fn () => service('response')->setJSON(['status' => 'success']), ['filter' => 'access:' . $policy]]]);
                    $response = $this->withSession(['logged_in' => true, 'user_id' => $id, 'role' => $role, 'available_roles' => [$role]])
                        ->withHeaders(['Accept' => 'application/json', config('Security')->headerName => service('security')->getHash()])->call(strtolower($method), $uri);
                    $response->assertStatus(in_array($role, $allowed, true) ? 200 : 403);
                }
            }
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
