<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Authorization extends BaseConfig
{
    /**
     * Named route permissions. Access is evaluated against the deliberately
     * selected active role, never the union of every role assigned to a user.
     *
     * @var array<string, list<string>>
     */
    public array $policies = [
        'portal.enter'          => ['ADMIN', 'HR', 'STORE_SYSTEM', 'STORE_SUPERVISOR', 'ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD', 'PAYROLL_CLERK', 'CASHIER_HEAD', 'COLLECTING_OFFICER', 'BE_DIRECTOR'],
        'account.self'          => ['ADMIN', 'HR', 'STORE_SYSTEM', 'STORE_SUPERVISOR', 'ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD', 'PAYROLL_CLERK', 'CASHIER_HEAD', 'COLLECTING_OFFICER', 'BE_DIRECTOR', 'USER'],
        'system.manage'         => ['ADMIN'],
        'hr.staff.create'       => ['HR'],
        'cashier.office'        => ['CASHIER_HEAD', 'COLLECTING_OFFICER'],
        'be.review'             => ['BE_DIRECTOR'],
        'report.accounting_copy' => ['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD'],
        'finance.reports'       => ['BE_DIRECTOR', 'CASHIER_HEAD', 'COLLECTING_OFFICER', 'ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD'],
        'accounting.payroll'    => ['PAYROLL_CLERK'],
        'deduction.workflow'    => ['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD', 'PAYROLL_CLERK', 'CASHIER_HEAD', 'COLLECTING_OFFICER'],
        'store.review_assigned' => ['STORE_SUPERVISOR'],
        'store.report_submit'    => ['STORE_SUPERVISOR'],
        'store.checkout'        => ['STORE_SYSTEM'],
        'store.offerings'       => ['STORE_SYSTEM', 'STORE_SUPERVISOR'],
        'store.manage_assigned' => ['STORE_SUPERVISOR'],
        'store.inspect'         => ['STORE_SYSTEM', 'STORE_SUPERVISOR', 'ADMIN'],
        'user.self'             => ['USER'],
        'accounting.inspect'    => ['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD', 'ADMIN'],
        'accounting.investigate' => ['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD'],
        'accounting.debt_manage' => ['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD'],
        'accounting.settle'      => ['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD'],
    ];

    /** @return list<string>|null */
    public function rolesFor(string $policy): ?array
    {
        $policy = strtolower(trim($policy));
        if (!array_key_exists($policy, $this->policies)) {
            return null;
        }

        return array_values(array_unique(array_map(
            static fn (string $role): string => ibems_normalize_role($role),
            $this->policies[$policy]
        )));
    }
}
