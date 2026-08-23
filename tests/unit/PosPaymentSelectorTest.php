<?php

use PHPUnit\Framework\TestCase;

final class PosPaymentSelectorTest extends TestCase
{
    public function testPaymentSelectorSeparatesTenderAndAccountTransactions(): void
    {
        $view = (string) file_get_contents(ROOTPATH . 'app/Views/store/pos.php');
        $js = (string) file_get_contents(ROOTPATH . 'public/assets/js/store-pos.js');
        $css = (string) file_get_contents(ROOTPATH . 'public/assets/css/store-pos.css');

        $this->assertStringContainsString('class="payment-selector"', $view);
        $this->assertStringContainsString('function isAccountPaymentMethod', $js);
        $this->assertStringContainsString('Charge to employee or department account', $js);
        $this->assertStringContainsString('requiresCheckoutCustomer(paymentMethod)', $js);
        $this->assertStringContainsString('secondary-btn payment-method-option', $js);
        $this->assertStringContainsString('aria-pressed=', $js);
        $this->assertStringContainsString('.payment-method-grid', $css);
        $this->assertStringContainsString('.payment-method-option.secondary-btn.is-active', $css);
        $this->assertStringContainsString('id="split-payment-toggle"', $view);
        $this->assertStringContainsString('function getSplitPaymentLines', $js);
        $this->assertStringContainsString('Balance Split Payment', $js);
        $this->assertStringContainsString('splitTenderSupported', $js);
        $this->assertStringContainsString('paymentMethodsCache', $js);
        $this->assertStringContainsString('splitIncludesDebt()', $js);
        $this->assertStringContainsString('method.requires_destination_account', $js);
        $this->assertStringNotContainsString('if (isAccountPaymentMethod(method)) return;', $js);
        $this->assertStringContainsString('remaining before a 15-minute lockout', $js);
        $this->assertStringContainsString('debtPinLockoutTimer', $js);
        $this->assertStringContainsString('const debtPinLockouts = new Map()', $js);
        $this->assertStringContainsString('debtPinLockouts.get(lockoutKey)', $js);
        $this->assertStringContainsString('Try again in ${minutes}:', $js);
        $this->assertStringContainsString('Other customers and payment methods remain available.', $js);
        $this->assertStringContainsString('requestError.data = data', $js);
        $this->assertStringContainsString('id="customer-qr-modal"', $view);
        $this->assertStringContainsString('data-show-payment-qr=', $js);
        $this->assertStringContainsString('function openCustomerQrModal', $js);
        $this->assertStringContainsString('function closeCustomerQrModal', $js);
        $this->assertStringContainsString('customerQrTrigger?.focus()', $js);
        $this->assertStringContainsString('.customer-qr-content>img', $css);
        $this->assertStringContainsString('id="debt-credit-meter"', $view);
        $this->assertStringContainsString('function getCheckoutDebtAmount', $js);
        $this->assertStringContainsString('function updateDebtCreditMeter', $js);
        $this->assertStringContainsString('Debt Exceeds Credit', $js);
        $this->assertStringContainsString('Debt allocation exceeds available credit by', $js);
        $this->assertStringContainsString('.debt-credit-meter.is-over', $css);
        $this->assertStringContainsString('id="opening-ecash-input" type="hidden"', $view);
        $this->assertStringContainsString('id="closing-ecash-input" type="hidden"', $view);
        $this->assertStringContainsString('accountOpenings.reduce', $js);
        $this->assertStringContainsString('accountCounts.reduce', $js);
        $this->assertStringContainsString('Count cash and every receiving account independently.', $view);
    }

    public function testSplitTenderPersistenceAndLegacyFallbackAreDefined(): void
    {
        $migration = (string) file_get_contents(ROOTPATH . 'app/Database/Migrations/2026-08-13-130000_CreateTransactionPayments.php');
        $service = (string) file_get_contents(ROOTPATH . 'app/Services/TransactionService.php');
        $controller = (string) file_get_contents(ROOTPATH . 'app/Controllers/StoreController.php');
        $receipt = (string) file_get_contents(ROOTPATH . 'public/assets/js/receipt-standard.js');

        $this->assertStringContainsString('transaction_payments', $migration);
        $this->assertStringContainsString('normalizePaymentLines', $service);
        $this->assertStringContainsString("if (\$debtLine !== null)", $service);
        $this->assertStringContainsString("addDebt((int) \$customerUserId, \$debtAmount)", $service);
        $this->assertStringContainsString("count(\$paymentLines) > 1 ? 'split'", $service);
        $this->assertStringContainsString("in_array('transaction_payments'", $controller);
        $this->assertStringContainsString('COALESCE(tp.amount, t.amount)', $controller);
        $this->assertStringContainsString('SPLIT PAYMENT', $receipt);
        $this->assertStringContainsString('payments.length > 1 || payments.some', $receipt);
        $this->assertStringContainsString('destination_account_name', $receipt);
    }
}
