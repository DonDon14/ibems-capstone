<?= $this->extend('layouts/accounting') ?>

<?= $this->section('content') ?>
<section class="acct-shell space-y-5">
    <div class="dashboard-title acct-head">
        <div>
            <h3 class="text-3xl font-bold tracking-tight text-slate-900">Debt Management</h3>
            <p class="mt-1 text-base text-slate-600">Search employees, deduct debt, and update credit limit.</p>
        </div>
    </div>

    <div class="dashboard-grid acct-summary">
        <?= view('components/stat_card', ['title' => 'Accounts', 'value' => '0', 'valueId' => 'acct-count', 'icon' => 'bi bi-people', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Total Debt', 'value' => 'PHP 0.00', 'valueId' => 'acct-total-debt', 'icon' => 'bi bi-cash-stack', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Today Deductions', 'value' => '0', 'valueId' => 'acct-today-count', 'icon' => 'bi bi-calendar-check', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Today Deducted Amount', 'value' => 'PHP 0.00', 'valueId' => 'acct-today-amount', 'icon' => 'bi bi-coin', 'tone' => 'finance']) ?>
    </div>

    <article class="dash-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="acct-filters acct-filters-redesign space-y-3">
        <div class="acct-filters-main flex flex-wrap items-end gap-3">
            <div class="acct-search-wrap relative min-w-[260px] grow">
                <i class="bi bi-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                <input id="acct-search" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm text-slate-700 outline-none transition focus:border-blue-300 focus:bg-white" type="search" placeholder="Search name, ID, office...">
            </div>
            <label class="toggle-check acct-toggle-inline inline-flex h-11 items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-700">
                <input id="acct-debt-only" class="h-4 w-4" type="checkbox">
                <span>Debt only</span>
            </label>
        </div>
        <div class="acct-filters-actions flex flex-wrap gap-2">
            <div class="action-group action-group-primary">
                <button id="acct-refresh-btn" class="history-action alt inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            </div>
            <div class="action-group action-group-tools flex flex-wrap gap-2">
                <button id="open-settlement-run" class="history-action inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-blue-700" type="button"><i class="bi bi-calendar2-check"></i> Settlement Run</button>
                <button id="open-deduction-mode" class="history-action inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" type="button"><i class="bi bi-cash-coin"></i> Deduction Mode</button>
                <button id="open-import-csv" class="history-action inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" type="button"><i class="bi bi-file-earmark-arrow-up"></i> Import HR CSV</button>
            </div>
        </div>
    </div>
    </article>

    <article class="dash-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="acct-table-wrap table-standard-wrap">
        <p id="acct-count-text" class="acct-count-text text-sm text-slate-500">Showing 0 records</p>
        <div id="acct-body" class="acct-record-list grid gap-2">
            <div class="acct-empty rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">Loading records...</div>
        </div>
    </div>
    </article>

    <p id="acct-result" class="acct-result text-sm font-semibold"></p>
</section>

<div id="deduction-mode-modal" class="acct-modal is-hidden">
    <div class="acct-modal-card max-h-[92vh] w-[min(1200px,96vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="acct-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Deduction Mode</h4>
            <button id="close-deduction-mode" type="button" class="acct-modal-close">x</button>
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
                        <button id="mode-deduct-full" type="button" class="mini-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700">Deduct Full</button>
                        <button id="mode-open-manual" type="button" class="mini-btn alt inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Manual Deduct</button>
                    </div>
                    <div id="mode-manual-box" class="inline-box mt-2 flex flex-col gap-2 hidden">
                        <input id="mode-manual-amount" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="number" min="0.01" step="0.01" placeholder="Amount">
                        <input id="mode-manual-reason" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="text" value="Manual deduction">
                        <div class="inline-actions">
                            <button id="mode-apply-manual" type="button" class="mini-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700">Apply</button>
                            <button id="mode-cancel-manual" type="button" class="mini-btn alt inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Cancel</button>
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

<div id="employee-modal" class="acct-modal is-hidden">
    <div class="acct-modal-card max-h-[92vh] w-[min(980px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="acct-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Employee Details</h4>
            <button id="close-employee-modal" type="button" class="acct-modal-close">x</button>
        </div>
        <div id="employee-modal-profile" class="mode-profile-empty rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">Loading profile...</div>

        <div id="employee-modal-actions" class="mode-actions hidden">
            <h5 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Credit Limit</h5>
            <div class="inline-actions">
                <span id="employee-limit-current" class="limit-label">PHP 0.00</span>
                <button id="employee-open-limit-edit" type="button" class="mini-btn alt inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Edit</button>
            </div>
            <div id="employee-limit-box" class="inline-box mt-2 flex flex-col gap-2 hidden">
                <input id="employee-limit-value" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="number" min="0" step="0.01" value="0">
                <input id="employee-limit-reason" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="text" value="Manual credit limit update">
                <div class="inline-actions">
                    <button id="employee-limit-save" type="button" class="mini-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700">Save</button>
                    <button id="employee-limit-cancel" type="button" class="mini-btn alt inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Cancel</button>
                </div>
            </div>
        </div>

        <div class="mode-history">
            <h5 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Recent History</h5>
            <div id="employee-modal-history" class="mode-history-list flex max-h-64 flex-col gap-2 overflow-auto">Loading history...</div>
        </div>
    </div>
</div>

<div id="settlement-run-modal" class="acct-modal is-hidden">
    <div class="acct-modal-card max-h-[92vh] w-[min(1320px,96vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="acct-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Monthly Settlement Run</h4>
            <button id="close-settlement-run" type="button" class="acct-modal-close">x</button>
        </div>

        <div class="settlement-controls flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div class="field">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="settlement-run-month">Run Month</label>
                <input id="settlement-run-month" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="month">
            </div>
            <div class="field grow">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500" for="settlement-notes">Notes</label>
                <input id="settlement-notes" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" type="text" placeholder="Optional note for this run">
            </div>
            <button id="settlement-preview-btn" class="primary-btn inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" type="button">Preview</button>
            <button id="settlement-apply-btn" class="primary-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300" type="button" disabled>Apply Run</button>
        </div>
        <div id="settlement-existing-run" class="settlement-existing-run mt-3 flex items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 hidden">
            <span id="settlement-existing-run-text"></span>
            <button id="settlement-existing-run-view" type="button" class="history-action inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">View Existing Run</button>
        </div>

        <div class="acct-summary settlement-summary mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <?= view('components/stat_card', ['title' => 'Candidates', 'value' => '0', 'valueId' => 'settle-candidate-count', 'icon' => 'bi bi-people', 'tone' => 'users']) ?>
            <?= view('components/stat_card', ['title' => 'Processable', 'value' => '0', 'valueId' => 'settle-processable-count', 'icon' => 'bi bi-check2-circle', 'tone' => 'sales']) ?>
            <?= view('components/stat_card', ['title' => 'Total Debt Before', 'value' => 'PHP 0.00', 'valueId' => 'settle-total-before', 'icon' => 'bi bi-cash-stack', 'tone' => 'debt']) ?>
            <?= view('components/stat_card', ['title' => 'Total Deducted', 'value' => 'PHP 0.00', 'valueId' => 'settle-total-deducted', 'icon' => 'bi bi-cash-coin', 'tone' => 'finance']) ?>
        </div>

        <div class="acct-table-wrap table-standard-wrap">
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
                    <tr><td colspan="6">Click Preview to load settlement candidates.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="mode-history">
            <h5 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Recent Settlement Runs</h5>
            <div id="settlement-runs-list" class="mode-history-list flex max-h-64 flex-col gap-2 overflow-auto">Loading runs...</div>
        </div>
    </div>
</div>

<div id="settlement-run-details-modal" class="acct-modal is-hidden">
    <div class="acct-modal-card max-h-[92vh] w-[min(1320px,96vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="acct-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Settlement Run Details</h4>
            <div class="flex flex-wrap items-center gap-2">
                <button id="settlement-details-export" type="button" class="history-action inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" disabled><i class="bi bi-download"></i> Export CSV</button>
                <button id="settlement-details-print" type="button" class="history-action inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" disabled><i class="bi bi-printer"></i> Print</button>
                <button id="close-settlement-run-details" type="button" class="acct-modal-close">x</button>
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

<div id="import-csv-modal" class="acct-modal is-hidden">
    <div class="acct-modal-card max-h-[92vh] w-[min(880px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="acct-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Import Employee CSV</h4>
            <button id="close-import-csv" type="button" class="acct-modal-close">x</button>
        </div>

        <div class="import-guide rounded-xl border border-slate-200 bg-slate-50 p-3">
            <p class="mb-1 text-sm text-slate-700">Required headers:</p>
            <code class="mb-2 block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">employee_id,name,email,user_type,monthly_salary</code>
            <p class="mb-1 text-sm text-slate-700">Optional header:</p>
            <code class="mb-2 block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">credit_limit</code>
            <p class="text-sm text-slate-700">Allowed category (`user_type`) values: <strong>faculty</strong>, <strong>staff</strong></p>
        </div>

        <div class="inline-box flex flex-col gap-2">
            <input id="import-csv-file" class="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700" type="file" accept=".csv,.txt">
            <div class="flex flex-wrap gap-2">
                <button id="import-csv-preview" type="button" class="primary-btn inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Preview CSV</button>
                <button id="import-csv-submit" type="button" class="primary-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700" disabled>Apply Import</button>
            </div>
        </div>

        <div id="import-csv-result" class="import-result mt-3 text-sm"></div>
        <div id="import-csv-valid" class="import-valid mt-2 max-h-56 space-y-2 overflow-auto text-sm"></div>
        <div id="import-csv-invalid" class="import-invalid mt-2 max-h-56 space-y-2 overflow-auto text-sm"></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/accounting-debts.js') ?>"></script>
<?= $this->endSection() ?>
