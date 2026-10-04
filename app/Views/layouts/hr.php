<?php
$name = (string) (session()->get('name') ?? 'HR Staff');
$role = (string) (session()->get('role') ?? 'HR');
$pageTitle = 'HR Portal';
$portalTitle = 'HR Portal';
$portalSubtitle = 'Staff Onboarding';
$footerText = 'USTP IBEMS Human Resources';
$initials = ibems_initials($name, 'HR');
$profileImageUrl = (string) (session()->get('profile_image_url') ?? '');
$availableRoles = ibems_available_roles();
$navigation = [
    ['path' => 'hr/staff', 'label' => 'Add & Import Staff', 'icon' => 'bi bi-person-plus'],
];

include APPPATH . 'Views/components/portal_shell.php';
