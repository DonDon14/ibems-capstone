<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class AccountingPortalContentConventionTest extends CIUnitTestCase
{
    public function testDepartmentAccountMonthsUseTheSharedPicker(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/accounting/department-debts.php');
        $controls = (string) file_get_contents(FCPATH . 'assets/js/modern-controls.js');
        $layout = (string) file_get_contents(FCPATH . 'assets/js/app-layout.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/modern-ui.css');

        $this->assertSame(3, substr_count($view, 'type="month"'));
        $this->assertStringContainsString("input[type='month']:not([data-no-enhance])", $controls);
        $this->assertStringContainsString('const enhanceMonth = (input)', $controls);
        $this->assertStringContainsString('ui-date-popup ui-month-popup', $controls);
        $this->assertStringContainsString('data-month-current', $controls);
        $this->assertStringContainsString('input[type="month"]', $layout);
        $this->assertStringContainsString('.ui-month-grid', $styles);
        $this->assertStringContainsString('.ui-month-option.is-selected', $styles);
    }

    public function testDepartmentBatchAllocationUsesSearchableMultiselectAndOneAtomicEndpoint(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = (string) file_get_contents(APPPATH . 'Controllers/DepartmentDebtController.php');
        $service = (string) file_get_contents(APPPATH . 'Services/DepartmentDebtService.php');
        $view = (string) file_get_contents(APPPATH . 'Views/accounting/department-debts.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/department-debt-pages.js');

        $this->assertStringContainsString("department-debts/allocations', 'DepartmentDebtController::setAllocations'", $routes);
        $this->assertStringContainsString('public function setAllocations()', $controller);
        $this->assertStringContainsString('public function setAllocations(array $departmentIds', $service);
        $this->assertStringContainsString('$this->db->transBegin()', $service);
        $this->assertStringContainsString('foreach ($departmentIds as $departmentId)', $service);
        $this->assertStringContainsString('id="department-batch-search" type="search"', $view);
        $this->assertStringContainsString('id="department-batch-options"', $view);
        $this->assertStringContainsString('Amount per department', $view);
        $this->assertStringContainsString('The complete batch succeeds or no department is changed.', $view);
        $this->assertStringContainsString('const batchSelected = new Set()', $script);
        $this->assertStringContainsString('/accounting/department-debts/allocations', $script);
    }

    public function testDepartmentFinanceDialogUsesFixedChromeAndRequiredSettlementEvidence(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/accounting/department-debts.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/department-debt.css');
        $script = (string) file_get_contents(FCPATH . 'assets/js/department-debt-pages.js');

        $this->assertStringContainsString('class="department-modal-scroll"', $view);
        $this->assertStringContainsString('id="department-finance-context"', $view);
        $this->assertStringContainsString('id="department-settlement-reference" maxlength="120" required disabled', $view);
        $this->assertStringContainsString('id="department-settlement-remarks" maxlength="500"', $view);
        $this->assertStringContainsString('required disabled', $view);
        $this->assertStringContainsString('overflow: hidden;', $styles);
        $this->assertStringContainsString('.department-modal-scroll', $styles);
        $this->assertStringContainsString('background: transparent; color: var(--text-soft);', $styles);
        $this->assertStringContainsString('button:focus-visible { outline: 2px solid var(--text-main); outline-offset: 2px;', $styles);
        $this->assertStringContainsString('Outstanding liability:', $script);
        $this->assertStringContainsString('Record settlement', $script);
    }

    public function testDebtCenterLinksToTheSharedDeductionWorkspace(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/accounting/debts.php');
        $modals = (string) file_get_contents(APPPATH . 'Views/components/accounting_debt_modals.php');
        $controller = (string) file_get_contents(APPPATH . 'Controllers/AccountingController.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/accounting-debts.js') . file_get_contents(FCPATH . 'assets/js/accounting-debts.part2.js') . file_get_contents(FCPATH . 'assets/js/accounting-debts.part3.js');

        $this->assertStringContainsString("site_url('deductions/workspace')", $view);
        $this->assertStringContainsString("return redirect()->to(site_url('deductions/workspace'));", $controller);
        $this->assertStringNotContainsString('deduction-workflow-modal', $script);
        $this->assertStringNotContainsString('deduction-workflow-modal', $modals);
        $this->assertStringNotContainsString('workflow-create-period', $modals);
        $this->assertStringNotContainsString('/accounting/deduction-', $script);
    }

    public function testLegacyDeductionWarningsUseThemeAwareSurfaces(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/accounting/debts.php') . file_get_contents(APPPATH . 'Views/components/accounting_debt_modals.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/accounting-debts.css');

        $this->assertSame(1, substr_count($view, 'settlement-warning-panel'));
        $this->assertStringNotContainsString('border-amber-200 bg-amber-50', $view);
        $this->assertStringContainsString('.settlement-warning-panel {', $styles);
        $this->assertStringContainsString('background: color-mix(in srgb, #f59e0b 12%, var(--surface));', $styles);
        $this->assertStringContainsString('html[data-theme="dark"] .ibems-modern .settlement-warning-panel', $styles);
        $this->assertStringContainsString('color: #fcd34d;', $styles);
        $this->assertStringContainsString('.ibems-modern .settlement-warning-panel strong', $styles);
        $this->assertStringContainsString('.app-container .settlement-warning-panel strong', $styles);
    }

    public function testRetiredDirectDeductionPathsStayRemoved(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = (string) file_get_contents(APPPATH . 'Controllers/AccountingController.php');
        $view = (string) file_get_contents(APPPATH . 'Views/accounting/debts.php') . file_get_contents(APPPATH . 'Views/components/accounting_debt_modals.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/accounting-debts.js') . file_get_contents(FCPATH . 'assets/js/accounting-debts.part2.js') . file_get_contents(FCPATH . 'assets/js/accounting-debts.part3.js');

        foreach (['accounting/settlement/apply', 'accounting/debts/deduct', 'accounting/debts/deduct-full'] as $path) {
            $this->assertStringNotContainsString($path, $routes);
            $this->assertStringNotContainsString('/' . $path, $script);
        }

        foreach (['applySettlementRun', 'deductDebt', 'deductFullDebt'] as $method) {
            $this->assertStringNotContainsString('public function ' . $method . '(', $controller);
        }

        foreach (['deduction-mode-modal', 'settlement-confirm-modal', 'settlement-preview-btn', 'settlement-apply-btn'] as $controlId) {
            $this->assertStringNotContainsString($controlId, $view);
            $this->assertStringNotContainsString($controlId, $script);
        }

        $this->assertStringContainsString("site_url('deductions/workspace')", $view);
        $this->assertStringNotContainsString('open-deduction-workflow', $view);
        $this->assertStringNotContainsString('accounting/deduction-batches', $routes);
        $this->assertStringNotContainsString('accounting/deduction-workflow', $routes);
        $this->assertStringNotContainsString('/accounting/deduction-batches', $script);
        $this->assertStringNotContainsString('public function prepareDeductionBatch(', $controller);
        $this->assertStringNotContainsString('public function reconcileDeductionBatch(', $controller);
        $this->assertStringContainsString("deductions/workspace/batches', 'DeductionWorkspaceController::prepareBatch'", $routes);
    }
}
