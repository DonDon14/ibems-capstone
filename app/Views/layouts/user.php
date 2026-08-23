<?php
$name = (string) (session()->get('name') ?? 'User');
$role = (string) (session()->get('role') ?? 'USER');
$pageTitle = 'User Portal';
$portalTitle = 'User Portal';
$portalSubtitle = 'Account and Purchase Overview';
$footerText = 'USTP IBEMS User Portal';
$initials = ibems_initials($name, 'US');
$profileImageUrl = (string) (session()->get('profile_image_url') ?? '');
$availableRoles = ibems_available_roles();
$userMobileExperience = strtoupper($role) === 'USER';
$hasDepartmentApprovalAssignment = false;
try {
    $hasDepartmentApprovalAssignment = (new \App\Services\DepartmentAuthorizationService())
        ->userHasAssignment((int) session()->get('user_id'));
} catch (\Throwable) {
    $hasDepartmentApprovalAssignment = false;
}
$bodyClass = trim(($bodyClass ?? '') . ($userMobileExperience ? ' user-mobile-enabled' : ''));
$navigation = [
    ['path' => 'user/dashboard', 'label' => 'Dashboard', 'icon' => 'bi bi-speedometer2'],
    ['path' => 'user/stores', 'label' => 'Stores & Products', 'icon' => 'bi bi-shop-window'],
    ['path' => 'user/history', 'label' => 'History', 'icon' => 'bi bi-clock-history'],
    ['path' => 'user/deductions', 'label' => 'My Deductions', 'icon' => 'bi bi-receipt-cutoff'],
    ['path' => 'user/department-authorizations', 'label' => 'Department Approvals', 'icon' => 'bi bi-shield-lock', 'visible' => $hasDepartmentApprovalAssignment],
];

include APPPATH . 'Views/components/portal_shell.php';
