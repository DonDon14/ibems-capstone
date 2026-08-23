<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class SearchControlConventionTest extends CIUnitTestCase
{
    public function testSearchInputsUseOnlyTheSharedMagnifier(): void
    {
        $views = glob(APPPATH . 'Views/*/*.php') ?: [];

        foreach ($views as $view) {
            $source = (string) file_get_contents($view);
            $this->assertStringNotContainsString('pointer-events-none absolute left-3', $source, $view);
            $this->assertStringNotContainsString('staff-search-icon', $source, $view);
            $this->assertDoesNotMatchRegularExpression(
                '/class=["\'][^"\']*(?:search-icon|input-search-icon)[^"\']*["\']/',
                $source,
                $view
            );
        }

        $modernUi = (string) file_get_contents(FCPATH . 'assets/css/modern-ui.css');
        $this->assertStringContainsString('.ibems-modern input[type="search"]', $modernUi);
        $this->assertStringContainsString('background-image:', $modernUi);
    }

    public function testEnhancedControlsRenderOneVisibleTrigger(): void
    {
        $controls = (string) file_get_contents(FCPATH . 'assets/js/modern-controls.js');
        $modernUi = (string) file_get_contents(FCPATH . 'assets/css/modern-ui.css');

        $this->assertStringContainsString('select:not([multiple]):not([size])', $controls);
        $this->assertStringContainsString("input[type='date']", $controls);
        $this->assertSame(1, substr_count($controls, 'trigger.className = "ui-select-trigger"'));
        $this->assertSame(1, substr_count($controls, 'trigger.className = "ui-date-trigger"'));
        $this->assertStringContainsString('.ibems-modern .ui-native-control', $modernUi);
        $this->assertStringContainsString('position: absolute !important;', $modernUi);
        $this->assertStringContainsString('width: 1px !important;', $modernUi);
        $this->assertStringContainsString('.ui-select-menu::-webkit-scrollbar-button', $modernUi);
        $this->assertStringContainsString("display: none;\n    width: 0;\n    height: 0;", $modernUi);
        $this->assertStringContainsString("overscroll-behavior: contain;\n    scrollbar-color: #94a3b8 transparent;\n    scrollbar-width: thin;", $modernUi);
    }
}
