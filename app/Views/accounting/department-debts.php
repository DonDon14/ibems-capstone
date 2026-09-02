<?= $this->extend('layouts/accounting') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/department-debt.css') ?>?v=20260824g">
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
        <label for="department-period-month"><span>Allocation month</span><input id="department-period-month" type="month" value="<?= esc(substr(ibems_business_date(), 0, 7)) ?>" aria-label="Allocation month"></label>
        <label for="department-accounting-search"><span>Search</span><input id="department-accounting-search" type="search" placeholder="Code, department, or head"></label>
        <button id="department-accounting-refresh" class="secondary-btn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        <?php if ($canOperateDepartmentDebt): ?><button id="department-batch-allocation-open" class="primary-btn" type="button"><i class="bi bi-collection"></i> Batch allocation</button><?php endif; ?>
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
    <form id="department-finance-form" class="department-modal-card department-finance-card" aria-describedby="department-finance-context department-finance-result">
        <header><div><span id="department-finance-eyebrow">Monthly control</span><h3 id="department-finance-title">Set allocation</h3></div><button id="department-finance-close" class="app-modal-close" type="button" aria-label="Close financial form">&times;</button></header>
        <div class="department-modal-scroll">
            <input id="department-finance-id" type="hidden"><input id="department-finance-period-id" type="hidden"><input id="department-finance-mode" type="hidden">
            <p id="department-finance-context" class="department-finance-context">Set the monthly spending ceiling and account availability.</p>
            <div id="department-allocation-fields" class="department-form-grid">
                <label for="department-finance-month"><span>Month</span><input id="department-finance-month" type="month" required aria-label="Allocation month"></label>
                <label for="department-allocation-amount"><span>Allocation amount</span><input id="department-allocation-amount" type="number" min="0" step="0.01" required><small>Cannot be lower than the amount already used.</small></label>
                <label for="department-period-status"><span>Status</span><select id="department-period-status"><option value="open">Open for purchases</option><option value="suspended">Suspended</option><option value="closed">Closed</option></select></label>
                <label for="department-allocation-reason"><span>Reason / notes</span><input id="department-allocation-reason" maxlength="500" placeholder="Explain allocation or status changes"><small>Required when changing an existing allocation or status.</small></label>
            </div>
            <div id="department-settlement-fields" class="department-form-grid is-hidden">
                <label for="department-settlement-amount"><span>Settlement amount</span><input id="department-settlement-amount" type="number" min="0.01" step="0.01" required disabled></label>
                <label for="department-settlement-reference"><span>Official reference no.</span><input id="department-settlement-reference" maxlength="120" required disabled></label>
                <label class="department-wide" for="department-settlement-remarks"><span>Remarks</span><input id="department-settlement-remarks" maxlength="500" placeholder="Payment source and reconciliation note" required disabled></label>
            </div>
            <p id="department-finance-result" class="department-result" role="status" aria-live="polite"></p>
        </div>
        <footer><button id="department-finance-cancel" class="secondary-btn" type="button">Cancel</button><button id="department-finance-save" class="primary-btn" type="submit"><i class="bi bi-check2-circle"></i> <span>Save allocation</span></button></footer>
    </form>
</div>

<div id="department-batch-allocation-modal" class="department-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="department-batch-allocation-title">
    <form id="department-batch-allocation-form" class="department-modal-card department-batch-card" aria-describedby="department-batch-allocation-context department-batch-allocation-result">
        <header><div><span>Batch entry</span><h3 id="department-batch-allocation-title">Allocate to multiple departments</h3></div><button id="department-batch-allocation-close" class="app-modal-close" type="button" aria-label="Close batch allocation form">&times;</button></header>
        <div class="department-modal-scroll">
            <p id="department-batch-allocation-context" class="department-finance-context">The same amount and account status will be applied to every selected department. The complete batch succeeds or no department is changed.</p>
            <section class="department-batch-picker" aria-labelledby="department-batch-picker-label">
                <div class="department-batch-picker-heading">
                    <div><span id="department-batch-picker-label" class="department-field-label">Departments</span><strong id="department-batch-selected-count">0 selected</strong></div>
                    <div class="department-batch-picker-actions"><button id="department-batch-select-all" class="secondary-btn" type="button">Select active</button><button id="department-batch-clear" class="secondary-btn" type="button">Clear</button></div>
                </div>
                <label class="department-batch-search" for="department-batch-search"><i class="bi bi-search" aria-hidden="true"></i><input id="department-batch-search" type="search" placeholder="Search department, code, or head" autocomplete="off"></label>
                <div id="department-batch-options" class="department-batch-options" role="group" aria-label="Departments to receive this allocation"></div>
            </section>
            <div class="department-form-grid department-batch-fields">
                <label for="department-batch-month"><span>Month</span><input id="department-batch-month" type="month" required aria-label="Batch allocation month"></label>
                <label for="department-batch-amount"><span>Amount per department</span><input id="department-batch-amount" type="number" min="0" step="0.01" required><small>This is not divided among departments.</small></label>
                <label for="department-batch-status"><span>Status</span><select id="department-batch-status"><option value="open">Open for purchases</option><option value="suspended">Suspended</option><option value="closed">Closed</option></select></label>
                <label for="department-batch-reason"><span>Audit reason</span><input id="department-batch-reason" maxlength="500" required placeholder="Why this batch allocation is being applied"><small>Recorded in every affected department ledger.</small></label>
            </div>
            <div class="department-batch-preview" aria-live="polite"><span>Combined allocation</span><strong id="department-batch-total">PHP 0.00</strong><small id="department-batch-preview-detail">Select departments and enter an amount.</small></div>
            <p id="department-batch-allocation-result" class="department-result" role="status" aria-live="polite"></p>
        </div>
        <footer><button id="department-batch-allocation-cancel" class="secondary-btn" type="button">Cancel</button><button id="department-batch-allocation-save" class="primary-btn" type="submit"><i class="bi bi-check2-circle"></i> Apply to selected</button></footer>
    </form>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/department-debt-pages.js') ?>?v=20260824f"></script>
<?= $this->endSection() ?>
