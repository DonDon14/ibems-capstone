<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IBEMS Login</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/auth-login.css') ?>">
</head>
<body>
    <main class="login-card">
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

    <script src="<?= base_url('assets/js/auth-login.js') ?>"></script>
</body>
</html>
