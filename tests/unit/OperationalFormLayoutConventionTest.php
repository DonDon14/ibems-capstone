<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class OperationalFormLayoutConventionTest extends CIUnitTestCase
{
    public function testPreviousDayResolutionUsesStructuredReviewLayout(): void
    {
        $adminView = (string) file_get_contents(APPPATH . 'Views/admin/store-details.php');
        $storeAdminView = (string) file_get_contents(APPPATH . 'Views/store-admin/store-details.php');

        foreach ([$adminView, $storeAdminView] as $view) {
            foreach (['admin-modal-card', 'stale-day-resolution-form', 'stale-day-expected', 'stale-day-payment-breakdown', 'stale-day-fields', 'stale-day-reason', 'admin-modal-actions'] as $class) {
                $this->assertStringContainsString($class, $view);
            }
        }
    }

    public function testProductCreationUsesSectionedResponsiveLayout(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/inventory.php') . file_get_contents(APPPATH . 'Views/components/store_inventory_modals.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-inventory.css');

        foreach (['section-product-info', 'section-media', 'section-pricing', 'section-inventory', 'section-review'] as $class) {
            $this->assertStringContainsString($class, $view);
        }

        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $styles);
        $this->assertStringContainsString('width: min(1080px, 100%);', $styles);
    }
}
