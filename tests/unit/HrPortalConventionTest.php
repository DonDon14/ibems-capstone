<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class HrPortalConventionTest extends CIUnitTestCase
{
    public function testHrPortalHasOnlyStaffAddAndImportRoutes(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $authorization = config(\Config\Authorization::class);
        $layout = (string) file_get_contents(APPPATH . 'Views/layouts/hr.php');

        $this->assertSame(['HR'], $authorization->rolesFor('hr.staff.create'));
        $this->assertStringContainsString("hr/staff/create', 'HrController::createStaff'", $routes);
        $this->assertStringContainsString("hr/staff/import-csv', 'HrController::importStaffCsv'", $routes);
        $this->assertSame(1, substr_count($layout, "['path' => 'hr/staff'"));
        $this->assertStringNotContainsString('admin/', $layout);
        $this->assertStringNotContainsString('accounting/', $layout);
        $this->assertStringNotContainsString('store/', $layout);
    }

    public function testHrServiceAllowsClassifiedEmployeesButKeepsCreateOnlyImports(): void
    {
        $service = (string) file_get_contents(APPPATH . 'Services/HrStaffService.php');
        $importService = (string) file_get_contents(APPPATH . 'Services/AdminUserService.php');
        $view = (string) file_get_contents(APPPATH . 'Views/hr/staff.php');

        $this->assertStringContainsString("['faculty', 'staff']", $service);
        $this->assertStringContainsString('SalaryCreditPolicy::EMPLOYMENT_TYPES', $service);
        $this->assertStringContainsString("'user_type' => \$userType", $service);
        $this->assertStringContainsString("'roles' => ['USER']", $service);
        $this->assertStringContainsString("'allow_updates' => false", $service);
        $this->assertStringContainsString("'force_active' => true", $service);
        $this->assertStringContainsString('strlen($password) < 6', $service);
        $this->assertStringContainsString('if ($existing && !$allowUpdates)', $importService);
        $this->assertStringContainsString('Existing email or employee ID matches are skipped, never overwritten.', $view);
        $this->assertStringContainsString('name="user_type"', $view);
        $this->assertStringContainsString('name="employment_type"', $view);
        $this->assertStringContainsString('name="salary_grade"', $view);
        $this->assertStringContainsString('name="salary_step"', $view);
        $this->assertStringNotContainsString('name="base_salary"', $view);
    }

    public function testHrRoleIsPresentedAndHasItsOwnLandingPage(): void
    {
        $common = (string) file_get_contents(APPPATH . 'Common.php');
        $selector = (string) file_get_contents(APPPATH . 'Views/auth/select_role.php');
        $adminUsers = (string) file_get_contents(APPPATH . 'Views/admin/user-view.php');

        $this->assertStringContainsString("'HR' => '/hr/staff'", $common);
        $this->assertStringContainsString("'label' => 'Human Resources'", $selector);
        $this->assertStringContainsString('value="HR"> Human Resources', $adminUsers);
    }
}
