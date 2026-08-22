<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class DashboardInteractionConventionTest extends CIUnitTestCase
{
    public function testAdminDashboardProvidesAccessibleAlertPagination(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/dashboard.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-dashboard.js');
        $controller = (string) file_get_contents(APPPATH . 'Controllers/AdminController.php');

        $this->assertStringContainsString('id="ad-alerts-summary"', $view);
        $this->assertStringContainsString('id="ad-alerts-pager"', $view);
        $this->assertStringContainsString('class="overview-pager dashboard-alert-pager"', $view);
        $this->assertStringContainsString('aria-label="Operational alert pages"', $view);
        $this->assertStringContainsString('alerts_page', $script);
        $this->assertStringContainsString('adRenderAlertPagination', $script);
        $this->assertStringContainsString('class="secondary-btn btn-sm"', $script);
        $this->assertStringContainsString('Page ${page} of ${totalPages}', $script);
        $this->assertStringContainsString('DashboardAlertPagination::paginate', $controller);
        $this->assertStringContainsString("'label' => 'Inactive Store'", $controller);
        $this->assertStringNotContainsString('array_slice($alerts, 0, 12)', $controller);
    }

    public function testSharedPortalLogoutRequiresTheApplicationConfirmationDialog(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');
        $layoutScript = (string) file_get_contents(FCPATH . 'assets/js/app-layout.js');

        $this->assertSame(2, substr_count($shell, 'data-confirm-logout'));
        $this->assertStringContainsString('window.IbemsDialog.confirm', $layoutScript);
        $this->assertStringContainsString('Log out of IBEMS?', $layoutScript);
        $this->assertStringContainsString('Stay signed in', $layoutScript);
        $this->assertStringContainsString('HTMLFormElement.prototype.submit.call(form)', $layoutScript);
    }
}
