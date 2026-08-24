<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>?v=20260824a" data-user-page-style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Personal account',
        'title' => 'User dashboard',
        'description' => 'Welcome, ' . (string) (session()->get('name') ?? 'User') . '.',
        'imageUrl' => ibems_profile_image_url(session()->get('profile_image_url')),
        'imageAlt' => (string) (session()->get('name') ?? 'User') . ' profile picture',
        'imageSize' => 'large',
    ]) ?>

    <div class="dashboard-grid dashboard-grid-single">
        <article id="u-debt-status-card" class="user-status-card user-status-card--info">
            <div>
                <span id="u-debt-status-label" class="user-status-badge">Loading</span>
                <h4 id="u-debt-status-title">Debt Status</h4>
                <p id="u-debt-status-message">Checking your current debt balance.</p>
            </div>
            <a href="<?= site_url('user/history') ?>" class="secondary-btn">
                <i class="bi bi-clock-history"></i> View History
            </a>
        </article>

        <article class="metric-card credit-meter-card">
            <div class="metric-card-head">
                <span class="metric-card-label">Credit Usage</span>
                <span class="metric-card-icon metric-card-icon--finance" aria-hidden="true">
                    <i class="bi bi-speedometer2"></i>
                </span>
            </div>
            <strong id="u-credit-used-percent">0%</strong>
            <small id="u-credit-used-amount">Used: PHP 0.00 of PHP 0.00</small>
            <div class="credit-meter-track" aria-hidden="true">
                <i id="u-credit-meter-fill" class="credit-meter-fill"></i>
            </div>
            <small id="u-credit-meter-status">Remaining: PHP 0.00</small>
        </article>
    </div>

    <div class="dashboard-grid">
        <?= view('components/stat_card', ['title' => 'Credit Limit', 'value' => 'PHP 0.00', 'valueId' => 'u-credit-limit', 'icon' => 'bi bi-wallet2', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Current Debt', 'value' => 'PHP 0.00', 'valueId' => 'u-current-debt', 'icon' => 'bi bi-credit-card-2-front', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Available Credit', 'value' => 'PHP 0.00', 'valueId' => 'u-available-credit', 'icon' => 'bi bi-cash-coin', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Total Purchases', 'value' => 'PHP 0.00', 'valueId' => 'u-total-spent', 'icon' => 'bi bi-bag-check', 'tone' => 'finance']) ?>
    </div>

    <div class="dashboard-grid dashboard-grid-three">
        <?= view('components/stat_card', ['title' => 'Debt Purchases Total', 'value' => 'PHP 0.00', 'valueId' => 'u-debt-added-total', 'icon' => 'bi bi-plus-circle', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Debt Deductions Total', 'value' => 'PHP 0.00', 'valueId' => 'u-debt-deducted-total', 'icon' => 'bi bi-dash-circle', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Total Transactions', 'value' => '0', 'valueId' => 'u-txn-count', 'icon' => 'bi bi-receipt-cutoff', 'tone' => 'users']) ?>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <h4><i class="bi bi-graph-up-arrow"></i> Spending Trend (7 Days)</h4>
            <p>Daily purchases across all stores.</p>
            <div class="dash-chart-wrap user-chart-wrap">
                <canvas id="u-trend-chart" class="is-hidden" aria-label="Spending trend for the last seven days" role="img"></canvas>
                <div id="u-trend-chart-state" class="user-chart-state" role="status" aria-live="polite">
                    <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading spending trend...']) ?>
                </div>
            </div>
        </article>
        <article class="dash-panel">
            <h4><i class="bi bi-clock-history"></i> Recent Debt Cashbook</h4>
            <div id="u-recent-cashbook" class="stack-list">
                <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading recent cashbook...']) ?>
            </div>
        </article>
    </div>

    <div class="dash-panel">
        <h4><i class="bi bi-receipt"></i> Recent Transactions</h4>
        <div class="table-standard-wrap user-mobile-card-table mt-10">
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
                <tbody id="u-recent-transactions-body">
                    <?= view('components/data_state', ['tag' => 'tr', 'colspan' => 5, 'type' => 'loading', 'message' => 'Loading recent transactions...']) ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" data-user-page-script data-user-page-once></script>
<script src="<?= base_url('assets/js/user-dashboard.js') ?>?v=20260824a" data-user-page-script></script>
<?= $this->endSection() ?>
