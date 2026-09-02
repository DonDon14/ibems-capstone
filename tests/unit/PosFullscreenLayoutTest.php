<?php

use PHPUnit\Framework\TestCase;

final class PosFullscreenLayoutTest extends TestCase
{
    public function testPosUsesDedicatedFullscreenCashierShellWithoutManagementNavigation(): void
    {
        $storeLayout = (string) file_get_contents(ROOTPATH . 'app/Views/layouts/store.php');
        $portalShell = (string) file_get_contents(ROOTPATH . 'app/Views/components/portal_shell.php');
        $posView = (string) file_get_contents(ROOTPATH . 'app/Views/store/pos.php') . file_get_contents(ROOTPATH . 'app/Views/components/store_pos_modals.php');
        $posCss = (string) file_get_contents(ROOTPATH . 'public/assets/css/store-pos.css');
        $posScript = (string) file_get_contents(ROOTPATH . 'public/assets/js/store-pos.part4.js');

        $this->assertStringContainsString("str_ends_with(\$requestPath, 'store/pos')", $storeLayout);
        $this->assertStringContainsString('<body class="<?= esc($bodyClasses) ?>" data-portal-page-classes="<?= esc($bodyClass) ?>">', $portalShell);
        $this->assertStringNotContainsString("site_url('store/dashboard')", $posView);
        $this->assertStringNotContainsString('Back to dashboard', $posView);
        $this->assertStringContainsString("['path' => 'store/pos', 'label' => 'POS'", $storeLayout);
        $this->assertStringContainsString('data-account-menu-toggle', $posView);
        $this->assertStringContainsString('data-current-user-avatar', $posView);
        $this->assertStringContainsString('Store Cashier', $posView);
        $this->assertStringContainsString('data-confirm-logout', $portalShell);
        $this->assertStringContainsString("site_url('auth/logout')", $portalShell);
        $this->assertStringContainsString('csrf_field()', $portalShell);
        $this->assertStringContainsString('.pos-account-trigger', $posCss);
        $this->assertStringContainsString('.pos-fullscreen .app-sidebar', $posCss);
        $this->assertStringContainsString('.pos-fullscreen .app-topbar', $posCss);
        $this->assertStringContainsString('.pos-fullscreen .app-footer', $posCss);
        $this->assertStringContainsString('margin-left: 0;', $posCss);
        $this->assertStringContainsString('.pos-fullscreen .product-grid', $posCss);
        $this->assertStringContainsString('.pos-fullscreen .pos-right', $posCss);
        $this->assertStringContainsString('position: sticky;', $posCss);
        $this->assertStringContainsString('overflow-y: auto;', $posCss);
        $this->assertStringContainsString('is-pos-locked', $posScript);
        $this->assertStringContainsString('${openingBalanceReady ? actionHtml : ""}', $posScript);
        $this->assertStringNotContainsString('POS locked</span>', $posScript);
    }
}
