<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<header class="app-header">
    <h2>IBEMS - Dashboard</h2>
</header>

<nav class="app-nav">
    <a href="/store/pos">Store</a>
    <a href="/user/dashboard">User</a>
    <form method="post" action="<?= site_url('auth/logout') ?>">
        <?= csrf_field() ?>
        <button type="submit">Logout</button>
    </form>
</nav>

<main class="app-container">
    <h3>Welcome</h3>
    <p>Select a module from the navigation menu.</p>
</main>
</body>
</html>
