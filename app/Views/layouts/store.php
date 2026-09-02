<?php
$name = (string) (session()->get('name') ?? 'Store User');
$email = (string) (session()->get('email') ?? '');
$role = (string) (session()->get('role') ?? 'STORE_SYSTEM');
$profileImageUrl = (string) (session()->get('profile_image_url') ?? '');
$initials = ibems_initials($name, 'SU');
$availableRoles = ibems_available_roles();

$storeModel = new \App\Models\StoreModel();
$stores = $storeModel->getAccessibleStores((int) session()->get('user_id'), $role);
$activeStore = $stores[0] ?? null;
$storeName = (string) ($activeStore['store_name'] ?? 'Store Portal');
$portalContext = [
    'stores' => array_map(static fn (array $store): array => [
        'id' => (int) ($store['id'] ?? 0),
        'store_name' => (string) ($store['store_name'] ?? ''),
    ], $stores),
    'default_store_id' => (int) ($activeStore['id'] ?? 0),
];

$isCashierPortal = strtoupper($role) === 'STORE_SYSTEM';
$pageTitle = $isCashierPortal ? 'Store Cashier POS' : 'Store Supervisor Portal';
$portalTitle = $isCashierPortal ? $storeName . ' POS' : $storeName;
$portalSubtitle = $isCashierPortal ? 'Cashier Checkout' : 'Assigned Store Management';
$footerText = $isCashierPortal ? 'USTP IBEMS Store Cashier' : 'USTP IBEMS Store Supervision';
$profileDetail = ($isCashierPortal ? 'Store Cashier' : 'Store Supervisor') . ($email !== '' ? ' | ' . $email : '');
$requestPath = trim(service('uri')->getPath(), '/');
$bodyClass = str_ends_with($requestPath, 'store/pos') ? 'pos-fullscreen' : '';
$navigation = $isCashierPortal
    ? [
        ['path' => 'store/pos', 'label' => 'POS', 'icon' => 'bi bi-cart3'],
    ]
    : [
        ['path' => 'store-admin/dashboard', 'label' => 'Dashboard', 'icon' => 'bi bi-speedometer2'],
        ['path' => 'store-admin/stores', 'label' => 'Assigned Stores', 'icon' => 'bi bi-shop-window'],
        ['path' => 'store/inventory', 'label' => 'Inventory', 'icon' => 'bi bi-box-seam'],
        ['path' => 'store/reports', 'label' => 'Reports', 'icon' => 'bi bi-bar-chart-line'],
        ['path' => 'store/history', 'label' => 'History', 'icon' => 'bi bi-clock-history'],
        ['path' => 'store/staff-records', 'label' => 'Employee Accounts', 'icon' => 'bi bi-person-vcard'],
        ['path' => 'store/settings', 'label' => 'Settings', 'icon' => 'bi bi-gear'],
    ];

include APPPATH . 'Views/components/portal_shell.php';
