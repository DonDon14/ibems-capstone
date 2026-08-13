<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class StoreSettingsUiTest extends CIUnitTestCase
{
    public function testPaymentMethodSaveShowsAndClearsAnExclusiveLoadingState(): void
    {
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-settings.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-settings.css');

        $this->assertStringContainsString('let paymentMethodSavePending = false;', $script);
        $this->assertStringContainsString('function setPaymentMethodSavePending(isPending)', $script);
        $this->assertStringContainsString('Creating payment method...', $script);
        $this->assertStringContainsString('Saving changes...', $script);
        $this->assertStringContainsString('control.disabled = paymentMethodSavePending;', $script);
        $this->assertStringContainsString('if (paymentMethodSavePending) return;', $script);
        $this->assertStringContainsString('setPaymentMethodSavePending(false);', $script);
        $this->assertStringContainsString('.settings-submit-spinner', $styles);
        $this->assertStringContainsString('@keyframes settings-submit-spin', $styles);
    }
}
