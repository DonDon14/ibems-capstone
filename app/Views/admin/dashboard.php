<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <div class="dashboard-title">
        <h3>Admin Dashboard</h3>
        <p>School-wide operations snapshot and quick controls.</p>
    </div>

    <div class="dashboard-grid">
        <article class="dash-card">
            <div class="dash-card-head">
                <span>Total Stores</span>
                <i class="bi bi-shop-window" aria-hidden="true"></i>
            </div>
            <strong>4</strong>
        </article>
        <article class="dash-card">
            <div class="dash-card-head">
                <span>Active Store Officers</span>
                <i class="bi bi-person-badge" aria-hidden="true"></i>
            </div>
            <strong>4</strong>
        </article>
        <article class="dash-card">
            <div class="dash-card-head">
                <span>Debt Accounts</span>
                <i class="bi bi-cash-stack" aria-hidden="true"></i>
            </div>
            <strong>0</strong>
        </article>
        <article class="dash-card">
            <div class="dash-card-head">
                <span>Open Alerts</span>
                <i class="bi bi-bell" aria-hidden="true"></i>
            </div>
            <strong>0</strong>
        </article>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <h4><i class="bi bi-heart-pulse"></i> Operations Health</h4>
            <p>All core modules are online. Continue monitoring store activity and accounting actions daily.</p>
        </article>
        <article class="dash-panel">
            <h4><i class="bi bi-lightning-charge"></i> Quick Access</h4>
            <div class="quick-links">
                <a href="/admin/stores"><i class="bi bi-shop"></i>Manage Stores & POS</a>
                <a href="/admin/accounting-debts"><i class="bi bi-wallet2"></i>Review Accounting Debts</a>
                <a href="/admin/user-view"><i class="bi bi-people"></i>Manage Users</a>
            </div>
        </article>
    </div>
</section>
<?= $this->endSection() ?>
