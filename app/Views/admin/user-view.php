<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell">
    <div class="admin-overview-head">
        <h3>Employee Records</h3>
        <p>Manage employee and student profiles, government IDs, salary, and credit.</p>
    </div>

    <div class="summary-grid">
        <?= view('components/stat_card', ['title' => 'Total Users', 'value' => '0', 'valueId' => 'uv-total-users', 'icon' => 'bi bi-people', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Outstanding Debt', 'value' => 'PHP 0.00', 'valueId' => 'uv-total-debt', 'icon' => 'bi bi-cash-stack', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Active Faculty', 'value' => '0', 'valueId' => 'uv-active-faculty', 'icon' => 'bi bi-person-check', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Store Officers', 'value' => '0', 'valueId' => 'uv-store-officers', 'icon' => 'bi bi-person-badge', 'tone' => 'finance']) ?>
    </div>

    <div class="uv-chip-row">
        <button class="uv-chip is-active" data-uv-quick="all" type="button">All Users</button>
        <button class="uv-chip" data-uv-quick="active" type="button">Active</button>
        <button class="uv-chip" data-uv-quick="debt" type="button">Debt</button>
        <button class="uv-chip" data-uv-quick="store_system" type="button">Store System</button>
    </div>

    <div class="overview-filter uv-filter-panel">
        <div class="uv-filter-main">
            <div class="uv-search-wrap">
                <i class="bi bi-search"></i>
                <input id="uv-search" type="search" placeholder="Search name, ID, office...">
            </div>
            <label class="uv-select-field">
                <span>Role</span>
                <select id="uv-role-filter">
                    <option value="">All Roles</option>
                    <option value="ADMIN">Admin</option>
                    <option value="ACCOUNTING_OFFICE">Accounting Office</option>
                    <option value="STORE_SYSTEM">Store System</option>
                    <option value="USER">User</option>
                </select>
            </label>
            <label class="uv-select-field">
                <span>Employment Type</span>
                <select id="uv-type-filter">
                    <option value="">All Types</option>
                    <option value="faculty">Faculty</option>
                    <option value="staff">Staff</option>
                    <option value="student">Student</option>
                </select>
            </label>
        </div>
        <div class="uv-filter-actions">
            <button id="uv-search-btn" class="history-action alt" type="button"><i class="bi bi-search"></i> Search</button>
            <button id="uv-refresh-btn" class="history-action alt" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            <button id="uv-import-btn" class="secondary-btn" type="button"><i class="bi bi-upload"></i> Import CSV</button>
            <button id="uv-export-btn" class="primary-btn" type="button"><i class="bi bi-download"></i> Export CSV</button>
            <button id="uv-add-btn" class="primary-btn" type="button"><i class="bi bi-plus-lg"></i> Add User</button>
        </div>
    </div>

    <p id="uv-result" class="stores-result"></p>
    <p id="uv-count-text" class="uv-count-text">Showing 0 of 0 records</p>

    <div class="overview-table-wrap uv-list-wrap">
        <div id="uv-body" class="uv-record-list">
            <div class="uv-empty">Loading records...</div>
        </div>
    </div>
</section>

<div id="uv-edit-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <h4>Edit User</h4>
            <button id="uv-edit-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="form-grid">
            <div class="field"><label for="uv-e-employee-id">Employee ID</label><input id="uv-e-employee-id" type="text"></div>
            <div class="field"><label for="uv-e-name">Name</label><input id="uv-e-name" type="text"></div>
            <div class="field"><label for="uv-e-email">Email</label><input id="uv-e-email" type="email"></div>
            <div class="field">
                <label>Roles</label>
                <div class="role-check-grid">
                    <label><input type="checkbox" name="uv-e-roles" value="USER"> User</label>
                    <label><input type="checkbox" name="uv-e-roles" value="STORE_SYSTEM"> Store System</label>
                    <label><input type="checkbox" name="uv-e-roles" value="ACCOUNTING_OFFICE"> Accounting Office</label>
                    <label><input type="checkbox" name="uv-e-roles" value="ADMIN"> Admin</label>
                </div>
            </div>
            <div class="field"><label for="uv-e-type">Category</label>
                <select id="uv-e-type">
                    <option value="faculty">Faculty</option>
                    <option value="staff">Staff</option>
                    <option value="student">Student</option>
                </select>
            </div>
            <div class="field"><label for="uv-e-salary">Base Salary</label><input id="uv-e-salary" type="number" step="0.01" min="0"></div>
            <div class="field"><label for="uv-e-credit-limit">Credit Limit</label><input id="uv-e-credit-limit" type="number" step="0.01" min="0"></div>
            <div class="field"><label for="uv-e-active">Status</label>
                <select id="uv-e-active">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-edit-save" class="primary-btn" type="button">Save Changes</button>
        </div>
    </div>
</div>

<div id="uv-view-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <h4>Employee Details</h4>
            <button id="uv-view-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="form-grid">
            <div class="field"><label>Employee ID</label><input id="uv-v-employee-id" type="text" readonly></div>
            <div class="field"><label>Name</label><input id="uv-v-name" type="text" readonly></div>
            <div class="field"><label>Email</label><input id="uv-v-email" type="text" readonly></div>
            <div class="field"><label>Roles</label><input id="uv-v-roles" type="text" readonly></div>
            <div class="field"><label>Category</label><input id="uv-v-type" type="text" readonly></div>
            <div class="field"><label>Status</label><input id="uv-v-status" type="text" readonly></div>
            <div class="field"><label>Base Salary</label><input id="uv-v-salary" type="text" readonly></div>
            <div class="field"><label>Credit Limit</label><input id="uv-v-credit-limit" type="text" readonly></div>
            <div class="field"><label>Current Debt</label><input id="uv-v-current-debt" type="text" readonly></div>
            <div class="field"><label>Created At</label><input id="uv-v-created-at" type="text" readonly></div>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-view-edit" class="primary-btn" type="button">Edit User</button>
        </div>
    </div>
</div>

<div id="uv-add-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <h4>Add User</h4>
            <button id="uv-add-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="form-grid">
            <div class="field"><label for="uv-a-employee-id">Employee ID</label><input id="uv-a-employee-id" type="text"></div>
            <div class="field"><label for="uv-a-name">Name</label><input id="uv-a-name" type="text"></div>
            <div class="field"><label for="uv-a-email">Email</label><input id="uv-a-email" type="email"></div>
            <div class="field"><label for="uv-a-password">Password (optional)</label><input id="uv-a-password" type="password" placeholder="defaults to 123456"></div>
            <div class="field">
                <label>Roles</label>
                <div class="role-check-grid">
                    <label><input type="checkbox" name="uv-a-roles" value="USER" checked> User</label>
                    <label><input type="checkbox" name="uv-a-roles" value="STORE_SYSTEM"> Store System</label>
                    <label><input type="checkbox" name="uv-a-roles" value="ACCOUNTING_OFFICE"> Accounting Office</label>
                    <label><input type="checkbox" name="uv-a-roles" value="ADMIN"> Admin</label>
                </div>
            </div>
            <div class="field"><label for="uv-a-type">Category</label>
                <select id="uv-a-type">
                    <option value="faculty">Faculty</option>
                    <option value="staff">Staff</option>
                    <option value="student">Student</option>
                </select>
            </div>
            <div class="field"><label for="uv-a-salary">Base Salary</label><input id="uv-a-salary" type="number" step="0.01" min="0"></div>
            <div class="field"><label for="uv-a-credit-limit">Credit Limit</label><input id="uv-a-credit-limit" type="number" step="0.01" min="0"></div>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-add-save" class="primary-btn" type="button">Create User</button>
        </div>
    </div>
</div>

<div id="uv-import-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <h4>Import Users CSV</h4>
            <button id="uv-import-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="field">
            <label for="uv-import-file">CSV File</label>
            <input id="uv-import-file" type="file" accept=".csv,text/csv">
            <small>Required columns: name, email, role, user_type. Optional: employee_id, base_salary, credit_limit, is_active.</small>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-import-submit" class="primary-btn" type="button">Import</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-user-view.js') ?>"></script>
<?= $this->endSection() ?>
