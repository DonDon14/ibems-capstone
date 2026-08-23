<?= $this->extend('layouts/accounting') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/accounting-debts.css') ?>?v=20260823d">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="accounting-debt-overview acct-shell space-y-5<?= !empty($deductionsPage) ? ' is-hidden' : '' ?>"<?= !empty($deductionsPage) ? ' aria-hidden="true"' : '' ?>>
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
                <?php if (!empty($deductionsPage)): ?>
                    <button id="open-deduction-workflow" class="primary-btn" type="button"><i class="bi bi-diagram-3"></i> Deduction Workflow</button>
                <?php endif; ?>
                <button id="open-debt-investigations" class="secondary-btn" type="button"><i class="bi bi-shield-check"></i> Investigations</button>
                <button id="open-settlement-run" class="secondary-btn" type="button"><i class="bi bi-clock-history"></i> Legacy History</button>
                <button id="open-deduction-mode" class="hidden" type="button" tabindex="-1" aria-hidden="true">Retired manual deduction</button>
                <button id="open-import-csv" class="secondary-btn" type="button"><i class="bi bi-file-earmark-arrow-up"></i> Import HR CSV</button>
            </div>
        </div>
    </div>
    </article>

    <article class="dash-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="acct-tabs" role="tablist" aria-label="Accounting debt sections">
        <button class="acct-tab is-active" type="button" data-acct-tab="employee-debts" role="tab" aria-selected="true"><i class="bi bi-bag-check"></i> Employee Accounts <span id="acct-tab-employee-count">0</span></button>
        <button class="acct-tab" type="button" data-acct-tab="advance-payments" role="tab" aria-selected="false"><i class="bi bi-wallet2"></i> Direct Payments Received <span id="acct-tab-advance-count">0</span></button>
        <button class="acct-tab" type="button" data-acct-tab="operator-accountabilities" role="tab" aria-selected="false"><i class="bi bi-exclamation-octagon"></i> Store Operator Shortages <span id="acct-tab-operator-count">0</span></button>
    </div>
    <div class="acct-table-wrap table-standard-wrap">
        <p id="acct-count-text" class="acct-count-text text-sm text-slate-500">Showing 0 records</p>
        <div id="acct-body" class="acct-record-list grid gap-2">
            <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading accounting records...']) ?>
        </div>
    </div>
    </article>

    <p id="acct-result" class="acct-result text-sm font-semibold"></p>
    <div id="acct-pager" class="overview-pager" aria-label="Accounting record pages"></div>
</section>

