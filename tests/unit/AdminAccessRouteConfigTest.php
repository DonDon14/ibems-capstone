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
            "accounting/settlement/preview', 'AccountingController::settlementPreview', ['filter' => 'access:accounting.inspect']",
        ] as $expectedRoute) {
            $this->assertStringContainsString($expectedRoute, $routes);
        }
    }

    public function testAdminRoleDoesNotBypassOperationalMutationRoutes(): void
    {
        $routes = $this->routesFile();

        foreach ([
            "pos/transactions', 'PosController::createTransaction', ['filter' => 'access:store.operate']",
            "store/categories/create', 'StoreController::createCategory', ['filter' => 'access:store.operate']",
            "store/categories/update', 'StoreController::updateCategory', ['filter' => 'access:store.operate']",
            "store/categories/delete', 'StoreController::deleteCategory', ['filter' => 'access:store.operate']",
            "store/payment-methods/create', 'StoreController::createPaymentMethod', ['filter' => 'access:store.operate']",
            "store/payment-methods/update', 'StoreController::updatePaymentMethod', ['filter' => 'access:store.operate']",
            "store/payment-methods/delete', 'StoreController::deletePaymentMethod', ['filter' => 'access:store.operate']",
            "store/day-session/open', 'StoreController::openDaySession', ['filter' => 'access:store.operate']",
            "store/day-session/close', 'StoreController::closeDaySession', ['filter' => 'access:store.operate']",
            "store/opening-balance/set', 'StoreController::setOpeningBalance', ['filter' => 'access:store.operate']",
            "store/cash-movements/create', 'StoreController::createCashMovement', ['filter' => 'access:store.operate']",
            "store/inventory/restock', 'StoreController::restock', ['filter' => 'access:store.operate']",
            "store/inventory/adjust-stock', 'StoreController::adjustStock', ['filter' => 'access:store.operate']",
            "store/inventory/add-product', 'StoreController::addProduct', ['filter' => 'access:store.operate']",
            "store/inventory/update-product', 'StoreController::updateProduct', ['filter' => 'access:store.operate']",
            "accounting/deduction-batches/(:num)/submit', 'AccountingController::submitDeductionBatch/$1', ['filter' => 'access:accounting.operate']",
            "accounting/deduction-batches/(:num)/reconcile', 'AccountingController::reconcileDeductionBatch/$1', ['filter' => 'access:accounting.operate']",
            "accounting/deduction-batches/(:num)/finalize', 'AccountingController::finalizeDeductionBatch/$1', ['filter' => 'access:accounting.operate']",
            "accounting/debt-investigations', 'AccountingController::openDebtInvestigation', ['filter' => 'access:accounting.operate']",
            "accounting/debt-investigations/(:num)/recommend', 'AccountingController::recommendDebtInvestigation/$1', ['filter' => 'access:accounting.operate']",
            "accounting/debts/import-csv', 'AccountingController::importCsv', ['filter' => 'access:accounting.operate']",
            "accounting/debts/credit-limit', 'AccountingController::updateCreditLimit', ['filter' => 'access:accounting.operate']",
        ] as $expectedRoute) {
            $this->assertStringContainsString($expectedRoute, $routes);
        }

        foreach ([
            "accounting/settlement/apply'",
            "accounting/debts/deduct'",
            "accounting/debts/deduct-full'",
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
            "store-admin/store-day-sessions/(:num)/resolve-stale', 'StoreAdminController::resolveStaleStoreDay/$1', ['filter' => 'access:store.review_assigned']",
        ] as $expectedRoute) {
            $this->assertStringContainsString($expectedRoute, $routes);
        }

        $this->assertSame(['STORE_SUPERVISOR'], config(Authorization::class)->rolesFor('store.review_assigned'));
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
                $this->assertSame('accounting/debts/preview-csv', $route);
            }
        }

        $this->assertArrayHasKey('dashboard', $mixedRoutes);
        $this->assertArrayHasKey('store/dashboard', $mixedRoutes);
        $this->assertArrayHasKey('accounting/dashboard', $mixedRoutes);
        $this->assertSame('post', $mixedRoutes['accounting/debts/preview-csv'] ?? null);
    }

    public function testAdminRoleDoesNotEnterThePersonalUserPortal(): void
    {
        $routes = $this->routesFile();

        foreach ([
            'user/dashboard', 'user/dashboard/data', 'user/debt-pin/status', 'user/debt-pin/set',
            'user/history', 'user/summary', 'user/transactions', 'user/transactions/(:num)',
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

        $known = IbemsRouteAudit::inspectAuthorization("['filter' => 'access:store.operate']", $authorization);
        $unknown = IbemsRouteAudit::inspectAuthorization("['filter' => 'access:not.a.policy']", $authorization);
        $missing = IbemsRouteAudit::inspectAuthorization('[]', $authorization);

        $this->assertTrue($known['protected']);
        $this->assertSame('access policy store.operate', $known['label']);
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
