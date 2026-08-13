<?php

use PHPUnit\Framework\TestCase;

final class ProductFamilyArchitectureTest extends TestCase
{
    public function testFamilySchemaAndAtomicEndpointAreDefined(): void
    {
        $migration = file_get_contents(ROOTPATH . 'app/Database/Migrations/2026-08-13-120000_AddProductFamilies.php');
        $controller = file_get_contents(ROOTPATH . 'app/Controllers/StoreController.php');
        $routes = file_get_contents(ROOTPATH . 'app/Config/Routes.php');

        $this->assertStringContainsString("product_families", $migration);
        $this->assertStringContainsString("family_id", $migration);
        $this->assertStringContainsString('public function addProductFamily()', $controller);
        $this->assertStringContainsString('$db->transBegin()', $controller);
        $this->assertStringContainsString('$db->transRollback()', $controller);
        $this->assertStringContainsString('inventory/add-product-family', $routes);
    }

    public function testInventoryAndPosGroupFamiliesButRetainExactVariantActions(): void
    {
        $inventoryJs = file_get_contents(ROOTPATH . 'public/assets/js/store-inventory.js');
        $posJs = file_get_contents(ROOTPATH . 'public/assets/js/store-pos.js');
        $posView = file_get_contents(ROOTPATH . 'app/Views/store/pos.php');

        $this->assertStringContainsString('function invGroupProducts', $inventoryJs);
        $this->assertStringContainsString('data-family-toggle', $inventoryJs);
        $this->assertStringContainsString('inventory-variant-row', $inventoryJs);
        $this->assertStringContainsString('data-product-action="${item.id}"', $inventoryJs);
        $this->assertStringContainsString('function groupCatalogProducts', $posJs);
        $this->assertStringContainsString('function openProductVariantPicker', $posJs);
        $this->assertStringContainsString('data-direct-product', $posJs);
        $this->assertStringContainsString('data-pick-variant', $posJs);
        $this->assertStringContainsString('const familyImage =', $posJs);
        $this->assertStringContainsString('product-variant-option-image', $posJs);
        $this->assertStringContainsString('id="product-variant-modal"', $posView);
        $this->assertStringContainsString('getProductByScanCode', $posJs);
    }
}
