<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell">
    <div class="admin-overview-head">
        <h3>Accounting Debts Oversight</h3>
        <p>Read-only debt and deduction visibility for admin audit.</p>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><span>Accounts</span><strong id="ad-account-count">0</strong></div>
        <div class="summary-card"><span>With Debt</span><strong id="ad-debt-accounts">0</strong></div>
        <div class="summary-card"><span>Total Debt</span><strong id="ad-total-debt">PHP 0.00</strong></div>
        <div class="summary-card"><span>Today Deducted</span><strong id="ad-today-deducted">PHP 0.00</strong></div>
    </div>

    <div class="overview-table-wrap">
        <h4>Top Debt Accounts</h4>
        <table class="table">
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Current Debt</th>
                    <th>Credit Limit</th>
                </tr>
            </thead>
            <tbody id="ad-top-body">
                <tr><td colspan="5">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-accounting-debts.js') ?>"></script>
<?= $this->endSection() ?>
