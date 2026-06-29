<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-reports.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="dashboard-shell reports-shell">
    <div class="dashboard-title reports-top">
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

    <div class="dashboard-grid reports-summary-grid">
        <?= view('components/stat_card', ['title' => 'Total Sales', 'value' => 'PHP 0.00', 'valueId' => 'sum-sales', 'icon' => 'bi bi-graph-up-arrow', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Estimated Cost', 'value' => 'PHP 0.00', 'valueId' => 'sum-cost', 'icon' => 'bi bi-bag', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Estimated Profit', 'value' => 'PHP 0.00', 'valueId' => 'sum-profit', 'icon' => 'bi bi-cash-coin', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Profit Margin', 'value' => '0.00%', 'valueId' => 'sum-margin', 'icon' => 'bi bi-percent', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Transactions', 'value' => '0', 'valueId' => 'sum-transactions', 'icon' => 'bi bi-receipt', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Items Sold', 'value' => '0', 'valueId' => 'sum-items', 'icon' => 'bi bi-box-seam', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Average Ticket', 'value' => 'PHP 0.00', 'valueId' => 'sum-ticket', 'icon' => 'bi bi-ticket-perforated', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Stock-in Cost', 'value' => 'PHP 0.00', 'valueId' => 'sum-stockin-cost', 'icon' => 'bi bi-cart-plus', 'tone' => 'warning']) ?>
    </div>

    <div class="dash-panels reports-panels-top">
        <article class="dash-panel">
            <h4><i class="bi bi-bar-chart"></i> Sales Trend</h4>
            <p id="reports-range-label" class="muted">Selected period trend.</p>
            <div class="dash-chart-wrap">
                <canvas id="reports-trend-chart"></canvas>
            </div>
        </article>

        <article class="dash-panel">
            <h4><i class="bi bi-pie-chart"></i> Payment Mix</h4>
            <p class="muted">Share of payment methods in selected period.</p>
            <div class="dash-chart-wrap">
                <canvas id="reports-payment-mix-chart"></canvas>
            </div>
        </article>
    </div>

    <article class="dash-panel reports-card">
        <h4><i class="bi bi-cash-stack"></i> Payment Records</h4>
        <div class="payment-records-grid">
            <?= view('components/stat_card', ['title' => 'Cash Sales', 'value' => 'PHP 0.00', 'valueId' => 'pay-cash', 'icon' => 'bi bi-cash', 'tone' => 'sales', 'meta' => '0 transactions', 'metaId' => 'pay-cash-meta', 'class' => 'payment-record']) ?>
            <?= view('components/stat_card', ['title' => 'GCash Sales', 'value' => 'PHP 0.00', 'valueId' => 'pay-gcash', 'icon' => 'bi bi-phone', 'tone' => 'finance', 'meta' => '0 transactions', 'metaId' => 'pay-gcash-meta', 'class' => 'payment-record']) ?>
            <?= view('components/stat_card', ['title' => 'Other Payments', 'value' => 'PHP 0.00', 'valueId' => 'pay-others', 'icon' => 'bi bi-three-dots', 'tone' => 'finance', 'meta' => '0 transactions', 'metaId' => 'pay-others-meta', 'class' => 'payment-record']) ?>
            <?= view('components/stat_card', ['title' => 'Debt Payments', 'value' => 'PHP 0.00', 'valueId' => 'pay-debt', 'icon' => 'bi bi-credit-card', 'tone' => 'debt', 'meta' => '0 transactions', 'metaId' => 'pay-debt-meta', 'class' => 'payment-record']) ?>
            <?= view('components/stat_card', ['title' => 'Opening Cash', 'value' => 'PHP 0.00', 'valueId' => 'cash-opening', 'icon' => 'bi bi-calendar2-plus', 'tone' => 'warning', 'meta' => 'Business date: --', 'metaId' => 'cash-date', 'class' => 'payment-record']) ?>
            <?= view('components/stat_card', ['title' => 'Expected Cash on Hand', 'value' => 'PHP 0.00', 'valueId' => 'cash-on-hand', 'icon' => 'bi bi-safe2', 'tone' => 'sales', 'meta' => 'Opening + cash sales', 'metaId' => 'cash-on-hand-meta', 'class' => 'payment-record']) ?>
            <?= view('components/stat_card', ['title' => 'Expected E-Cash on Hand', 'value' => 'PHP 0.00', 'valueId' => 'ecash-on-hand', 'icon' => 'bi bi-wallet2', 'tone' => 'finance', 'meta' => 'E-cash sales + cash in/out', 'metaId' => 'ecash-on-hand-meta', 'class' => 'payment-record']) ?>
            <?= view('components/stat_card', ['title' => 'Total Revenue', 'value' => 'PHP 0.00', 'valueId' => 'pay-total-revenue', 'icon' => 'bi bi-stars', 'tone' => 'sales', 'meta' => 'All payment methods combined', 'metaId' => 'pay-total-meta', 'class' => 'payment-record payment-record--highlight']) ?>
        </div>
    </article>

    <article class="dash-panel reports-card">
        <h4><i class="bi bi-safe2"></i> Cash Control (Per Store)</h4>
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
        <article class="dash-panel reports-card">
            <h4><i class="bi bi-list-check"></i> Payment Breakdown</h4>
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

        <article class="dash-panel reports-card">
            <h4><i class="bi bi-stars"></i> Top Products</h4>
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

    <article class="dash-panel reports-card">
        <h4><i class="bi bi-calendar3"></i> Daily Trend</h4>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= base_url('assets/js/store-reports.js') ?>"></script>
<?= $this->endSection() ?>