<div id="deduction-mode-modal" class="acct-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="deduction-mode-title">
    <div class="acct-modal-card max-h-[92vh] w-[min(1200px,96vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl" data-inset-modal-scroll>
        <div class="acct-modal-head">
            <h4 id="deduction-mode-title" class="text-lg font-bold text-slate-900">Manual Payroll Deduction</h4>
            <button id="close-deduction-mode" type="button" class="acct-modal-close" aria-label="Close manual payroll deduction">x</button>
        </div>

        <div class="mode-layout grid gap-4 lg:grid-cols-[340px_minmax(0,1fr)]">
            <div class="mode-left rounded-xl border border-slate-200 bg-slate-50 p-3">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="mode-search">Find Employee</label>
                <input id="mode-search" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="search" placeholder="Name, email, employee ID">
                <label class="mode-check mt-2 inline-flex items-center gap-2 text-sm text-slate-700"><input id="mode-debt-only" class="h-4 w-4" type="checkbox" checked> Show only with debt</label>
                <div id="mode-results" class="mode-results mt-2 flex max-h-[62vh] flex-col gap-2 overflow-auto"></div>
            </div>

            <div class="mode-right space-y-3">
                <div id="mode-profile" class="mode-profile-empty rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">Select a person from the left list.</div>
                <div id="mode-actions" class="mode-actions hidden">
                    <h5 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Actions</h5>
                    <div class="inline-actions">
                        <button id="mode-deduct-full" type="button" class="primary-btn btn-sm">Deduct Full Balance</button>
                        <button id="mode-open-manual" type="button" class="secondary-btn btn-sm">Deduct Custom Amount</button>
                    </div>
                    <div id="mode-manual-box" class="inline-box mt-2 flex flex-col gap-2 hidden">
                        <label class="field" for="mode-manual-amount"><span class="text-xs font-semibold text-slate-500">Deduction amount</span><input id="mode-manual-amount" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="number" min="0.01" step="0.01" placeholder="Amount"></label>
                        <label class="field" for="mode-manual-reason"><span class="text-xs font-semibold text-slate-500">Reason</span><input id="mode-manual-reason" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="text" value="Manual deduction"></label>
                        <div class="inline-actions">
                            <button id="mode-apply-manual" type="button" class="primary-btn btn-sm">Apply Deduction</button>
                            <button id="mode-cancel-manual" type="button" class="secondary-btn btn-sm">Cancel</button>
                        </div>
                    </div>
                </div>

                <div id="mode-history-wrap" class="mode-history hidden">
                    <h5 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Recent History</h5>
                    <div class="mode-history-list flex max-h-64 flex-col gap-2 overflow-auto" id="mode-history-list"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="employee-modal" class="acct-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="employee-modal-title">
    <div class="acct-modal-card max-h-[92vh] w-[min(980px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl" data-inset-modal-scroll>
        <div class="acct-modal-head">
            <div>
                <h4 id="employee-modal-title" class="text-lg font-bold text-slate-900">Employee Details</h4>
                <p class="mt-1 text-sm text-slate-500">Review identity, salary reference, credit capacity, and financial activity.</p>
            </div>
            <button id="close-employee-modal" type="button" class="acct-modal-close" aria-label="Close employee details">x</button>
        </div>
        <div id="employee-modal-profile" class="employee-profile-loading"><?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading employee profile...']) ?></div>

        <section id="employee-modal-actions" class="employee-financial-section hidden">
            <div class="employee-section-head">
                <div class="employee-section-title">
                    <span class="employee-section-icon" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
                    <div>
                        <h5>Salary grade and dynamic credit</h5>
                        <p id="employee-limit-current">Salary PHP 0.00 · Credit PHP 0.00</p>
                    </div>
                </div>
                <button id="employee-open-limit-edit" type="button" class="secondary-btn btn-sm"><i class="bi bi-pencil-square"></i> Edit profile</button>
            </div>
            <div id="employee-limit-box" class="inline-box mt-2 flex flex-col gap-2 hidden">
                <label class="field" for="employee-employment-type"><span class="text-xs font-semibold text-slate-500">Employment type</span><select id="employee-employment-type" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700"><option value="plantilla">Plantilla</option><option value="cos">COS</option><option value="part_time">Part-time</option></select></label>
                <label class="field" for="employee-salary-schedule"><span class="text-xs font-semibold text-slate-500">Salary schedule</span><select id="employee-salary-schedule" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700"></select></label>
                <label class="field" for="employee-salary-grade"><span class="text-xs font-semibold text-slate-500">Salary grade</span><select id="employee-salary-grade" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700"></select></label>
                <label class="field" for="employee-salary-step"><span class="text-xs font-semibold text-slate-500">Salary step</span><select id="employee-salary-step" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700"></select></label>
                <label class="field" for="employee-salary-effective-date"><span class="text-xs font-semibold text-slate-500">Effective date</span><input id="employee-salary-effective-date" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="date"></label>
                <label class="field" for="employee-credit-percentage"><span class="text-xs font-semibold text-slate-500">Credit percentage</span><div class="relative"><input id="employee-credit-percentage" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 pr-9 text-sm text-slate-700" type="number" min="0" max="100" step="0.01" value="25"><span class="pointer-events-none absolute right-3 top-3 text-sm text-slate-500">%</span></div></label>
                <label class="field" for="employee-salary-value"><span class="text-xs font-semibold text-slate-500">Monthly salary / compensation</span><input id="employee-salary-value" class="h-11 rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm text-slate-700" type="text" readonly value="PHP 0.00"></label>
                <label class="field" for="employee-limit-value"><span class="text-xs font-semibold text-slate-500">Calculated credit limit</span><input id="employee-limit-value" class="h-11 rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm text-slate-700" type="text" readonly value="PHP 0.00"></label>
                <p class="text-xs text-slate-500">The selected versioned schedule determines salary. The percentage determines available credit; existing debt is never erased.</p>
                <label class="field" for="employee-limit-reason"><span class="text-xs font-semibold text-slate-500">Reason</span><input id="employee-limit-reason" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="text" value="Accounting financial profile setup"></label>
                <div class="inline-actions">
                    <button id="employee-limit-save" type="button" class="primary-btn btn-sm">Save</button>
                    <button id="employee-limit-cancel" type="button" class="secondary-btn btn-sm">Cancel</button>
                </div>
            </div>
        </section>

        <section class="employee-history-section">
            <div class="employee-section-head">
                <div class="employee-section-title">
                    <span class="employee-section-icon is-neutral" aria-hidden="true"><i class="bi bi-clock-history"></i></span>
                    <div><h5>Recent financial history</h5><p>Latest salary, credit-limit, repayment, and deduction changes.</p></div>
                </div>
            </div>
            <div id="employee-modal-history" class="mode-history-list employee-history-list flex max-h-64 flex-col gap-2 overflow-auto"><?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading financial history...']) ?></div>
        </section>
    </div>
