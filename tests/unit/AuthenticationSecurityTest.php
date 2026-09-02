<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/** @internal */
final class AuthenticationSecurityTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, password_hash TEXT, role TEXT, is_active INTEGER NOT NULL DEFAULT 1)');
        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'user_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, role TEXT NOT NULL)');
        $this->withRoutes([
            ['POST', 'test/login', 'AuthController::login'],
        ]);
    }

    public function testSqlInjectionShapedIdentityIsRejectedBeforeLookup(): void
    {
        $result = $this->postLogin([
            'email' => "admin@example.test' OR 1=1 --",
            'password' => 'irrelevant',
        ]);

        $result->assertStatus(400);
        $body = json_decode((string) $result->getJSON(), true);
        $this->assertSame('Enter a valid email and password', $body['message'] ?? null);
    }

    public function testRepeatedLoginAttemptsAreRateLimitedWithoutIdentityDisclosure(): void
    {
        $email = 'missing-' . bin2hex(random_bytes(8)) . '@example.test';
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $result = $this->postLogin([
                'email' => $email,
                'password' => 'wrong-password',
            ]);
            $result->assertStatus(401);
            $body = json_decode((string) $result->getJSON(), true);
            $this->assertSame('Invalid email or password', $body['message'] ?? null);
        }

        $limited = $this->postLogin([
            'email' => $email,
            'password' => 'wrong-password',
        ]);
        $limited->assertStatus(429);
        $limitedBody = json_decode((string) $limited->getJSON(), true);
        $this->assertSame('Too many sign-in attempts. Please wait before trying again.', $limitedBody['message'] ?? null);
    }

    public function testLogoutIsPostOnlyAndCsrfProtected(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $filters = (string) file_get_contents(APPPATH . 'Config/Filters.php');
        $appConfig = (string) file_get_contents(APPPATH . 'Config/App.php');
        $cspConfig = (string) file_get_contents(APPPATH . 'Config/ContentSecurityPolicy.php');

        $this->assertStringContainsString("\$routes->post('auth/logout'", $routes);
        $this->assertStringNotContainsString("\$routes->get('auth/logout'", $routes);
        $this->assertStringNotContainsString("'csrf' => ['except'", $filters);
        $this->assertStringContainsString("'secureheaders'", $filters);
        $this->assertStringContainsString('public bool $CSPEnabled = true;', $appConfig);
        $this->assertStringContainsString("public \$objectSrc = 'none';", $cspConfig);
        $this->assertStringContainsString("public \$frameAncestors = 'self';", $cspConfig);
        $this->assertStringContainsString("'https://fonts.googleapis.com'", $cspConfig);
        $this->assertStringContainsString("'https://fonts.gstatic.com'", $cspConfig);
        $this->assertStringContainsString("'https://cdn.jsdelivr.net'", $cspConfig);
    }

    private function postLogin(array $payload)
    {
        $security = service('security');
        $payload[$security->getTokenName()] = $security->getHash();
        return $this->withBodyFormat('json')->post('test/login', $payload);
    }
}
