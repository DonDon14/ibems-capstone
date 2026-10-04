<?= $this->extend('layouts/accounting') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/accounting-debts.css') ?>?v=20261004-import-feedback">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="accounting-debt-overview acct-shell space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'Financial controls',
        'title' => 'Accounting debt center',
        'description' => 'Track payroll-deductible debts, direct store payments, and store operator shortages in separate work queues.',
        'icon' => 'bi bi-cash-stack',
    ]) ?>

    <div class="dashboard-grid acct-summary">
        <?= view('components/stat_card', ['title' => 'Employee Records', 'value' => '0', 'valueId' => 'acct-count', 'icon' => 'bi bi-people', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Open Employee Debt', 'value' => 'PHP 0.00', 'valueId' => 'acct-total-debt', 'icon' => 'bi bi-cash-stack', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Today Payroll Deductions', 'value' => '0', 'valueId' => 'acct-today-count', 'icon' => 'bi bi-calendar-check', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Deducted Today', 'value' => 'PHP 0.00', 'valueId' => 'acct-today-amount', 'icon' => 'bi bi-coin', 'tone' => 'finance']) ?>
    </div>

    <article class="dash-panel salary-queue-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" aria-labelledby="acct-salary-queue-heading">
        <div class="acct-section-head">
            <div>
                <h4 id="acct-salary-queue-heading">Scheduled salary changes</h4>
                <p>Accounting must review and explicitly activate each due change. The current salary and credit remain in force until then.</p>
            </div>
            <div class="salary-queue-tools"><span id="acct-salary-due-count" class="text-sm font-semibold text-amber-700">Loading…</span><button id="acct-salary-refresh" type="button" class="secondary-btn btn-sm" disabled>Refresh scheduled salaries</button></div>
        </div>
        <div id="acct-salary-queue-feedback" class="salary-queue-feedback is-pending" role="status" aria-live="polite" aria-atomic="true" tabindex="-1">Loading scheduled changes…</div>
        <div id="acct-salary-queue" class="mt-3 grid gap-2" role="region" aria-label="Scheduled salary records" tabindex="-1" aria-busy="true"></div>
    </article>

    <article class="dash-panel acct-flow-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" aria-label="Accounting debt workflow">
        <div class="acct-section-head">
            <div>
                <h4>How Accounting Uses This Page</h4>
                <p>Each queue represents a different kind of money follow-up. Employee debts affect payroll; store shortages affect store operator accountability.</p>
            </div>
        </div>
        <div class="acct-flow-grid">
            <div class="acct-flow-item">
                <span>1</span>
                <strong>Employee Debts for Payroll</strong>
                <p>Debt purchases from POS increase the employee's balance and become candidates for salary deduction.</p>
            </div>
            <div class="acct-flow-item">
                <span>2</span>
                <strong>Direct Payments Received</strong>
                <p>Payments made directly at a store reduce the employee's balance before payroll is processed.</p>
            </div>
            <div class="acct-flow-item">
                <span>3</span>
                <strong>Store Operator Shortages</strong>
                <p>Approved close-day shortages are recorded separately for the assigned store operator.</p>
            </div>
            <div class="acct-flow-item">
                <span>4</span>
                <strong>Prepare and Confirm Deductions</strong>
                <p>Accounting prepares and reviews the deductions, then confirms them in IBEMS. Only confirmed amounts reduce debt.</p>
            </div>
        </div>
    </article>

    <article class="dash-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="acct-filters acct-filters-redesign space-y-3" data-compact-filters>
        <div class="acct-filters-main flex flex-wrap items-end gap-3">
            <label class="field min-w-[260px] grow" for="acct-search">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Search employees</span>
                <span class="acct-search-wrap relative block">
                    <input id="acct-search" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm text-slate-700 outline-none transition focus:border-blue-300 focus:bg-white" type="search" placeholder="Name, ID, or office">
                </span>
            </label>
            <label class="toggle-check acct-toggle-inline inline-flex h-11 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-700">
                <input id="acct-debt-only" class="h-4 w-4" type="checkbox">
                <span>Debt only</span>
            </label>
            <label class="field min-w-[170px]" for="acct-sort">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Sort</span>
                <select id="acct-sort" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="name:asc">Name A-Z</option>
                    <option value="name:desc">Name Z-A</option>
                    <option value="debt:desc">Highest debt / amount</option>
                    <option value="debt:asc">Lowest debt / amount</option>
                    <option value="credit:desc">Highest credit</option>
                    <option value="date:desc">Newest activity</option>
                </select>
            </label>
            <label class="field min-w-[110px]" for="acct-page-size">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Rows</span>
                <select id="acct-page-size" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
            </label>
        </div>
        <div class="acct-filters-actions flex flex-wrap gap-2">
            <div class="action-group action-group-primary">
                <button id="acct-refresh-btn" class="secondary-btn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            </div>
            <div class="action-group action-group-tools flex flex-wrap gap-2">
                <a class="primary-btn" href="<?= site_url('deductions/workspace') ?>"><i class="bi bi-diagram-3"></i> Shared Deduction Workflow</a>
                <button id="open-debt-investigations" class="secondary-btn" type="button"><i class="bi bi-shield-check"></i> Investigations</button>
                <button id="open-settlement-run" class="secondary-btn" type="button"><i class="bi bi-clock-history"></i> Legacy History</button>
                <button id="open-import-csv" class="secondary-btn" type="button"><i class="bi bi-file-earmark-arrow-up"></i> Import HR CSV</button>
            </div>
        </div>
    </div>
    </article>

    <article class="dash-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="acct-tabs" role="tablist" aria-label="Accounting debt sections">
        <button class="acct-tab is-active" type="button" id="acct-tab-employee-debts" aria-controls="acct-record-panel" data-acct-tab="employee-debts" role="tab" tabindex="0" aria-selected="true"><i class="bi bi-bag-check"></i> Employee Accounts <span id="acct-tab-employee-count">0</span></button>
        <button class="acct-tab" type="button" id="acct-tab-advance-payments" aria-controls="acct-record-panel" data-acct-tab="advance-payments" role="tab" tabindex="-1" aria-selected="false"><i class="bi bi-wallet2"></i> Direct Payments Received <span id="acct-tab-advance-count">0</span></button>
        <button class="acct-tab" type="button" id="acct-tab-operator-accountabilities" aria-controls="acct-record-panel" data-acct-tab="operator-accountabilities" role="tab" tabindex="-1" aria-selected="false"><i class="bi bi-exclamation-octagon"></i> Store Operator Shortages <span id="acct-tab-operator-count">0</span></button>
    </div>
    <div id="acct-record-panel" class="acct-table-wrap table-standard-wrap" role="tabpanel" aria-labelledby="acct-tab-employee-debts" tabindex="0">
        <p id="acct-count-text" class="acct-count-text text-sm text-slate-500" role="status" aria-live="polite" aria-atomic="true">Showing 0 records</p>
        <div id="acct-body" class="acct-record-list grid gap-2">
            <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading accounting records...']) ?>
        </div>
    </div>
    </article>

    <p id="acct-result" class="acct-result text-sm font-semibold"></p>
    <div id="acct-pager" class="overview-pager" aria-label="Accounting record pages"></div>
</section>

<?= view('components/accounting_debt_modals') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/accounting-debts.js') ?>?v=20261004-import-feedback"></script>
<script src="<?= base_url('assets/js/accounting-debts.part2.js') ?>?v=20261004-import-feedback"></script>
<script src="<?= base_url('assets/js/accounting-import.js') ?>?v=20261004-import-feedback"></script>
<script src="<?= base_url('assets/js/accounting-legacy-history.js') ?>?v=20261004-import-feedback"></script>
<script src="<?= base_url('assets/js/accounting-employee-actions.js') ?>?v=20261004-import-feedback"></script>
<script src="<?= base_url('assets/js/accounting-investigation-reads.js') ?>?v=20261004-import-feedback"></script>
<script src="<?= base_url('assets/js/accounting-investigation-actions.js') ?>?v=20261004-import-feedback"></script>
<script src="<?= base_url('assets/js/accounting-debts.part3.js') ?>?v=20261004-import-feedback"></script>
<?= $this->endSection() ?>
