<?php

namespace Tests\Unit;

use App\Services\CreditService;
use CodeIgniter\Test\CIUnitTestCase;

class CreditServiceTest extends CIUnitTestCase
{
    public function testComputeLimitUsesDefaultWhenSalaryMissing(): void
    {
        $service = new CreditService();

        $this->assertSame(1000.0, $service->computeLimit(null));
        $this->assertSame(1000.0, $service->computeLimit(0));
    }

    public function testComputeLimitUsesThirtyPercentWithinBounds(): void
    {
        $service = new CreditService();

        $this->assertSame(3000.0, $service->computeLimit(10000));
        $this->assertSame(15000.0, $service->computeLimit(100000));
        $this->assertSame(1000.0, $service->computeLimit(2000));
    }

    public function testCanBorrowEnforcesAvailableCredit(): void
    {
        $service = new CreditService();

        $this->assertTrue($service->canBorrow(1000, 5000, 3999));
        $this->assertTrue($service->canBorrow(1000, 5000, 4000));
        $this->assertFalse($service->canBorrow(1000, 5000, 4000.01));
    }
}
