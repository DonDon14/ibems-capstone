<?php

use CodeIgniter\Test\CIUnitTestCase;
use App\Commands\IbemsRouteAudit;
use Config\Authorization;

/** @internal */
final class AdminAccessRouteConfigTest extends CIUnitTestCase
{
    public function testAdminCanInspectStoreAndAccountingReadRoutes(): void
    {
        $routes = $this->routesFile();

        foreach ([
            "store/products', 'StoreController::products', ['filter' => 'access:store.inspect']",
            "store/transactions', 'StoreController::transactions', ['filter' => 'access:store.inspect']",
            "store/reports/summary', 'StoreController::reportSummary', ['filter' => 'access:store.inspect']",
            "accounting/debts/data', 'AccountingController::debtsData', ['filter' => 'access:accounting.inspect']",
            "accounting/debts/scheduled-salary', 'AccountingController::scheduledSalaryQueue', ['filter' => 'access:accounting.inspect']",
            "accounting/salary-schedules', 'AccountingController::salarySchedules', ['filter' => 'access:accounting.inspect']",
            "accounting/settlement/preview', 'AccountingController::settlementPreview', ['filter' => 'access:accounting.inspect']",
        ] as $expectedRoute) {
            $this->assertStringContainsString($expectedRoute, $routes);
        }
    }

    public function testAdminRoleDoesNotBypassOperationalMutationRoutes(): void
    {
        $routes = $this->routesFile();

        foreach ([
            "pos/transactions', 'PosController::createTransaction', ['filter' => 'access:store.checkout']",
            "store/day-session/open', 'StoreController::openDaySession', ['filter' => 'access:store.checkout']",
            "store/day-session/close', 'StoreController::closeDaySession', ['filter' => 'access:store.checkout']",
            "store/opening-balance/set', 'StoreController::setOpeningBalance', ['filter' => 'access:store.checkout']",
            "store/cash-movements/create', 'StoreController::createCashMovement', ['filter' => 'access:store.checkout']",
            "store/categories/create', 'StoreController::createCategory', ['filter' => 'access:store.manage_assigned']",
            "store/categories/update', 'StoreController::updateCategory', ['filter' => 'access:store.manage_assigned']",
            "store/categories/delete', 'StoreController::deleteCategory', ['filter' => 'access:store.manage_assigned']",
            "store/payment-methods/create', 'StoreController::createPaymentMethod', ['filter' => 'access:store.manage_assigned']",
            "store/payment-methods/update', 'StoreController::updatePaymentMethod', ['filter' => 'access:store.manage_assigned']",
            "store/payment-methods/delete', 'StoreController::deletePaymentMethod', ['filter' => 'access:store.manage_assigned']",
            "store/inventory/restock', 'StoreController::restock', ['filter' => 'access:store.manage_assigned']",
            "store/inventory/adjust-stock', 'StoreController::adjustStock', ['filter' => 'access:store.manage_assigned']",
            "store/inventory/add-product', 'StoreController::addProduct', ['filter' => 'access:store.manage_assigned']",
            "store/inventory/update-product', 'StoreController::updateProduct', ['filter' => 'access:store.manage_assigned']",
            "deductions/workspace/batches/(:num)/reconcile', 'DeductionWorkspaceController::reconcileBatch/$1', ['filter' => 'access:deduction.workflow']",
            "deductions/workspace/batches/(:num)/finalize', 'DeductionWorkspaceController::finalizeBatch/$1', ['filter' => 'access:deduction.workflow']",
            "deductions/workspace/items/(:num)/confirm', 'DeductionWorkspaceController::confirmItem/$1', ['filter' => 'access:deduction.workflow']",
            "accounting/debt-investigations', 'AccountingController::openDebtInvestigation', ['filter' => 'access:accounting.investigate']",
            "accounting/debt-investigations/(:num)/recommend', 'AccountingController::recommendDebtInvestigation/$1', ['filter' => 'access:accounting.investigate']",
            "accounting/debts/import-csv', 'AccountingController::importCsv', ['filter' => 'access:accounting.debt_manage']",
            "accounting/debts/financial-profile', 'AccountingController::updateFinancialProfile', ['filter' => 'access:accounting.debt_manage']",
            "accounting/debts/scheduled-salary/(:num)/activate', 'AccountingController::activateScheduledSalaryProfile/$1', ['filter' => 'access:accounting.debt_manage']",
        ] as $expectedRoute) {
            $this->assertStringContainsString($expectedRoute, $routes);
        }

        $authorization = config(Authorization::class);
        $this->assertNull($authorization->rolesFor('accounting.deduct'));
        $this->assertStringNotContainsString("accounting/deduction-batches/(:num)/apply", $routes);
        $this->assertSame(['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD', 'PAYROLL_CLERK', 'CASHIER_HEAD', 'COLLECTING_OFFICER'], $authorization->rolesFor('deduction.workflow'));
        $this->assertSame(['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD'], $authorization->rolesFor('accounting.debt_manage'));
        $this->assertSame(['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD'], $authorization->rolesFor('accounting.investigate'));
        $this->assertNull($authorization->rolesFor('accounting.operate'));

        foreach ([
            "accounting/settlement/apply'",
            "accounting/debts/deduct'",
            "accounting/debts/deduct-full'",
            "accounting/debts/credit-limit'",
        ] as $retiredWriteRoute) {
            $this->assertStringNotContainsString($retiredWriteRoute, $routes);
        }
    }

