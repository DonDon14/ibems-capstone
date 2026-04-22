<?= $this->extend('layouts/accounting') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/accounting-debts.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="acct-shell">
    <div class="acct-head">
        <div>
            <h3>Debt Management</h3>
            <p>Search employees, deduct debt, and update credit limit.</p>
        </div>
    </div>

    <div class="acct-summary">
        <div class="summary-card">
            <span>Accounts</span>
            <strong id="acct-count">0</strong>
        </div>
        <div class="summary-card">
            <span>Total Debt</span>
            <strong id="acct-total-debt">PHP 0.00</strong>
        </div>
        <div class="summary-card">
            <span>Today Deductions</span>
            <strong id="acct-today-count">0</strong>
        </div>
        <div class="summary-card">
            <span>Today Deducted Amount</span>
            <strong id="acct-today-amount">PHP 0.00</strong>
        </div>
    </div>

    <div class="acct-filters acct-filters-redesign">
        <div class="acct-filters-main">
            <div class="field field-search">
                <label for="acct-search">Search Employee</label>
                <input id="acct-search" type="search" placeholder="Name, email, or employee ID">
            </div>
            <label class="toggle-check">
                <input id="acct-debt-only" type="checkbox">
                <span>Debt only</span>
            </label>
        </div>
        <div class="acct-filters-actions">
            <div class="action-group action-group-primary">
                <button id="acct-refresh-btn" class="history-action alt" type="button">Refresh</button>
            </div>
            <div class="action-group action-group-tools">
                <button id="open-settlement-run" class="history-action" type="button">Settlement Run</button>
                <button id="open-deduction-mode" class="history-action" type="button">Deduction Mode</button>
                <button id="open-import-csv" class="history-action" type="button">Import HR CSV</button>
            </div>
        </div>
    </div>

    <div class="acct-table-wrap table-standard-wrap">
        <table class="table table-standard">
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Current Debt</th>
                    <th>Credit Limit</th>
                    <th>Available</th>
                </tr>
            </thead>
            <tbody id="acct-body">
                <tr><td colspan="8">Loading records...</td></tr>
            </tbody>
        </table>
    </div>

    <p id="acct-result" class="acct-result"></p>
</section>

<div id="deduction-mode-modal" class="acct-modal" style="display:none;">
    <div class="acct-modal-card">
        <div class="acct-modal-head">
            <h4>Deduction Mode</h4>
            <button id="close-deduction-mode" type="button" class="acct-modal-close">x</button>
        </div>

        <div class="mode-layout">
            <div class="mode-left">
                <label for="mode-search">Find Employee</label>
                <input id="mode-search" type="search" placeholder="Name, email, employee ID">
                <label class="mode-check"><input id="mode-debt-only" type="checkbox" checked> Show only with debt</label>
                <div id="mode-results" class="mode-results"></div>
            </div>

            <div class="mode-right">
                <div id="mode-profile" class="mode-profile-empty">Select a person from the left list.</div>
                <div id="mode-actions" class="mode-actions hidden">
                    <h5>Actions</h5>
                    <div class="inline-actions">
                        <button id="mode-deduct-full" type="button" class="mini-btn">Deduct Full</button>
                        <button id="mode-open-manual" type="button" class="mini-btn alt">Manual Deduct</button>
                    </div>
                    <div id="mode-manual-box" class="inline-box hidden">
                        <input id="mode-manual-amount" type="number" min="0.01" step="0.01" placeholder="Amount">
                        <input id="mode-manual-reason" type="text" value="Manual deduction">
                        <div class="inline-actions">
                            <button id="mode-apply-manual" type="button" class="mini-btn">Apply</button>
                            <button id="mode-cancel-manual" type="button" class="mini-btn alt">Cancel</button>
                        </div>
                    </div>
                </div>

                <div id="mode-history-wrap" class="mode-history hidden">
                    <h5>Recent History</h5>
                    <div class="mode-history-list" id="mode-history-list"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="employee-modal" class="acct-modal" style="display:none;">
    <div class="acct-modal-card">
        <div class="acct-modal-head">
            <h4>Employee Details</h4>
            <button id="close-employee-modal" type="button" class="acct-modal-close">x</button>
        </div>
        <div id="employee-modal-profile" class="mode-profile-empty">Loading profile...</div>

        <div id="employee-modal-actions" class="mode-actions hidden">
            <h5>Credit Limit</h5>
            <div class="inline-actions">
                <span id="employee-limit-current" class="limit-label">PHP 0.00</span>
                <button id="employee-open-limit-edit" type="button" class="mini-btn alt">Edit</button>
            </div>
            <div id="employee-limit-box" class="inline-box hidden">
                <input id="employee-limit-value" type="number" min="0" step="0.01" value="0">
                <input id="employee-limit-reason" type="text" value="Manual credit limit update">
                <div class="inline-actions">
                    <button id="employee-limit-save" type="button" class="mini-btn">Save</button>
                    <button id="employee-limit-cancel" type="button" class="mini-btn alt">Cancel</button>
                </div>
            </div>
        </div>

        <div class="mode-history">
            <h5>Recent History</h5>
            <div id="employee-modal-history" class="mode-history-list">Loading history...</div>
        </div>
    </div>
