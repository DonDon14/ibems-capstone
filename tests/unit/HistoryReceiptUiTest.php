<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class HistoryReceiptUiTest extends CIUnitTestCase
{
    public function testHistoryReceiptUsesAnInnerRoundedScrollRegion(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/history.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-history.css');

        $this->assertStringContainsString('id="history-receipt-content"', $view);
        $this->assertStringContainsString('#history-receipt-modal .receipt-card', $styles);
        $this->assertStringContainsString('overflow: hidden;', $styles);
        $this->assertStringContainsString('#history-receipt-content', $styles);
        $this->assertStringContainsString('scrollbar-gutter: stable;', $styles);
        $this->assertStringContainsString('border-radius: 999px;', $styles);
        $this->assertStringContainsString('::-webkit-scrollbar-button', $styles);
        $this->assertStringContainsString('#history-receipt-modal .receipt-actions', $styles);
    }

    public function testHistorySummaryPrecedesAutomaticFilters(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/history.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-history.js');

        $this->assertLessThan(strpos($view, 'class="history-filters"'), strpos($view, 'class="history-summary"'));
        $this->assertStringNotContainsString('id="history-apply-filters"', $view);
        $this->assertStringContainsString('id="history-clear-filters" class="secondary-btn is-hidden"', $view);
        $this->assertStringContainsString('applyHistoryFiltersAutomatically', $script);
        $this->assertStringContainsString('"history-date-from"', $script);
        $this->assertStringContainsString('classList.toggle("is-hidden", !hasActiveFilters)', $script);
    }

    public function testHistoryControlsAlignRightAndPrintingWaitsForQrImage(): void
    {
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-history.css');
        $receipt = (string) file_get_contents(FCPATH . 'assets/js/receipt-standard.js');

        $this->assertStringContainsString('.history-filters.compact-filter-bar .compact-filter-primary', $styles);
        $this->assertStringContainsString('justify-content: flex-end;', $styles);
        $this->assertStringContainsString('function printWhenQrReady', $receipt);
        $this->assertStringContainsString('qr?.complete && Number(qr.naturalWidth || 0) > 0', $receipt);
        $this->assertStringContainsString('printWhenQrReady(popup);', $receipt);
        $this->assertStringNotContainsString("popup.document.close();\n        popup.focus();\n        popup.print();", $receipt);
    }
}
