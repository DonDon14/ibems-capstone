<?php

use App\Services\DashboardAlertPagination;
use PHPUnit\Framework\TestCase;

final class DashboardAlertPaginationTest extends TestCase
{
    public function testReturnsTheRequestedAlertPageWithStableMetadata(): void
    {
        $alerts = array_map(
            static fn (int $number): array => ['title' => 'Alert ' . $number],
            range(1, 12),
        );

        $result = DashboardAlertPagination::paginate($alerts, 2, 5);

        $this->assertSame(['Alert 6', 'Alert 7', 'Alert 8', 'Alert 9', 'Alert 10'], array_column($result['items'], 'title'));
        $this->assertSame(2, $result['pagination']['page']);
        $this->assertSame(12, $result['pagination']['total']);
        $this->assertSame(3, $result['pagination']['total_pages']);
        $this->assertSame(6, $result['pagination']['from']);
        $this->assertSame(10, $result['pagination']['to']);
        $this->assertTrue($result['pagination']['has_previous']);
        $this->assertTrue($result['pagination']['has_next']);
    }

    public function testClampsAnOutOfRangePageToTheLastPage(): void
    {
        $alerts = array_map(
            static fn (int $number): array => ['title' => 'Alert ' . $number],
            range(1, 12),
        );

        $result = DashboardAlertPagination::paginate($alerts, 99, 5);

        $this->assertSame(['Alert 11', 'Alert 12'], array_column($result['items'], 'title'));
        $this->assertSame(3, $result['pagination']['page']);
        $this->assertSame(11, $result['pagination']['from']);
        $this->assertSame(12, $result['pagination']['to']);
        $this->assertFalse($result['pagination']['has_next']);
    }

    public function testEmptyAlertsStillExposeAValidFirstPage(): void
    {
        $result = DashboardAlertPagination::paginate([], 4, 5);

        $this->assertSame([], $result['items']);
        $this->assertSame(1, $result['pagination']['page']);
        $this->assertSame(1, $result['pagination']['total_pages']);
        $this->assertSame(0, $result['pagination']['total']);
        $this->assertSame(0, $result['pagination']['from']);
        $this->assertSame(0, $result['pagination']['to']);
    }
}
