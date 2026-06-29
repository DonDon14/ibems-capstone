<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token-name" content="<?= esc(config('Security')->tokenName) ?>">
    <meta name="csrf-token-value" content="<?= esc(service('security')->getHash()) ?>">
    <meta name="csrf-header-name" content="<?= esc(config('Security')->headerName) ?>">
    <meta name="csrf-cookie-name" content="<?= esc(config('Security')->cookieName) ?>">
    <title>Store System</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?= $this->renderSection('styles') ?>
</head>
<body>
<?php
$name = (string) (session()->get('name') ?? 'Store User');
$email = (string) (session()->get('email') ?? '');
$role = (string) (session()->get('role') ?? 'STORE_SYSTEM');
$ustpLogoUrl = base_url('assets/images/ustp_claveria_logo.jpg');
$profileImageUrl = (string) (session()->get('profile_image_url') ?? '');
$initials = ibems_initials($name, 'SU');
$availableRoles = ibems_available_roles();
$storeModel = new \App\Models\StoreModel();
$stores = $storeModel->getAccessibleStores((int) session()->get('user_id'), $role);
$activeStore = $stores[0] ?? null;
$storeName = (string) ($activeStore['store_name'] ?? 'Store Portal');
$storeLogoUrl = (string) ($activeStore['logo_url'] ?? '');
?>
<div class="app-shell">
    <aside class="app-sidebar">
        <div class="app-sidebar-header">
            <div class="app-brand">
                <img src="<?= esc($ustpLogoUrl) ?>" alt="USTP Logo" class="app-brand-logo">
                <div class="app-brand-text">
                    <h1>IBEMS</h1>
                    <p><?= esc($storeName) ?></p>
                </div>
            </div>
            <button id="sidebar-toggle" type="button" class="secondary-btn sidebar-toggle sidebar-toggle-in-sidebar" aria-label="Toggle Sidebar">
                <i class="bi bi-layout-sidebar"></i>
            </button>
        </div>

        <nav class="app-menu">
            <a href="<?= site_url('store/dashboard') ?>" class="<?= ibems_is_active_path('store/dashboard') ?>" <?= ibems_is_active_path('store/dashboard') ? 'aria-current="page"' : '' ?>><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
            <a href="<?= site_url('store/pos') ?>" class="<?= ibems_is_active_path('store/pos') ?>" <?= ibems_is_active_path('store/pos') ? 'aria-current="page"' : '' ?>><i class="bi bi-cart3"></i><span>POS</span></a>
            <a href="<?= site_url('store/inventory') ?>" class="<?= ibems_is_active_path('store/inventory') ?>" <?= ibems_is_active_path('store/inventory') ? 'aria-current="page"' : '' ?>><i class="bi bi-box-seam"></i><span>Inventory</span></a>
            <a href="<?= site_url('store/reports') ?>" class="<?= ibems_is_active_path('store/reports') ?>" <?= ibems_is_active_path('store/reports') ? 'aria-current="page"' : '' ?>><i class="bi bi-bar-chart-line"></i><span>Reports</span></a>
            <a href="<?= site_url('store/history') ?>" class="<?= ibems_is_active_path('store/history') ?>" <?= ibems_is_active_path('store/history') ? 'aria-current="page"' : '' ?>><i class="bi bi-clock-history"></i><span>History</span></a>
            <a href="<?= site_url('store/staff-records') ?>" class="<?= ibems_is_active_path('store/staff-records') ?>" <?= ibems_is_active_path('store/staff-records') ? 'aria-current="page"' : '' ?>><i class="bi bi-person-vcard"></i><span>Employee Records</span></a>
            <a href="<?= site_url('store/settings') ?>" class="<?= ibems_is_active_path('store/settings') ?>" <?= ibems_is_active_path('store/settings') ? 'aria-current="page"' : '' ?>><i class="bi bi-gear"></i><span>Settings</span></a>
        </nav>

        <div class="app-sidebar-spacer"></div>

        <div class="sidebar-logout">
            <form method="post" action="<?= site_url('auth/logout') ?>" class="sidebar-logout-form">
                <?= csrf_field() ?>
                <button type="submit"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
            </form>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <div class="topbar-title">
                    <h2><?= esc($storeName) ?></h2>
                    <p>School Store Operations</p>
                </div>
            </div>

            <div class="topbar-actions">
                <?php if (count($availableRoles) > 1): ?>
                    <a href="<?= site_url('auth/select-role') ?>" class="topbar-action">
                        <i class="bi bi-shuffle"></i>
                        <span>Switch Portal</span>
                    </a>
                <?php endif; ?>

                <div class="topbar-profile">
                    <?php if ($profileImageUrl !== ''): ?>
                        <img src="<?= esc($profileImageUrl) ?>" alt="Profile" class="profile-avatar-img">
                    <?php else: ?>
                        <div class="profile-avatar"><?= esc($initials) ?></div>
                    <?php endif; ?>
                    <div class="profile-meta">
                        <div class="profile-name"><?= esc($name) ?></div>
                        <div class="profile-role"><?= esc($role) ?><?= $email !== '' ? ' | ' . esc($email) : '' ?></div>
                    </div>
                </div>
            </div>
        </header>

        <main class="app-container">
            <?= $this->renderSection('content') ?>
        </main>

        <footer class="app-footer">
            USTP IBEMS Store System
        </footer>
    </div>
</div>

<script src="<?= base_url('assets/js/csrf.js') ?>"></script>
<script src="<?= base_url('assets/js/app-layout.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
