<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class SearchControlConventionTest extends CIUnitTestCase
{
    public function testSearchInputsUseOnlyTheSharedMagnifier(): void
    {
        $views = [
            APPPATH . 'Views/accounting/debts.php',
            APPPATH . 'Views/admin/user-view.php',
            APPPATH . 'Views/store/staff-records.php',
        ];

        foreach ($views as $view) {
            $source = (string) file_get_contents($view);
            $this->assertStringNotContainsString('pointer-events-none absolute left-3', $source, $view);
            $this->assertStringNotContainsString('staff-search-icon', $source, $view);
        }

        $modernUi = (string) file_get_contents(FCPATH . 'assets/css/modern-ui.css');
        $this->assertStringContainsString('.ibems-modern input[type="search"]', $modernUi);
        $this->assertStringContainsString('background-image:', $modernUi);
    }
}
