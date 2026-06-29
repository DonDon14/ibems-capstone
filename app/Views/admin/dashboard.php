<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <div class="dashboard-title">
        <h3>Admin Dashboard</h3>
        <p>School-wide operations snapshot and quick controls.</p>
    </div>

    <div class="dashboard-grid">
        <?= view('components/stat_card', ['title' => 'Total Stores', 'value' => '0', 'valueId' => 'ad-total-stores', 'icon' => 'bi bi-shop-window', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Active Store Officers', 'value' => '0', 'valueId' => 'ad-active-store-officers', 'icon' => 'bi bi-person-badge', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Debt Accounts', 'value' => '0', 'valueId' => 'ad-debt-accounts', 'icon' => 'bi bi-cash-stack', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Open Alerts', 'value' => '0', 'valueId' => 'ad-open-alerts', 'icon' => 'bi bi-bell', 'tone' => 'warning']) ?>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <h4><i class="bi bi-heart-pulse"></i> Operations Health</h4>
            <p id="ad-health-message">Loading operations status...</p>
            <p id="ad-health-breakdown" class="dash-health-breakdown"></p>
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

    <div class="dash-panels">
        <article class="dash-panel">
            <h4><i class="bi bi-graph-up-arrow"></i> Revenue over Time (Last 7 Days)</h4>
            <div class="dash-chart-wrap">
                <canvas id="ad-sales-trend-chart"></canvas>
            </div>
        </article>
        <article class="dash-panel">
            <h4><i class="bi bi-bar-chart-fill"></i> Top Selling Items (Last 7 Days)</h4>
            <div class="dash-chart-wrap">
                <canvas id="ad-top-items-chart"></canvas>
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
                    <tr><td colspan="3">Loading top stores...</td></tr>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= base_url('assets/js/admin-dashboard.js') ?>"></script>
<?= $this->endSection() ?>