</div>

<div id="settlement-run-modal" class="acct-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="settlement-run-title">
    <div class="acct-modal-card max-h-[92vh] w-[min(1320px,96vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl" data-inset-modal-scroll>
        <div class="acct-modal-head">
            <h4 id="settlement-run-title" class="text-lg font-bold text-slate-900">Legacy Monthly Deduction History</h4>
            <button id="close-settlement-run" type="button" class="acct-modal-close" aria-label="Close legacy deduction history"></button>
        </div>

        <div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            This workflow is read-only. Create and process all new deductions through <strong>Deduction Workflow</strong>.
        </div>

        <div class="settlement-controls hidden flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div class="field">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="settlement-run-month">Run Month</label>
                <input id="settlement-run-month" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="month">
            </div>
            <div class="field grow">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="settlement-notes">Notes</label>
                <input id="settlement-notes" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="text" placeholder="Optional note for this run">
            </div>
            <button id="settlement-preview-btn" class="secondary-btn" type="button">Preview Deductions</button>
            <button id="settlement-apply-btn" class="primary-btn" type="button" disabled>Review & Confirm</button>
        </div>
        <div id="settlement-existing-run" class="settlement-existing-run mt-3 flex items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 hidden">
            <span id="settlement-existing-run-text"></span>
            <button id="settlement-existing-run-view" type="button" class="secondary-btn">View Existing Batch</button>
        </div>

        <div class="acct-summary settlement-summary mt-3 hidden gap-3 md:grid-cols-2 xl:grid-cols-4">
            <?= view('components/stat_card', ['title' => 'Candidates', 'value' => '0', 'valueId' => 'settle-candidate-count', 'icon' => 'bi bi-people', 'tone' => 'users']) ?>
            <?= view('components/stat_card', ['title' => 'Processable', 'value' => '0', 'valueId' => 'settle-processable-count', 'icon' => 'bi bi-check2-circle', 'tone' => 'sales']) ?>
            <?= view('components/stat_card', ['title' => 'Total Debt Before', 'value' => 'PHP 0.00', 'valueId' => 'settle-total-before', 'icon' => 'bi bi-cash-stack', 'tone' => 'debt']) ?>
            <?= view('components/stat_card', ['title' => 'Total Deducted', 'value' => 'PHP 0.00', 'valueId' => 'settle-total-deducted', 'icon' => 'bi bi-cash-coin', 'tone' => 'finance']) ?>
        </div>

        <div class="settlement-preview-tools hidden">
            <label class="field min-w-[260px] grow" for="settlement-preview-search">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Search preview</span>
                <span class="acct-search-wrap relative block">
                    <input id="settlement-preview-search" class="h-11 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-sm text-slate-700 outline-none transition focus:border-blue-300" type="search" placeholder="Employee, ID, or category">
                </span>
            </label>
            <p id="settlement-preview-count" class="settlement-preview-count">Preview the batch to show employees for deduction.</p>
        </div>

        <div class="acct-table-wrap table-standard-wrap hidden">
            <table class="table table-standard">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Category</th>
                        <th>Monthly Salary</th>
                        <th>Current Debt</th>
                        <th>Deductible</th>
                        <th>New Debt</th>
                    </tr>
                </thead>
                <tbody id="settlement-preview-body">
                    <tr><td colspan="6">Click Preview Deductions to load payroll deduction candidates.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="mode-history">
            <h5 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Recent Salary Deduction Batches</h5>
            <div id="settlement-runs-list" class="mode-history-list flex max-h-64 flex-col gap-2 overflow-auto">Loading runs...</div>
        </div>
    </div>
