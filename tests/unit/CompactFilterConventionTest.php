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

    public function testSharedBehaviorIncludesAccessibleSortFilterDoneAndResetControls(): void
    {
        $source = file_get_contents(ROOTPATH . 'public/assets/js/app-layout.js');
        $styles = file_get_contents(ROOTPATH . 'public/assets/css/app.css');

        $this->assertIsString($source);
        $this->assertIsString($styles);
        $this->assertStringContainsString('aria-controls', $source);
        $this->assertStringContainsString('aria-expanded', $source);
        $this->assertStringContainsString('data-compact-filter-reset', $source);
        $this->assertStringContainsString('.staff-search-wrap, .uv-search-wrap, .acct-search-wrap, .debt-search-wrap', $source);
        $this->assertStringContainsString('!button.closest(".ui-select, .ui-date")', $source);
        $this->assertStringContainsString('data-compact-filter-apply', $source);
        $this->assertStringContainsString('>Done</button>', $source);
        $this->assertStringContainsString('panel.dataset.mode === "sort"', $source);
        $this->assertStringContainsString('.ui-select-menu, .ui-date-popup', $source);
        $this->assertStringContainsString('event.key === "Escape"', $source);
        $this->assertStringContainsString('panel.style.removeProperty("max-height")', $source);
        $this->assertStringContainsString('panelRect.height > availableHeight', $source);
        $this->assertStringNotContainsString('Math.max(240, window.innerHeight - top - 16)', $source);
        $this->assertStringContainsString('.compact-filter-panel::-webkit-scrollbar-button', $styles);
        $this->assertStringContainsString('scrollbar-width: thin;', $styles);
    }
}
