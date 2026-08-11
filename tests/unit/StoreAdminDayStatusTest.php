<?php

namespace Tests\Unit;

use App\Controllers\StoreAdminController;
use CodeIgniter\Test\CIUnitTestCase;

final class StoreAdminDayStatusTest extends CIUnitTestCase
{
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
}
