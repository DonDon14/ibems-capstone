<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class OperationalFormLayoutConventionTest extends CIUnitTestCase
{
    public function testPreviousDayResolutionUsesStructuredReviewLayout(): void
    {
        $source = (string) file_get_contents(FCPATH . 'assets/js/admin-store-details.js');

        foreach (['stale-day-intro', 'stale-day-fields', 'stale-day-reason', 'stale-day-footer'] as $class) {
            $this->assertStringContainsString($class, $source);
        }
    }

    public function testProductCreationUsesSectionedResponsiveLayout(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/inventory.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-inventory.css');

        foreach (['section-product-info', 'section-media', 'section-pricing', 'section-inventory', 'section-review'] as $class) {
            $this->assertStringContainsString($class, $view);
        }

        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $styles);
        $this->assertStringContainsString('width: min(1080px, 100%);', $styles);
    }
}
