<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class UserPortalPageConventionTest extends CIUnitTestCase
{
    public function testEveryUserPageUsesTheSharedPageHierarchy(): void
    {
        foreach ([
            'dashboard.php',
            'stores.php',
            'history.php',
            'deductions.php',
            'department-authorizations.php',
            'receipt.php',
        ] as $view) {
            $contents = (string) file_get_contents(APPPATH . 'Views/user/' . $view);
            $this->assertStringContainsString("view('components/page_header'", $contents, $view);
            $this->assertStringContainsString('dashboard-shell', $contents, $view);
            $this->assertStringContainsString('data-user-page-style', $contents, $view);
        }

        $receipt = (string) file_get_contents(APPPATH . 'Views/user/receipt.php');
        $this->assertStringNotContainsString('class="user-shell"', $receipt);
        $this->assertStringNotContainsString('class="user-head"', $receipt);
        $this->assertStringContainsString("view('components/data_state'", $receipt);
        $this->assertStringContainsString('data-state--error', $receipt);
    }

    public function testDynamicUserContentUsesSharedLoadingEmptyAndErrorStates(): void
    {
        $dashboardView = (string) file_get_contents(APPPATH . 'Views/user/dashboard.php');
        $dashboardScript = (string) file_get_contents(FCPATH . 'assets/js/user-dashboard.js');
        $deductionsScript = (string) file_get_contents(FCPATH . 'assets/js/user-deductions.js');
        $departmentScript = (string) file_get_contents(FCPATH . 'assets/js/department-debt-pages.js');

        $this->assertStringContainsString('id="u-trend-chart-state"', $dashboardView);
        $this->assertStringContainsString('uSetTrendState("loading"', $dashboardScript);
        $this->assertStringContainsString('uSetTrendState("empty"', $dashboardScript);
        $this->assertStringContainsString('uSetTrendState("error"', $dashboardScript);
        $this->assertStringContainsString('window.addEventListener("ibems:themechange"', $dashboardScript);

        $this->assertStringContainsString('data-state data-state--${safeType}', $deductionsScript);
        $this->assertStringNotContainsString('data-state is-${', $deductionsScript);
        $this->assertStringContainsString('dataState("loading", "Loading department assignments...', $departmentScript);
        $this->assertStringContainsString('dataState("empty", "You do not currently have', $departmentScript);
        $this->assertStringContainsString('dataState("error", error.message', $departmentScript);
    }

    public function testUserPortalStyleChangesInvalidateTheOfflineShellCache(): void
    {
        $worker = (string) file_get_contents(FCPATH . 'user/service-worker.js');
        $this->assertStringContainsString('ibems-user-shell-v7', $worker);
        $this->assertStringContainsString('/assets/css/user-portal.css', $worker);
    }

    public function testProductDirectoryProvidesAccessibleAnimatedVariantDetails(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/user/stores.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/user-stores.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/user-portal.css');
        $controller = (string) file_get_contents(APPPATH . 'Controllers/UserController.php');

        $this->assertStringContainsString('id="us-product-modal"', $view);
        $this->assertStringContainsString('aria-modal="true"', $view);
        $this->assertStringContainsString('class="user-product-modal-body app-inset-modal-scroll"', $view);
        $this->assertStringContainsString('id="us-product-variant-rail"', $view);
        $this->assertStringContainsString('data-product-id=', $script);
        $this->assertStringContainsString('aria-haspopup="dialog"', $script);
        $this->assertStringContainsString('document.body.appendChild(usProductModalPortal)', $script);
        $this->assertStringContainsString('usProductModalPortal?.remove()', $script);
        $this->assertStringContainsString('pointerdown', $script);
        $this->assertStringContainsString('pointermove', $script);
        $this->assertStringContainsString('draggable="false"', $script);
        $this->assertStringContainsString('setPointerCapture', $script);
        $this->assertStringContainsString('closest(".user-product-variant-arrow")', $script);
        $this->assertStringContainsString('usFinishSwipe', $script);
        $this->assertStringContainsString('--user-product-drag-x', $script);
        $this->assertStringContainsString('ArrowLeft', $script);
        $this->assertStringContainsString('ArrowRight', $script);
        $this->assertStringContainsString('event.key === "Escape"', $script);
        $this->assertStringContainsString('event.key === "Tab"', $script);
        $this->assertStringContainsString('scroll-snap-type: x mandatory;', $styles);
        $this->assertStringContainsString('.user-product-detail-media.is-dragging', $styles);
        $this->assertStringContainsString('.user-product-detail-image.is-snap-back', $styles);
        $this->assertStringContainsString('background: rgba(15, 23, 42, 0.94);', $styles);
        $this->assertStringContainsString('height: 100dvh;', $styles);
        $this->assertStringContainsString('overscroll-behavior: contain;', $styles);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1.08fr) minmax(300px, 0.92fr);', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        $this->assertStringContainsString("'variants' => array_map(\$mapVariant, \$variantRows)", $controller);
    }
}
