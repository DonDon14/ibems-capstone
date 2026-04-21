<?= $this->extend('layouts/user') ?>

<?= $this->section('content') ?>
<h3>User Dashboard</h3>
<p>Welcome, <?= esc(session()->get('name') ?? 'User') ?>.</p>
<?= $this->endSection() ?>
