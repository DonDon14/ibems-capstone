<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token-name" content="<?= esc(config('Security')->tokenName) ?>">
    <meta name="csrf-token-value" content="<?= esc(service('security')->getHash()) ?>">
    <meta name="csrf-header-name" content="<?= esc(config('Security')->headerName) ?>">
    <meta name="csrf-cookie-name" content="<?= esc(config('Security')->cookieName) ?>">
    <title>Select Role</title>
    <script>
    (function () {
        var saved = localStorage.getItem('ibems-theme');
        var theme = saved === 'dark' || saved === 'light' ? saved : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.dataset.theme = theme;
    }());
    </script>
    <link rel="icon" type="image/png" href="<?= base_url('assets/images/ibems-logo.png') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/auth-login.css') ?>?v=20260823c">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="auth-modern">
<?php $ibemsLogoUrl = base_url('assets/images/ibems-logo.png'); ?>
    <button id="theme-toggle" class="auth-theme-toggle" type="button" aria-label="Use dark mode" title="Use dark mode" aria-pressed="false">
        <i class="bi bi-moon-stars" aria-hidden="true"></i>
    </button>
    <main class="auth-shell">
        <section class="auth-panel auth-panel-form">
            <div class="auth-mark">
                <img src="<?= esc($ibemsLogoUrl) ?>" alt="IBEMS logo" class="auth-mark-logo">
            </div>
            <h1>Select Portal</h1>
            <p>Choose which role to use for this session.</p>

            <div id="role-options" class="auth-role-list">
                <?php foreach (ibems_available_roles() as $availableRole): ?>
                    <?php
                    $roleKey = strtoupper((string) $availableRole);
                    $roleLabels = [
                        'ADMIN' => 'Admin',
                        'STORE_SYSTEM' => 'Store Officer',
                        'STORE_SUPERVISOR' => 'Store Supervisor',
                        'ACCOUNTING_OFFICE' => 'Accounting Office',
                        'USER' => 'User',
                    ];
                    ?>
                    <form method="post" action="<?= site_url('auth/select-role') ?>" class="auth-role-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="auth-role-btn" name="role" value="<?= esc($roleKey) ?>">
                            <span><?= esc($roleLabels[$roleKey] ?? str_replace('_', ' ', $roleKey)) ?></span>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
            <div id="status"></div>
        </section>

        <section class="auth-panel auth-panel-art" aria-hidden="true">
            <div class="auth-art-layer auth-art-1"></div>
            <div class="auth-art-layer auth-art-2"></div>
            <div class="auth-art-layer auth-art-3"></div>
            <div class="auth-art-layer auth-art-4"></div>
            <div class="auth-art-line auth-art-line-1"></div>
            <div class="auth-art-line auth-art-line-2"></div>
        </section>
    </main>

    <script src="<?= base_url('assets/js/theme.js') ?>?v=20260822b"></script>
</body>
</html>
