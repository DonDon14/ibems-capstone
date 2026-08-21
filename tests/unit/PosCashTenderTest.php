<?php

use PHPUnit\Framework\TestCase;

final class PosCashTenderTest extends TestCase
{
    public function testCashCheckoutRequiresTenderAndShowsChange(): void
    {
        $js = file_get_contents(ROOTPATH . 'public/assets/js/store-pos.js');
        $receipt = file_get_contents(ROOTPATH . 'public/assets/js/receipt-standard.js');

        $this->assertStringContainsString('id="confirm-cash-received"', $js);
        $this->assertStringContainsString('id="confirm-change-due"', $js);
        $this->assertStringContainsString('received >= cashAmount', $js);
        $this->assertStringContainsString('received - cashAmount', $js);
        $this->assertStringContainsString('payload.cash_received', $js);
        $this->assertStringContainsString('Cash received', $receipt);
        $this->assertStringContainsString('Change', $receipt);

        $css = file_get_contents(ROOTPATH . 'public/assets/css/store-pos.css');
        $this->assertMatchesRegularExpression(
            '/\.confirm-cash-grid input\s*\{[^}]*padding:\s*9px 34px 9px 12px;/s',
            $css
        );
    }

    public function testProductBarcodeCanBeEnteredWithoutScanner(): void
    {
        $view = file_get_contents(ROOTPATH . 'app/Views/store/inventory.php');

        $this->assertStringContainsString('Type the barcode digits manually', $view);
        $this->assertStringContainsString('No scanner required.', $view);
    }

    public function testNonDebtSalesCanAttachAnEmployeeCustomer(): void
    {
        $js = file_get_contents(ROOTPATH . 'public/assets/js/store-pos.js');
        $view = file_get_contents(ROOTPATH . 'app/Views/store/pos.php');

        $this->assertStringContainsString('customer_user_id: selectedDebtCustomerId || null', $js);
        $this->assertStringContainsString('customer_type: selectedDebtCustomer?.user_type || "walk_in"', $js);
        $this->assertStringContainsString('Saved to this employee&apos;s purchase history', $js);
        $this->assertStringContainsString('Customer (optional)', $view);
    }
}
