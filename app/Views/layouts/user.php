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
$navigation = [
    ['path' => 'user/dashboard', 'label' => 'Dashboard', 'icon' => 'bi bi-speedometer2'],
    ['path' => 'user/stores', 'label' => 'Stores & Products', 'icon' => 'bi bi-shop-window'],
    ['path' => 'user/history', 'label' => 'History', 'icon' => 'bi bi-clock-history'],
    ['path' => 'user/deductions', 'label' => 'My Deductions', 'icon' => 'bi bi-receipt-cutoff'],
];

include APPPATH . 'Views/components/portal_shell.php';
