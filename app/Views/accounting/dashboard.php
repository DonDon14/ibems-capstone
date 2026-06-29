<?= $this->extend('layouts/accounting') ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <div class="dashboard-title">
        <h3>Accounting Dashboard</h3>
        <p>Debt settlement and payroll-linked deduction monitoring.</p>
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
            <p>Daily deduction amount from manual deductions and settlement runs.</p>
            <div class="dash-chart-wrap">
                <canvas id="acd-trend-chart"></canvas>
            </div>
        </article>
        <article class="dash-panel">
            <h4><i class="bi bi-lightning-charge"></i> Quick Access</h4>
            <div class="quick-links">
                <a href="/accounting/debts"><i class="bi bi-search"></i> Open Debt Monitoring</a>
                <a href="/accounting/debts"><i class="bi bi-calculator"></i> Run Settlement Preview</a>
                <a href="/accounting/debts"><i class="bi bi-file-earmark-arrow-up"></i> Import HR CSV</a>
            </div>
            <p id="acd-last-settlement" class="muted mt-10">Last settlement: -</p>
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
