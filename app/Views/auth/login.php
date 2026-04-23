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
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/auth-login.css') ?>">
</head>
<body>
<?php $ustpLogoUrl = base_url('assets/images/ustp_claveria_logo.jpg'); ?>
    <main class="auth-shell">
        <section class="auth-panel auth-panel-form">
            <div class="auth-mark">
                <img src="<?= esc($ustpLogoUrl) ?>" alt="USTP Logo" class="auth-mark-logo">
            </div>
            <h1>Welcome Back</h1>
            <p>Sign in to the Integrated Business Enterprise Management System.</p>

            <form id="login-form" class="auth-form">
                <label for="email">Username</label>
                <input id="email" name="email" type="email" required autocomplete="username" placeholder="you@example.com">

                <label for="password">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password">

                <button id="submit-btn" type="submit">Sign In to Dashboard</button>
            </form>

            <div class="demo-creds">
                <strong>Demo Credentials</strong>
                <span>Admin: admin@ibems.local / 123456</span>
                <span>Store: store.main@ibems.local / 123456</span>
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

    <script src="<?= base_url('assets/js/csrf.js') ?>"></script>
    <script src="<?= base_url('assets/js/auth-login.js') ?>"></script>
</body>
</html>
