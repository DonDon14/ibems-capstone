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
            <div class="reports-store-wrap">
                <label for="reports-store-select">Store</label>
                <select id="reports-store-select"></select>
            </div>
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
