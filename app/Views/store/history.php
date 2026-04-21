<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-history.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="history-shell">
    <div class="history-toolbar">
        <div>
            <h3>Store Transaction History</h3>
            <p>View past transactions and reprint receipts.</p>
        </div>

        <div class="history-store-switch">
            <label for="history-store-select">Store</label>
            <select id="history-store-select"></select>
        </div>
    </div>

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
            <button id="history-apply-filters" class="history-action" type="button">Apply Filters</button>
            <button id="history-clear-filters" class="history-action alt" type="button">Clear</button>
        </div>
    </div>

    <div class="history-summary">
        <div class="history-summary-card">
            <span class="label">Transactions</span>
            <strong id="history-summary-count">0</strong>
        </div>
        <div class="history-summary-card">
            <span class="label">Total Sales</span>
            <strong id="history-summary-total">PHP 0.00</strong>
        </div>
    </div>

    <div class="history-table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Payment</th>
                    <th>Total</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="history-body">
                <tr>
                    <td colspan="6">Loading transactions...</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p id="history-result" class="history-result"></p>
</section>

<div id="history-receipt-modal" class="receipt-modal" style="display:none;">
    <div class="receipt-card">
        <div class="receipt-head">
            <h3>Transaction Receipt</h3>
            <button id="history-receipt-close" type="button" class="receipt-close">×</button>
        </div>

        <div id="history-receipt-content"></div>

        <div class="receipt-actions">
            <button id="history-receipt-print" type="button" class="primary-btn">Print Receipt</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-history.js') ?>"></script>
<?= $this->endSection() ?>
