<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class StoreLifecycleConventionTest extends CIUnitTestCase
{
    public function testLifecycleGuardCoversOperationalBlockers(): void
    {
        $service = (string) file_get_contents(APPPATH . 'Services/StoreLifecycleService.php');

        $this->assertStringContainsString("where('status', 'open')", $service);
        $this->assertStringContainsString("where('status !=', 'resolved')", $service);
        $this->assertStringContainsString('A deactivation reason is required.', $service);
        $this->assertStringContainsString('Variance-case verification is unavailable; store deactivation is blocked.', $service);
        $this->assertStringContainsString('validateReactivation', $service);
        $this->assertStringContainsString("where('u.is_active', true)", $service);
    }

    public function testSupervisorChangesPreserveEffectiveDatedHistory(): void
    {
        $model = (string) file_get_contents(APPPATH . 'Models/StoreSupervisorModel.php');
        $migration = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-08-13-180000_AddStoreLifecycleAndSupervisorHistory.php');

        $this->assertStringContainsString('store_supervisor_assignment_history', $model);
        $this->assertStringContainsString("'ended_at'", $model);
        $this->assertStringContainsString("'assigned_by'", $migration);
        $this->assertStringContainsString("'ended_by'", $migration);
    }

    public function testStatusEndpointsUseTheLifecycleService(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/AdminController.php');

        $this->assertGreaterThanOrEqual(2, substr_count($controller, 'validateDeactivation'));
        $this->assertStringContainsString('lifecyclePayload', $controller);
    }

    public function testPostgreSqlLifecycleAndVarianceMigrationsAreAdditiveAndVerifiable(): void
    {
        $lifecycle = (string) file_get_contents(ROOTPATH . 'database/postgresql/007_store_lifecycle.sql');
        $variance = (string) file_get_contents(ROOTPATH . 'database/postgresql/008_variance_governance.sql');
        $verification = (string) file_get_contents(ROOTPATH . 'database/postgresql/008_verify_variance_governance.sql');

        $this->assertStringContainsString('add column if not exists deactivated_at', $lifecycle);
        $this->assertStringContainsString('store_supervisor_assignment_history', $lifecycle);
        $this->assertStringContainsString('create table if not exists public.store_day_variance_cases', $variance);
        $this->assertStringContainsString('legacy_snapshot', $variance);
        $this->assertStringContainsString('begin read only', $verification);
        $this->assertStringNotContainsString('update public.stores', strtolower($lifecycle . $variance));
        $this->assertStringNotContainsString('delete from public.stores', strtolower($lifecycle . $variance));
    }
}
