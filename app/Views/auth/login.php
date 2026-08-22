<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token-name" content="<?= esc(config('Security')->tokenName) ?>">
    <meta name="csrf-token-value" content="<?= esc(service('security')->getHash()) ?>">
    <meta name="csrf-header-name" content="<?= esc(config('Security')->headerName) ?>">
    <meta name="csrf-cookie-name" content="<?= esc(config('Security')->cookieName) ?>">
    <title>IBEMS Login</title>
    <link rel="icon" type="image/png" href="<?= base_url('assets/images/ibems-logo.png') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/auth-login.css') ?>?v=20260822a">
    <link rel="stylesheet" href="<?= base_url('assets/css/password-visibility.css') ?>?v=20260813b">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="auth-modern">
<?php $ibemsLogoUrl = base_url('assets/images/ibems-logo.png'); ?>
    <main class="auth-shell">
        <section class="auth-panel auth-panel-form">
            <div class="auth-mark">
                <img src="<?= esc($ibemsLogoUrl) ?>" alt="IBEMS logo" class="auth-mark-logo">
            </div>
            <h1>Welcome Back</h1>
            <p>Sign in to the Integrated Business Enterprise Management System.</p>

            <form id="login-form" class="auth-form" method="post" action="/auth/login">
                <?= csrf_field() ?>
                <label for="email">Username</label>
                <input id="email" name="email" type="email" required autocomplete="username" placeholder="you@example.com">

                <label for="password">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password">

                <button id="submit-btn" type="submit">Sign In to Dashboard</button>
            </form>

            <div id="status" role="status" aria-live="polite"></div>

            <div class="demo-creds">
                <strong>Demo Credentials</strong>
                <span>Admin: admin@ibems.local / 123456</span>
                <span>Store Admin: store.admin@ibems.local / 123456</span>
                <span>Store: store.main@ibems.local / 123456</span>
                <span>Accounting: accounting@ibems.local / 123456</span>
                <span>User: maria.santos@ibems.local / 123456</span>
            </div>
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

    <script src="<?= base_url('assets/js/csrf.js') ?>"></script>
    <script src="<?= base_url('assets/js/auth-login.js') ?>?v=20260822b"></script>
    <script src="<?= base_url('assets/js/password-visibility.js') ?>?v=20260813a"></script>
</body>
</html>
