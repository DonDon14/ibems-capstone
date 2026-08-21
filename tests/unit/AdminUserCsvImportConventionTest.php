<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class AdminUserCsvImportConventionTest extends CIUnitTestCase
{
    public function testImportUsesPortableBooleanAndAtomicFailureHandling(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Controllers/AdminController.php');

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

    public function testNewEmployeesReceiveConsistentBaseCredit(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Controllers/AdminController.php');
        $view = file_get_contents(ROOTPATH . 'app/Views/admin/user-view.php');

        $this->assertStringContainsString('DEFAULT_EMPLOYEE_CREDIT_LIMIT = 1000.00', $source);
        $this->assertGreaterThanOrEqual(3, substr_count($source, 'initialCreditLimitForUserType('));
        $this->assertStringContainsString("['faculty', 'staff']", $source);
        $this->assertStringContainsString('PHP 1,000 base credit', $view);
    }
}
