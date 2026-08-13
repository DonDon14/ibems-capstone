<?php

use PHPUnit\Framework\TestCase;

final class PosFullscreenLayoutTest extends TestCase
{
    public function testPosUsesDedicatedFullscreenShellWithBackAction(): void
    {
        $storeLayout = (string) file_get_contents(ROOTPATH . 'app/Views/layouts/store.php');
        $portalShell = (string) file_get_contents(ROOTPATH . 'app/Views/components/portal_shell.php');
        $posView = (string) file_get_contents(ROOTPATH . 'app/Views/store/pos.php');
        $posCss = (string) file_get_contents(ROOTPATH . 'public/assets/css/store-pos.css');

        $this->assertStringContainsString("str_ends_with(\$requestPath, 'store/pos')", $storeLayout);
        $this->assertStringContainsString('<body class="<?= esc($bodyClasses) ?>">', $portalShell);
        $this->assertStringContainsString("site_url('store/dashboard')", $posView);
        $this->assertStringContainsString('Back to dashboard', $posView);
        $this->assertStringContainsString('.pos-fullscreen .app-sidebar', $posCss);
        $this->assertStringContainsString('.pos-fullscreen .app-topbar', $posCss);
        $this->assertStringContainsString('.pos-fullscreen .app-footer', $posCss);
        $this->assertStringContainsString('margin-left: 0;', $posCss);
        $this->assertStringContainsString('.pos-fullscreen .product-grid', $posCss);
        $this->assertStringContainsString('.pos-fullscreen .pos-right', $posCss);
        $this->assertStringContainsString('position: sticky;', $posCss);
        $this->assertStringContainsString('overflow-y: auto;', $posCss);
    }
}
