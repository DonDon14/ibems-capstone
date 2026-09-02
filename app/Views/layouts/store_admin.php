<?php
$name = (string) (session()->get('name') ?? 'Store Administrator');
$role = (string) (session()->get('role') ?? 'STORE_SUPERVISOR');
$pageTitle = 'Store Admin Portal';
$portalTitle = 'Store Admin Portal';
$portalSubtitle = 'Assigned Store Oversight';
$footerText = 'USTP IBEMS Store Administration';
$initials = ibems_initials($name, 'SA');
$profileImageUrl = (string) (session()->get('profile_image_url') ?? '');
$availableRoles = ibems_available_roles();
$navigation = [
    ['path' => 'store-admin/dashboard', 'label' => 'Dashboard', 'icon' => 'bi bi-speedometer2'],
    ['path' => 'store-admin/stores', 'label' => 'Assigned Stores', 'icon' => 'bi bi-shop-window'],
    ['path' => 'store/inventory', 'label' => 'Inventory', 'icon' => 'bi bi-box-seam'],
    ['path' => 'store/reports', 'label' => 'Reports', 'icon' => 'bi bi-bar-chart-line'],
    ['path' => 'store/history', 'label' => 'History', 'icon' => 'bi bi-clock-history'],
    ['path' => 'store/staff-records', 'label' => 'Employee Accounts', 'icon' => 'bi bi-person-vcard'],
    ['path' => 'store/settings', 'label' => 'Settings', 'icon' => 'bi bi-gear'],
];

include APPPATH . 'Views/components/portal_shell.php';
