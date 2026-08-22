<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class StorePosProductImageConventionTest extends CIUnitTestCase
{
    public function testCatalogUsesAnyAvailableVariantImageAndHandlesMissingAssets(): void
    {
        $script = file_get_contents(FCPATH . 'assets/js/store-pos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('function getCatalogImageUrl(variants)', $script);
        $this->assertStringContainsString('function replaceBrokenCatalogImage(image)', $script);
        $this->assertStringContainsString('image.matches("[data-product-image]")', $script);
        $this->assertStringContainsString('document.addEventListener("error"', $script);
        $this->assertStringContainsString('data-product-image data-product-initials=', $script);
        $this->assertStringContainsString('const imageUrl = getCatalogImageUrl(variants);', $script);
    }
}
