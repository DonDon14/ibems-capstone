<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="user-shell">
    <div class="user-head">
        <h3>User Dashboard</h3>
        <p>Welcome, <?= esc(session()->get('name') ?? 'User') ?>.</p>
    </div>

    <div class="user-summary-grid">
        <div class="summary-card"><span>Credit Limit</span><strong id="u-credit-limit">PHP 0.00</strong></div>
        <div class="summary-card"><span>Current Debt</span><strong id="u-current-debt">PHP 0.00</strong></div>
        <div class="summary-card"><span>Available Credit</span><strong id="u-available-credit">PHP 0.00</strong></div>
        <div class="summary-card"><span>Total Purchases</span><strong id="u-total-spent">PHP 0.00</strong></div>
        <div class="summary-card"><span>Total Transactions</span><strong id="u-txn-count">0</strong></div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/user-dashboard.js') ?>"></script>
<?= $this->endSection() ?>
