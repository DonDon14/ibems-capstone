<?php

use PHPUnit\Framework\TestCase;

final class PasswordVisibilityTest extends TestCase
{
    public function testAllPasswordAndPinInputsUseTheSharedVisibilityEnhancer(): void
    {
        $views = [
            ROOTPATH . 'app/Views/auth/login.php',
            ROOTPATH . 'app/Views/components/portal_shell.php',
            ROOTPATH . 'app/Views/admin/user-view.php',
            ROOTPATH . 'app/Views/store/pos.php',
            ROOTPATH . 'app/Views/components/store_pos_modals.php',
            ROOTPATH . 'app/Views/department/authorizations.php',
        ];
        $passwordFieldCount = 0;
        foreach ($views as $view) {
            $passwordFieldCount += substr_count((string) file_get_contents($view), 'type="password"');
        }

        $shell = (string) file_get_contents(ROOTPATH . 'app/Views/components/portal_shell.php');
        $login = (string) file_get_contents(ROOTPATH . 'app/Views/auth/login.php');
        $script = (string) file_get_contents(ROOTPATH . 'public/assets/js/password-visibility.js');
        $styles = (string) file_get_contents(ROOTPATH . 'public/assets/css/password-visibility.css');

        $this->assertSame(12, $passwordFieldCount);
        $this->assertStringContainsString('password-visibility.js', $shell);
        $this->assertStringContainsString('password-visibility.js', $login);
        $this->assertStringContainsString('input[type="password"]', $script);
        $this->assertStringContainsString('aria-pressed', $script);
        $this->assertStringContainsString('bi-eye-slash', $script);
        $this->assertStringContainsString('MutationObserver', $script);
        $this->assertStringContainsString('.password-field-shell > .password-visibility-toggle', $styles);
        $this->assertStringContainsString('min-height: 0', $styles);
        $this->assertStringContainsString('transform: translateY(-50%)', $styles);
    }
}
