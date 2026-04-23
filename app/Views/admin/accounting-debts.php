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
        <?= view('components/stat_card', ['title' => 'Accounts', 'value' => '0', 'valueId' => 'ad-account-count', 'icon' => 'bi bi-people', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'With Debt', 'value' => '0', 'valueId' => 'ad-debt-accounts', 'icon' => 'bi bi-exclamation-circle', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Total Debt', 'value' => 'PHP 0.00', 'valueId' => 'ad-total-debt', 'icon' => 'bi bi-cash-stack', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Today Deducted', 'value' => 'PHP 0.00', 'valueId' => 'ad-today-deducted', 'icon' => 'bi bi-cash-coin', 'tone' => 'finance']) ?>
    </div>

    <div class="overview-table-wrap table-standard-wrap">
        <h4>Top Debt Accounts</h4>
        <table class="table table-standard">
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
