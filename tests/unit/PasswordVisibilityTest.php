<?php

use PHPUnit\Framework\TestCase;

final class PasswordVisibilityTest extends TestCase
{
    public function testAllPasswordAndPinInputsUseTheSharedVisibilityEnhancer(): void
    {
        $views = [
            ROOTPATH . 'app/Views/auth/login.php',
            ROOTPATH . 'app/Views/user/dashboard.php',
            ROOTPATH . 'app/Views/admin/user-view.php',
            ROOTPATH . 'app/Views/store/pos.php',
        ];
        $passwordFieldCount = 0;
        foreach ($views as $view) {
            $passwordFieldCount += substr_count((string) file_get_contents($view), 'type="password"');
        }

        $shell = (string) file_get_contents(ROOTPATH . 'app/Views/components/portal_shell.php');
        $login = (string) file_get_contents(ROOTPATH . 'app/Views/auth/login.php');
        $script = (string) file_get_contents(ROOTPATH . 'public/assets/js/password-visibility.js');

        $this->assertSame(6, $passwordFieldCount);
        $this->assertStringContainsString('password-visibility.js', $shell);
        $this->assertStringContainsString('password-visibility.js', $login);
        $this->assertStringContainsString('input[type="password"]', $script);
        $this->assertStringContainsString('aria-pressed', $script);
        $this->assertStringContainsString('bi-eye-slash', $script);
        $this->assertStringContainsString('MutationObserver', $script);
    }
}
