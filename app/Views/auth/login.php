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
    <title><?= getenv('IBEMS_INSTRUCTOR_DEMO') === '1' ? 'Campus Business Portal' : 'IBEMS' ?> Login</title>
    <script {csp-script-nonce}>
    (function () {
        var saved = null;
        try { saved = localStorage.getItem('ibems-theme'); } catch (_) {}
        var theme = saved === 'dark' || saved === 'light' ? saved : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.dataset.theme = theme;
    }());
    </script>
    <link rel="icon" type="image/png" href="<?= base_url('assets/images/ibems-logo.png') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>?v=20261003-ui-polish">
    <link rel="stylesheet" href="<?= base_url('assets/css/auth-login.css') ?>?v=20261003-role-feedback">
    <link rel="stylesheet" href="<?= base_url('assets/css/password-visibility.css') ?>?v=20261004-visibility-focus">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
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
            <h1>Welcome Back</h1>
            <p><?= getenv('IBEMS_INSTRUCTOR_DEMO') === '1' ? 'Sign in to the Campus Business Portal.' : 'Sign in to the Integrated Business Enterprise Management System.' ?></p>

            <form id="login-form" class="auth-form" method="post" action="/auth/login">
                <?= csrf_field() ?>
                <label for="email">Username</label>
                <input id="email" name="email" type="email" required autocomplete="username" placeholder="you@example.com">

                <label for="password">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password">

                <button id="submit-btn" type="submit">Sign In to Dashboard</button>
            </form>

            <div id="status" role="status" aria-live="polite"></div>

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

    <script src="<?= base_url('assets/js/csrf.js') ?>?v=20261003-ui-polish"></script>
    <script src="<?= base_url('assets/js/auth-login.js') ?>?v=20261002-feedback"></script>
    <script src="<?= base_url('assets/js/password-visibility.js') ?>?v=20261004-mask-reset"></script>
    <script src="<?= base_url('assets/js/theme.js') ?>?v=20261003-transition-ready"></script>
</body>
</html>
