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
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <?= $this->renderSection('styles') ?>
</head>
<body>
<?php
$name = (string) (session()->get('name') ?? 'Store User');
$email = (string) (session()->get('email') ?? '');
$role = (string) (session()->get('role') ?? 'STORE_SYSTEM');
$profileImageUrl = (string) (session()->get('profile_image_url') ?? '');
$path = trim((string) service('uri')->getPath(), '/');
if (strpos($path, 'index.php/') === 0) {
    $path = substr($path, strlen('index.php/'));
}
$isActive = static function (string $prefix) use ($path): string {
    return strpos($path, $prefix) === 0 ? 'is-active' : '';
};
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = '';
foreach (array_slice($parts, 0, 2) as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
if ($initials === '') {
    $initials = 'SU';
}
$storeModel = new \App\Models\StoreModel();
$stores = $storeModel->getAccessibleStores((int) session()->get('user_id'), $role);
$activeStore = $stores[0] ?? null;
$storeName = (string) ($activeStore['store_name'] ?? 'Store Portal');
$storeLogoUrl = (string) ($activeStore['logo_url'] ?? '');
?>
<div class="app-shell">
    <aside class="app-sidebar">
        <div class="app-brand">
            <h1>IBEMS</h1>
            <p><?= esc($storeName) ?></p>
        </div>

        <nav class="app-menu">
            <a href="/store/pos" class="<?= $isActive('store/pos') ?>">POS</a>
            <a href="/store/inventory" class="<?= $isActive('store/inventory') ?>">Inventory</a>
            <a href="/store/reports" class="<?= $isActive('store/reports') ?>">Reports</a>
            <a href="/store/history" class="<?= $isActive('store/history') ?>">History</a>
            <a href="/store/staff-records" class="<?= $isActive('store/staff-records') ?>">Employee Records</a>
        </nav>

        <div class="app-sidebar-spacer"></div>

        <div class="sidebar-logout">
            <a href="/auth/logout">Logout</a>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <h2><?= esc($storeName) ?></h2>
                <p>School Store Operations</p>
            </div>

            <div class="topbar-profile">
                <?php if ($storeLogoUrl !== ''): ?>
                    <img src="<?= esc($storeLogoUrl) ?>" alt="Store Logo" class="store-logo">
                <?php else: ?>
                    <div class="store-logo-fallback"><?= esc(strtoupper(substr($storeName, 0, 1))) ?></div>
                <?php endif; ?>

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
<?= $this->renderSection('scripts') ?>
</body>
</html>
