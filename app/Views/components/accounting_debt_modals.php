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

        <div class="settlement-warning-panel mb-3 rounded-xl border p-3 text-sm">
            This workflow is read-only. Create and process all new deductions through <strong>Deduction Workflow</strong>.
        </div>

        <div class="mode-history">
            <h5 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Recent Salary Deduction Batches</h5>
            <div id="settlement-runs-list" class="mode-history-list flex max-h-64 flex-col gap-2 overflow-auto">Loading runs...</div>
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

<section id="deduction-workflow-modal" class="<?= !empty($deductionsPage) ? 'deductions-page-shell' : 'acct-modal is-hidden' ?>" role="<?= !empty($deductionsPage) ? 'region' : 'dialog' ?>"<?= !empty($deductionsPage) ? '' : ' aria-modal="true"' ?> aria-labelledby="deduction-workflow-title" data-current-role="<?= esc((string) session()->get('role')) ?>" data-current-user="<?= (int) session()->get('user_id') ?>">
    <div class="<?= !empty($deductionsPage) ? 'deductions-page-card' : 'acct-modal-card max-h-[94vh] w-[min(1380px,97vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl' ?>">
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

        <div class="deductions-workflow-layout grid gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
            <div class="deductions-workflow-column space-y-4">
                <section class="deductions-workflow-card rounded-xl border border-slate-200 bg-slate-50 p-4">
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

                <section class="deductions-safeguard rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                    <strong class="block">Workflow safeguard</strong>
                    <p class="mt-1">Prepared means reviewed but not yet deducted. Only Apply deductions changes employee balances.</p>
                </section>
            </div>

            <div class="deductions-workflow-column space-y-4">
                <section class="deductions-workflow-card rounded-xl border border-slate-200 bg-white p-4">
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

                <section id="workflow-prepare-section" class="deductions-workflow-card rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h5 class="text-sm font-bold text-slate-900">2. Prepare employee requests</h5>
                            <p class="text-sm text-slate-500">Review the period summary, then open the employee register only when adjustments are needed.</p>
                        </div>
                        <button id="workflow-open-employees" type="button" class="primary-btn"><i class="bi bi-people"></i> Review employees</button>
                    </div>
                    <div class="deductions-summary-grid mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="deductions-summary-card rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="text-xs text-slate-500">Employees with debt</span><strong id="workflow-summary-employees" class="mt-1 block text-xl text-slate-900">0</strong></div>
                        <div class="deductions-summary-card rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="text-xs text-slate-500">Debt at cutoff</span><strong id="workflow-summary-debt" class="mt-1 block text-xl text-slate-900">PHP 0.00</strong></div>
                        <div class="deductions-summary-card rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="text-xs text-slate-500">Salary not provided</span><strong id="workflow-summary-missing" class="mt-1 block text-xl text-slate-900">0</strong></div>
                        <div class="deductions-summary-card rounded-xl border border-slate-200 bg-slate-50 p-3"><span class="text-xs text-slate-500">Selected for batch</span><strong id="workflow-summary-selected" class="mt-1 block text-xl text-slate-900">0</strong></div>
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

                <section id="workflow-results-section" class="deductions-workflow-card hidden rounded-xl border border-slate-200 bg-white p-4">
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
</section>

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
