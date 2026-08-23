<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class IbemsBrandIdentityTest extends TestCase
{
    public function testApplicationUsesDedicatedIbemsLogoInsteadOfUniversitySeal(): void
    {
        $logoPath = FCPATH . 'assets/images/ibems-logo.png';
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');
        $login = (string) file_get_contents(APPPATH . 'Views/auth/login.php');
        $roleSelector = (string) file_get_contents(APPPATH . 'Views/auth/select_role.php');

        $this->assertFileExists($logoPath);
        $this->assertSame('image/png', mime_content_type($logoPath));
        $this->assertStringContainsString('assets/images/ibems-logo.png', $shell);
        $this->assertStringContainsString('alt="IBEMS logo"', $shell);
        $this->assertStringContainsString('assets/images/ibems-logo.png', $login);
        $this->assertStringContainsString('assets/images/ibems-logo.png', $roleSelector);
        $this->assertStringNotContainsString('ustp_claveria_logo.jpg', $shell);
        $this->assertStringNotContainsString('ustp_claveria_logo.jpg', $login);
        $this->assertStringNotContainsString('ustp_claveria_logo.jpg', $roleSelector);
    }

    public function testSharedPortalUsesOptimizedIbemsBackground(): void
    {
        $backgroundPath = FCPATH . 'assets/images/ibems-app-background.svg';
        $modernUi = (string) file_get_contents(FCPATH . 'assets/css/modern-ui.css');

        $this->assertFileExists($backgroundPath);
        $this->assertSame('image/svg+xml', mime_content_type($backgroundPath));
        $this->assertLessThan(10000, filesize($backgroundPath));
        $this->assertStringContainsString("url('../images/ibems-app-background.svg?v=20260823a')", $modernUi);
        $this->assertStringContainsString('background-attachment: scroll, fixed', $modernUi);
    }
}
