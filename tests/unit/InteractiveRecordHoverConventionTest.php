<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class InteractiveRecordHoverConventionTest extends TestCase
{
    public function testClickableRecordCardsShareTheLeftEdgeHoverCue(): void
    {
        $storeScript = file_get_contents(ROOTPATH . 'public/assets/js/store-staff-records.js');
        $accountingScript = file_get_contents(ROOTPATH . 'public/assets/js/accounting-debts.js');
        $adminScript = file_get_contents(ROOTPATH . 'public/assets/js/admin-user-view.js');
        $styles = file_get_contents(ROOTPATH . 'public/assets/css/app.css');

        $this->assertIsString($storeScript);
        $this->assertIsString($accountingScript);
        $this->assertIsString($adminScript);
        $this->assertIsString($styles);
        $this->assertStringContainsString('staff-record-row sr-row-clickable interactive-record-row', $storeScript);
        $this->assertStringContainsString('acct-record-row acct-row-clickable interactive-record-row', $accountingScript);
        $this->assertStringContainsString('uv-record-row interactive-record-row', $adminScript);
        $this->assertStringContainsString('.ibems-modern .interactive-record-row:hover', $styles);
        $this->assertStringContainsString('box-shadow: inset 3px 0 0 #2563eb', $styles);
    }

    public function testClickableTableRowsUseTheSameLeftEdgeCue(): void
    {
        $styles = file_get_contents(ROOTPATH . 'public/assets/css/app.css');

        $this->assertIsString($styles);
        $this->assertStringContainsString('tbody tr.table-row-clickable:hover', $styles);
        $this->assertStringContainsString('tbody tr.table-row-clickable:focus-within', $styles);
    }

    public function testAdminEmployeeMetadataUsesSemanticIconsInsteadOfPipeSeparators(): void
    {
        $script = file_get_contents(ROOTPATH . 'public/assets/js/admin-user-view.js');
        $styles = file_get_contents(ROOTPATH . 'public/assets/css/app.css');

        $this->assertIsString($script);
        $this->assertIsString($styles);
        $this->assertStringContainsString('class="uv-subline uv-meta-line', $script);
        $this->assertStringContainsString('bi-person-vcard', $script);
        $this->assertStringContainsString('bi-person-badge', $script);
        $this->assertStringContainsString('bi-envelope', $script);
        $this->assertStringNotContainsString('formatTypeLabel(row.user_type))} |', $script);
        $this->assertStringContainsString('.uv-meta-item', $styles);
    }
}
