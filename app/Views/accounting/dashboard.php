<?= $this->extend('layouts/accounting') ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <div class="dashboard-title">
        <h3>Accounting Dashboard</h3>
        <p>Employee debt, governed payroll deductions, and correction monitoring.</p>
    </div>

    <div class="dashboard-grid">
        <?= view('components/stat_card', ['title' => 'Total Accounts', 'value' => '-', 'valueId' => 'acd-total-accounts', 'icon' => 'bi bi-people', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'With Debt', 'value' => '-', 'valueId' => 'acd-with-debt', 'icon' => 'bi bi-person-badge', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Total Debt', 'value' => 'PHP 0.00', 'valueId' => 'acd-total-debt', 'icon' => 'bi bi-cash-coin', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Today Deducted', 'value' => 'PHP 0.00', 'valueId' => 'acd-today-deducted', 'icon' => 'bi bi-calendar-check', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Over Limit', 'value' => '0', 'valueId' => 'acd-over-limit', 'icon' => 'bi bi-exclamation-triangle', 'tone' => 'warning']) ?>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <h4><i class="bi bi-activity"></i> Debt Deduction Trend (7 Days)</h4>
            <p>Daily amount from confirmed payroll deduction results.</p>
            <div class="dash-chart-wrap">
                <canvas id="acd-trend-chart"></canvas>
            </div>
        </article>
        <article class="dash-panel action-quick-panel">
            <h4><i class="bi bi-lightning-charge"></i> Quick Access</h4>
            <div class="quick-links action-quick-links">
                <a href="<?= site_url('accounting/debts') ?>">
                    <span class="quick-link-icon"><i class="bi bi-search"></i></span>
                    <span class="quick-link-copy">
                        <strong>Open Debt Monitoring</strong>
                        <small>Review employee balances, credit limits, and payment history</small>
                    </span>
                    <i class="bi bi-arrow-right quick-link-arrow"></i>
                </a>
                <a href="<?= site_url('accounting/debts') ?>">
                    <span class="quick-link-icon"><i class="bi bi-diagram-3"></i></span>
                    <span class="quick-link-copy">
                        <strong>Open Deduction Workflow</strong>
                        <small>Prepare, reconcile, and finalize salary-period deductions</small>
                    </span>
                    <i class="bi bi-arrow-right quick-link-arrow"></i>
                </a>
                <a href="<?= site_url('accounting/debts') ?>">
                    <span class="quick-link-icon"><i class="bi bi-file-earmark-arrow-up"></i></span>
                    <span class="quick-link-copy">
                        <strong>Import HR CSV</strong>
                        <small>Preview employee records before applying controlled updates</small>
                    </span>
                    <i class="bi bi-arrow-right quick-link-arrow"></i>
                </a>
            </div>
            <p id="acd-last-settlement" class="muted mt-10">Latest deduction period: -</p>
        </article>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <h4><i class="bi bi-clock-history"></i> Recent Accounting Activity</h4>
            <div id="acd-activity" class="stack-list"></div>
        </article>
        <article class="dash-panel">
            <h4><i class="bi bi-exclamation-circle"></i> Top Debt Accounts</h4>
            <div class="table-standard-wrap mt-10">
                <table class="table table-standard">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Email</th>
                            <th>Current Debt</th>
                            <th>Credit Limit</th>
                        </tr>
                    </thead>
                    <tbody id="acd-top-debt-body">
                        <tr><td colspan="4">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </article>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <h4><i class="bi bi-exclamation-triangle"></i> Accounting Alerts</h4>
            <div id="acd-alerts" class="stack-list"></div>
        </article>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= base_url('assets/js/accounting-dashboard.js') ?>"></script>
<?= $this->endSection() ?>
