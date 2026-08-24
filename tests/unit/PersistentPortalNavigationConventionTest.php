<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class PersistentPortalNavigationConventionTest extends CIUnitTestCase
{
    public function testEveryAuthenticatedPortalUsesOnePersistentShellImplementation(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');

        $this->assertStringContainsString('data-portal-navigation-shell', $shell);
        $this->assertStringContainsString('data-portal-navigation-key', $shell);
        $this->assertStringContainsString('data-portal-navigation-link', $shell);
        $this->assertStringContainsString('data-portal-page-content', $shell);
        $this->assertStringContainsString('id="portal-page-scripts"', $shell);
        $this->assertStringContainsString('data-portal-page-style', $shell);
        $this->assertStringContainsString('data-portal-page-script', $shell);

        foreach (['admin.php', 'accounting.php', 'store.php', 'store_admin.php', 'user.php'] as $layout) {
            $contents = (string) file_get_contents(APPPATH . 'Views/layouts/' . $layout);
            $this->assertStringContainsString("Views/components/portal_shell.php", $contents, $layout);
            $this->assertStringNotContainsString('user-navigation.js', $contents, $layout);
        }
    }

    public function testNavigationHelpersStayOutsideTheDesktopMainGrid(): void
    {
        session()->set([
            'logged_in' => true,
            'user_id' => 1,
            'name' => 'Navigation Test',
            'role' => 'ADMIN',
            'available_roles' => ['ADMIN'],
        ]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(view('admin/dashboard'));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query(
            '//*[@data-portal-navigation-shell]/*[contains(concat(" ", normalize-space(@class), " "), " app-main ")]//*[@data-portal-navigation-status]'
        )->length, 'The navigation live region must not become an implicit desktop grid row.');
        $this->assertSame(0, $xpath->query(
            '//*[@data-portal-navigation-shell]/*[contains(concat(" ", normalize-space(@class), " "), " app-main ")]//*[contains(concat(" ", normalize-space(@class), " "), " portal-navigation-progress ")]'
        )->length, 'The navigation progress bar must not become an implicit desktop grid row.');
        $this->assertSame(1, $xpath->query(
            '//*[@data-portal-navigation-shell]/*[contains(concat(" ", normalize-space(@class), " "), " app-main ")]/*[@data-portal-page-content]'
        )->length);
        $this->assertSame(1, $xpath->query('//*[@data-portal-navigation-shell]/*[@data-portal-navigation-status]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-portal-navigation-shell]/*[contains(concat(" ", normalize-space(@class), " "), " portal-navigation-progress ")]')->length);
    }

    public function testDesktopSidebarCollapseUsesCoordinatedMotionWithoutSnappingLabels(): void
    {
        $baseStyles = str_replace("\r\n", "\n", (string) file_get_contents(FCPATH . 'assets/css/app.css'));
        $modernStyles = str_replace("\r\n", "\n", (string) file_get_contents(FCPATH . 'assets/css/modern-ui.css'));
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');

        $this->assertStringContainsString('max-width 0.24s cubic-bezier(0.4, 0, 0.2, 1)', $baseStyles);
        $this->assertStringContainsString('opacity 0.1s ease 0.12s', $baseStyles);
        $this->assertStringContainsString('margin-left 0.24s cubic-bezier(0.4, 0, 0.2, 1)', $baseStyles);
        $this->assertStringContainsString("max-width: 0;\n    opacity: 0;", $baseStyles);
        $this->assertStringContainsString('transition-delay: 0s;', $baseStyles);
        $this->assertStringContainsString("width: 23px;\n    height: 17px;", $baseStyles);
        $this->assertStringContainsString("top: -1.7px;\n    bottom: -1.7px;\n    left: 7px;", $baseStyles);
        $this->assertStringContainsString('.sidebar-toggle-glyph::after', $baseStyles);
        $this->assertStringContainsString('.app-shell.sidebar-collapsed .sidebar-toggle-glyph::after', $baseStyles);
        $this->assertStringContainsString('rotate(45deg)', $baseStyles);
        $this->assertStringContainsString('rotate(225deg)', $baseStyles);
        $this->assertStringNotContainsString(".app-shell.sidebar-collapsed .sidebar-logout button span {\n    display: none;", $baseStyles);
        $this->assertStringContainsString('width .24s cubic-bezier(.4, 0, .2, 1)', $modernStyles);
        $this->assertStringContainsString('margin .24s cubic-bezier(.4, 0, .2, 1)', $modernStyles);
        $this->assertStringContainsString("height: 44px;\n    min-height: 44px;", $modernStyles);
        $this->assertStringContainsString("height: auto;\n        min-width: max-content;\n        min-height: 38px;", $modernStyles);
        $this->assertStringContainsString("justify-content: flex-start;\n    gap: 0;\n    margin-inline: 6px;\n    padding-inline: 12px;", $modernStyles);
        $this->assertStringContainsString("min-height: 52px;\n    justify-content: flex-start;", $modernStyles);
        $this->assertStringContainsString("width: 48px;\n    padding: 3px;", $modernStyles);
        $this->assertStringContainsString("color: #1d4ed8;\n    border-color: #bfdbfe;", $modernStyles);
        $this->assertStringContainsString("color: #9bc4ff;\n    border-color: #315d91;\n    background: #102c52;", $modernStyles);
        $this->assertStringNotContainsString('content: "Workspace";', $modernStyles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $modernStyles);
        $this->assertStringContainsString("assets/css/app.css') ?>?v=20260824e", $shell);
        $this->assertStringContainsString("assets/css/modern-ui.css') ?>?v=20260824m", $shell);
        $this->assertStringContainsString('body:is(.app-modal-open, .user-product-modal-open) .app-container', $baseStyles);
        $this->assertStringContainsString('body.account-menu-open .app-container', $baseStyles);
        $this->assertStringContainsString('.app-modal, .user-product-modal)', $modernStyles);
    }

    public function testOnlyExactCurrentRoleSidebarDestinationsArePartiallyLoaded(): void
    {
        $navigation = (string) file_get_contents(FCPATH . 'assets/js/user-navigation.js');
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        $this->assertStringContainsString('[data-portal-navigation-link][href]', $navigation);
        $this->assertStringContainsString('supportedPaths.has(normalizePath(url.pathname))', $navigation);
        $this->assertStringContainsString('url.origin === window.location.origin', $navigation);
        $this->assertStringContainsString('nextShell.dataset.portalNavigationKey !== navigationKey', $navigation);
        $this->assertStringContainsString('window.location.assign', $navigation);
        $this->assertStringContainsString('content-type', $navigation);
        $this->assertStringContainsString('response.ok', $navigation);
        $this->assertStringContainsString('link.dataset.noPortalNavigation', $navigation);

        foreach (['admin.php', 'accounting.php', 'store.php', 'store_admin.php', 'user.php'] as $layout) {
            $contents = (string) file_get_contents(APPPATH . 'Views/layouts/' . $layout);
            preg_match_all("/'path' => '([^']+)'/", $contents, $matches);
            $this->assertNotEmpty($matches[1], $layout);
            foreach ($matches[1] as $path) {
                $this->assertStringContainsString("\$routes->get('{$path}'", $routes, "{$layout}: {$path}");
            }
        }
    }

    public function testNavigationPreservesHistorySecurityMetadataAndPageLifecycle(): void
    {
        $navigation = (string) file_get_contents(FCPATH . 'assets/js/user-navigation.js');

        foreach (['csrf-token-name', 'csrf-token-value', 'csrf-header-name', 'csrf-cookie-name', 'csp-script-nonce'] as $metadata) {
            $this->assertStringContainsString($metadata, $navigation);
        }
        $this->assertStringContainsString('history.pushState', $navigation);
        $this->assertStringContainsString('window.addEventListener("popstate"', $navigation);
        $this->assertStringContainsString('credentials: "same-origin"', $navigation);
        $this->assertStringContainsString('AbortSignal.any', $navigation);
        $this->assertStringContainsString('runtime.controller.abort()', $navigation);
        $this->assertStringContainsString('runtime.disposeTimers()', $navigation);
        $this->assertStringContainsString('cleanupCallbacks', $navigation);
        $this->assertStringContainsString('removeStaleStyles()', $navigation);
        $this->assertStringContainsString('updateBodyPageClasses(nextDocument)', $navigation);
        $this->assertStringContainsString('script.nonce = nonce', $navigation);
        $this->assertStringContainsString('window.IbemsPortalNavigation = api', $navigation);
        $this->assertStringContainsString('window.IbemsUserNavigation = api', $navigation);
    }

    public function testResponsiveEnhancementsAndCameraPagesParticipateInCleanup(): void
    {
        $layout = (string) file_get_contents(FCPATH . 'assets/js/app-layout.js');
        $controls = (string) file_get_contents(FCPATH . 'assets/js/modern-controls.js');

        $this->assertStringContainsString('ibems:portal-page-loaded', $layout);
        $this->assertStringContainsString('enhanceModalAccessibility(root)', $layout);
        $this->assertStringContainsString('enhanceCompactFilters(root)', $layout);
        $this->assertStringContainsString('ibems:portal-page-unload', $controls);

        foreach (['store-pos.js', 'store-inventory.js', 'store-staff-records.js'] as $script) {
            $contents = (string) file_get_contents(FCPATH . 'assets/js/' . $script);
            $this->assertStringContainsString('IbemsPortalNavigation?.onCleanup', $contents, $script);
        }
    }

    public function testRepresentativeRolePagesRenderScopedPageAssetsInsideTheSharedShell(): void
    {
        $pages = [
            'admin/dashboard' => 'ADMIN',
            'accounting/dashboard' => 'ACCOUNTING_OFFICE',
            'store-admin/dashboard' => 'STORE_SUPERVISOR',
            'user/dashboard' => 'USER',
        ];

        foreach ($pages as $view => $role) {
            session()->set([
                'logged_in' => true,
                'user_id' => 1,
                'name' => 'Navigation Test',
                'role' => $role,
                'available_roles' => [$role],
            ]);

            $html = view($view);
            $this->assertStringContainsString('data-portal-navigation-shell', $html, $view);
            $this->assertStringContainsString('data-portal-page-content', $html, $view);
            $this->assertStringContainsString('<template id="portal-page-scripts">', $html, $view);
            $this->assertStringContainsString('data-portal-page-script', $html, $view);
            $this->assertSame(1, substr_count($html, 'assets/js/user-navigation.js'), $view);
        }
    }
}
