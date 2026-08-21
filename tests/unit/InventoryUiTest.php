<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class InventoryUiTest extends CIUnitTestCase
{
    public function testInventoryProvidesFilterAndFamilyManagementControls(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/inventory.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-inventory.js');

        foreach (['inventory-results-count', 'inventory-toggle-families', 'inventory-stock-summary'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $view);
        }

        $this->assertStringContainsString('data-stock-summary', $script);
        $this->assertStringContainsString('inventory-family-name-toggle', $script);
        $this->assertStringContainsString('product-action-thumb', $script);
        $this->assertStringContainsString('POS Availability', $script);
        $this->assertStringContainsString('aria-selected', $script);
        $this->assertStringContainsString('Out of Stock', $script);
        $this->assertStringContainsString('invRenderResultsContext', $script);
        $this->assertStringContainsString('id="inventory-clear-filters" class="secondary-btn is-hidden"', $view);
        $this->assertStringContainsString('Reset filters', $view);
        $this->assertStringContainsString('clear.classList.toggle("is-hidden", !hasNonDefaultControls)', $script);
        $this->assertStringContainsString('groups.flatMap', $script);
        $this->assertStringContainsString('bi bi-sliders', $script);
        $this->assertStringContainsString('aria-label="No product image"', $script);
        $this->assertStringNotContainsString('No Img', $script);
    }

    public function testInventorySummaryAndTableFollowInteractiveUiConventions(): void
    {
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-inventory.css');

        $this->assertStringContainsString('.inventory-summary-pill:focus-visible', $styles);
        $this->assertStringContainsString('.inventory-summary-pill.is-active', $styles);
        $this->assertStringContainsString('.inventory-results-toolbar', $styles);
        $this->assertStringContainsString('overflow-x: auto;', $styles);
        $this->assertStringNotContainsString('max-height: min(62vh, 720px);', $styles);
        $this->assertStringContainsString('table-layout: fixed;', $styles);
        $this->assertStringContainsString('.product-action-thumb', $styles);
        $this->assertStringContainsString('.adjust-save-hint', $styles);
        $this->assertStringContainsString('background: #0f3f72;', $styles);
        $this->assertStringContainsString('position: sticky;', $styles);
    }

    public function testUnsavedProductConfirmationAppearsAboveInventoryModal(): void
    {
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-inventory.js');
        $sharedStyles = (string) file_get_contents(FCPATH . 'assets/css/modern-ui.css');

        $this->assertStringContainsString('cancelLabel: "Continue editing"', $script);
        $this->assertMatchesRegularExpression('/\.app-dialog\s*\{[^}]*z-index:\s*3000;/s', $sharedStyles);
    }
}
