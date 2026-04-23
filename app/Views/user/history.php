<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <div class="dashboard-title">
        <h3>Transaction History</h3>
        <p>Your purchase records across stores.</p>
    </div>

    <div class="dashboard-grid">
        <?= view('components/stat_card', ['title' => 'Credit Limit', 'value' => 'PHP 0.00', 'valueId' => 'uh-credit-limit', 'icon' => 'bi bi-wallet2', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Current Debt', 'value' => 'PHP 0.00', 'valueId' => 'uh-current-debt', 'icon' => 'bi bi-credit-card-2-front', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Available Credit', 'value' => 'PHP 0.00', 'valueId' => 'uh-available-credit', 'icon' => 'bi bi-cash-coin', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Total Purchases', 'value' => 'PHP 0.00', 'valueId' => 'uh-total-spent', 'icon' => 'bi bi-bag-check', 'tone' => 'finance']) ?>
    </div>

    <div class="user-filters">
        <div class="field">
            <label for="uh-date-from">From</label>
            <input id="uh-date-from" type="date">
        </div>
        <div class="field">
            <label for="uh-date-to">To</label>
            <input id="uh-date-to" type="date">
        </div>
        <button id="uh-apply" class="primary-btn" type="button">Apply</button>
        <button id="uh-clear" class="history-action alt" type="button">Clear</button>
    </div>

    <div class="table-standard-wrap">
        <table class="table table-standard">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Store</th>
                    <th>Payment</th>
                    <th>Amount</th>
                    <th>Reference</th>
                </tr>
            </thead>
            <tbody id="uh-body">
                <tr><td colspan="5">Loading transactions...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="dashboard-title">
        <h3>Debt Cashbook</h3>
        <p>Track debt purchases, salary/manual deductions, and running debt balance.</p>
    </div>

    <div class="table-standard-wrap">
        <table class="table table-standard">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Entry</th>
                    <th>Direction</th>
                    <th>Amount</th>
                    <th>Debt Before</th>
                    <th>Debt After</th>
                    <th>Available Credit</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody id="uh-cashbook-body">
                <tr><td colspan="8">Loading cashbook...</td></tr>
            </tbody>
        </table>
    </div>
</section>

<div id="uh-receipt-modal" class="receipt-modal is-hidden">
    <div class="receipt-card">
        <div class="receipt-head">
            <h3>Transaction Receipt</h3>
            <button id="uh-receipt-close" type="button" class="receipt-close">x</button>
        </div>
        <div id="uh-receipt-content"></div>
        <div class="receipt-actions">
            <button id="uh-receipt-print" type="button" class="primary-btn">Print Receipt</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>"></script>
<script src="<?= base_url('assets/js/user-history.js') ?>"></script>
<?= $this->endSection() ?>
