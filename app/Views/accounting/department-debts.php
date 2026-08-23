<?= $this->extend('layouts/accounting') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/department-debt.css') ?>?v=20260824d">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $canOperateDepartmentDebt = ibems_current_role() === 'ACCOUNTING_OFFICE'; ?>
<section id="department-accounting-page" class="department-page" data-can-operate="<?= $canOperateDepartmentDebt ? 'true' : 'false' ?>">
    <?= view('components/page_header', [
        'eyebrow' => 'Department financial controls',
        'title' => 'Department debt allocations',
        'description' => $canOperateDepartmentDebt
            ? 'Set monthly department limits, monitor use and outstanding liability, and record settlements separately from employee payroll debt.'
            : 'Inspect monthly department limits, use, outstanding liability, and settlement history in read-only mode.',
        'icon' => 'bi bi-buildings',
    ]) ?>

    <div class="department-toolbar" data-compact-filters>
        <label><span>Allocation month</span><input id="department-period-month" type="month" value="<?= esc(date('Y-m')) ?>"></label>
        <label><span>Search</span><input id="department-accounting-search" type="search" placeholder="Code, department, or head"></label>
        <button id="department-accounting-refresh" class="secondary-btn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
    </div>

    <div class="department-summary-grid department-financial-summary">
        <article><span>Allocated</span><strong id="department-sum-allocation">PHP 0.00</strong></article>
        <article><span>Used this month</span><strong id="department-sum-used">PHP 0.00</strong></article>
        <article><span>Remaining allocation</span><strong id="department-sum-remaining">PHP 0.00</strong></article>
        <article><span>Outstanding liability</span><strong id="department-sum-outstanding">PHP 0.00</strong></article>
    </div>

    <div class="department-table-wrap">
        <table class="table table-standard">
            <thead><tr><th>Department</th><th>Monthly allocation</th><th>Used / remaining</th><th>Outstanding</th><th>Status</th><th><?= $canOperateDepartmentDebt ? 'Actions' : 'Access' ?></th></tr></thead>
            <tbody id="department-accounting-body"><tr><td colspan="6">Loading department accounts...</td></tr></tbody>
        </table>
    </div>

    <article class="department-ledger-panel">
        <header><div><span>Audit-ready history</span><h3>Department account ledger</h3></div></header>
        <div class="department-table-wrap"><table class="table table-standard"><thead><tr><th>Date</th><th>Department</th><th>Entry</th><th>Reference</th><th>Amount</th><th>Outstanding after</th><th>Details</th></tr></thead><tbody id="department-ledger-body"><tr><td colspan="7">Loading ledger...</td></tr></tbody></table></div>
    </article>
    <p id="department-accounting-result" class="department-result" role="status" aria-live="polite"></p>
</section>

<?php if ($canOperateDepartmentDebt): ?>
<div id="department-finance-modal" class="department-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="department-finance-title">
    <form id="department-finance-form" class="department-modal-card department-finance-card">
        <header><div><span id="department-finance-eyebrow">Monthly control</span><h3 id="department-finance-title">Set allocation</h3></div><button id="department-finance-close" class="app-modal-close" type="button" aria-label="Close financial form">&times;</button></header>
        <input id="department-finance-id" type="hidden"><input id="department-finance-period-id" type="hidden"><input id="department-finance-mode" type="hidden">
        <div id="department-allocation-fields" class="department-form-grid">
            <label><span>Month</span><input id="department-finance-month" type="month" required></label>
            <label><span>Allocation amount</span><input id="department-allocation-amount" type="number" min="0" step="0.01" required></label>
            <label><span>Status</span><select id="department-period-status"><option value="open">Open</option><option value="suspended">Suspended</option><option value="closed">Closed</option></select></label>
            <label><span>Reason / notes</span><input id="department-allocation-reason" maxlength="500" placeholder="Required for changes"></label>
        </div>
        <div id="department-settlement-fields" class="department-form-grid is-hidden">
            <label><span>Settlement amount</span><input id="department-settlement-amount" type="number" min="0.01" step="0.01"></label>
            <label><span>Official reference no.</span><input id="department-settlement-reference" maxlength="120"></label>
            <label class="department-wide"><span>Remarks</span><input id="department-settlement-remarks" maxlength="500" placeholder="Payment source and reconciliation note"></label>
        </div>
        <p id="department-finance-result" class="department-result" role="status" aria-live="polite"></p>
        <footer><button id="department-finance-cancel" class="secondary-btn" type="button">Cancel</button><button id="department-finance-save" class="primary-btn" type="submit"><i class="bi bi-check2-circle"></i> Save</button></footer>
    </form>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/department-debt-pages.js') ?>?v=20260824d"></script>
<?= $this->endSection() ?>