</div>

<div id="settlement-confirm-modal" class="acct-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="settlement-confirm-title">
    <div class="acct-modal-card max-h-[92vh] w-[min(1040px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl" data-inset-modal-scroll>
        <div class="acct-modal-head">
            <h4 id="settlement-confirm-title" class="text-lg font-bold text-slate-900">Confirm Salary Deduction</h4>
            <button id="close-settlement-confirm" type="button" class="acct-modal-close" aria-label="Close salary deduction confirmation">x</button>
        </div>

        <div id="settlement-confirm-summary" class="settlement-confirm-summary rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            Review the employees below before applying the payroll deduction batch.
        </div>
        <label class="settlement-select-all">
            <input id="settlement-confirm-select-all" type="checkbox" checked>
            <span>Select all listed employees for deduction</span>
        </label>

        <div class="acct-table-wrap table-standard-wrap">
            <table class="table table-standard">
                <thead>
                    <tr>
                        <th>Confirm</th>
                        <th>Employee</th>
                        <th>Category</th>
                        <th>Current Debt</th>
                        <th>Amount To Deduct</th>
                        <th>Debt After</th>
                    </tr>
                </thead>
                <tbody id="settlement-confirm-body">
                    <tr><td colspan="6">No employees selected for deduction.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="settlement-confirm-actions">
            <button id="cancel-settlement-confirm" type="button" class="secondary-btn">Cancel</button>
            <button id="confirm-settlement-apply" type="button" class="primary-btn"><i class="bi bi-check2-circle"></i> Confirm Deduction</button>
        </div>
    </div>
</div>

