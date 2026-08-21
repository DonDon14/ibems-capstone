<?php

use CodeIgniter\Test\CIUnitTestCase;

final class StoreDashboardTimezoneTest extends CIUnitTestCase
{
    public function testDashboardUsesManilaBusinessDateWithUtcStorageBoundaries(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Controllers/StoreController.php');

        $this->assertStringContainsString("new \\DateTimeZone('Asia/Manila')", $source);
        $this->assertStringContainsString("new \\DateTimeZone('UTC')", $source);
        $this->assertStringContainsString('setTime(0, 0)->setTimezone($storageZone)', $source);
        $this->assertStringContainsString('$sessionStartTs = $todayStart;', $source);
    }

    public function testStoredUtcTransactionTimeDisplaysInManila(): void
    {
        $this->assertSame('Aug 14, 07:58 AM', ibems_datetime('2026-08-13 23:58:00'));
    }
}
