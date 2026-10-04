<?php

use CodeIgniter\Test\CIUnitTestCase;

final class AccountingDepartmentControlsTest extends CIUnitTestCase
{
    public function testHeadAndOfficerReceiveFinancialControlsButAdminDoesNot(): void
    {
        foreach (['ACCOUNTING_HEAD' => true, 'ACCOUNTING_OFFICE' => true, 'ADMIN' => false] as $role => $allowed) {
            session()->set(['role' => $role, 'user_id' => 900001, 'available_roles' => [$role]]);
            $html = view('accounting/department-debts');
            $this->assertStringContainsString('data-can-operate="' . ($allowed ? 'true' : 'false') . '"', $html);
            if ($allowed) {
                $this->assertStringContainsString('id="department-finance-form"', $html);
            } else {
                $this->assertStringNotContainsString('id="department-finance-form"', $html);
            }
        }
        session()->remove(['role', 'user_id', 'available_roles']);
    }
}
