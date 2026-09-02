<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class AdminUserCsvImportConventionTest extends CIUnitTestCase
{
    public function testImportUsesPortableBooleanAndAtomicFailureHandling(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Services/AdminUserService.php');

        $this->assertStringContainsString("$" . "isActive = isset($" . "row['is_active']) ? (int) $" . "row['is_active'] === 1 : true;", $source);
        $this->assertStringContainsString('transException(true)->transStart()', $source);
        $this->assertStringContainsString('User CSV import rolled back', $source);
        $this->assertStringContainsString("fgetcsv($" . "handle, null, ',', '\"', '')", $source);
    }

    public function testImportUiProvidesPendingAndErrorFeedback(): void
    {
        $view = file_get_contents(ROOTPATH . 'app/Views/admin/user-view.php');
        $script = file_get_contents(ROOTPATH . 'public/assets/js/admin-user-view.js');

        $this->assertStringContainsString('id="uv-import-result"', $view);
        $this->assertStringContainsString('aria-live="polite"', $view);
        $this->assertStringContainsString('button.disabled = true', $script);
        $this->assertStringContainsString('Importing...', $script);
        $this->assertStringContainsString('button.disabled = false', $script);
    }

    public function testNewEmployeesUseTheCurrentStandardSalaryProfile(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Services/AdminUserService.php');
        $view = file_get_contents(ROOTPATH . 'app/Views/admin/user-view.php');

        $this->assertStringNotContainsString('DEFAULT_EMPLOYEE_CREDIT_LIMIT', $source);
        $this->assertStringContainsString('standardProfile($db)', $source);
        $this->assertStringContainsString("'salary_schedule_id' => $" . "standardProfile['schedule_id']", $source);
        $this->assertStringContainsString('current standard SG 11, Step 1 profile', $view);
    }

    public function testNewEmployeesDefaultToUserAndStoreRolesFollowAssignments(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Services/AdminUserService.php');
        $controller = file_get_contents(ROOTPATH . 'app/Controllers/AdminController.php');
        $view = file_get_contents(ROOTPATH . 'app/Views/admin/user-view.php');
        $script = file_get_contents(ROOTPATH . 'public/assets/js/admin-user-view.js');

        $this->assertStringContainsString("\$roles = ['USER'];", $source);
        $this->assertStringContainsString('employeeRolesWithAssignments', $source);
        $this->assertStringContainsString("['STORE_SYSTEM', 'STORE_SUPERVISOR']", $source);
        $this->assertStringContainsString("addRoleToUser(\$officerId, 'STORE_SYSTEM'", $controller);
        $this->assertStringContainsString("addRoleToUser(\$supervisorId, 'STORE_SUPERVISOR'", $controller);
        $this->assertStringContainsString('Initial role: User', $view);
        $this->assertStringContainsString('roles: ["USER"]', $script);
    }
}
