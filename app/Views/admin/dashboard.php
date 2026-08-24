<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Administration',
        'title' => 'Operations overview',
        'description' => 'Monitor stores, people, debt exposure, and operational exceptions.',
        'icon' => 'bi bi-grid-1x2',
    ]) ?>

    <div class="dashboard-grid">
        <?= view('components/stat_card', ['title' => 'Total Stores', 'value' => '0', 'valueId' => 'ad-total-stores', 'icon' => 'bi bi-shop-window', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Active Store Officers', 'value' => '0', 'valueId' => 'ad-active-store-officers', 'icon' => 'bi bi-person-badge', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Debt Accounts', 'value' => '0', 'valueId' => 'ad-debt-accounts', 'icon' => 'bi bi-cash-stack', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Open Alerts', 'value' => '0', 'valueId' => 'ad-open-alerts', 'icon' => 'bi bi-bell', 'tone' => 'warning']) ?>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <h4><i class="bi bi-heart-pulse"></i> Operations Health</h4>
            <p id="ad-health-message" class="dashboard-health-message">Loading operations status...</p>
            <p id="ad-health-breakdown" class="dash-health-breakdown"></p>
            <div id="ad-store-day-status" class="admin-status-grid"></div>
        </article>
        <article class="dash-panel admin-quick-panel">
            <h4><i class="bi bi-lightning-charge"></i> Quick Access</h4>
            <div class="quick-links admin-quick-links">
                <a href="<?= site_url('admin/stores') ?>">
                    <span class="quick-link-icon"><i class="bi bi-shop"></i></span>
                    <span class="quick-link-copy">
                        <strong>Manage Stores & POS</strong>
                        <small>Store setup, officer assignment, and POS access</small>
                    </span>
                    <i class="bi bi-arrow-right quick-link-arrow"></i>
                </a>
                <a href="<?= site_url('admin/accounting-debts') ?>">
                    <span class="quick-link-icon"><i class="bi bi-wallet2"></i></span>
                    <span class="quick-link-copy">
                        <strong>Review Accounting Debts</strong>
                        <small>Debt accounts, balances, and collection status</small>
                    </span>
                    <i class="bi bi-arrow-right quick-link-arrow"></i>
                </a>
                <a href="<?= site_url('admin/user-view') ?>">
                    <span class="quick-link-icon"><i class="bi bi-people"></i></span>
                    <span class="quick-link-copy">
                        <strong>Manage Users</strong>
                        <small>Profiles, roles, salary, and credit limits</small>
                    </span>
                    <i class="bi bi-arrow-right quick-link-arrow"></i>
                </a>
            </div>
        </article>
    </div>

    <div class="dash-panels admin-insight-panels">
        <article class="dash-panel dashboard-alert-panel">
            <div class="dashboard-panel-heading">
                <div>
                    <h4><i class="bi bi-exclamation-triangle"></i> Operational Alerts</h4>
                    <p>Prioritized exceptions requiring administrator review.</p>
                </div>
                <span id="ad-alerts-summary" class="dashboard-panel-meta" aria-live="polite">Loading...</span>
            </div>
            <div id="ad-alerts-list" class="admin-alert-list">
                <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading alerts...']) ?>
            </div>
            <nav id="ad-alerts-pager" class="overview-pager dashboard-alert-pager" aria-label="Operational alert pages"></nav>
        </article>
        <article class="dash-panel dashboard-payment-panel">
            <div class="dashboard-panel-heading">
                <div>
                    <h4><i class="bi bi-credit-card-2-front"></i> Payment Breakdown</h4>
                    <p>Collected payment mix for the last seven days.</p>
                </div>
                <span class="dashboard-panel-meta">7 days</span>
            </div>
            <div id="ad-payment-breakdown" class="admin-payment-list">
                <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading payment breakdown...']) ?>
            </div>
        </article>
    </div>

    <div class="dash-panels dashboard-analytics-grid">
        <article class="dash-panel dashboard-chart-panel">
            <div class="dashboard-panel-heading">
                <div>
                    <h4><i class="bi bi-graph-up-arrow"></i> Revenue trend</h4>
                    <p>Daily collected revenue across all stores.</p>
                </div>
                <span class="dashboard-panel-meta">Last 7 days</span>
            </div>
            <div class="dashboard-chart-summary" aria-live="polite">
                <div>
                    <span>7-day revenue</span>
                    <strong id="ad-sales-total">PHP 0.00</strong>
                </div>
                <p id="ad-sales-peak">No revenue recorded yet.</p>
            </div>
            <div class="dash-chart-wrap dashboard-revenue-chart">
                <canvas id="ad-sales-trend-chart" role="img" aria-label="Revenue collected during the last seven days" aria-describedby="ad-sales-chart-summary">
                    Revenue trend chart for the last seven days.
                </canvas>
            </div>
            <p id="ad-sales-chart-summary" class="sr-only">Revenue data is loading.</p>
        </article>
        <article class="dash-panel dashboard-ranking-panel">
            <div class="dashboard-panel-heading">
                <div>
                    <h4><i class="bi bi-bar-chart-fill"></i> Top-selling items</h4>
                    <p>Products ranked by units sold, with sales value.</p>
                </div>
                <span class="dashboard-panel-meta">Last 7 days</span>
            </div>
            <div id="ad-top-items-ranking" class="dashboard-ranking-list" aria-live="polite" aria-busy="true">
                <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading product ranking...']) ?>
            </div>
        </article>
    </div>

    <article class="dash-panel">
        <h4><i class="bi bi-trophy"></i> Top Stores (Last 7 Days)</h4>
        <div class="table-standard-wrap">
            <table class="table table-standard">
                <thead>
                    <tr>
                        <th>Store</th>
                        <th>Transactions</th>
                        <th>Sales</th>
                    </tr>
                </thead>
                <tbody id="ad-top-stores-body">
                    <?= view('components/data_state', [
                        'tag' => 'tr',
                        'colspan' => 3,
                        'type' => 'loading',
                        'message' => 'Loading top stores...',
                    ]) ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= base_url('assets/js/admin-dashboard.js') ?>?v=20260824a"></script>
<?= $this->endSection() ?>
