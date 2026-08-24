<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class StoreReportsAndScrollbarUiTest extends CIUnitTestCase
{
    public function testReportChartsRenderExplicitLoadingEmptyAndErrorStates(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/reports.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-reports.js');

        foreach (['reports-trend-chart-state', 'reports-payment-mix-chart-state'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $view);
        }
        $this->assertStringContainsString('function rSetChartState', $script);
        $this->assertStringContainsString('No sales were recorded for this period.', $script);
        $this->assertStringContainsString('No payments were recorded for this period.', $script);
        $this->assertStringContainsString('The chart library could not be loaded.', $script);
    }

    public function testAppUsesOneGlobalScrollbarContractAndSettingsOptsIntoInsetBodies(): void
    {
        $shared = (string) file_get_contents(FCPATH . 'assets/css/app.css');
        $settings = (string) file_get_contents(APPPATH . 'Views/store/settings.php');

        $this->assertStringContainsString('body.ibems-modern *::-webkit-scrollbar', $shared);
        $this->assertStringContainsString('body.ibems-modern *::-webkit-scrollbar-button', $shared);
        $this->assertStringContainsString('@supports (-moz-appearance: none)', $shared);
        $this->assertSame(2, substr_count($settings, 'settings-modal-body'));
        $this->assertSame(2, substr_count($settings, 'app-inset-modal-scroll'));
    }
}
