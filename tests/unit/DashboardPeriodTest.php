<?php

use App\Services\DashboardPeriod;
use CodeIgniter\Test\CIUnitTestCase;

final class DashboardPeriodTest extends CIUnitTestCase
{
    public function testSupportedDashboardPeriodsUseManilaBusinessBoundaries(): void
    {
        $today = ibems_business_date();
        foreach (['day', 'week', 'month', 'year'] as $key) {
            $period = DashboardPeriod::resolve($key);
            $this->assertSame($key, $period['key']);
            $this->assertSame($today, $period['to']);
            $this->assertSame(ibems_business_day_utc_bounds($period['from'])['start'], $period['start']);
            $this->assertSame(ibems_business_day_utc_bounds($period['to'])['end'], $period['end']);
            $this->assertNotSame([], DashboardPeriod::trendSeed($period));
        }
    }

    public function testInvalidPeriodFailsSafelyToDay(): void
    {
        $period = DashboardPeriod::resolve('all-time');
        $this->assertSame('day', $period['key']);
        $this->assertCount(1, DashboardPeriod::trendSeed($period));
    }

    public function testYearTrendUsesMonthlyBuckets(): void
    {
        $period = DashboardPeriod::resolve('year');
        $seed = DashboardPeriod::trendSeed($period);
        $this->assertSame('month', $period['bucket']);
        $this->assertSame(substr($period['from'], 0, 7), $seed[0]['key']);
        $this->assertSame(substr($period['to'], 0, 7), $seed[array_key_last($seed)]['key']);
    }

    public function testEveryPortalDashboardExposesTheSharedPeriodSelector(): void
    {
        foreach (['admin', 'accounting', 'store-admin', 'store', 'user', 'department'] as $portal) {
            $view = (string) file_get_contents(APPPATH . 'Views/' . $portal . '/dashboard.php');
            $this->assertStringContainsString('components/dashboard_period_filter', $view, $portal);
        }

        $component = (string) file_get_contents(APPPATH . 'Views/components/dashboard_period_filter.php');
        foreach (['day', 'week', 'month', 'year'] as $period) {
            $this->assertStringContainsString("'{$period}'", $component);
        }

        foreach (['admin-dashboard.js', 'accounting-dashboard.js', 'store-admin-dashboard.js', 'user-dashboard.js', 'department-portal.js'] as $scriptName) {
            $script = (string) file_get_contents(FCPATH . 'assets/js/' . $scriptName);
            $this->assertStringContainsString('dashboard-period-change', $script, $scriptName);
            $this->assertStringContainsString('period', $script, $scriptName);
        }
    }
}
