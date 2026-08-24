<?php

use PHPUnit\Framework\TestCase;

final class AdminDetailModalLayoutTest extends TestCase
{
    public function testDebtAndProductDetailsUseTheSharedInsetModalLayout(): void
    {
        $debtView = (string) file_get_contents(APPPATH . 'Views/admin/accounting-debts.php');
        $productView = (string) file_get_contents(APPPATH . 'Views/admin/products.php');
        $productScript = (string) file_get_contents(FCPATH . 'assets/js/admin-products.js');
        $overviewStyles = (string) file_get_contents(FCPATH . 'assets/css/admin-overview.css');
        $sharedStyles = (string) file_get_contents(FCPATH . 'assets/css/app.css');
        $themeStyles = (string) file_get_contents(FCPATH . 'assets/css/modern-ui.css');

        $this->assertStringContainsString('app-inset-modal-card ad-account-modal-card', $debtView);
        $this->assertStringContainsString('app-inset-modal-card ap-view-card', $productView);
        $this->assertStringContainsString('app-inset-modal-scroll ap-view-modal-scroll', $productView);
        $this->assertStringNotContainsString('w-[min(760px,95vw)] overflow-y-auto', $productView);

        $this->assertStringContainsString('#ad-account-modal-content', $overviewStyles);
        $this->assertMatchesRegularExpression('/#ad-account-modal-content\s*\{[^}]*gap:\s*14px/s', $overviewStyles);
        $this->assertStringContainsString('body.ibems-modern .admin-modal .app-inset-modal-card', $themeStyles);
        $this->assertStringContainsString('padding: 0;', $themeStyles);
        $this->assertStringContainsString('body:is(.app-modal-open, .user-product-modal-open)', $sharedStyles);
        $this->assertStringContainsString('body:is(.app-modal-open, .user-product-modal-open) .app-container', $sharedStyles);
        $this->assertStringContainsString('document.body.classList.add("app-modal-open")', $productScript);
        $this->assertStringContainsString('document.body.classList.remove("app-modal-open")', $productScript);
    }
}
