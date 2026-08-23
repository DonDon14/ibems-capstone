<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class UserMobilePortalConventionTest extends CIUnitTestCase
{
    public function testOnlyTheActiveUserLayoutOptsIntoTheMobileExperience(): void
    {
        $userLayout = (string) file_get_contents(APPPATH . 'Views/layouts/user.php');
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');

        $this->assertStringContainsString("strtoupper(\$role) === 'USER'", $userLayout);
        $this->assertStringContainsString('user-mobile-enabled', $userLayout);
        $this->assertStringContainsString("strtoupper(\$role) === 'USER'", $shell);
        $this->assertStringContainsString('aria-label="User mobile navigation"', $shell);
        $this->assertStringContainsString('assets/css/user-mobile.css', $shell);
        $this->assertStringContainsString('assets/js/user-mobile.js', $shell);
        $this->assertStringContainsString('assets/js/user-navigation.js', $shell);
        $this->assertStringContainsString('user/manifest.webmanifest', $shell);

        foreach (['admin.php', 'store_admin.php', 'store.php', 'accounting.php'] as $layout) {
            $contents = (string) file_get_contents(APPPATH . 'Views/layouts/' . $layout);
            $this->assertStringNotContainsString('userMobileExperience', $contents, $layout);
            $this->assertStringNotContainsString('user-mobile-enabled', $contents, $layout);
        }
    }

    public function testUserMobilePresentationPreservesDesktopAndUsesPhoneBreakpoints(): void
    {
        $styles = (string) file_get_contents(FCPATH . 'assets/css/user-mobile.css');
        $script = (string) file_get_contents(FCPATH . 'assets/js/user-mobile.js');
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');

        $this->assertStringContainsString('@media (max-width: 760px)', $styles);
        $this->assertStringContainsString('.user-mobile-enabled .app-sidebar', $styles);
        $this->assertStringContainsString('.user-mobile-enabled .user-mobile-nav', $styles);
        $this->assertStringContainsString('grid-template-columns: repeat(4, minmax(0, 1fr))', $styles);
        $this->assertStringContainsString('--user-mobile-nav-height: 64px', $styles);
        $this->assertStringContainsString('.user-mobile-nav a span', $styles);
        $this->assertStringContainsString('aria-label="<?= esc($label) ?>"', $shell);
        $this->assertStringContainsString('min-height: 44px', $styles);
        $this->assertStringContainsString('env(safe-area-inset-bottom', $styles);
        $this->assertStringContainsString('touch-action: manipulation', $styles);
        $this->assertStringContainsString('.user-mobile-nav a:active', $styles);
        $this->assertStringContainsString('transition: color 120ms ease', $styles);
        $this->assertStringNotContainsString('.user-mobile-nav a:hover', $styles);
        $this->assertStringContainsString('bottom: calc(var(--user-mobile-nav-height)', $styles);
        $this->assertStringContainsString('overscroll-behavior: contain', $styles);
        $this->assertStringContainsString('.user-mobile-enabled .compact-filter-panel-actions', $styles);
        $this->assertStringContainsString('position: sticky', $styles);
        $this->assertStringContainsString('body.classList.contains("user-mobile-enabled")', $script);
        $this->assertStringContainsString('ibems:account-menu-close', $script);
    }

    public function testUserTablesExposeMobileRecordLabelsWithoutDroppingFields(): void
    {
        $dashboardView = (string) file_get_contents(APPPATH . 'Views/user/dashboard.php');
        $historyView = (string) file_get_contents(APPPATH . 'Views/user/history.php');
        $deductionsView = (string) file_get_contents(APPPATH . 'Views/user/deductions.php');
        $dashboardScript = (string) file_get_contents(FCPATH . 'assets/js/user-dashboard.js');
        $historyScript = (string) file_get_contents(FCPATH . 'assets/js/user-history.js');
        $deductionsScript = (string) file_get_contents(FCPATH . 'assets/js/user-deductions.js');

        $this->assertStringContainsString('user-mobile-card-table', $dashboardView);
        $this->assertSame(2, substr_count($historyView, 'user-mobile-card-table'));
        $this->assertStringContainsString('user-mobile-card-table', $deductionsView);
        $this->assertStringContainsString('data-label="Reference"', $dashboardScript);
        $this->assertStringContainsString('data-label="Available Credit"', $historyScript);
        $this->assertStringContainsString('data-label="Remarks"', $historyScript);
        $this->assertStringContainsString('data-label="Debt Before"', $deductionsScript);
        $this->assertStringContainsString('data-label="Debt After"', $deductionsScript);
        $this->assertStringContainsString('data-label="Status"', $deductionsScript);
    }

    public function testPwaIsInstallableButDoesNotCacheUserFinancialData(): void
    {
        $manifestPath = FCPATH . 'user/manifest.webmanifest';
        $worker = (string) file_get_contents(FCPATH . 'user/service-worker.js');
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('/user/', $manifest['scope']);
        $this->assertSame('/user/dashboard?source=pwa', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame(['192x192', '512x512'], array_column($manifest['icons'], 'sizes'));
        $this->assertFileExists(FCPATH . 'assets/images/ibems-user-icon-192.png');
        $this->assertFileExists(FCPATH . 'assets/images/ibems-user-icon-512.png');
        $this->assertFileExists(FCPATH . 'user/offline.html');

        $this->assertStringContainsString('request.method !== "GET"', $worker);
        $this->assertStringContainsString('request.mode === "navigate"', $worker);
        $this->assertStringContainsString('url.pathname.startsWith("/user/")', $worker);
        $this->assertStringContainsString('USER_SHELL_ASSETS.has(url.pathname)', $worker);
        $this->assertStringNotContainsString('/user/dashboard/data', $worker);
        $this->assertStringNotContainsString('/user/transactions', $worker);
        $this->assertStringNotContainsString('/user/cashbook', $worker);
        $this->assertStringNotContainsString('/user/deductions/data', $worker);
    }

    public function testUserNavigationUsesAPersistentShellWithFullPageFallbacks(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');
        $navigation = (string) file_get_contents(FCPATH . 'assets/js/user-navigation.js');

        $this->assertStringContainsString('data-user-navigation-shell', $shell);
        $this->assertStringContainsString('id="user-page-content"', $shell);
        $this->assertStringContainsString('X-Requested-With', $navigation);
        $this->assertStringContainsString('main.innerHTML = nextMain.innerHTML', $navigation);
        $this->assertStringContainsString('history.pushState', $navigation);
        $this->assertStringContainsString('userNavigationVisits', $navigation);
        $this->assertStringContainsString('window.addEventListener("popstate"', $navigation);
        $this->assertStringContainsString('window.location.assign', $navigation);
        $this->assertStringContainsString('runtime.controller.abort()', $navigation);
        $this->assertStringContainsString('window.IbemsUserNavigation = api', $navigation);
        $this->assertStringContainsString('ibems:user-page-loaded', $navigation);

        foreach (['dashboard.php', 'stores.php', 'history.php', 'deductions.php'] as $view) {
            $contents = (string) file_get_contents(APPPATH . 'Views/user/' . $view);
            $this->assertStringContainsString('data-user-page-script', $contents, $view);
            $this->assertStringContainsString('data-user-page-style', $contents, $view);
        }

        foreach (['user-dashboard.js', 'user-stores.js', 'user-history.js', 'user-deductions.js'] as $script) {
            $contents = (string) file_get_contents(FCPATH . 'assets/js/' . $script);
            $this->assertStringContainsString('IbemsUserNavigation?.currentSignal', $contents, $script);
            $this->assertStringContainsString('signal:', $contents, $script);
        }
    }

    public function testUserAccountSettingsAreAvailableFromEveryUserPage(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');
        $dashboard = (string) file_get_contents(APPPATH . 'Views/user/dashboard.php');
        $accountScript = (string) file_get_contents(FCPATH . 'assets/js/user-account-settings.js');

        $this->assertStringContainsString('id="account-menu-toggle"', $shell);
        $this->assertStringContainsString('id="u-open-pin-modal"', $shell);
        $this->assertStringContainsString('id="u-debt-pin-modal"', $shell);
        $this->assertStringContainsString('assets/js/user-account-settings.js', $shell);
        $this->assertStringNotContainsString('id="u-open-pin-modal"', $dashboard);
        $this->assertStringContainsString('/user/debt-pin/status', $accountScript);
        $this->assertStringContainsString('/user/debt-pin/set', $accountScript);
        $this->assertStringContainsString('current_password', $accountScript);
    }
}
