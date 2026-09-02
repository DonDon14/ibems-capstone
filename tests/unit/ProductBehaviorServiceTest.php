<?php

use App\Services\ProductBehaviorService;
use CodeIgniter\Test\CIUnitTestCase;

final class ProductBehaviorServiceTest extends CIUnitTestCase
{
    public function testNormalizesSupportedProductBehavior(): void
    {
        $this->assertSame([
            'item_type' => 'prepared_item',
            'stock_policy' => 'tracked',
            'unit_code' => 'serving',
        ], ProductBehaviorService::normalize([
            'item_type' => 'PREPARED_ITEM',
            'stock_policy' => 'TRACKED',
            'unit_code' => 'SERVING',
        ]));
    }

    public function testDepositCannotPretendToBeTrackedInventory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProductBehaviorService::normalize(['item_type' => 'deposit', 'stock_policy' => 'tracked', 'unit_code' => 'container']);
    }

    public function testCapabilitiesAreDeduplicatedAndUnknownValuesAreRejected(): void
    {
        $this->assertSame(['retail', 'food_service'], ProductBehaviorService::normalizeCapabilities(['retail', 'unknown', 'food_service', 'retail']));
    }
}
