<?php
use App\Services\StoreWorkspaceService;
use CodeIgniter\Test\CIUnitTestCase;
final class StoreWorkspaceServiceTest extends CIUnitTestCase
{
    public function testCurrentPageDistinguishesSectionsAndStores(): void
    {
        $this->assertTrue(StoreWorkspaceService::isCurrent('store/reports?store_id=6', '/store/reports', ['store_id'=>'6']));
        $this->assertFalse(StoreWorkspaceService::isCurrent('store/reports?store_id=6', '/store/reports', ['store_id'=>'4']));
        $this->assertFalse(StoreWorkspaceService::isCurrent('store/reports?store_id=6', '/store/history', ['store_id'=>'6']));
        $this->assertTrue(StoreWorkspaceService::isCurrent('store/offerings?section=rental&store_id=6', '/store/offerings', ['section'=>'rental','store_id'=>'6']));
        $this->assertFalse(StoreWorkspaceService::isCurrent('store/offerings?section=food&store_id=6', '/store/offerings', ['section'=>'rental','store_id'=>'6']));
        $this->assertTrue(StoreWorkspaceService::isCurrent('store-admin/stores/6?store_id=6', '/store-admin/stores/6', []));
    }
    public function testCapabilitiesExposeOnlyTheirOwnStoreFunctions(): void
    {
        foreach ([['retail'], ['food_service'], ['rental'], ['food_service','rental']] as $caps) {
            $links = StoreWorkspaceService::links(42, $caps);
            $labels = array_column($links, 'label');
            $this->assertSame(in_array('food_service', $caps), in_array('Daily food', $labels));
            $this->assertSame(in_array('rental', $caps), in_array('Rentals', $labels));
            $this->assertContains('Daily report handoff', $labels);
            foreach ($links as $link) $this->assertStringContainsString('store_id=42', $link['path']);
        }
    }
    public function testDisabledRentalsRetainOnlyOutstandingReturnAccess(): void
    {
        $labels = array_column(StoreWorkspaceService::links(2, ['retail'], true), 'label');
        $this->assertContains('Outstanding returns', $labels);
        $this->assertNotContains('Rentals', $labels);
        $this->assertNotContains('Daily food', $labels);
    }
}
