<?php

use CodeIgniter\Test\CIUnitTestCase;

final class StoreDashboardTimezoneTest extends CIUnitTestCase
{
    public function testDashboardUsesManilaBusinessDateWithUtcStorageBoundaries(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Services/StoreDashboardService.php');

        $this->assertStringContainsString("new \\DateTimeZone('Asia/Manila')", $source);
        $this->assertStringContainsString("new \\DateTimeZone('UTC')", $source);
        $this->assertStringContainsString('setTime(0, 0)->setTimezone($storageZone)', $source);
        $this->assertStringContainsString('$sessionStartTs = $todayStart;', $source);
    }

    public function testStoredUtcTransactionTimeDisplaysInManila(): void
    {
        $this->assertSame('Aug 14, 07:58 AM', ibems_datetime('2026-08-13 23:58:00'));
    }

    public function testBusinessDateHelperKeepsManilaOperationsAndUtcStorageAligned(): void
    {
        $bounds = ibems_business_day_utc_bounds('2026-08-25');

        $this->assertSame('2026-08-24 16:00:00', $bounds['start']);
        $this->assertSame('2026-08-25 15:59:59', $bounds['end']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', ibems_business_date());

        $dayOperations = (string) file_get_contents(APPPATH . 'Services/StoreDayOperationService.php');
        $storeAdmin = (string) file_get_contents(APPPATH . 'Controllers/StoreAdminController.php');
        $expected = (string) file_get_contents(APPPATH . 'Services/StoreDayExpectedService.php');
        $transactions = (string) file_get_contents(APPPATH . 'Services/StoreTransactionQueryService.php');
        $inventory = (string) file_get_contents(APPPATH . 'Services/StoreInventoryQueryService.php');
        $reports = (string) file_get_contents(APPPATH . 'Services/StoreReportService.php');
        $adminDashboard = (string) file_get_contents(APPPATH . 'Services/AdminDashboardService.php');
        $accountingDashboard = (string) file_get_contents(APPPATH . 'Services/AccountingReportingService.php');
        $userDashboard = (string) file_get_contents(APPPATH . 'Services/UserDashboardService.php');
        $dashboardPeriod = (string) file_get_contents(APPPATH . 'Services/DashboardPeriod.php');
        $pos = (string) file_get_contents(FCPATH . 'assets/js/store-pos.js');

        $this->assertStringContainsString('ibems_business_date()', $dayOperations);
        $this->assertStringContainsString('DashboardPeriod::resolve', $storeAdmin);
        $this->assertStringContainsString('ibems_business_day_utc_bounds($businessDate)', $expected);
        $this->assertStringContainsString("ibems_business_day_utc_bounds(\$dateFrom)['start']", $transactions);
        $this->assertStringContainsString("ibems_business_day_utc_bounds(\$dateTo)['end']", $inventory);
        $this->assertStringContainsString("setTimezone(\$businessZone)->format('Y-m-d')", $reports);
        $this->assertStringContainsString("setTimezone(\$businessZone)->format('Y-m-d')", $adminDashboard);
        $this->assertStringContainsString('DashboardPeriod::resolve', $accountingDashboard);
        $this->assertStringContainsString('DashboardPeriod::resolve', $userDashboard);
        $this->assertStringContainsString('ibems_business_day_utc_bounds($fromDate)', $dashboardPeriod);
        $this->assertStringContainsString('timeZone: "Asia/Manila"', $pos);
        $this->assertStringNotContainsString('new Date().toISOString().slice(0, 10)', $pos);
    }
}