    public function testStoreAdministratorRoutesUseOnlyTheScopedReviewPolicy(): void
    {
        $routes = $this->routesFile();

        foreach ([
            "store-admin/dashboard', 'StoreAdminController::dashboard', ['filter' => 'access:store.review_assigned']",
            "store-admin/stores/data', 'StoreAdminController::storesData', ['filter' => 'access:store.review_assigned']",
            "store-admin/stores/(:num)/data', 'StoreAdminController::storeDetailsData/$1', ['filter' => 'access:store.review_assigned']",
            "store-admin/store-day-sessions/(:num)/review', 'StoreAdminController::reviewStoreDayVariance/$1', ['filter' => 'access:store.review_assigned']",
            "store-admin/daily-reports/submit', 'DailyStoreReportController::submit', ['filter' => 'access:store.report_submit']",
            "store-admin/store-day-sessions/(:num)/resolve-stale', 'StoreAdminController::resolveStaleStoreDay/$1', ['filter' => 'access:store.review_assigned']",
        ] as $expectedRoute) {
            $this->assertStringContainsString($expectedRoute, $routes);
        }

        $this->assertSame(['STORE_SUPERVISOR'], config(Authorization::class)->rolesFor('store.review_assigned'));
        $this->assertSame(['STORE_SUPERVISOR'], config(Authorization::class)->rolesFor('store.report_submit'));
    }

    public function testEveryMixedAdminOperationalRouteIsReadOnlyOrAnExplicitPreview(): void
    {
        $routes = $this->routesFile();
        preg_match_all(
            '~\$routes->(get|post|put|patch|delete)\\(\'([^\']+)\'[^\\n]*\'filter\' => \'access:([^\']+)\'~i',
            $routes,
            $matches,
            PREG_SET_ORDER
        );

        $authorization = config(Authorization::class);
        $mixedRoutes = [];
        $allowedMixedPosts = [
            'accounting/debts/preview-csv',
            'profile/image',
            'profile/password',
            'profile/debt-pin',
            'notifications/(:num)/read',
            'notifications/read-all',
        ];
        foreach ($matches as $match) {
            $roles = $authorization->rolesFor($match[3]);
            $this->assertNotNull($roles, "Unknown access policy {$match[3]} on route {$match[2]}.");
            if (!in_array('ADMIN', $roles, true) || count($roles) < 2) {
                continue;
            }

            $method = strtolower($match[1]);
            $route = $match[2];
            $mixedRoutes[$route] = $method;
            if ($method !== 'get') {
                $this->assertContains($route, $allowedMixedPosts);
            }
        }

        $this->assertArrayHasKey('dashboard', $mixedRoutes);
        $this->assertArrayHasKey('store/products', $mixedRoutes);
        $this->assertArrayHasKey('accounting/dashboard', $mixedRoutes);
        $this->assertSame('post', $mixedRoutes['accounting/debts/preview-csv'] ?? null);
        $this->assertSame('post', $mixedRoutes['profile/image'] ?? null);
        $this->assertSame('post', $mixedRoutes['profile/password'] ?? null);
        $this->assertSame('post', $mixedRoutes['profile/debt-pin'] ?? null);
        $this->assertSame('post', $mixedRoutes['notifications/(:num)/read'] ?? null);
        $this->assertSame('post', $mixedRoutes['notifications/read-all'] ?? null);
    }

    public function testAdminRoleDoesNotEnterThePersonalUserPortal(): void
    {
        $routes = $this->routesFile();

        foreach ([
            'user/dashboard', 'user/dashboard/data', 'user/debt-pin/status', 'user/debt-pin/set',
            'user/history', 'user/stores', 'user/stores/data', 'user/deductions', 'user/deductions/data', 'user/summary', 'user/transactions', 'user/transactions/(:num)',
            'user/receipt/(:num)', 'user/cashbook',
        ] as $route) {
            $this->assertMatchesRegularExpression(
                '~\$routes->(?:get|post)\\(\'' . preg_quote($route, '~') . '\'[^\\n]*\'filter\' => \'access:user.self\'~',
                $routes
            );
        }

        $this->assertSame(['USER'], config(Authorization::class)->rolesFor('user.self'));
    }

    public function testRoutesUseNamedAccessPoliciesInsteadOfRawRoleLists(): void
    {
        $routes = $this->routesFile();

        $this->assertStringNotContainsString("'filter' => 'role:", $routes);
        preg_match_all("~'filter' => 'access:([^']+)'~", $routes, $matches);
        $this->assertNotEmpty($matches[1]);

        $authorization = config(Authorization::class);
        foreach (array_unique($matches[1]) as $policy) {
            $this->assertNotNull($authorization->rolesFor($policy), "Route uses unknown access policy {$policy}.");
        }
    }

    public function testRouteAuditRecognizesNamedPoliciesAndRejectsUnknownOnes(): void
    {
        $authorization = config(Authorization::class);

        $known = IbemsRouteAudit::inspectAuthorization("['filter' => 'access:store.checkout']", $authorization);
        $unknown = IbemsRouteAudit::inspectAuthorization("['filter' => 'access:not.a.policy']", $authorization);
        $missing = IbemsRouteAudit::inspectAuthorization('[]', $authorization);

        $this->assertTrue($known['protected']);
        $this->assertSame('access policy store.checkout', $known['label']);
        $this->assertFalse($unknown['protected']);
        $this->assertStringContainsString('unknown access policy', $unknown['message']);
        $this->assertFalse($missing['protected']);
        $this->assertStringContainsString('missing an authorization filter', $missing['message']);
    }

    private function routesFile(): string
    {
        return (string) file_get_contents(APPPATH . 'Config/Routes.php');
    }
}
