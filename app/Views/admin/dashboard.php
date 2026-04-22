<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <div class="dashboard-title">
        <h3>Admin Dashboard</h3>
        <p>School-wide operations snapshot and quick controls.</p>
    </div>

    <div class="dashboard-grid">
        <article class="dash-card"><span>Total Stores</span><strong>4</strong></article>
        <article class="dash-card"><span>Active Store Officers</span><strong>4</strong></article>
        <article class="dash-card"><span>Debt Accounts</span><strong>0</strong></article>
        <article class="dash-card"><span>Open Alerts</span><strong>0</strong></article>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <h4>Operations Health</h4>
            <p>All core modules are online. Continue monitoring store activity and accounting actions daily.</p>
        </article>
        <article class="dash-panel">
            <h4>Quick Access</h4>
            <div class="quick-links">
                <a href="/admin/stores">Manage Stores & POS</a>
                <a href="/admin/accounting-debts">Review Accounting Debts</a>
                <a href="/admin/user-view">Manage Users</a>
            </div>
        </article>
    </div>
</section>
<?= $this->endSection() ?>
