<?php

namespace Tests\Unit;

use App\Services\NotificationService;
use CodeIgniter\Test\CIUnitTestCase;

class NotificationLinkTest extends CIUnitTestCase
{
    public function testLegacyTransactionLinksOpenCurrentReceiptPage(): void
    {
        $reflection = new \ReflectionClass(NotificationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('inAppLink');
        foreach (['http://localhost:8080/index.php/user/transactions/271', '/user/transactions/271', 'https://old.example/user/receipt/271'] as $link) {
            $this->assertSame(site_url('user/receipt/271'), $method->invoke($service, $link));
        }
        foreach (['/user/transactions/0', '/other/user/transactions/271'] as $link) {
            $this->assertSame($link, $method->invoke($service, $link));
        }
    }
    public function testLegacyDashboardLinksUseCurrentOriginAndPreserveContext(): void
    {
        $service = (new \ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass(NotificationService::class))->getMethod('inAppLink');
        foreach (['http://localhost:8080/index.php/user/dashboard', '/user/dashboard', 'https://old.example/user/dashboard'] as $link) {
            $this->assertSame(site_url('user/dashboard'), $method->invoke($service, $link));
        }
        $this->assertSame(site_url('user/dashboard') . '?period=month#summary', $method->invoke($service, 'http://localhost:8080/index.php/user/dashboard?period=month#summary'));
        foreach (['https://external.example/guide', '/other/user/dashboard', '/user/dashboard-extra'] as $link) {
            $this->assertSame($link, $method->invoke($service, $link));
        }
    }

    public function testGeneratedAuditAndDepartmentDestinationsUseCurrentOrigin(): void
    {
        $reflection = new \ReflectionClass(NotificationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('inAppLink');
        foreach (['admin/audit', 'user/department-authorizations'] as $path) {
            foreach (['/' . $path, 'http://localhost:8080/index.php/' . $path, 'https://old.example/' . $path] as $link) {
                $this->assertSame(site_url($path), $method->invoke($service, $link));
            }
            $this->assertSame(site_url($path) . '?period=month#activity', $method->invoke($service, 'http://localhost:8080/index.php/' . $path . '?period=month#activity'));
            $this->assertSame('/' . $path . '-extra', $method->invoke($service, '/' . $path . '-extra'));
        }
    }
}
