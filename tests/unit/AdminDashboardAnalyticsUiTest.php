<?php

use PHPUnit\Framework\TestCase;

final class AdminDashboardAnalyticsUiTest extends TestCase
{
    public function testAdminDashboardUsesAccessibleModernAnalyticsPresentation(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/dashboard.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-dashboard.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/app.css');
        $themeStyles = (string) file_get_contents(FCPATH . 'assets/css/modern-ui.css');

        $this->assertStringContainsString('id="ad-sales-total"', $view);
        $this->assertStringContainsString('aria-describedby="ad-sales-chart-summary"', $view);
        $this->assertStringContainsString('id="ad-top-items-ranking"', $view);
        $this->assertStringNotContainsString('id="ad-top-items-chart"', $view);

        $this->assertStringContainsString('tension: 0', $script);
        $this->assertStringContainsString('grid: { display: false }', $script);
        $this->assertStringContainsString('adRenderTopItemsRanking', $script);
        $this->assertStringContainsString('row.sales', $script);
        $this->assertStringContainsString('window.addEventListener("ibems:themechange"', $script);
        $this->assertStringNotContainsString('adRenderTopItemsChart', $script);

        $this->assertStringContainsString('.dashboard-ranking-list', $styles);
        $this->assertStringContainsString('.dashboard-rank-track', $styles);
        $this->assertStringContainsString('.dashboard-revenue-chart', $styles);
        $this->assertStringContainsString('.dashboard-rank-item', $themeStyles);
    }
}
