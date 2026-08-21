<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class DataBrowsingHierarchyConventionTest extends CIUnitTestCase
{
    public function testEmployeeCatalogAndHistoryRoutesUsePersonalAccessPolicy(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        foreach (['user/stores', 'user/stores/data', 'user/history', 'user/transactions', 'user/cashbook'] as $route) {
            $this->assertMatchesRegularExpression(
                '~\$routes->get\(\'' . preg_quote($route, '~') . '\'[^\n]*\'filter\' => \'access:user.self\'~',
                $routes
            );
        }

        $this->assertStringContainsString("\$routes->get('admin/hierarchy'", $routes);
        $this->assertStringContainsString("'filter' => 'access:system.manage'", $routes);
    }

    public function testEmployeeHistoryProvidesScopedTotalsSortingAndPagination(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/UserController.php');
        $view = (string) file_get_contents(APPPATH . 'Views/user/history.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/user-history.js');

        $this->assertStringContainsString("->where('t.user_id', \$userId)", $controller);
        $this->assertStringContainsString("'store_totals'", $controller);
        $this->assertStringContainsString("'grand_totals'", $controller);
        $this->assertStringContainsString("'debt_charged'", $controller);
        $this->assertStringContainsString("'pagination'", $controller);
        $this->assertStringContainsString('$sortColumns', $controller);
        $this->assertStringContainsString('transaction_payments', $controller);

        foreach (['uh-store', 'uh-sort', 'uh-page-size', 'uh-store-totals', 'uh-transactions-pager', 'uh-cashbook-pager'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $view);
            $this->assertStringContainsString('"' . $id . '"', $script);
        }
        $this->assertStringContainsString('historical credit usage', $view);
        $this->assertStringContainsString('current outstanding debt', $view);
    }

    public function testEmployeeCatalogShowsOnlyActiveStoreProductsWithControls(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/UserController.php');
        $view = (string) file_get_contents(APPPATH . 'Views/user/stores.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/user-stores.js');
        $layout = (string) file_get_contents(APPPATH . 'Views/layouts/user.php');

        $this->assertStringContainsString("->where('s.is_active', true)->where('p.is_active', true)", $controller);
        $this->assertStringContainsString("'availability'", $controller);
        $this->assertStringContainsString("'pagination'", $controller);
        $this->assertStringContainsString("'path' => 'user/stores'", $layout);
        foreach (['us-search', 'us-store', 'us-category', 'us-availability', 'us-sort', 'us-pager'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $view);
            $this->assertStringContainsString('"' . $id . '"', $script);
        }
        $this->assertStringContainsString('user-product-image-fallback', $script);
    }

    public function testLargeDataScreensExposeSortAndPaginationControls(): void
    {
        $pairs = [
            [APPPATH . 'Views/admin/products.php', ['ap-sort', 'ap-page-size', 'ap-pager']],
            [APPPATH . 'Views/admin/audit.php', ['audit-sort', 'audit-limit', 'audit-pager']],
            [APPPATH . 'Views/admin/user-view.php', ['uv-sort', 'uv-page-size', 'uv-pager']],
            [APPPATH . 'Views/store/inventory.php', ['inventory-sort', 'inventory-page-size', 'inventory-pager']],
            [APPPATH . 'Views/store/history.php', ['history-sort', 'history-page-size', 'history-pager']],
            [APPPATH . 'Views/accounting/debts.php', ['acct-sort', 'acct-page-size', 'acct-pager']],
        ];

        foreach ($pairs as [$path, $ids]) {
            $source = (string) file_get_contents($path);
            foreach ($ids as $id) {
                $this->assertStringContainsString('id="' . $id . '"', $source, $path . ' is missing ' . $id);
            }
        }
    }

    public function testHierarchyHasPortalAndTransactionFlowReferences(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/hierarchy.php');
        $document = (string) file_get_contents(ROOTPATH . 'docs/system-hierarchy.md');
        $layout = (string) file_get_contents(APPPATH . 'Views/layouts/admin.php');

        foreach (['Administrator', 'Accounting Office', 'Store Supervisor', 'Store Officer / Cashier', 'Employee / User'] as $role) {
            $this->assertStringContainsString($role, $view);
            $this->assertStringContainsString($role, $document);
        }
        $this->assertStringContainsString('Connected transaction flow', $document);
        $this->assertStringContainsString("'path' => 'admin/hierarchy'", $layout);
    }
}
