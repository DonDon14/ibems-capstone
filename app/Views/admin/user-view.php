<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell">
    <div class="admin-overview-head">
        <h3>User Management</h3>
        <p>Manage portal users, roles, and account details.</p>
    </div>

    <div class="summary-grid">
        <article class="summary-card">
            <span>Total Users</span>
            <strong id="uv-total-users">0</strong>
        </article>
        <article class="summary-card">
            <span>Outstanding Debt</span>
            <strong id="uv-total-debt">PHP 0.00</strong>
        </article>
        <article class="summary-card">
            <span>Active Faculty</span>
            <strong id="uv-active-faculty">0</strong>
        </article>
        <article class="summary-card">
            <span>Store Officers</span>
            <strong id="uv-store-officers">0</strong>
        </article>
    </div>

    <div class="uv-chip-row">
        <button class="uv-chip is-active" data-uv-quick="all" type="button">All Users</button>
        <button class="uv-chip" data-uv-quick="active" type="button">Active</button>
        <button class="uv-chip" data-uv-quick="debt" type="button">Debt</button>
        <button class="uv-chip" data-uv-quick="store_system" type="button">Store System</button>
    </div>

    <div class="overview-filter">
        <input id="uv-search" type="search" placeholder="Search name, email, or employee ID">
        <select id="uv-role-filter">
            <option value="">Filter by Role</option>
            <option value="ADMIN">Admin</option>
            <option value="ACCOUNTING_OFFICE">Accounting Office</option>
            <option value="STORE_SYSTEM">Store System</option>
            <option value="USER">User</option>
        </select>
        <select id="uv-type-filter">
            <option value="">Filter by Category</option>
            <option value="faculty">Faculty</option>
            <option value="staff">Staff</option>
            <option value="student">Student</option>
        </select>
        <button id="uv-search-btn" class="primary-btn" type="button">Search</button>
        <button id="uv-add-btn" class="primary-btn" type="button">+ Add User</button>
        <button id="uv-import-btn" class="primary-btn" type="button">Import CSV</button>
        <button id="uv-refresh-btn" class="history-action alt" type="button">Refresh</button>
    </div>

    <p id="uv-result" class="stores-result"></p>

    <div class="overview-table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Current Debt</th>
                    <th>Credit Limit</th>
                </tr>
            </thead>
            <tbody id="uv-body">
                <tr><td colspan="8">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</section>

<div id="uv-edit-modal" class="admin-modal" style="display:none;">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <h4>Edit User</h4>
            <button id="uv-edit-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="form-grid">
            <div class="field"><label for="uv-e-employee-id">Employee ID</label><input id="uv-e-employee-id" type="text"></div>
            <div class="field"><label for="uv-e-name">Name</label><input id="uv-e-name" type="text"></div>
            <div class="field"><label for="uv-e-email">Email</label><input id="uv-e-email" type="email"></div>
            <div class="field"><label for="uv-e-role">Role</label>
                <select id="uv-e-role">
                    <option value="USER">User</option>
                    <option value="STORE_SYSTEM">Store System</option>
                    <option value="ACCOUNTING_OFFICE">Accounting Office</option>
                    <option value="ADMIN">Admin</option>
                </select>
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

<div id="uv-add-modal" class="admin-modal" style="display:none;">
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
            <div class="field"><label for="uv-a-role">Role</label>
                <select id="uv-a-role">
                    <option value="USER">User</option>
                    <option value="STORE_SYSTEM">Store System</option>
                    <option value="ACCOUNTING_OFFICE">Accounting Office</option>
                    <option value="ADMIN">Admin</option>
                </select>
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

<div id="uv-import-modal" class="admin-modal" style="display:none;">
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
