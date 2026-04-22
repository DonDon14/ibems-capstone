<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token-name" content="<?= esc(config('Security')->tokenName) ?>">
    <meta name="csrf-token-value" content="<?= esc(service('security')->getHash()) ?>">
    <meta name="csrf-header-name" content="<?= esc(config('Security')->headerName) ?>">
    <meta name="csrf-cookie-name" content="<?= esc(config('Security')->cookieName) ?>">
    <title>Select Role</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/auth-login.css') ?>">
</head>
<body>
    <main class="login-card">
        <h1>Select Portal</h1>
        <p>Choose which role to use for this session.</p>

        <div id="role-options"></div>
        <div id="status"></div>
    </main>

    <script src="<?= base_url('assets/js/csrf.js') ?>"></script>
    <script src="<?= base_url('assets/js/auth-select-role.js') ?>"></script>
</body>
</html>

