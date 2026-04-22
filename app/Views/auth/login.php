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
    <link rel="stylesheet" href="<?= base_url('assets/css/auth-login.css') ?>">
</head>
<body>
<?php $ustpLogoUrl = base_url('assets/images/ustp_claveria_logo.jpg'); ?>
    <main class="login-card">
        <div class="login-brand">
            <img src="<?= esc($ustpLogoUrl) ?>" alt="USTP Logo" class="login-brand-logo">
            <div class="login-brand-text">
                <strong>USTP IBEMS</strong>
                <span>Integrated Business Enterprise Management System</span>
            </div>
        </div>
        <h1>IBEMS Login</h1>
        <p>Sign in to continue to the system.</p>

        <form id="login-form">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required autocomplete="username">

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">

            <button id="submit-btn" type="submit">Login</button>
        </form>

        <div id="status"></div>
    </main>

    <script src="<?= base_url('assets/js/csrf.js') ?>"></script>
    <script src="<?= base_url('assets/js/auth-login.js') ?>"></script>
</body>
</html>
