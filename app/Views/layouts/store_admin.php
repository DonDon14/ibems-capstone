<?php
$name = (string) (session()->get('name') ?? 'Store Administrator');
$role = (string) (session()->get('role') ?? 'STORE_SUPERVISOR');
$pageTitle = 'Store Admin Portal';
$portalTitle = 'Store Admin Portal';
$portalSubtitle = 'Assigned Store Oversight';
$footerText = 'USTP IBEMS Store Administration';
$initials = ibems_initials($name, 'SA');
$availableRoles = ibems_available_roles();
$navigation = [
    ['path' => 'store-admin/dashboard', 'label' => 'Dashboard', 'icon' => 'bi bi-speedometer2'],
    ['path' => 'store-admin/stores', 'label' => 'Assigned Stores', 'icon' => 'bi bi-shop-window'],
];

include APPPATH . 'Views/components/portal_shell.php';
