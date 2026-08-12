<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Personal records',
        'title' => 'Transaction history',
        'description' => 'Your purchase records across stores.',
        'icon' => 'bi bi-clock-history',
    ]) ?>

    <div class="dashboard-grid">
        <?= view('components/stat_card', ['title' => 'Credit Limit', 'value' => 'PHP 0.00', 'valueId' => 'uh-credit-limit', 'icon' => 'bi bi-wallet2', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Current Debt', 'value' => 'PHP 0.00', 'valueId' => 'uh-current-debt', 'icon' => 'bi bi-credit-card-2-front', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Available Credit', 'value' => 'PHP 0.00', 'valueId' => 'uh-available-credit', 'icon' => 'bi bi-cash-coin', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Total Purchases', 'value' => 'PHP 0.00', 'valueId' => 'uh-total-spent', 'icon' => 'bi bi-bag-check', 'tone' => 'finance']) ?>
    </div>

    <article id="uh-debt-status-card" class="user-status-card user-status-card--info">
        <div>
            <span id="uh-debt-status-label" class="user-status-badge">Loading</span>
            <h4>Debt Status</h4>
            <p id="uh-debt-status-message">Checking your current debt balance.</p>
        </div>
    </article>

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
        <button id="uh-clear" class="secondary-btn" type="button">Clear</button>
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
                    <th>Receipt</th>
                </tr>
            </thead>
            <tbody id="uh-body">
                <?= view('components/data_state', ['tag' => 'tr', 'colspan' => 6, 'type' => 'loading', 'message' => 'Loading transactions...']) ?>
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
                <?= view('components/data_state', ['tag' => 'tr', 'colspan' => 8, 'type' => 'loading', 'message' => 'Loading cashbook...']) ?>
            </tbody>
        </table>
    </div>
</section>

<div id="uh-receipt-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="uh-receipt-title">
    <div class="receipt-card" tabindex="-1">
        <div class="receipt-head">
            <h3 id="uh-receipt-title">Transaction Receipt</h3>
            <button id="uh-receipt-close" type="button" class="receipt-close" aria-label="Close receipt">&times;</button>
        </div>
        <div id="uh-receipt-content" aria-live="polite"></div>
        <div class="receipt-actions">
            <button id="uh-receipt-print" type="button" class="primary-btn">
                <i class="bi bi-printer"></i> Print Receipt
            </button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>"></script>
<script src="<?= base_url('assets/js/user-history.js') ?>"></script>
<?= $this->endSection() ?>
