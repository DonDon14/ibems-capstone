<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminAccountingDebtDetailConventionTest extends TestCase
{
    public function testDebtRowsOpenAReadOnlyTabbedAccountDetail(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = (string) file_get_contents(APPPATH . 'Controllers/AdminController.php');
        $debtQuery = (string) file_get_contents(APPPATH . 'Services/AdminDebtQueryService.php');
        $view = (string) file_get_contents(APPPATH . 'Views/admin/accounting-debts.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-accounting-debts.js');

        $this->assertStringContainsString("admin/accounting-debts/user/(:num)", $routes);
        $this->assertStringContainsString("'filter' => 'access:system.manage'", $routes);
        $this->assertStringContainsString('function accountingDebtUserDetail', $controller);
        $this->assertStringContainsString("'general' => [", $debtQuery);
        $this->assertStringContainsString("'debt' => [", $debtQuery);
        $this->assertStringContainsString("'stores' => array_map", $debtQuery);
        $this->assertStringContainsString('id="ad-account-modal"', $view);
        $this->assertStringContainsString('data-ad-account-user', $script);
        $this->assertStringContainsString('data-ad-account-tab="general"', $script);
        $this->assertStringContainsString('data-ad-account-tab="stores"', $script);
        $this->assertStringContainsString('General Debt Totals', $script);
        $this->assertStringContainsString('Debt Totals Per Store', $script);
        $this->assertStringContainsString('["Enter", " "]', $script);
    }

    public function testDebtActivityUsesServerPaginationAndStoreFiltering(): void
    {
        $debtQuery = (string) file_get_contents(APPPATH . 'Services/AdminDebtQueryService.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-accounting-debts.js');

        $this->assertStringContainsString('$filters[\'page\']', $debtQuery);
        $this->assertStringContainsString('$filters[\'page_size\']', $debtQuery);
        $this->assertStringContainsString('$filters[\'store_id\']', $debtQuery);
        $this->assertStringContainsString('$filters[\'date_from\']', $debtQuery);
        $this->assertStringContainsString('$filters[\'date_to\']', $debtQuery);
        $this->assertStringContainsString("'pagination' => [", $debtQuery);
        $this->assertStringContainsString('data-ad-history-page', $script);
        $this->assertStringContainsString('data-ad-store-toggle', $script);
        $this->assertStringContainsString('data-ad-store-accordion', $script);
        $this->assertStringContainsString('data-ad-store-date-from', $script);
        $this->assertStringContainsString('data-ad-store-date-to', $script);
        $this->assertStringContainsString('data-ad-store-filter-apply', $script);
        $this->assertStringContainsString('data-ad-store-filter-clear', $script);
        $this->assertStringContainsString('page_size: "10"', $script);
    }

    public function testNameCellKeepsIdentityTextSeparated(): void
    {
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-accounting-debts.js');
        $css = (string) file_get_contents(FCPATH . 'assets/css/app.css');

        $this->assertStringContainsString('class="ad-person-copy"', $script);
        $this->assertStringContainsString('.table-person-cell > span', $css);
        $this->assertMatchesRegularExpression('/\.table-person-cell > span\s*\{[^}]*display:\s*grid/s', $css);
    }
}