</div>

<div id="settlement-run-modal" class="acct-modal" style="display:none;">
    <div class="acct-modal-card">
        <div class="acct-modal-head">
            <h4>Monthly Settlement Run</h4>
            <button id="close-settlement-run" type="button" class="acct-modal-close">x</button>
        </div>

        <div class="settlement-controls">
            <div class="field">
                <label for="settlement-run-month">Run Month</label>
                <input id="settlement-run-month" type="month">
            </div>
            <div class="field grow">
                <label for="settlement-notes">Notes</label>
                <input id="settlement-notes" type="text" placeholder="Optional note for this run">
            </div>
            <button id="settlement-preview-btn" class="primary-btn" type="button">Preview</button>
            <button id="settlement-apply-btn" class="primary-btn" type="button" disabled>Apply Run</button>
        </div>
        <div id="settlement-existing-run" class="settlement-existing-run hidden">
            <span id="settlement-existing-run-text"></span>
            <button id="settlement-existing-run-view" type="button" class="history-action">View Existing Run</button>
        </div>

        <div class="acct-summary settlement-summary">
            <div class="summary-card"><span>Candidates</span><strong id="settle-candidate-count">0</strong></div>
            <div class="summary-card"><span>Processable</span><strong id="settle-processable-count">0</strong></div>
            <div class="summary-card"><span>Total Debt Before</span><strong id="settle-total-before">PHP 0.00</strong></div>
            <div class="summary-card"><span>Total Deducted</span><strong id="settle-total-deducted">PHP 0.00</strong></div>
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
            <h5>Recent Settlement Runs</h5>
            <div id="settlement-runs-list" class="mode-history-list">Loading runs...</div>
        </div>
    </div>
</div>

<div id="settlement-run-details-modal" class="acct-modal" style="display:none;">
    <div class="acct-modal-card">
        <div class="acct-modal-head">
            <h4>Settlement Run Details</h4>
            <button id="close-settlement-run-details" type="button" class="acct-modal-close">x</button>
        </div>

        <div id="settlement-details-head" class="mode-profile-empty">Loading settlement run details...</div>

        <div class="acct-summary settlement-summary">
            <div class="summary-card"><span>Processed Accounts</span><strong id="settle-details-count">0</strong></div>
            <div class="summary-card"><span>Total Deducted</span><strong id="settle-details-deducted">PHP 0.00</strong></div>
            <div class="summary-card"><span>Total Debt After</span><strong id="settle-details-after">PHP 0.00</strong></div>
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

<div id="import-csv-modal" class="acct-modal" style="display:none;">
    <div class="acct-modal-card">
        <div class="acct-modal-head">
            <h4>Import Employee CSV</h4>
            <button id="close-import-csv" type="button" class="acct-modal-close">x</button>
        </div>

        <div class="import-guide">
            <p>Required headers:</p>
            <code>employee_id,name,email,user_type,monthly_salary</code>
            <p>Optional header:</p>
            <code>credit_limit</code>
            <p>Allowed category (`user_type`) values: <strong>faculty</strong>, <strong>staff</strong></p>
        </div>

        <div class="inline-box">
            <input id="import-csv-file" type="file" accept=".csv,.txt">
            <button id="import-csv-submit" type="button" class="primary-btn">Upload and Import</button>
        </div>

        <div id="import-csv-result" class="import-result"></div>
        <div id="import-csv-invalid" class="import-invalid"></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/accounting-debts.js') ?>"></script>
<?= $this->endSection() ?>
