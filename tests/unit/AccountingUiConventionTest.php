<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AccountingUiConventionTest extends TestCase
{
    public function testAccountingPagesUseSharedPageAndDataStateComponents(): void
    {
        $dashboard = file_get_contents(APPPATH . 'Views/accounting/dashboard.php');
        $debts = file_get_contents(APPPATH . 'Views/accounting/debts.php') . file_get_contents(APPPATH . 'Views/components/accounting_debt_modals.php');

        $this->assertIsString($dashboard);
        $this->assertIsString($debts);
        $this->assertStringContainsString("view('components/page_header'", $dashboard);
        $this->assertStringContainsString("view('components/page_header'", $debts);
        $this->assertStringContainsString("view('components/data_state'", $dashboard);
        $this->assertStringContainsString("view('components/data_state'", $debts);
        $this->assertStringContainsString('assets/css/accounting-debts.css', $debts);
        $this->assertStringContainsString("'eyebrow' => 'Payroll controls'", $debts);
        $this->assertStringContainsString("'titleId' => 'deduction-workflow-title'", $debts);
    }

    public function testDebtPagerUsesTheSharedPaginationConvention(): void
    {
        $view = file_get_contents(APPPATH . 'Views/accounting/debts.php') . file_get_contents(APPPATH . 'Views/components/accounting_debt_modals.php');
        $script = file_get_contents(ROOTPATH . 'public/assets/js/accounting-debts.js') . file_get_contents(ROOTPATH . 'public/assets/js/accounting-debts.part2.js') . file_get_contents(ROOTPATH . 'public/assets/js/accounting-debts.part3.js');

        $this->assertIsString($view);
        $this->assertIsString($script);
        $this->assertSame(1, substr_count($view, 'id="acct-pager"'));
        $this->assertStringContainsString('id="acct-pager" class="overview-pager"', $view);
        $this->assertStringNotContainsString('class="acct-pager"', $view);
        $this->assertStringContainsString('<option value="10" selected>10</option>', $view);
        $this->assertSame(2, substr_count($script, '.value || 10'));
        $this->assertStringContainsString('function renderAcctPager', $script);
    }

    public function testAccountingStatesAndModalScrollingUseSharedUiPrimitives(): void
    {
        $view = file_get_contents(APPPATH . 'Views/accounting/debts.php') . file_get_contents(APPPATH . 'Views/components/accounting_debt_modals.php');
        $debtScript = file_get_contents(ROOTPATH . 'public/assets/js/accounting-debts.js') . file_get_contents(ROOTPATH . 'public/assets/js/accounting-debts.part2.js') . file_get_contents(ROOTPATH . 'public/assets/js/accounting-debts.part3.js');
        $dashboardScript = file_get_contents(ROOTPATH . 'public/assets/js/accounting-dashboard.js');
        $layoutScript = file_get_contents(ROOTPATH . 'public/assets/js/app-layout.js');
        $styles = file_get_contents(ROOTPATH . 'public/assets/css/app.css');

        $this->assertIsString($view);
        $this->assertIsString($debtScript);
        $this->assertIsString($dashboardScript);
        $this->assertIsString($layoutScript);
        $this->assertIsString($styles);
        $this->assertSame(5, substr_count($view, 'data-inset-modal-scroll'));
        $this->assertStringContainsString('function aDataState', $debtScript);
        $this->assertStringContainsString('function buildEmployeeProfileHtml', $debtScript);
        $this->assertStringContainsString('function acdDataState', $dashboardScript);
        $this->assertStringContainsString('app-inset-modal-scroll', $layoutScript);
        $this->assertStringContainsString('.app-inset-modal-card', $styles);
        $this->assertStringContainsString('.app-inset-modal-scroll::-webkit-scrollbar-button', $styles);
    }

    public function testEmployeeDetailsUsesTheAccountingFinancialProfileConvention(): void
    {
        $view = file_get_contents(APPPATH . 'Views/accounting/debts.php') . file_get_contents(APPPATH . 'Views/components/accounting_debt_modals.php');
        $styles = file_get_contents(ROOTPATH . 'public/assets/css/accounting-debts.css');

        $this->assertIsString($view);
        $this->assertIsString($styles);
        $this->assertStringContainsString('class="employee-financial-section hidden"', $view);
        $this->assertStringContainsString('class="employee-history-section"', $view);
        $this->assertStringContainsString('.employee-profile-overview', $styles);
        $this->assertStringContainsString('.employee-finance-grid', $styles);
        $this->assertStringContainsString('.employee-history-list > .data-state', $styles);
        $this->assertStringContainsString('.ibems-modern .acct-table-wrap', $styles);
        $this->assertStringContainsString('padding: 14px;', $styles);
    }
}
