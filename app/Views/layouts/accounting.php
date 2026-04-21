<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Portal</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <?= $this->renderSection('styles') ?>
</head>
<body>
<header class="app-header">
    <h2>IBEMS - Accounting Portal</h2>
</header>

<nav class="app-nav">
    <a href="/dashboard">Dashboard</a>
    <a href="/auth/logout">Logout</a>
</nav>

<main class="app-container">
    <?= $this->renderSection('content') ?>
</main>

<?= $this->renderSection('scripts') ?>
</body>
</html>
