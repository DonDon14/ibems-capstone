<?php
$name = (string) (session()->get('name') ?? 'Accounting Officer');
$role = (string) (session()->get('role') ?? 'ACCOUNTING_OFFICE');
$pageTitle = 'Accounting Portal';
$portalTitle = 'Accounting Portal';
$portalSubtitle = 'Employee & Department Debt Controls';
$footerText = 'USTP IBEMS Accounting';
$initials = ibems_initials($name, 'AO');
$profileImageUrl = (string) (session()->get('profile_image_url') ?? '');
$availableRoles = ibems_available_roles();
$navigation = [
    ['path' => 'accounting/dashboard', 'label' => 'Dashboard', 'icon' => 'bi bi-speedometer2'],
    ['path' => 'accounting/debts', 'label' => 'Debt Monitoring', 'icon' => 'bi bi-cash-stack'],
    ['path' => 'accounting/department-debts', 'label' => 'Department Accounts', 'icon' => 'bi bi-buildings'],
    ['path' => 'deductions/workspace', 'label' => 'Payroll Deductions', 'icon' => 'bi bi-file-earmark-spreadsheet'],
    ['path' => 'accounting/reports', 'label' => 'Report Copies', 'icon' => 'bi bi-clipboard2-check'],
    ['path' => 'finance/reports', 'label' => 'Sales & Charges', 'icon' => 'bi bi-bar-chart-line'],
];

include APPPATH . 'Views/components/portal_shell.php';
