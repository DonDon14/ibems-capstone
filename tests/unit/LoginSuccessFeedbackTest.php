<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LoginSuccessFeedbackTest extends TestCase
{
    public function testSuccessfulLoginUsesPersonalizedAccessibleFeedback(): void
    {
        $view = file_get_contents(APPPATH . 'Views/auth/login.php');
        $script = file_get_contents(ROOTPATH . 'public/assets/js/auth-login.js');
        $styles = file_get_contents(ROOTPATH . 'public/assets/css/auth-login.css');
        $controller = file_get_contents(APPPATH . 'Controllers/AuthController.php');

        $this->assertIsString($view);
        $this->assertIsString($script);
        $this->assertIsString($styles);
        $this->assertIsString($controller);
        $this->assertStringContainsString('id="status" role="status" aria-live="polite"', $view);
        $this->assertLessThan(strpos($view, 'class="demo-creds"'), strpos($view, 'id="status"'));
        $this->assertStringContainsString('Welcome back, ${firstName}!', $script);
        $this->assertStringContainsString('Opening your ${roleLabel(role)} securely.', $script);
        $this->assertStringContainsString('Signed in', $script);
        $this->assertStringContainsString('#status.ok', $styles);
        $this->assertStringContainsString('.auth-status-icon', $styles);
        $this->assertStringContainsString("'message' => 'Sign-in successful.'", $controller);
    }
}
