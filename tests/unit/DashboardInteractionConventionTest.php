<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class DashboardInteractionConventionTest extends CIUnitTestCase
{
    public function testAdminDashboardProvidesAccessibleAlertPagination(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/dashboard.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-dashboard.js');
        $dashboardService = (string) file_get_contents(APPPATH . 'Services/AdminDashboardService.php');

        $this->assertStringContainsString('id="ad-alerts-summary"', $view);
        $this->assertStringContainsString('id="ad-alerts-pager"', $view);
        $this->assertStringContainsString('class="overview-pager dashboard-alert-pager"', $view);
        $this->assertStringContainsString('aria-label="Operational alert pages"', $view);
        $this->assertStringContainsString('alerts_page', $script);
        $this->assertStringContainsString('adRenderAlertPagination', $script);
        $this->assertStringContainsString('class="secondary-btn btn-sm"', $script);
        $this->assertStringContainsString('Page ${page} of ${totalPages}', $script);
        $this->assertStringContainsString('DashboardAlertPagination::paginate', $dashboardService);
        $this->assertStringContainsString("'label' => 'Inactive Store'", $dashboardService);
        $this->assertStringNotContainsString('array_slice($alerts, 0, 12)', $dashboardService);
    }

    public function testSharedPortalLogoutRequiresTheApplicationConfirmationDialog(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');
        $layoutScript = (string) file_get_contents(FCPATH . 'assets/js/app-layout.js');

        $this->assertSame(1, substr_count($shell, 'data-confirm-logout'));
        $this->assertStringContainsString('class="account-menu-logout" data-confirm-logout', $shell);
        $this->assertStringNotContainsString('class="sidebar-logout"', $shell);
        $this->assertStringNotContainsString('class="topbar-logout"', $shell);
        $this->assertStringContainsString('window.IbemsDialog.confirm', $layoutScript);
        $this->assertStringContainsString('Log out of IBEMS?', $layoutScript);
        $this->assertStringContainsString('Stay signed in', $layoutScript);
        $this->assertStringContainsString('HTMLFormElement.prototype.submit.call(form)', $layoutScript);
    }

    public function testSidebarBrandAlwaysOpensThePortalHomeInsteadOfTheLastVisitedTab(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');
        $layoutScript = (string) file_get_contents(FCPATH . 'assets/js/app-layout.js');

        $this->assertStringContainsString('const dashboardLink = navLinks.find', $layoutScript);
        $this->assertStringContainsString('const portalHomeLink = dashboardLink || navLinks[0] || null;', $layoutScript);
        $this->assertStringContainsString('portalHomeLink.click();', $layoutScript);
        $this->assertStringContainsString('brand.setAttribute("title", `Open ${homeLabel}`);', $layoutScript);
        $this->assertStringNotContainsString('ibems.nav.last.', $layoutScript);
        $this->assertStringNotContainsString('Open last visited page', $layoutScript);
        $this->assertStringContainsString('class="app-brand-text" href="<?= esc(site_url($portalHomePath)) ?>"', $shell);
        $this->assertStringContainsString('data-portal-navigation-link aria-label="Open <?= esc($portalHomeLabel) ?>"', $shell);
        $this->assertStringContainsString("assets/js/app-layout.js') ?>?v=20260825b", $shell);
    }
}
