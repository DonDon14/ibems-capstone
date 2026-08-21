<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>?v=20260821b">
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>?v=20260821b">
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
            <label for="uh-store">Store</label>
            <select id="uh-store">
                <option value="">All stores</option>
            </select>
        </div>
        <div class="field">
            <label for="uh-date-from">From</label>
            <input id="uh-date-from" type="date">
        </div>
        <div class="field">
            <label for="uh-date-to">To</label>
            <input id="uh-date-to" type="date">
        </div>
        <div class="field">
            <label for="uh-sort">Sort purchases</label>
            <select id="uh-sort">
                <option value="date:desc">Newest first</option>
                <option value="date:asc">Oldest first</option>
                <option value="store:asc">Store A-Z</option>
                <option value="store:desc">Store Z-A</option>
                <option value="amount:desc">Highest amount</option>
                <option value="amount:asc">Lowest amount</option>
            </select>
        </div>
        <div class="field">
            <label for="uh-page-size">Rows</label>
            <select id="uh-page-size">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
            </select>
        </div>
        <button id="uh-reset-filters" class="secondary-btn is-hidden" type="button"><i class="bi bi-arrow-counterclockwise"></i> Reset filters</button>
    </div>

    <article class="dash-panel user-store-spending-panel">
        <div class="dashboard-title user-section-title">
            <div>
                <h3>Spending by Store</h3>
                <p>All stores remain listed for the selected date range, even when the transaction table is filtered to one store. Debt charged is historical credit usage; current outstanding debt remains the grand balance above.</p>
            </div>
            <strong id="uh-grand-total" class="user-grand-total">PHP 0.00</strong>
        </div>
        <div id="uh-store-totals" class="user-store-total-grid" aria-live="polite">
            <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading store totals...']) ?>
        </div>
    </article>

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
    <div id="uh-transactions-pager" class="user-pager" aria-label="Transaction history pages"></div>

    <div class="dashboard-title">
        <h3>Debt Cashbook</h3>
        <p>Track debt purchases, salary/manual deductions, and running debt balance.</p>
    </div>

    <div class="user-subfilters">
        <label for="uh-cashbook-sort">Sort cashbook
            <select id="uh-cashbook-sort">
                <option value="date:desc">Newest first</option>
                <option value="date:asc">Oldest first</option>
                <option value="amount:desc">Highest amount</option>
                <option value="amount:asc">Lowest amount</option>
                <option value="balance:desc">Highest debt balance</option>
            </select>
        </label>
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
    <div id="uh-cashbook-pager" class="user-pager" aria-label="Debt cashbook pages"></div>
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
<script src="<?= base_url('assets/js/receipt-standard.js') ?>?v=20260821a"></script>
<script src="<?= base_url('assets/js/user-history.js') ?>?v=20260821b"></script>
<?= $this->endSection() ?>
