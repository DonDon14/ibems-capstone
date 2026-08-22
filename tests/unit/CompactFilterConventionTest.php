<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CompactFilterConventionTest extends TestCase
{
    public function testCrowdedListToolbarsOptIntoTheSharedCompactPattern(): void
    {
        $views = [
            'admin/user-view.php',
            'admin/products.php',
            'admin/audit.php',
            'admin/stores.php',
            'store-admin/stores.php',
            'accounting/debts.php',
            'store/history.php',
            'store/staff-records.php',
            'store/inventory.php',
            'store/settings.php',
            'user/stores.php',
            'user/history.php',
        ];

        foreach ($views as $view) {
            $source = file_get_contents(APPPATH . 'Views/' . $view);
            $this->assertIsString($source, $view);
            $this->assertStringContainsString('data-compact-filters', $source, $view);
        }
    }

    public function testSharedBehaviorIncludesAccessibleSortFilterApplyAndResetControls(): void
    {
        $source = file_get_contents(ROOTPATH . 'public/assets/js/app-layout.js');

        $this->assertIsString($source);
        $this->assertStringContainsString('aria-controls', $source);
        $this->assertStringContainsString('aria-expanded', $source);
        $this->assertStringContainsString('data-compact-filter-reset', $source);
        $this->assertStringContainsString('data-compact-filter-apply', $source);
        $this->assertStringContainsString('event.key === "Escape"', $source);
    }
}