<div id="settlement-run-details-modal" class="acct-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="settlement-run-details-title">
    <div class="acct-modal-card max-h-[92vh] w-[min(1320px,96vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl" data-inset-modal-scroll>
        <div class="acct-modal-head">
            <h4 id="settlement-run-details-title" class="text-lg font-bold text-slate-900">Salary Deduction Batch Details</h4>
            <div class="flex flex-wrap items-center gap-2">
                <button id="settlement-details-export" type="button" class="secondary-btn" disabled><i class="bi bi-download"></i> Export CSV</button>
                <button id="settlement-details-print" type="button" class="secondary-btn" disabled><i class="bi bi-printer"></i> Print</button>
                <button id="close-settlement-run-details" type="button" class="acct-modal-close" aria-label="Close salary deduction batch details">x</button>
            </div>
        </div>

        <div id="settlement-details-head" class="mode-profile-empty rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">Loading settlement run details...</div>

        <div class="acct-summary settlement-summary mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <?= view('components/stat_card', ['title' => 'Processed Accounts', 'value' => '0', 'valueId' => 'settle-details-count', 'icon' => 'bi bi-clipboard-check', 'tone' => 'users']) ?>
            <?= view('components/stat_card', ['title' => 'Total Deducted', 'value' => 'PHP 0.00', 'valueId' => 'settle-details-deducted', 'icon' => 'bi bi-cash-coin', 'tone' => 'finance']) ?>
            <?= view('components/stat_card', ['title' => 'Total Debt After', 'value' => 'PHP 0.00', 'valueId' => 'settle-details-after', 'icon' => 'bi bi-credit-card-2-front', 'tone' => 'debt']) ?>
        </div>

        <div class="acct-table-wrap table-standard-wrap">
            <table class="table table-standard">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Category</th>
                        <th>Monthly Salary</th>
                        <th>Previous Debt</th>
                        <th>Deducted</th>
                        <th>New Debt</th>
                    </tr>
                </thead>
                <tbody id="settlement-details-body">
                    <tr><td colspan="6">Loading details...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="deduction-workflow-modal" class="acct-modal is-hidden<?= !empty($deductionsPage) ? ' deductions-page-shell' : '' ?>" role="dialog" aria-modal="true" aria-labelledby="deduction-workflow-title" data-current-role="<?= esc((string) session()->get('role')) ?>" data-current-user="<?= (int) session()->get('user_id') ?>">
    <div class="acct-modal-card max-h-[94vh] w-[min(1380px,97vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl<?= !empty($deductionsPage) ? ' deductions-page-card' : '' ?>">
        <?php if (!empty($deductionsPage)): ?>
            <?= view('components/page_header', [
                'eyebrow' => 'Payroll controls',
                'title' => 'Payroll deductions',
                'titleId' => 'deduction-workflow-title',
                'description' => 'Choose employee deductions by pay period, apply them in IBEMS, and preserve a reconciled history.',
                'icon' => 'bi bi-calculator',
            ]) ?>
        <?php else: ?>
            <div class="acct-modal-head">
                <div>
                    <h4 id="deduction-workflow-title" class="text-lg font-bold text-slate-900">Payroll Deduction Workflow</h4>
                    <p class="mt-1 text-sm text-slate-500">Prepare requests first. Employee debt changes only after Accounting confirms the deduction in IBEMS.</p>
                </div>
                <button id="close-deduction-workflow" type="button" class="acct-modal-close" aria-label="Close deduction workflow"></button>
            </div>
        <?php endif; ?>

        <div class="grid gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
            <div class="space-y-4">
                <section class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <h5 class="text-sm font-bold text-slate-900">1. Create deduction period</h5>
                    <div class="mt-3 grid gap-3">
                        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-3 text-sm text-slate-600">
                            <strong class="block text-slate-900">Automatic period identity</strong>
                            <span id="workflow-period-preview">Choose the start and end dates. The period code and label will be generated automatically.</span>
                        </div>
                        <label class="field">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Frequency</span>
                            <select id="workflow-period-frequency" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                                <option value="semi_monthly">Semi-monthly</option>
                                <option value="monthly">Monthly</option>
                                <option value="custom">Custom</option>
                            </select>
                        </label>
                        <div class="grid gap-3">
                            <label class="field">
                                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Start date</span>
                                <input id="workflow-period-start" type="date" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Select start date">
                            </label>
                            <label class="field">
                                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">End date</span>
                                <input id="workflow-period-end" type="date" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Select end date">
                            </label>
                        </div>
                        <button id="workflow-create-period" type="button" class="primary-btn">Create period</button>
                    </div>
                </section>

                <section class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                    <strong class="block">Workflow safeguard</strong>
                    <p class="mt-1">Prepared means reviewed but not yet deducted. Only Apply deductions changes employee balances.</p>
                </section>
            </div>

            <div class="space-y-4">
                <section class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="field min-w-[260px] grow">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Deduction period</span>
                            <select id="workflow-period-select" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                                <option value="">Select a period</option>
                            </select>
                        </label>
                        <button id="workflow-refresh" type="button" class="secondary-btn"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                    </div>
                    <div id="workflow-period-summary" class="mt-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3 text-sm text-slate-600">Choose or create a deduction period.</div>
                </section>

                <section id="workflow-prepare-section" class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h5 class="text-sm font-bold text-slate-900">2. Prepare employee requests</h5>
                            <p class="text-sm text-slate-500">Review the period summary, then open the employee register only when adjustments are needed.</p>
                        </div>
                        <button id="workflow-open-employees" type="button" class="primary-btn"><i class="bi bi-people"></i> Review employees</button>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="text-xs text-slate-500">Employees with debt</span><strong id="workflow-summary-employees" class="mt-1 block text-xl text-slate-900">0</strong></div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="text-xs text-slate-500">Debt at cutoff</span><strong id="workflow-summary-debt" class="mt-1 block text-xl text-slate-900">PHP 0.00</strong></div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="text-xs text-slate-500">Salary not provided</span><strong id="workflow-summary-missing" class="mt-1 block text-xl text-slate-900">0</strong></div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="text-xs text-slate-500">Selected for batch</span><strong id="workflow-summary-selected" class="mt-1 block text-xl text-slate-900">0</strong></div>
                    </div>
                </section>

                <div id="workflow-employees-modal" class="acct-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="workflow-employees-title">
                    <div class="acct-modal-card max-h-[94vh] overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
                        <div class="acct-modal-head">
                            <div><h4 id="workflow-employees-title" class="text-lg font-bold text-slate-900">Employee deduction register</h4><p class="mt-1 text-sm text-slate-500">Search and adjust full, partial, or no-deduction requests for this period.</p></div>
                            <button id="workflow-close-employees" type="button" class="acct-modal-close" aria-label="Close employee deduction register"></button>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2">
                        <label class="flex min-w-56 flex-col gap-1 text-xs font-semibold text-slate-600" for="workflow-candidate-search">
                            <span>Search employees</span>
                            <input id="workflow-candidate-search" type="search" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Name or employee ID">
                        </label>
                        <button id="workflow-toggle-filters" type="button" class="secondary-btn" aria-expanded="false"><i class="bi bi-funnel"></i> Filters</button>
                        <button id="workflow-select-matching" type="button" class="secondary-btn">Select matching</button>
                        <button id="workflow-clear-selection" type="button" class="secondary-btn">Clear</button>
                    </div>
                    <div id="workflow-filter-options" class="mt-2 flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-2" hidden>
                        <label class="flex min-w-40 flex-col gap-1 text-xs font-semibold text-slate-600" for="workflow-candidate-type">
                            <span>Employee type</span>
                            <select id="workflow-candidate-type" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                                <option value="all">All employee types</option>
                                <option value="faculty">Faculty</option>
                                <option value="staff">Staff</option>
                            </select>
                        </label>
                        <label class="flex min-w-48 flex-col gap-1 text-xs font-semibold text-slate-600" for="workflow-candidate-salary">
                            <span>Salary reference</span>
                            <select id="workflow-candidate-salary" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                                <option value="all">Any salary reference</option>
                                <option value="provided">Salary provided</option>
                                <option value="missing">Salary not provided</option>
                            </select>
                        </label>
                        </div>
                        <div class="mt-2 flex items-center justify-between gap-3"><div id="workflow-candidate-count" class="text-sm text-slate-500">No employees loaded.</div><button id="workflow-prepare-batch" type="button" class="primary-btn">Prepare selected batch</button></div>
                        <div id="workflow-candidates" class="mt-2 max-h-[65vh] space-y-2 overflow-auto pr-1"></div>
                    </div>
                </div>

                <section id="workflow-results-section" class="hidden rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h5 class="text-sm font-bold text-slate-900">3. Apply and finalize deductions</h5>
                            <p class="text-sm text-slate-500">The assigned Accounting Officer applies deductions, reconciles totals, and finalizes with a complete audit trail.</p>
                        </div>
                        <div id="workflow-batch-actions"></div>
                    </div>
                    <div id="workflow-results" class="mt-3 space-y-3"></div>
                </section>
                <p id="workflow-message" class="text-sm font-semibold" role="status"></p>
            </div>
        </div>
    </div>
