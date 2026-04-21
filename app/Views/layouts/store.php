<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store System</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <?= $this->renderSection('styles') ?>
</head>
<body>
<?php
$name = (string) (session()->get('name') ?? 'Store User');
$role = (string) (session()->get('role') ?? 'STORE_SYSTEM');
$path = service('uri')->getPath();
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = '';
foreach (array_slice($parts, 0, 2) as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
if ($initials === '') {
    $initials = 'SU';
}
?>
<div class="app-shell">
    <aside class="app-sidebar">
        <div class="app-brand">
            <h1>IBEMS</h1>
            <p>Store Portal</p>
        </div>

        <nav class="app-menu">
            <a href="/store/pos" class="<?= $path === 'store/pos' ? 'is-active' : '' ?>">POS</a>
            <a href="/store/inventory" class="<?= $path === 'store/inventory' ? 'is-active' : '' ?>">Inventory</a>
            <a href="/store/history" class="<?= $path === 'store/history' ? 'is-active' : '' ?>">History</a>
        </nav>

        <div class="app-sidebar-spacer"></div>

        <div class="sidebar-logout">
            <a href="/auth/logout">Logout</a>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <h2>Store System</h2>
                <p>School Store Operations</p>
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
            USTP IBEMS Store System
        </footer>
    </div>
</div>

<?= $this->renderSection('scripts') ?>
</body>
</html>
