<?php

namespace Tests\Unit;

use App\Controllers\StoreAdminController;
use App\Services\StoreDayReviewPolicy;
use CodeIgniter\Test\CIUnitTestCase;

final class StoreAdminDayStatusTest extends CIUnitTestCase
{
    public function testStoreAdminPortalDoesNotInheritSystemAdminAuthority(): void
    {
        $controller = new StoreAdminController();

        $this->assertNotInstanceOf(\App\Controllers\AdminController::class, $controller);
        $this->assertFalse(method_exists($controller, 'createUser'));
        $this->assertFalse(method_exists($controller, 'toggleStoreStatus'));
        $this->assertFalse(method_exists($controller, 'auditData'));
        $source = (string) file_get_contents(APPPATH . 'Controllers/StoreAdminController.php');
        $this->assertStringNotContainsString('new AdminController', $source);
        $this->assertStringContainsString('StoreOversightService', $source);

        $serviceSource = (string) file_get_contents(APPPATH . 'Services/StoreOversightService.php');
        $this->assertStringNotContainsString('AdminController', $serviceSource);
        $this->assertStringContainsString('StoreDayReviewPolicy', $serviceSource);
    }

    public function testItDistinguishesTodaysOpenDayFromAStaleOpenDay(): void
    {
        $controller = new class extends StoreAdminController {
            public function statusFor(?array $session, string $todayDate): string
            {
                return $this->dayStatusForDate($session, $todayDate);
            }
        };

        $this->assertSame('open', $controller->statusFor([
            'business_date' => '2026-08-11',
            'status' => 'open',
        ], '2026-08-11'));
        $this->assertSame('stale_open', $controller->statusFor([
            'business_date' => '2026-08-10',
            'status' => 'open',
        ], '2026-08-11'));
    }

    public function testAPreviousClosedDayMeansTodayHasNotStarted(): void
    {
        $controller = new class extends StoreAdminController {
            public function statusFor(?array $session, string $todayDate): string
            {
                return $this->dayStatusForDate($session, $todayDate);
            }
        };

        $this->assertSame('not_started', $controller->statusFor([
            'business_date' => '2026-08-10',
            'status' => 'closed',
        ], '2026-08-11'));
        $this->assertSame('not_started', $controller->statusFor(null, '2026-08-11'));
    }

    public function testClosingOperatorCannotReviewTheirOwnStoreDay(): void
    {
        $policy = new StoreDayReviewPolicy();

        $this->assertFalse($policy->isIndependentReviewer(12, ['closed_by' => 12]));
        $this->assertTrue($policy->isIndependentReviewer(13, ['closed_by' => 12]));
        $this->assertFalse($policy->isIndependentReviewer(0, ['closed_by' => 12]));
    }
}
