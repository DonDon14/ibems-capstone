<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DashboardMetadataIconConventionTest extends TestCase
{
    public function testDashboardMetadataUsesTheSharedIconConvention(): void
    {
        $adminScript = file_get_contents(ROOTPATH . 'public/assets/js/admin-dashboard.js');
        $accountingScript = file_get_contents(ROOTPATH . 'public/assets/js/accounting-dashboard.js');
        $storeAdminScript = file_get_contents(ROOTPATH . 'public/assets/js/store-admin-dashboard.js');
        $styles = file_get_contents(ROOTPATH . 'public/assets/css/app.css');

        $this->assertIsString($adminScript);
        $this->assertIsString($accountingScript);
        $this->assertIsString($storeAdminScript);
        $this->assertIsString($styles);
        $this->assertStringContainsString('class="dashboard-meta-line"', $adminScript);
        $this->assertStringContainsString('detail_items', $adminScript);
        $this->assertStringContainsString('dashboard-health-message-lead', $adminScript);
        $this->assertStringContainsString('function acdDashboardMeta', $accountingScript);
        $this->assertStringContainsString('class="dashboard-meta-line"', $storeAdminScript);
        $this->assertStringContainsString('.dashboard-meta-line', $styles);
        $this->assertStringContainsString('.dashboard-meta-item', $styles);
    }

    public function testVisibleDashboardTemplatesNoLongerBuildPipeSeparatedMetadata(): void
    {
        $adminScript = file_get_contents(ROOTPATH . 'public/assets/js/admin-dashboard.js');
        $accountingScript = file_get_contents(ROOTPATH . 'public/assets/js/accounting-dashboard.js');
        $storeAdminScript = file_get_contents(ROOTPATH . 'public/assets/js/store-admin-dashboard.js');

        $this->assertIsString($adminScript);
        $this->assertIsString($accountingScript);
        $this->assertIsString($storeAdminScript);
        $this->assertStringNotContainsString('].join(" | ")', $adminScript);
        $this->assertStringNotContainsString('Attention needed: \' . implode(\' | \'', file_get_contents(APPPATH . 'Controllers/AdminController.php'));
        $this->assertStringNotContainsString('${row.employee_id || "-"} | Debt', $accountingScript);
        $this->assertStringNotContainsString('${sadEscape(row.business_date || "-")} | Closed by', $storeAdminScript);
    }
}
