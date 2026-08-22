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
