<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AdminAccessRouteConfigTest extends CIUnitTestCase
{
    public function testAdminCanInspectStoreAndAccountingReadRoutes(): void
    {
        $routes = $this->routesFile();

        foreach ([
            "store/products', 'StoreController::products', ['filter' => 'role:STORE_SYSTEM,ADMIN']",
            "store/transactions', 'StoreController::transactions', ['filter' => 'role:STORE_SYSTEM,ADMIN']",
            "store/reports/summary', 'StoreController::reportSummary', ['filter' => 'role:STORE_SYSTEM,ADMIN']",
            "accounting/debts/data', 'AccountingController::debtsData', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']",
            "accounting/settlement/preview', 'AccountingController::settlementPreview', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']",
        ] as $expectedRoute) {
            $this->assertStringContainsString($expectedRoute, $routes);
        }
    }

    public function testAdminRoleDoesNotBypassOperationalMutationRoutes(): void
    {
        $routes = $this->routesFile();

        foreach ([
            "pos/transactions', 'PosController::createTransaction', ['filter' => 'role:STORE_SYSTEM']",
            "store/categories/create', 'StoreController::createCategory', ['filter' => 'role:STORE_SYSTEM']",
            "store/categories/update', 'StoreController::updateCategory', ['filter' => 'role:STORE_SYSTEM']",
            "store/categories/delete', 'StoreController::deleteCategory', ['filter' => 'role:STORE_SYSTEM']",
            "store/payment-methods/create', 'StoreController::createPaymentMethod', ['filter' => 'role:STORE_SYSTEM']",
            "store/payment-methods/update', 'StoreController::updatePaymentMethod', ['filter' => 'role:STORE_SYSTEM']",
            "store/payment-methods/delete', 'StoreController::deletePaymentMethod', ['filter' => 'role:STORE_SYSTEM']",
            "store/day-session/open', 'StoreController::openDaySession', ['filter' => 'role:STORE_SYSTEM']",
            "store/day-session/close', 'StoreController::closeDaySession', ['filter' => 'role:STORE_SYSTEM']",
            "store/opening-balance/set', 'StoreController::setOpeningBalance', ['filter' => 'role:STORE_SYSTEM']",
            "store/cash-movements/create', 'StoreController::createCashMovement', ['filter' => 'role:STORE_SYSTEM']",
            "store/inventory/restock', 'StoreController::restock', ['filter' => 'role:STORE_SYSTEM']",
            "store/inventory/adjust-stock', 'StoreController::adjustStock', ['filter' => 'role:STORE_SYSTEM']",
            "store/inventory/add-product', 'StoreController::addProduct', ['filter' => 'role:STORE_SYSTEM']",
            "store/inventory/update-product', 'StoreController::updateProduct', ['filter' => 'role:STORE_SYSTEM']",
            "accounting/deduction-batches/(:num)/submit', 'AccountingController::submitDeductionBatch/$1', ['filter' => 'role:ACCOUNTING_OFFICE']",
            "accounting/deduction-batches/(:num)/reconcile', 'AccountingController::reconcileDeductionBatch/$1', ['filter' => 'role:ACCOUNTING_OFFICE']",
            "accounting/deduction-batches/(:num)/finalize', 'AccountingController::finalizeDeductionBatch/$1', ['filter' => 'role:ACCOUNTING_OFFICE']",
            "accounting/debts/import-csv', 'AccountingController::importCsv', ['filter' => 'role:ACCOUNTING_OFFICE']",
            "accounting/debts/credit-limit', 'AccountingController::updateCreditLimit', ['filter' => 'role:ACCOUNTING_OFFICE']",
        ] as $expectedRoute) {
            $this->assertStringContainsString($expectedRoute, $routes);
            $this->assertStringNotContainsString($this->withAdminRole($expectedRoute), $routes);
        }

        foreach ([
            "accounting/settlement/apply'",
            "accounting/debts/deduct'",
            "accounting/debts/deduct-full'",
        ] as $retiredWriteRoute) {
            $this->assertStringNotContainsString($retiredWriteRoute, $routes);
        }
    }

    private function routesFile(): string
    {
        return (string) file_get_contents(APPPATH . 'Config/Routes.php');
    }

    private function withAdminRole(string $route): string
    {
        return str_replace(
            ["role:STORE_SYSTEM']", "role:ACCOUNTING_OFFICE']"],
            ["role:STORE_SYSTEM,ADMIN']", "role:ACCOUNTING_OFFICE,ADMIN']"],
            $route
        );
    }
}
