<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-history.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="history-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Store records',
        'title' => 'Transaction history',
        'description' => 'View past transactions and reprint receipts.',
        'icon' => 'bi bi-receipt-cutoff',
    ]) ?>

    <div class="history-filters">
        <div class="history-filter-field">
            <label for="history-date-from">From</label>
            <input id="history-date-from" type="date">
        </div>
        <div class="history-filter-field">
            <label for="history-date-to">To</label>
            <input id="history-date-to" type="date">
        </div>
        <div class="history-filter-field">
            <label for="history-payment-filter">Payment</label>
            <select id="history-payment-filter">
                <option value="">All</option>
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
                <option value="card">Card</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="other">Other</option>
                <option value="debt">Debt</option>
                <option value="advance_payment">Advance Payment</option>
            </select>
        </div>
        <div class="history-filter-actions">
            <button id="history-apply-filters" class="primary-btn" type="button">Apply Filters</button>
            <button id="history-clear-filters" class="secondary-btn" type="button">Clear</button>
        </div>
    </div>

    <div class="history-summary">
        <?= view('components/stat_card', ['title' => 'Transactions', 'value' => '0', 'valueId' => 'history-summary-count', 'icon' => 'bi bi-receipt', 'tone' => 'users', 'class' => 'history-summary-card']) ?>
        <?= view('components/stat_card', ['title' => 'Total Sales', 'value' => 'PHP 0.00', 'valueId' => 'history-summary-total', 'icon' => 'bi bi-graph-up-arrow', 'tone' => 'sales', 'class' => 'history-summary-card']) ?>
    </div>

    <div class="history-table-wrap table-standard-wrap">
        <table class="table table-standard">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Payment</th>
                    <th>Total</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="history-body">
                <?= view('components/data_state', [
                    'tag' => 'tr',
                    'colspan' => 6,
                    'type' => 'loading',
                    'message' => 'Loading transactions...',
                ]) ?>
            </tbody>
        </table>
    </div>

    <p id="history-result" class="history-result"></p>
</section>

<div id="history-receipt-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="history-receipt-title">
    <div class="receipt-card">
        <div class="receipt-head">
            <h3 id="history-receipt-title">Transaction Receipt</h3>
            <button id="history-receipt-close" type="button" class="receipt-close" aria-label="Close transaction receipt">&times;</button>
        </div>

        <div id="history-receipt-content"></div>

        <div class="receipt-actions">
            <button id="history-receipt-view" type="button" class="secondary-btn"><i class="bi bi-box-arrow-up-right"></i> View Receipt</button>
            <button id="history-receipt-print" type="button" class="primary-btn"><i class="bi bi-printer"></i> Print Receipt</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>"></script>
<script src="<?= base_url('assets/js/store-history.js') ?>"></script>
<?= $this->endSection() ?>

