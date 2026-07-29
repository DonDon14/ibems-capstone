<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <div class="dashboard-title">
        <h3>User Dashboard</h3>
        <p>Welcome, <?= esc(session()->get('name') ?? 'User') ?>.</p>
    </div>

    <div class="dashboard-grid dashboard-grid-single">
        <article id="u-debt-status-card" class="user-status-card user-status-card--info">
            <div>
                <span id="u-debt-status-label" class="user-status-badge">Loading</span>
                <h4 id="u-debt-status-title">Debt Status</h4>
                <p id="u-debt-status-message">Checking your current debt balance.</p>
            </div>
            <a href="<?= site_url('user/history') ?>" class="history-action">
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
                <i id="u-credit-meter-fill" class="credit-meter-fill" style="width: 0%;"></i>
            </div>
            <small id="u-credit-meter-status">Remaining: PHP 0.00</small>
        </article>
    </div>

    <article class="dash-panel user-security-panel">
        <div class="user-security-copy">
            <h4><i class="bi bi-shield-lock"></i> Debt Authorization PIN</h4>
            <p id="u-debt-pin-status">Checking PIN status...</p>
        </div>
        <button id="u-open-pin-modal" type="button" class="primary-btn">
            <i class="bi bi-key"></i> Set PIN
        </button>
    </article>

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
            <div class="dash-chart-wrap">
                <canvas id="u-trend-chart"></canvas>
            </div>
        </article>
        <article class="dash-panel">
            <h4><i class="bi bi-clock-history"></i> Recent Debt Cashbook</h4>
            <div id="u-recent-cashbook" class="stack-list"></div>
        </article>
    </div>

    <div class="dash-panel">
        <h4><i class="bi bi-receipt"></i> Recent Transactions</h4>
        <div class="table-standard-wrap mt-10">
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
                    <tr><td colspan="5">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div id="u-debt-pin-modal" class="app-modal is-hidden">
    <div class="app-modal-card">
        <div class="app-modal-head">
            <h4><i class="bi bi-shield-lock"></i> Debt Authorization PIN</h4>
            <button id="u-pin-modal-close" type="button" class="app-modal-close">x</button>
        </div>
        <form id="u-debt-pin-form" class="user-pin-form">
            <p id="u-pin-form-help">Use a 4 to 6 digit PIN. Stores will ask for this only when charging purchases to debt.</p>
            <label id="u-current-password-wrap" class="user-pin-field is-hidden">
                <span>Current Password</span>
                <input id="u-current-password" name="current_password" type="password" autocomplete="current-password">
            </label>
            <label class="user-pin-field">
                <span>New Debt PIN</span>
                <input id="u-debt-pin" name="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" required>
            </label>
            <label class="user-pin-field">
                <span>Confirm Debt PIN</span>
                <input id="u-debt-pin-confirm" name="pin_confirm" type="password" inputmode="numeric" maxlength="6" autocomplete="off" required>
            </label>
            <p id="u-pin-form-result" class="user-pin-result"></p>
            <div class="app-modal-actions">
                <button id="u-pin-modal-cancel" type="button" class="scan-btn">Cancel</button>
                <button id="u-save-pin" type="submit" class="primary-btn"><i class="bi bi-check2-circle"></i> Save PIN</button>
            </div>
        </form>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= base_url('assets/js/user-dashboard.js') ?>"></script>
<?= $this->endSection() ?>
