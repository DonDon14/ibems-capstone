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

    public function testActiveStoreConfigurationRequiresOfficerAndSupervisorCoverage(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Controllers/AdminController.php');

        $this->assertSame(2, substr_count($source, 'An active store requires a primary officer and at least one supervisor.'));
        $this->assertStringContainsString('$willBeActive', $source);
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
}