</div>

<div id="debt-investigations-modal" class="acct-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="debt-investigations-title" data-current-role="<?= esc((string) session()->get('role')) ?>" data-current-user="<?= (int) session()->get('user_id') ?>">
    <div class="acct-modal-card max-h-[94vh] w-[min(1180px,97vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl" data-inset-modal-scroll>
        <div class="acct-modal-head">
            <div>
                <h4 id="debt-investigations-title" class="text-lg font-bold text-slate-900">Debt Investigations and Corrections</h4>
                <p class="mt-1 text-sm text-slate-500">Investigations preserve original transactions. Approved corrections are posted as linked ledger reversals.</p>
            </div>
            <button id="close-debt-investigations" type="button" class="acct-modal-close" aria-label="Close debt investigations"></button>
        </div>
        <div class="grid gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
            <section class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <h5 class="text-sm font-bold text-slate-900">Open investigation</h5>
                <div class="mt-3 grid gap-3">
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Employee</span>
                        <select id="investigation-user" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="">Select employee</option></select>
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Debt transaction</span>
                        <select id="investigation-transaction" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="">General balance investigation</option></select>
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Issue type</span>
                        <select id="investigation-issue" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                            <option value="incorrect_amount">Incorrect amount</option>
                            <option value="unauthorized_purchase">Unauthorized purchase</option>
                            <option value="duplicate_charge">Duplicate charge</option>
                            <option value="wrong_employee">Wrong employee</option>
                            <option value="other">Other</option>
                        </select>
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Issue summary</span>
                        <textarea id="investigation-summary" class="min-h-24 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm" placeholder="Describe what was reported and why it requires investigation."></textarea>
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Initial evidence</span>
                        <textarea id="investigation-evidence" class="min-h-20 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm" placeholder="Receipt, statement, store-day record, or other evidence."></textarea>
                    </label>
                    <button id="investigation-open" type="button" class="primary-btn">Open investigation</button>
                </div>
            </section>
            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h5 class="text-sm font-bold text-slate-900">Investigation queue</h5>
                        <p class="text-sm text-slate-500">A different Accounting user must approve a recommended correction.</p>
                    </div>
                    <button id="investigation-refresh" type="button" class="secondary-btn"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                </div>
                <div id="investigation-list" class="mt-3 max-h-[68vh] space-y-3 overflow-auto"></div>
            </section>
        </div>
        <p id="investigation-message" class="mt-3 text-sm font-semibold" role="status"></p>
    </div>
