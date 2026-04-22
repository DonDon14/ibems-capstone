<?= $this->extend('layouts/accounting') ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <div class="dashboard-title">
        <h3>Accounting Dashboard</h3>
        <p>Debt settlement and payroll-linked deduction monitoring.</p>
    </div>

    <div class="dashboard-grid">
        <article class="dash-card"><span>Total Accounts</span><strong>0</strong></article>
        <article class="dash-card"><span>Total Debt</span><strong>PHP 0.00</strong></article>
        <article class="dash-card"><span>Today Deductions</span><strong>0</strong></article>
        <article class="dash-card"><span>Last Settlement</span><strong>-</strong></article>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <h4>Settlement Readiness</h4>
            <p>Use Debt Monitoring to run settlement preview and apply monthly settlement safely with audit logs.</p>
        </article>
        <article class="dash-panel">
            <h4>Quick Access</h4>
            <div class="quick-links">
                <a href="/accounting/debts">Open Debt Monitoring</a>
                <a href="/accounting/debts">Run Settlement Preview</a>
                <a href="/accounting/debts">Import HR CSV</a>
            </div>
        </article>
    </div>
</section>
<?= $this->endSection() ?>
