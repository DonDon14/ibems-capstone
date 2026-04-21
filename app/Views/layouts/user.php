<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Portal</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <?= $this->renderSection('styles') ?>
</head>
<body>
<?php
$name = (string) (session()->get('name') ?? 'User');
$role = (string) (session()->get('role') ?? 'USER');
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
        <div class="app-brand">
            <h1>IBEMS</h1>
            <p>User Portal</p>
        </div>

        <nav class="app-menu">
            <a href="/user/dashboard" class="is-active">Dashboard</a>
            <a href="/user/history">History</a>
        </nav>

        <div class="app-sidebar-spacer"></div>

        <div class="sidebar-logout">
            <a href="/auth/logout">Logout</a>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <h2>User Portal</h2>
                <p>Account and Purchase Overview</p>
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

<?= $this->renderSection('scripts') ?>
</body>
</html>
