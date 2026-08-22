<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

class NotificationConventionTest extends CIUnitTestCase
{
    public function testSharedPortalShellContainsNotificationAndThemeControls(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');
        $this->assertStringContainsString('id="notification-toggle"', $shell);
        $this->assertStringContainsString('id="notification-badge"', $shell);
        $this->assertStringContainsString('id="theme-toggle"', $shell);
        $this->assertStringContainsString("localStorage.getItem('ibems-theme')", $shell);
        $this->assertStringContainsString("assets/js/notifications.js", $shell);
        $this->assertStringContainsString("assets/js/theme.js", $shell);
    }

    public function testEveryPortalUsesSharedShell(): void
    {
        foreach (['admin', 'accounting', 'store_admin', 'store', 'user'] as $layout) {
            $source = (string) file_get_contents(APPPATH . 'Views/layouts/' . $layout . '.php');
            $this->assertStringContainsString('portal_shell.php', $source, $layout . ' must use the shared controls.');
        }
    }

    public function testAuthenticatedNotificationRoutesAreRegistered(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $this->assertStringContainsString("\$routes->get('notifications'", $routes);
        $this->assertStringContainsString("\$routes->post('notifications/(:num)/read'", $routes);
        $this->assertStringContainsString("\$routes->post('notifications/read-all'", $routes);
        $this->assertSame(3, substr_count($routes, "NotificationController::"));
        $this->assertSame(4, substr_count($routes, "['filter' => 'access:account.self']"));
    }

    public function testAuditModelPublishesFromAuthoritativeEvents(): void
    {
        $auditModel = (string) file_get_contents(APPPATH . 'Models/AuditLogModel.php');
        $this->assertStringContainsString("protected \$afterInsert = ['publishNotification'];", $auditModel);
        $this->assertStringContainsString('NotificationService())->publishFromAudit', $auditModel);
    }

    public function testDeliveryTracksRetriesWithoutHardcodedCredentials(): void
    {
        $service = (string) file_get_contents(APPPATH . 'Services/NotificationService.php');
        $example = (string) file_get_contents(ROOTPATH . '.env.gmail.example');
        $this->assertStringContainsString('email_attempts', $service);
        $this->assertStringContainsString('email_next_attempt_at', $service);
        $this->assertStringContainsString("[1, 5, 30, 120, 720]", $service);
        $this->assertStringContainsString('your-16-character-google-app-password', $example);
        $this->assertStringNotContainsString('SMTPPass = \'', $service);
    }

    public function testDarkThemeAndFacebookStyleBadgeAreSharedStyles(): void
    {
        $css = (string) file_get_contents(FCPATH . 'assets/css/modern-ui.css');
        $this->assertStringContainsString('html[data-theme="dark"]', $css);
        $this->assertStringContainsString('.notification-badge', $css);
        $this->assertStringContainsString('background: #e11d48', $css);
    }
}
