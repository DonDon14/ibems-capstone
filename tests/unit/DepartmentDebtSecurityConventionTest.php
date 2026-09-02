<?php

use PHPUnit\Framework\TestCase;
use App\Services\DepartmentDebtService;

final class DepartmentDebtSecurityConventionTest extends TestCase
{
    public function testPinReplacementRequiresCurrentAccountPassword(): void
    {
        $controller = (string) file_get_contents(ROOTPATH . 'app/Controllers/DepartmentDebtController.php');
        $view = (string) file_get_contents(ROOTPATH . 'app/Views/user/department-authorizations.php');
        $script = (string) file_get_contents(ROOTPATH . 'public/assets/js/department-debt-pages.js');

        $this->assertStringContainsString("password_verify(\$currentPassword", $controller);
        $this->assertStringContainsString('department-current-password', $view);
        $this->assertStringContainsString('current_password: currentPassword', $script);
        $this->assertStringContainsString('currentPassword.required = pinIsSet', $script);
    }

    public function testDepartmentFinancialInputsAreStrictlyValidatedAndAtomic(): void
    {
        $service = (string) file_get_contents(ROOTPATH . 'app/Services/DepartmentDebtService.php');
        $authorization = (string) file_get_contents(ROOTPATH . 'app/Services/DepartmentAuthorizationService.php');

        $this->assertStringContainsString('private function validMonth', $service);
        $this->assertStringContainsString('checkdate(', $service);
        $this->assertGreaterThanOrEqual(2, substr_count($service, 'is_finite('));
        $this->assertStringContainsString("->where('used_amount <=', \$allocation)", $service);
        $this->assertStringContainsString("\$this->db->transBegin()", $authorization);
        $this->assertStringContainsString("'DEPARTMENT_PIN_CHANGED'", $authorization);
    }

    public function testInspectionRoleDoesNotReceiveAccountingWriteControls(): void
    {
        $view = (string) file_get_contents(ROOTPATH . 'app/Views/accounting/department-debts.php');
        $script = (string) file_get_contents(ROOTPATH . 'public/assets/js/department-debt-pages.js');

        $this->assertStringContainsString("ibems_current_role() === 'ACCOUNTING_OFFICE'", $view);
        $this->assertStringContainsString('data-can-operate=', $view);
        $this->assertStringContainsString('if (!canOperate)', $script);
        $this->assertStringContainsString('Read only', $script);
    }

    public function testMalformedFinancialInputsAreRejectedBeforeDatabaseUse(): void
    {
        $service = new DepartmentDebtService();

        $invalidMonth = $service->setAllocation(1, "2026-08' OR 1=1 --", 100, 'open', '', 1);
        $this->assertSame('error', $invalidMonth['status']);
        $this->assertSame(400, $invalidMonth['code']);

        $impossibleMonth = $service->setAllocation(1, '2026-13', 100, 'open', '', 1);
        $this->assertSame('error', $impossibleMonth['status']);

        $nonFiniteSettlement = $service->recordSettlement(1, 1, INF, 'REF', 'test', 1);
        $this->assertSame('error', $nonFiniteSettlement['status']);
    }

    public function testSelectedRequesterIdentityAndImageUrlsCannotBeSpoofed(): void
    {
        $transactions = (string) file_get_contents(ROOTPATH . 'app/Services/TransactionService.php');
        $storeConfiguration = (string) file_get_contents(ROOTPATH . 'app/Services/StoreConfigurationService.php');

        $this->assertStringContainsString("\$departmentRequesterName = trim((string) (\$requester['name']", $transactions);
        $this->assertStringContainsString('private function isAllowedImageUrl', $storeConfiguration);
        $this->assertStringContainsString("=== 'https'", $storeConfiguration);
        $this->assertStringContainsString("str_starts_with(\$url, '/uploads/')", $storeConfiguration);
    }

    public function testDepartmentApprovalHasOneAccountableHeadAndNoDelegateControls(): void
    {
        $controller = (string) file_get_contents(ROOTPATH . 'app/Controllers/DepartmentDebtController.php');
        $authorization = (string) file_get_contents(ROOTPATH . 'app/Services/DepartmentAuthorizationService.php');
        $adminView = (string) file_get_contents(ROOTPATH . 'app/Views/admin/departments.php');
        $adminScript = (string) file_get_contents(ROOTPATH . 'public/assets/js/department-debt-pages.js');
        $posView = (string) file_get_contents(ROOTPATH . 'app/Views/store/pos.php') . file_get_contents(ROOTPATH . 'app/Views/components/store_pos_modals.php');

        $this->assertStringContainsString("'approval_policy' => 'department_head_only'", $controller);
        $this->assertStringContainsString("->where('d.head_user_id', \$userId)", $authorization);
        $this->assertStringNotContainsString('department_delegates dd', $authorization);
        $this->assertStringNotContainsString('department-delegates', $adminView);
        $this->assertStringNotContainsString('delegate_user_ids', $adminScript);
        $this->assertStringContainsString('Department head', $posView);
    }

