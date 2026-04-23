<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token-name" content="<?= esc(config('Security')->tokenName) ?>">
    <meta name="csrf-token-value" content="<?= esc(service('security')->getHash()) ?>">
    <meta name="csrf-header-name" content="<?= esc(config('Security')->headerName) ?>">
    <meta name="csrf-cookie-name" content="<?= esc(config('Security')->cookieName) ?>">
    <title>User Portal</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?= $this->renderSection('styles') ?>
</head>
<body>
<?php
$name = (string) (session()->get('name') ?? 'User');
$role = (string) (session()->get('role') ?? 'USER');
$ustpLogoUrl = base_url('assets/images/ustp_claveria_logo.jpg');
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
    $initials = 'US';
}
?>
<div class="app-shell">
    <aside class="app-sidebar">
        <div class="app-sidebar-header">
            <div class="app-brand">
                <img src="<?= esc($ustpLogoUrl) ?>" alt="USTP Logo" class="app-brand-logo">
                <div class="app-brand-text">
                    <h1>IBEMS</h1>
                    <p>User Portal</p>
                </div>
            </div>
            <button id="sidebar-toggle" type="button" class="secondary-btn sidebar-toggle sidebar-toggle-in-sidebar" aria-label="Toggle Sidebar">
                <i class="bi bi-layout-sidebar"></i>
            </button>
        </div>

        <nav class="app-menu">
            <a href="/user/dashboard" class="<?= $isActive('user/dashboard') ?>"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
            <a href="/user/history" class="<?= $isActive('user/history') ?>"><i class="bi bi-clock-history"></i><span>History</span></a>
        </nav>

        <div class="app-sidebar-spacer"></div>

        <div class="sidebar-logout">
            <a href="/auth/logout"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <div class="topbar-title">
                    <h2>User Portal</h2>
                    <p>Account and Purchase Overview</p>
                </div>
            </div>

            <div class="topbar-profile">
                <div class="profile-avatar"><?= esc($initials) ?></div>
                <div class="profile-meta">
                    <div class="profile-name"><?= esc($name) ?></div>
                    <div class="profile-role"><?= esc($role) ?></div>
                </div>
            </div>
        </header>

        <main class="app-container">
            <?= $this->renderSection('content') ?>
        </main>

        <footer class="app-footer">
            USTP IBEMS User Portal
        </footer>
    </div>
</div>

<script src="<?= base_url('assets/js/csrf.js') ?>"></script>
<script src="<?= base_url('assets/js/app-layout.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
