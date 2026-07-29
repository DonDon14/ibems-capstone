<?php
$name = (string) (session()->get('name') ?? 'User');
$role = (string) (session()->get('role') ?? 'USER');
$pageTitle = 'User Portal';
$portalTitle = 'User Portal';
$portalSubtitle = 'Account and Purchase Overview';
$footerText = 'USTP IBEMS User Portal';
$initials = ibems_initials($name, 'US');
$availableRoles = ibems_available_roles();
$navigation = [
    ['path' => 'user/dashboard', 'label' => 'Dashboard', 'icon' => 'bi bi-speedometer2'],
    ['path' => 'user/history', 'label' => 'History', 'icon' => 'bi bi-clock-history'],
];

include APPPATH . 'Views/components/portal_shell.php';
