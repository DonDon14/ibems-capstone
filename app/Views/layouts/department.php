<?php
$name = (string) (session()->get('name') ?? 'Department Head');
$role = (string) (session()->get('role') ?? 'USER');
$pageTitle = 'Department Portal';
$portalTitle = 'Department Portal';
$portalSubtitle = 'Approvals and Department Account';
$footerText = 'USTP IBEMS Department Portal';
$initials = ibems_initials($name, 'DH');
$profileImageUrl = (string) (session()->get('profile_image_url') ?? '');
$availableRoles = ibems_available_roles();
$userMobileExperience = true;
$portalNavigationKey = 'DEPARTMENT';
$bodyClass = trim(($bodyClass ?? '') . ' department-portal user-mobile-enabled');
$navigation = [
    ['path' => 'department/dashboard', 'label' => 'Dashboard', 'icon' => 'bi bi-speedometer2'],
    ['path' => 'department/authorizations', 'label' => 'Authorization', 'icon' => 'bi bi-shield-lock'],
];
$portalSwitches = [
    ['path' => 'user/dashboard', 'label' => 'Personal Portal', 'description' => 'Return to your employee account', 'icon' => 'bi bi-person'],
];

include APPPATH . 'Views/components/portal_shell.php';
