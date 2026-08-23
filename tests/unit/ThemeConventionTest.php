<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class ThemeConventionTest extends CIUnitTestCase
{
    public function testPortalShellLoadsVersionedSharedThemeAssets(): void
    {
        $shell = file_get_contents(APPPATH . 'Views/components/portal_shell.php');

        $this->assertIsString($shell);
        $this->assertStringContainsString('name="theme-color"', $shell);
        $this->assertStringContainsString('assets/js/theme.js', $shell);
        $this->assertStringContainsString('assets/css/modern-ui.css', $shell);
    }

    public function testThemeControllerPersistsPreferenceAndAnnouncesChanges(): void
    {
        $script = file_get_contents(FCPATH . 'assets/js/theme.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('localStorage.setItem("ibems-theme"', $script);
        $this->assertStringContainsString('aria-pressed', $script);
        $this->assertStringContainsString('ibems:themechange', $script);
    }

    public function testAuthenticationScreensExposeThePersistentThemeControl(): void
    {
        $login = (string) file_get_contents(APPPATH . 'Views/auth/login.php');
        $roleSelection = (string) file_get_contents(APPPATH . 'Views/auth/select_role.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/auth-login.css');

        foreach ([$login, $roleSelection] as $view) {
            $this->assertStringContainsString('id="theme-toggle"', $view);
            $this->assertStringContainsString('assets/js/theme.js', $view);
            $this->assertStringContainsString("localStorage.getItem('ibems-theme')", $view);
            $this->assertStringContainsString('name="theme-color"', $view);
        }

        $this->assertStringContainsString('.auth-modern .auth-theme-toggle', $styles);
        $this->assertStringContainsString('html[data-theme="dark"] .auth-modern .auth-panel-form', $styles);
        $this->assertStringContainsString('html[data-theme="dark"] .auth-modern .auth-panel-art', $styles);
        $this->assertStringContainsString('html[data-theme="dark"] .auth-modern #status.ok', $styles);
        $this->assertStringContainsString('html[data-theme="dark"] .auth-modern #status.error', $styles);
    }

    public function testDarkThemeDefinesContrastingDataSurfacesAndControls(): void
    {
        $css = file_get_contents(FCPATH . 'assets/css/modern-ui.css');

        $this->assertIsString($css);
        $this->assertStringContainsString('--dark-text: #f4f7fb', $css);
        $this->assertStringContainsString('.inventory-summary-pill', $css);
        $this->assertStringContainsString('.product-card, .cart-list', $css);
        $this->assertStringContainsString('.acct-flow-item', $css);
        $this->assertStringContainsString('.user-product-image-wrap', $css);
        $this->assertStringContainsString('tbody tr:is(:hover, :focus-within) td', $css);
        $this->assertStringContainsString('.history-shell, .staff-scope-note, .debt-credit-meter', $css);
        $this->assertStringContainsString('.bg-white, .bg-slate-50, .bg-slate-100', $css);
        $this->assertStringContainsString('.user-deduction-filter', $css);
        $this->assertStringContainsString('.variance-case, .variance-pill.is-muted', $css);
        $this->assertStringContainsString('.payment-method-card.needs-setup', $css);
        $this->assertStringContainsString('.product-action-info, .product-detail-view, .modal-action-tabs', $css);
        $this->assertStringContainsString('.employee-profile-overview', $css);
        $this->assertStringContainsString('.receipt-head, .receipt-actions, .confirm-actions', $css);
        $this->assertStringContainsString('.acct-status.is-active, .user-availability.is-available', $css);
        $this->assertStringContainsString('.create-product-section', $css);
        $this->assertStringContainsString('.image-grid .image-preview-box, .modal-image-preview-grid .image-preview-box', $css);
        $this->assertStringContainsString('.readonly-value, .readiness-item', $css);
        $this->assertStringContainsString('.border-blue-200, .border-blue-300', $css);
        $this->assertStringContainsString('.ad-account-identity, .ad-account-tabs', $css);
        $this->assertStringContainsString('.data-panel-head, .dashboard-alert-pager, .store-card-footer', $css);
        $this->assertStringContainsString('.store-form-section, .compact-filter-panel-head, .compact-filter-panel-actions', $css);
        $this->assertStringContainsString('.ibems-receipt-brand, .ibems-receipt-details', $css);
        $this->assertStringContainsString('.supervisor-chip button:is(:hover, :focus-visible)', $css);
        $this->assertStringContainsString('.officer-suggestion-item:is(:hover, .is-active)', $css);
        $this->assertStringContainsString('.ui-select-trigger, .ui-date-trigger).is-open .ui-control-icon', $css);
        $this->assertStringContainsString('.ui-date-actions button:last-child', $css);
        $this->assertStringContainsString('.settings-table-wrap', $css);
        $this->assertStringContainsString('.ibems-receipt-brand, .ibems-receipt-section-head', $css);
        $this->assertStringContainsString('.ibems-receipt-status, .ibems-receipt-payment-pill', $css);
        $this->assertStringContainsString('.receipt-close, .compact-filter-close, .profile-image-close', $css);
        $this->assertStringContainsString('.inventory-movement-meta span', $css);
        $this->assertStringContainsString('.inventory-movement-type.movement-sale', $css);
        $this->assertStringContainsString('.app-modal-close, .receipt-close, .compact-filter-close, .profile-image-close', $css);
        $this->assertStringContainsString('.settings-modal-head .icon-btn', $css);
        $this->assertStringContainsString('.ui-date-popup::-webkit-scrollbar-button', $css);
        $this->assertStringContainsString('.audit-payload-block :is(pre, code)', $css);
        $this->assertStringContainsString('.metric-card-icon--sales', $css);
        $this->assertStringContainsString('.category-edit-summary', $css);
        $this->assertStringContainsString('.employee-identity-copy h5', $css);
        $this->assertStringContainsString('.staff-summary-finance span', $css);
        $this->assertStringContainsString('.metric-card-icon--alerts', $css);
        $this->assertStringContainsString('.product-visual.placeholder, .prod-thumb-fallback', $css);
        $this->assertStringContainsString('.app-dialog[data-tone="danger"] .app-dialog-icon', $css);
        $this->assertStringContainsString('.product-grid, .pos-right, .app-inset-modal-scroll', $css);
        $this->assertStringContainsString('color: #dc2626 !important;', $css);
        $this->assertStringContainsString('color: #f87171 !important;', $css);
        $this->assertStringContainsString('outline: none !important;', $css);
    }

    public function testAccountingChartRespondsToThemeChanges(): void
    {
        $script = file_get_contents(FCPATH . 'assets/js/accounting-dashboard.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('document.documentElement.dataset.theme === "dark"', $script);
        $this->assertStringContainsString('window.addEventListener("ibems:themechange"', $script);
    }

    public function testLoginUsesTheActiveFrontControllerPrefix(): void
    {
        $script = file_get_contents(FCPATH . 'assets/js/auth-login.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('window.location.pathname.includes("/index.php/")', $script);
        $this->assertStringContainsString('fetch(appPath("/auth/login")', $script);
        $this->assertStringContainsString('fetch(appPath("/auth/me")', $script);
        $this->assertStringContainsString('appPath("/auth/select-role")', $script);
    }

    public function testRoleSelectionHasAProgressiveFormFallback(): void
    {
        $view = file_get_contents(APPPATH . 'Views/auth/select_role.php');
        $controller = file_get_contents(APPPATH . 'Controllers/AuthController.php');

        $this->assertIsString($view);
        $this->assertIsString($controller);
        $this->assertStringContainsString('class="auth-role-form"', $view);
        $this->assertStringContainsString("site_url('auth/select-role')", $view);
        $this->assertStringContainsString('csrf_field()', $view);
        $this->assertStringContainsString('Content-Type', $controller);
        $this->assertStringContainsString('return redirect()->to(ibems_role_landing_path($role))', $controller);
    }
}
