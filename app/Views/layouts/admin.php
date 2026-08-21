<?php
$name = (string) (session()->get('name') ?? 'Administrator');
$role = (string) (session()->get('role') ?? 'ADMIN');
$isSupervisorPortal = strtoupper($role) === 'STORE_SUPERVISOR';
$pageTitle = 'Admin Portal';
$portalTitle = $isSupervisorPortal ? 'Store Supervisor Portal' : 'Admin Portal';
$portalSubtitle = $isSupervisorPortal ? 'Assigned Store Oversight' : 'School-wide Operations Oversight';
$footerText = 'USTP IBEMS Administration';
$initials = ibems_initials($name, 'AD');
$availableRoles = ibems_available_roles();
$navigation = [
    ['path' => 'admin/dashboard', 'label' => 'Dashboard', 'icon' => 'bi bi-speedometer2', 'visible' => !$isSupervisorPortal],
    ['path' => 'admin/stores', 'label' => 'Stores & POS', 'icon' => 'bi bi-shop-window'],
    ['path' => 'admin/products', 'label' => 'Products', 'icon' => 'bi bi-box-seam', 'visible' => !$isSupervisorPortal],
    ['path' => 'admin/accounting-debts', 'label' => 'Accounting Debts', 'icon' => 'bi bi-cash-coin', 'visible' => !$isSupervisorPortal],
    ['path' => 'admin/user-view', 'label' => 'User Management', 'icon' => 'bi bi-people', 'visible' => !$isSupervisorPortal],
    ['path' => 'admin/audit', 'label' => 'Audit Log', 'icon' => 'bi bi-journal-text', 'visible' => !$isSupervisorPortal],
];

include APPPATH . 'Views/components/portal_shell.php';