</div>

<div id="import-csv-modal" class="acct-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="import-csv-title">
    <div class="acct-modal-card max-h-[92vh] w-[min(880px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl" data-inset-modal-scroll>
        <div class="acct-modal-head">
            <h4 id="import-csv-title" class="text-lg font-bold text-slate-900">Import Employee CSV</h4>
            <button id="close-import-csv" type="button" class="acct-modal-close" aria-label="Close employee CSV import">x</button>
        </div>

        <div class="import-guide rounded-xl border border-slate-200 bg-slate-50 p-3">
            <p class="mb-1 text-sm text-slate-700">Required headers:</p>
            <code class="mb-2 block overflow-x-auto rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">employee_id,name,email,user_type,employment_type,salary_schedule_code,salary_grade,salary_step,salary_effective_date,credit_percentage</code>
            <p class="mb-1 text-sm text-slate-700">Salary is derived from the versioned schedule. Salary schedule code and credit percentage are optional; they default to the current schedule and 25%.</p>
            <p class="text-sm text-slate-700">Allowed category (`user_type`) values: <strong>faculty</strong>, <strong>staff</strong></p>
        </div>

        <div class="inline-box flex flex-col gap-2">
            <label class="field" for="import-csv-file"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">CSV file</span><input id="import-csv-file" class="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700" type="file" accept=".csv,.txt"></label>
            <div class="flex flex-wrap gap-2">
                <button id="import-csv-preview" type="button" class="secondary-btn">Preview CSV</button>
                <button id="import-csv-submit" type="button" class="primary-btn" disabled>Apply Import</button>
            </div>
        </div>

        <div id="import-csv-result" class="import-result mt-3 text-sm"></div>
        <div id="import-csv-valid" class="import-valid mt-2 max-h-56 space-y-2 overflow-auto text-sm"></div>
        <div id="import-csv-invalid" class="import-invalid mt-2 max-h-56 space-y-2 overflow-auto text-sm"></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/accounting-debts.js') ?>?v=20260824g"></script>
<?= $this->endSection() ?>
