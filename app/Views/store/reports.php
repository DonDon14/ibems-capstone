<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-reports.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="reports-shell">
    <div class="reports-top">
        <div>
            <h3>Store Financial Reports</h3>
            <p>Track sales, estimated cost, and profit by period.</p>
        </div>
        <div class="reports-controls">
            <div class="reports-period-chips">
                <button type="button" class="period-chip is-active" data-period="today">Today</button>
                <button type="button" class="period-chip" data-period="week">Week</button>
                <button type="button" class="period-chip" data-period="month">Month</button>
                <button type="button" class="period-chip" data-period="custom">Custom</button>
            </div>
            <div id="reports-custom-range" class="reports-custom-range hidden">
                <input id="reports-date-from" type="date">
                <input id="reports-date-to" type="date">
            </div>
            <button id="reports-refresh-btn" class="primary-btn" type="button">Refresh</button>
        </div>
    </div>

    <div class="reports-summary-grid">
        <article class="summary-card"><span>Total Sales</span><strong id="sum-sales">PHP 0.00</strong></article>
        <article class="summary-card"><span>Estimated Cost</span><strong id="sum-cost">PHP 0.00</strong></article>
        <article class="summary-card"><span>Estimated Profit</span><strong id="sum-profit">PHP 0.00</strong></article>
        <article class="summary-card"><span>Profit Margin</span><strong id="sum-margin">0.00%</strong></article>
        <article class="summary-card"><span>Transactions</span><strong id="sum-transactions">0</strong></article>
        <article class="summary-card"><span>Items Sold</span><strong id="sum-items">0</strong></article>
        <article class="summary-card"><span>Average Ticket</span><strong id="sum-ticket">PHP 0.00</strong></article>
        <article class="summary-card"><span>Stock-in Cost</span><strong id="sum-stockin-cost">PHP 0.00</strong></article>
    </div>

    <article class="reports-card">
        <h4>Payment Records</h4>
        <div class="payment-records-grid">
            <article class="summary-card payment-record">
                <span>Cash Sales</span>
                <strong id="pay-cash">PHP 0.00</strong>
                <small id="pay-cash-meta">0 transactions</small>
            </article>
            <article class="summary-card payment-record">
                <span>GCash Sales</span>
                <strong id="pay-gcash">PHP 0.00</strong>
                <small id="pay-gcash-meta">0 transactions</small>
            </article>
            <article class="summary-card payment-record">
                <span>Other Payments</span>
                <strong id="pay-others">PHP 0.00</strong>
                <small id="pay-others-meta">0 transactions</small>
            </article>
            <article class="summary-card payment-record">
                <span>Debt Payments</span>
                <strong id="pay-debt">PHP 0.00</strong>
                <small id="pay-debt-meta">0 transactions</small>
            </article>
            <article class="summary-card payment-record">
                <span>Initial Opening Balance</span>
                <strong id="cash-opening">PHP 0.00</strong>
                <small id="cash-date">Initial date: --</small>
            </article>
            <article class="summary-card payment-record">
                <span>Expected Cash on Hand</span>
                <strong id="cash-on-hand">PHP 0.00</strong>
                <small id="cash-on-hand-meta">Opening + cash sales</small>
            </article>
            <article class="summary-card payment-record">
                <span>Expected E-Cash on Hand</span>
                <strong id="ecash-on-hand">PHP 0.00</strong>
                <small id="ecash-on-hand-meta">E-cash sales + cash in/out</small>
            </article>
            <article class="summary-card payment-record payment-record--highlight">
                <span>Total Revenue</span>
                <strong id="pay-total-revenue">PHP 0.00</strong>
                <small id="pay-total-meta">All payment methods combined</small>
            </article>
        </div>
    </article>

    <article class="reports-card">
        <h4>Cash Control (Per Store)</h4>
        <div class="cash-control-form">
            <div class="field">
                <label for="cash-channel">Channel</label>
                <select id="cash-channel">
                    <option value="cash">Cash</option>
                    <option value="ecash">E-Cash</option>
                </select>
            </div>
            <div class="field">
                <label for="cash-movement-type">Type</label>
                <select id="cash-movement-type">
                    <option value="cash_in">Cash In</option>
                    <option value="cash_out">Cash Out</option>
                </select>
            </div>
            <div class="field">
                <label for="cash-movement-amount">Amount</label>
                <input id="cash-movement-amount" type="number" min="0.01" step="0.01" placeholder="0.00">
            </div>
            <div class="field field-reason">
                <label for="cash-movement-reason">Reason</label>
                <input id="cash-movement-reason" type="text" maxlength="255" placeholder="e.g. Petty cash top-up">
            </div>
            <button id="cash-movement-save" class="primary-btn" type="button">Save Entry</button>
        </div>

        <div class="table-wrap table-standard-wrap">
            <table class="table table-standard">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Channel</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Reason</th>
                </tr>
                </thead>
                <tbody id="cash-movements-body">
                <tr><td colspan="5">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </article>

    <div class="reports-panels">
        <article class="reports-card">
            <h4>Payment Breakdown</h4>
            <div class="table-wrap table-standard-wrap">
                <table class="table table-standard">
                    <thead>
                    <tr>
                        <th>Payment</th>
                        <th>Transactions</th>
                        <th>Sales</th>
                    </tr>
                    </thead>
                    <tbody id="payment-body">
                    <tr><td colspan="3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="reports-card">
            <h4>Top Products</h4>
            <div class="table-wrap table-standard-wrap">
                <table class="table table-standard">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Revenue</th>
                        <th>Est. Profit</th>
                    </tr>
                    </thead>
                    <tbody id="products-body">
                    <tr><td colspan="4">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </article>
    </div>

    <article class="reports-card">
        <h4>Daily Trend</h4>
        <div class="table-wrap table-standard-wrap">
            <table class="table table-standard">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Transactions</th>
                    <th>Sales</th>
                </tr>
                </thead>
                <tbody id="trend-body">
                <tr><td colspan="3">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </article>

    <p id="reports-note" class="reports-note"></p>
    <p id="reports-result" class="reports-result"></p>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-reports.js') ?>"></script>
<?= $this->endSection() ?>
