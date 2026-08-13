<?php

use PHPUnit\Framework\TestCase;

final class ProductVariantBuilderTest extends TestCase
{
    public function testCreateFormSupportsGeneratedSkusAndMultipleVariantImages(): void
    {
        $view = file_get_contents(ROOTPATH . 'app/Views/store/inventory.php');
        $js = file_get_contents(ROOTPATH . 'public/assets/js/store-inventory.js');

        $this->assertStringContainsString('Add Variant', $view);
        $this->assertStringContainsString('One image for all variants', $view);
        $this->assertStringContainsString('Different image per variant', $view);
        $this->assertStringContainsString('function invGeneratedSku', $js);
        $this->assertStringContainsString('invAdditionalVariants()', $js);
        $this->assertStringContainsString('/store/inventory/add-product-family', $js);
        $this->assertStringContainsString('formData.append("variants", JSON.stringify', $js);
        $this->assertStringContainsString('Scan Product Barcode', $view);
        $this->assertStringContainsString('facingMode:"environment"', $js);
        $css = file_get_contents(ROOTPATH . 'public/assets/css/store-inventory.css');
        $this->assertStringContainsString('grid-template-columns:repeat(2,minmax(0,1fr))', $css);
        $this->assertStringContainsString('max-height:360px', $css);
        $this->assertStringContainsString('overflow-y:auto', $css);
    }
}