    public function testPortalledDropdownsRenderAboveAllAppDialogs(): void
    {
        $styles = (string) file_get_contents(ROOTPATH . 'public/assets/css/modern-ui.css');
        $departmentView = (string) file_get_contents(ROOTPATH . 'app/Views/admin/departments.php');

        $this->assertMatchesRegularExpression('/\\.ui-select-menu,\\s*\\.ui-date-popup\\s*\\{[^}]*z-index:\s*3100/s', $styles);
        $this->assertStringContainsString('department-modal-card app-inset-modal-scroll', $departmentView);
    }

    public function testDepartmentDialogsMountAtViewportLevelAndLockPageScroll(): void
    {
        $script = (string) file_get_contents(ROOTPATH . 'public/assets/js/department-debt-pages.js');
        $styles = (string) file_get_contents(ROOTPATH . 'public/assets/css/department-debt.css');

        $this->assertStringContainsString('function mountDepartmentModal', $script);
        $this->assertStringContainsString('document.body.appendChild(modal)', $script);
        $this->assertStringContainsString('setDepartmentModalOpen(departmentModal, true)', $script);
        $this->assertStringContainsString('setDepartmentModalOpen(financeModal, true)', $script);
        $this->assertStringContainsString('body.department-modal-open { overflow: hidden; }', $styles);
    }

    public function testDepartmentHeadUsesAnExplicitAvatarCardChangeState(): void
    {
        $controller = (string) file_get_contents(ROOTPATH . 'app/Controllers/DepartmentDebtController.php');
        $view = (string) file_get_contents(ROOTPATH . 'app/Views/admin/departments.php');
        $script = (string) file_get_contents(ROOTPATH . 'public/assets/js/department-debt-pages.js');

        $this->assertStringContainsString('profile_image_url', $controller);
        $this->assertStringContainsString('department-head-summary', $view);
        $this->assertStringContainsString('department-head-editor', $view);
        $this->assertStringContainsString('data-change-department-head', $script);
        $this->assertStringContainsString('assignment-person-avatar', $script);
        $this->assertStringContainsString('headEditorOpen', $script);
        $this->assertStringContainsString('id="department-head-search"', $view);
        $this->assertStringContainsString('role="combobox"', $view);
        $this->assertStringContainsString('department-head-suggestions', $view);
        $this->assertStringContainsString('headMatches', $script);
        $this->assertStringContainsString('setActiveHeadSuggestion', $script);
        $this->assertStringContainsString('event.key === "ArrowDown"', $script);
        $this->assertStringContainsString('event.key === "Enter"', $script);
        $this->assertStringContainsString('event.key === "Escape"', $script);
        $this->assertStringContainsString('suggestions.replaceChildren()', $script);
        $this->assertStringContainsString('headEditorOpen = false;', $script);
        $this->assertStringNotContainsString('Start typing to find an active employee.', $script);
        $this->assertStringContainsString('if (!event.target.closest("#department-head-editor, #department-head-summary"))', $script);
    }

    public function testDepartmentSearchesPreserveTheSharedThemeAwareMagnifier(): void
    {
        $adminView = (string) file_get_contents(ROOTPATH . 'app/Views/admin/departments.php');
        $accountingView = (string) file_get_contents(ROOTPATH . 'app/Views/accounting/department-debts.php');
        $styles = (string) file_get_contents(ROOTPATH . 'public/assets/css/department-debt.css');

        foreach ([$adminView, $accountingView] as $view) {
            $this->assertStringContainsString('type="search"', $view);
            $this->assertStringNotContainsString('search-icon', $view);
        }
        $this->assertMatchesRegularExpression(
            '/\.department-toolbar input,[^{]+\{[^}]*background-color:\s*var\(--surface-soft\);/s',
            $styles
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.department-toolbar input,[^{]+\{[^}]*background:\s*var\(--surface-soft\);/s',
            $styles
        );
    }
}
