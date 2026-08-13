<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PosConfirmModalLayoutTest extends CIUnitTestCase
{
    public function testTransactionConfirmationUsesInsetBodyScroller(): void
    {
        $css = (string) file_get_contents(FCPATH . 'assets/css/store-pos.css');

        $this->assertStringContainsString('#confirm-transaction-modal .confirm-card', $css);
        $this->assertMatchesRegularExpression(
            '/#confirm-transaction-modal \.confirm-card\s*\{[^}]*overflow:\s*hidden;[^}]*display:\s*flex;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/#confirm-transaction-content\s*\{[^}]*margin-inline:\s*6px;[^}]*overflow-y:\s*auto;[^}]*scrollbar-gutter:\s*stable;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/#confirm-transaction-modal \.confirm-actions\s*\{[^}]*flex:\s*0 0 auto;[^}]*border-top:/s',
            $css
        );
    }
}
