<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class DashboardQuickAccessConventionTest extends CIUnitTestCase
{
    public function testActionDashboardsUseTheSharedQuickAccessStructure(): void
    {
        $dashboards = [
            APPPATH . 'Views/admin/dashboard.php',
            APPPATH . 'Views/accounting/dashboard.php',
            APPPATH . 'Views/store/dashboard.php',
        ];

        foreach ($dashboards as $dashboard) {
            $source = (string) file_get_contents($dashboard);

            $this->assertStringContainsString('quick-panel', $source, $dashboard);
            $this->assertStringContainsString('quick-links', $source, $dashboard);
            $this->assertStringContainsString('quick-link-icon', $source, $dashboard);
            $this->assertStringContainsString('quick-link-copy', $source, $dashboard);
            $this->assertStringContainsString('quick-link-arrow', $source, $dashboard);
        }
    }

    public function testProductOversightUsesOnlyTheShellSpacingSystem(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/products.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/admin-overview.css');

        $this->assertStringContainsString('<section class="admin-overview-shell">', $view);
        $this->assertStringNotContainsString('admin-overview-shell space-y-5', $view);
        $this->assertStringContainsString('.stores-result:empty', $styles);
    }
}
