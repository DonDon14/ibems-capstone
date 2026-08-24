<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class ReceiptStandardUiTest extends CIUnitTestCase
{
    public function testSharedReceiptUsesStructuredModernSections(): void
    {
        $script = (string) file_get_contents(FCPATH . 'assets/js/receipt-standard.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/receipt-standard.css');

        $this->assertStringContainsString('class="ibems-receipt-brand"', $script);
        $this->assertStringContainsString('class="ibems-receipt-details"', $script);
        $this->assertStringContainsString('class="ibems-receipt-payment"', $script);
        $this->assertStringContainsString('class="ibems-receipt-total"', $script);
        $this->assertStringContainsString('Verify this receipt', $script);
        $this->assertStringContainsString('ibems-receipt-qty', $styles);
        $this->assertStringContainsString('@media (max-width: 520px)', $styles);
        $this->assertStringContainsString('@media print', $script);
    }

    public function testReceiptAssetsAreVersionedAcrossEveryReceiptSurface(): void
    {
        $views = [
            APPPATH . 'Views/store/pos.php',
            APPPATH . 'Views/store/history.php',
            APPPATH . 'Views/store/reports.php',
            APPPATH . 'Views/store/receipt.php',
            APPPATH . 'Views/store/staff-records.php',
            APPPATH . 'Views/user/receipt.php',
            APPPATH . 'Views/user/history.php',
        ];

        foreach ($views as $viewPath) {
            $view = (string) file_get_contents($viewPath);
            $this->assertStringContainsString('receipt-standard.css\') ?>?v=20260821b', $view, $viewPath);
            $this->assertStringContainsString('receipt-standard.js\') ?>?v=20260824a', $view, $viewPath);
        }
    }

    public function testEveryReceiptModalUsesTheSharedClippedScrollShell(): void
    {
        $styles = (string) file_get_contents(FCPATH . 'assets/css/receipt-standard.css');
        $staffView = (string) file_get_contents(APPPATH . 'Views/store/staff-records.php');

        foreach ([
            '#receipt-modal',
            '#history-receipt-modal',
            '#reports-receipt-modal',
            '#staff-receipt-modal',
            '#uh-receipt-modal',
            '#receipt-content',
            '#history-receipt-content',
            '#reports-receipt-content',
            '#staff-receipt-content',
            '#uh-receipt-content',
        ] as $selector) {
            $this->assertStringContainsString($selector, $styles, $selector);
        }

        $this->assertStringContainsString('overflow: hidden;', $styles);
        $this->assertStringContainsString('overflow-y: auto;', $styles);
        $this->assertStringContainsString('::-webkit-scrollbar-button', $styles);
        $this->assertStringContainsString('::-webkit-scrollbar-button:vertical:start:decrement', $styles);
        $this->assertStringContainsString('background-image: none;', $styles);
        $this->assertStringContainsString('scrollbar-width: none;', $styles);
        $this->assertStringContainsString('<div class="receipt-card">', $staffView);
        $this->assertStringNotContainsString('id="staff-receipt-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="staff-receipt-title">\r\n    <div class="receipt-card max-h', $staffView);
    }
}
