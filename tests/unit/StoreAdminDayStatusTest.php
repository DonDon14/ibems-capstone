<?php

namespace Tests\Unit;

use App\Controllers\StoreAdminController;
use App\Services\StoreDayReviewPolicy;
use CodeIgniter\Test\CIUnitTestCase;

final class StoreAdminDayStatusTest extends CIUnitTestCase
{
    public function testStoreAdminPortalDoesNotInheritSystemAdminAuthority(): void
    {
        $controller = new StoreAdminController();

        $this->assertNotInstanceOf(\App\Controllers\AdminController::class, $controller);
        $this->assertFalse(method_exists($controller, 'createUser'));
        $this->assertFalse(method_exists($controller, 'toggleStoreStatus'));
        $this->assertFalse(method_exists($controller, 'auditData'));
        $source = (string) file_get_contents(APPPATH . 'Controllers/StoreAdminController.php');
        $this->assertStringNotContainsString('new AdminController', $source);
        $this->assertStringContainsString('StoreOversightService', $source);

        $serviceSource = (string) file_get_contents(APPPATH . 'Services/StoreOversightService.php');
        $this->assertStringNotContainsString('AdminController', $serviceSource);
        $this->assertStringContainsString('StoreDayReviewPolicy', $serviceSource);
    }

    public function testItDistinguishesTodaysOpenDayFromAStaleOpenDay(): void
    {
        $controller = new class extends StoreAdminController {
            public function statusFor(?array $session, string $todayDate): string
            {
                return $this->dayStatusForDate($session, $todayDate);
            }
        };

        $this->assertSame('open', $controller->statusFor([
            'business_date' => '2026-08-11',
            'status' => 'open',
        ], '2026-08-11'));
        $this->assertSame('stale_open', $controller->statusFor([
            'business_date' => '2026-08-10',
            'status' => 'open',
        ], '2026-08-11'));
    }

    public function testAPreviousClosedDayMeansTodayHasNotStarted(): void
    {
        $controller = new class extends StoreAdminController {
            public function statusFor(?array $session, string $todayDate): string
            {
                return $this->dayStatusForDate($session, $todayDate);
            }
        };

        $this->assertSame('not_started', $controller->statusFor([
            'business_date' => '2026-08-10',
            'status' => 'closed',
        ], '2026-08-11'));
        $this->assertSame('not_started', $controller->statusFor(null, '2026-08-11'));
    }

    public function testClosingOperatorCannotReviewTheirOwnStoreDay(): void
    {
        $policy = new StoreDayReviewPolicy();

        $this->assertFalse($policy->isIndependentReviewer(12, ['closed_by' => 12]));
        $this->assertTrue($policy->isIndependentReviewer(13, ['closed_by' => 12]));
        $this->assertFalse($policy->isIndependentReviewer(0, ['closed_by' => 12]));
    }

    public function testHistoricalStoreDayReviewsRemainDiscoverable(): void
    {
        $service = (string) file_get_contents(APPPATH . 'Services/StoreOversightService.php');
        $adminController = (string) file_get_contents(APPPATH . 'Controllers/AdminController.php');
        $adminView = (string) file_get_contents(APPPATH . 'Views/admin/store-details.php');
        $supervisorView = (string) file_get_contents(APPPATH . 'Views/store-admin/store-details.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-store-details.js');

        $this->assertStringContainsString("'day_sessions' => array_map", $service);
        $this->assertStringContainsString("->limit(60)", $service);
        $this->assertStringContainsString("whereIn('sds.review_status', ['pending', 'needs_investigation'])", $adminController);
        $this->assertStringContainsString('unresolved_store_day_reviews', $adminController);
        $this->assertSame(1, substr_count($adminView, 'id="sd-session-history-body"'));
        $this->assertSame(1, substr_count($supervisorView, 'id="sd-session-history-body"'));
        $this->assertStringContainsString('function sdRenderSessionHistory()', $script);
        $this->assertStringContainsString('data-variance-action', $script);
    }

    public function testStaleDayResolutionIsIndependentAuditedAndUsesSharedExpectedBalances(): void
    {
        $oversight = (string) file_get_contents(APPPATH . 'Services/StoreOversightService.php');
        $storeController = (string) file_get_contents(APPPATH . 'Controllers/StoreController.php');
        $calculator = (string) file_get_contents(APPPATH . 'Services/StoreDayExpectedService.php');
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-store-details.js');

        $this->assertStringContainsString('StoreDayExpectedService', $storeController);
        $this->assertStringContainsString('StoreDayExpectedService', $oversight);
        $this->assertStringContainsString("where('business_date', \$businessDate)", $calculator);
        $this->assertStringContainsString("\$actorId === (int) (\$session['opened_by']", $oversight);
        $this->assertStringContainsString('RESOLVE_STALE_STORE_DAY_SESSION', $oversight);
        $this->assertStringContainsString('StoreDayVarianceCaseService', $oversight);
        $this->assertStringContainsString("admin/store-day-sessions/(:num)/resolve-stale", $routes);
        $this->assertStringContainsString("store-admin/store-day-sessions/(:num)/resolve-stale", $routes);
        $this->assertStringContainsString('stale-day-resolution-form', $script);
    }

