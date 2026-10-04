<?php
$name = (string) (session()->get('name') ?? 'Staff');
$role = (string) (session()->get('role') ?? '');
if (in_array($role, ['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD'], true)) {
    include APPPATH . 'Views/layouts/accounting.php';
    return;
}
$pageTitle = (string) ($portalTitle ?? 'Office Portal');
$portalTitle = $pageTitle;
$portalSubtitle = (string) ($portalSubtitle ?? '');
$footerText = 'USTP IBEMS';
$initials = ibems_initials($name, 'IB');
$profileImageUrl = (string) (session()->get('profile_image_url') ?? '');
$availableRoles = ibems_available_roles();
$navigation = [
    ['path' => (string) ($homePath ?? ''), 'label' => 'Dashboard', 'icon' => 'bi bi-speedometer2'],
];
if (in_array($role, ['CASHIER_HEAD', 'COLLECTING_OFFICER', 'PAYROLL_CLERK'], true)) {
    $navigation[] = ['path' => 'deductions/workspace', 'label' => 'Deductions', 'icon' => 'bi bi-cash-coin'];
}
if (in_array($role, ['CASHIER_HEAD', 'COLLECTING_OFFICER'], true)) {
    $navigation[] = ['path' => 'cashier/reports', 'label' => 'Daily Reports', 'icon' => 'bi bi-clipboard2-check'];
} elseif ($role === 'BE_DIRECTOR') {
    $navigation[] = ['path' => 'be/reports', 'label' => 'Daily Reports', 'icon' => 'bi bi-clipboard2-check'];
} elseif (in_array($role, ['ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD'], true)) {
    $navigation[] = ['path' => 'accounting/reports', 'label' => 'Report Copies', 'icon' => 'bi bi-clipboard2-check'];
}
if (in_array($role, ['BE_DIRECTOR', 'CASHIER_HEAD', 'COLLECTING_OFFICER', 'ACCOUNTING_OFFICE', 'ACCOUNTING_HEAD'], true)) {
    $navigation[] = ['path' => 'finance/reports', 'label' => 'Sales & Charges', 'icon' => 'bi bi-bar-chart-line'];
}

include APPPATH . 'Views/components/portal_shell.php';