    public function testVarianceDispositionRequiresAVisiblePreviewAndEvidenceAwareCorrectionCopy(): void
    {
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-store-details.js');
        $dialogs = (string) file_get_contents(FCPATH . 'assets/js/app-dialog.js');

        $this->assertStringContainsString('function sdReviewPreview(', $script);
        $this->assertStringContainsString('Supporting evidence required', $script);
        $this->assertStringContainsString('This does not change the original counts or reopen the store day.', $script);
        $this->assertStringContainsString('Accept correction evidence', $script);
        $this->assertStringContainsString('options.multiline ? textarea : input', $dialogs);
    }

    public function testCloseDayPreviewRefreshesExpectedBalancesBeforeShowingCounts(): void
    {
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-pos.js');

        $this->assertStringContainsString('async function openStoreDayCloseModal()', $script);
        $this->assertStringContainsString('await loadOpeningBalanceStatus();', $script);
        $this->assertStringContainsString('Unable to load current close-day totals.', $script);
    }

    public function testActiveStoreConfigurationRequiresOfficerAndSupervisorCoverage(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Controllers/AdminController.php');

        $this->assertSame(2, substr_count($source, 'An active store requires a primary officer and at least one supervisor.'));
        $this->assertStringContainsString('$willBeActive', $source);
    }

    public function testStoreAdminDashboardUsesSharedPresentationConventions(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store-admin/dashboard.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-admin-dashboard.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/admin-overview.css');

        $this->assertStringContainsString("view('components/page_header'", $view);
        $this->assertStringContainsString("view('components/stat_card'", $view);
        $this->assertStringContainsString("view('components/data_state'", $view);
        $this->assertStringContainsString('store-admin-panel', $view);
        $this->assertSame(2, substr_count($view, 'class="data-panel store-admin-panel"'));
        $this->assertSame(2, substr_count($view, 'class="data-panel-head store-admin-section-head"'));
        $this->assertSame(2, substr_count($view, 'class="data-panel-body store-admin-panel-body"'));
        $this->assertStringContainsString('function sadDataState(', $script);
        $this->assertStringContainsString('No pending variance reviews', $script);
        $this->assertStringContainsString('.store-admin-store-item:focus-visible', $styles);
    }

    public function testVarianceReviewUsesDurableCasesAndShowsIndependentReviewers(): void
    {
        $service = (string) file_get_contents(APPPATH . 'Services/StoreDayVarianceCaseService.php');
        $oversight = (string) file_get_contents(APPPATH . 'Services/StoreOversightService.php');
        $migration = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-08-12-000001_CreateStoreDayVarianceCases.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-store-details.js');

        $this->assertStringContainsString('store_day_variance_cases', $migration);
        $this->assertStringContainsString('store_day_variance_case_events', $migration);
        $this->assertStringContainsString("'evidence_json'", $service);
        $this->assertStringContainsString('recordReview(', $oversight);
        $this->assertStringContainsString('Eligible reviewers:', $oversight);
        $this->assertStringContainsString('function sdCaseSummary(session)', $script);
        $this->assertStringContainsString('Eligible independent reviewers:', $script);
    }

    public function testVarianceEvidenceIsPrivateAndHandoffsStayStoreScoped(): void
    {
        $service = (string) file_get_contents(APPPATH . 'Services/StoreOversightService.php');
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-store-details.js');

        $this->assertStringContainsString("WRITEPATH . 'private/variance-evidence/'", $service);
        $this->assertStringContainsString("['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png']", $service);
        $this->assertStringContainsString("5 * 1024 * 1024", $service);
        $this->assertStringContainsString("hash_equals", $service);
        $this->assertStringContainsString('accessibleVarianceCase', $service);
        $this->assertStringContainsString('variance-cases/(:num)/attachments', $routes);
        $this->assertStringContainsString('variance-cases/(:num)/handoff', $routes);
        $this->assertStringContainsString('variance-evidence-form', $script);
        $this->assertStringContainsString('variance-handoff-form', $script);
    }

    public function testFinalVarianceDispositionRequiresAcknowledgedOwnershipAndEvidence(): void
    {
        $service = (string) file_get_contents(APPPATH . 'Services/StoreOversightService.php');
        $admin = (string) file_get_contents(APPPATH . 'Controllers/AdminController.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-store-details.js');

        $this->assertStringContainsString('Only the assigned case owner can finalize this variance.', $service);
        $this->assertStringContainsString('Acknowledge the latest reviewer handoff before final disposition.', $service);
        $this->assertStringContainsString('Attach at least one supporting evidence file before final disposition.', $service);
        $this->assertStringContainsString('acknowledgeVarianceCase', $service);
        $this->assertStringContainsString("'retention_until'", $service);
        $this->assertStringContainsString("'due_at'", $service);
        $this->assertStringContainsString('Acknowledgment overdue', $admin);
        $this->assertStringContainsString('data-case-acknowledge', $script);
    }
}
