<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell space-y-5">
    <div class="admin-overview-head">
        <h3 class="text-3xl font-bold tracking-tight text-slate-900">Employee Records</h3>
        <p class="mt-1 text-base text-slate-600">Manage employee and student profiles, government IDs, salary, and credit.</p>
    </div>

    <div class="summary-grid grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <?= view('components/stat_card', ['title' => 'Total Users', 'value' => '0', 'valueId' => 'uv-total-users', 'icon' => 'bi bi-people', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Outstanding Debt', 'value' => 'PHP 0.00', 'valueId' => 'uv-total-debt', 'icon' => 'bi bi-cash-stack', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Active Faculty', 'value' => '0', 'valueId' => 'uv-active-faculty', 'icon' => 'bi bi-person-check', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Store Officers', 'value' => '0', 'valueId' => 'uv-store-officers', 'icon' => 'bi bi-person-badge', 'tone' => 'finance']) ?>
    </div>

    <div class="uv-chip-row flex flex-wrap gap-2">
        <button class="uv-chip is-active rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:-translate-y-0.5 hover:bg-slate-50" data-uv-quick="all" type="button">All Users</button>
        <button class="uv-chip rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:-translate-y-0.5 hover:bg-slate-50" data-uv-quick="active" type="button">Active</button>
        <button class="uv-chip rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:-translate-y-0.5 hover:bg-slate-50" data-uv-quick="debt" type="button">Debt</button>
        <button class="uv-chip rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:-translate-y-0.5 hover:bg-slate-50" data-uv-quick="store_system" type="button">Store System</button>
    </div>

    <div class="overview-filter uv-filter-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="uv-filter-main flex flex-wrap items-end gap-3">
            <div class="uv-search-wrap relative min-w-[260px] grow">
                <i class="bi bi-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input id="uv-search" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm text-slate-700 outline-none ring-0 transition focus:border-blue-300 focus:bg-white" type="search" placeholder="Search name, ID, office...">
            </div>
            <label class="uv-select-field min-w-[170px] grow basis-[170px]">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Role</span>
                <select id="uv-role-filter" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-blue-300">
                    <option value="">All Roles</option>
                    <option value="ADMIN">Admin</option>
                    <option value="ACCOUNTING_OFFICE">Accounting Office</option>
                    <option value="STORE_SYSTEM">Store System</option>
                    <option value="USER">User</option>
                </select>
            </label>
            <label class="uv-select-field min-w-[170px] grow basis-[170px]">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Employment Type</span>
                <select id="uv-type-filter" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-blue-300">
                    <option value="">All Types</option>
                    <option value="faculty">Faculty</option>
                    <option value="staff">Staff</option>
                    <option value="student">Student</option>
                </select>
            </label>
        </div>
        <div class="uv-filter-actions mt-3 flex flex-wrap gap-2">
            <button id="uv-search-btn" class="history-action alt inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" type="button"><i class="bi bi-search"></i> Search</button>
            <button id="uv-refresh-btn" class="history-action alt inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            <button id="uv-import-btn" class="secondary-btn inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" type="button"><i class="bi bi-upload"></i> Import CSV</button>
            <button id="uv-export-btn" class="primary-btn inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-blue-700" type="button"><i class="bi bi-download"></i> Export CSV</button>
            <button id="uv-add-btn" class="primary-btn inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-blue-700" type="button"><i class="bi bi-plus-lg"></i> Add User</button>
        </div>
    </div>

    <p id="uv-result" class="stores-result text-sm font-semibold"></p>
    <p id="uv-count-text" class="uv-count-text text-sm text-slate-500">Showing 0 of 0 records</p>

    <div class="overview-table-wrap uv-list-wrap rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
        <div id="uv-body" class="uv-record-list grid gap-2">
            <div class="uv-empty rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">Loading records...</div>
        </div>
    </div>
</section>

<div id="uv-edit-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card max-h-[92vh] w-[min(980px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Edit User</h4>
            <button id="uv-edit-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="form-grid grid gap-3 md:grid-cols-2">
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-e-employee-id">Employee ID</label><input id="uv-e-employee-id" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="text"></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-e-name">Name</label><input id="uv-e-name" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="text"></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-e-email">Email</label><input id="uv-e-email" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="email"></div>
            <div class="field">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Roles</label>
                <div class="role-check-grid grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                    <label class="inline-flex items-center gap-2"><input class="h-4 w-4" type="checkbox" name="uv-e-roles" value="USER"> User</label>
                    <label class="inline-flex items-center gap-2"><input class="h-4 w-4" type="checkbox" name="uv-e-roles" value="STORE_SYSTEM"> Store System</label>
                    <label class="inline-flex items-center gap-2"><input class="h-4 w-4" type="checkbox" name="uv-e-roles" value="ACCOUNTING_OFFICE"> Accounting Office</label>
                    <label class="inline-flex items-center gap-2"><input class="h-4 w-4" type="checkbox" name="uv-e-roles" value="ADMIN"> Admin</label>
                </div>
            </div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-e-type">Category</label>
                <select id="uv-e-type" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="faculty">Faculty</option>
                    <option value="staff">Staff</option>
                    <option value="student">Student</option>
                </select>
            </div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-e-salary">Base Salary</label><input id="uv-e-salary" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="number" step="0.01" min="0"></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-e-credit-limit">Credit Limit</label><input id="uv-e-credit-limit" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="number" step="0.01" min="0"></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-e-active">Status</label>
                <select id="uv-e-active" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-edit-save" class="primary-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700" type="button">Save Changes</button>
        </div>
    </div>
</div>

<div id="uv-view-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card max-h-[92vh] w-[min(980px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Employee Details</h4>
            <button id="uv-view-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="form-grid grid gap-3 md:grid-cols-2">
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Employee ID</label><input id="uv-v-employee-id" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Name</label><input id="uv-v-name" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email</label><input id="uv-v-email" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Roles</label><input id="uv-v-roles" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Category</label><input id="uv-v-type" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</label><input id="uv-v-status" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Base Salary</label><input id="uv-v-salary" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Credit Limit</label><input id="uv-v-credit-limit" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current Debt</label><input id="uv-v-current-debt" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Created At</label><input id="uv-v-created-at" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-view-edit" class="primary-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700" type="button">Edit User</button>
        </div>
    </div>
</div>

<div id="uv-add-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card max-h-[92vh] w-[min(980px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Add User</h4>
            <button id="uv-add-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="form-grid grid gap-3 md:grid-cols-2">
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-a-employee-id">Employee ID</label><input id="uv-a-employee-id" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="text"></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-a-name">Name</label><input id="uv-a-name" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="text"></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-a-email">Email</label><input id="uv-a-email" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="email"></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-a-password">Password (optional)</label><input id="uv-a-password" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="password" placeholder="defaults to 123456"></div>
            <div class="field">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Roles</label>
                <div class="role-check-grid grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                    <label class="inline-flex items-center gap-2"><input class="h-4 w-4" type="checkbox" name="uv-a-roles" value="USER" checked> User</label>
                    <label class="inline-flex items-center gap-2"><input class="h-4 w-4" type="checkbox" name="uv-a-roles" value="STORE_SYSTEM"> Store System</label>
                    <label class="inline-flex items-center gap-2"><input class="h-4 w-4" type="checkbox" name="uv-a-roles" value="ACCOUNTING_OFFICE"> Accounting Office</label>
                    <label class="inline-flex items-center gap-2"><input class="h-4 w-4" type="checkbox" name="uv-a-roles" value="ADMIN"> Admin</label>
                </div>
            </div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-a-type">Category</label>
                <select id="uv-a-type" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="faculty">Faculty</option>
                    <option value="staff">Staff</option>
                    <option value="student">Student</option>
                </select>
            </div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-a-salary">Base Salary</label><input id="uv-a-salary" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="number" step="0.01" min="0"></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-a-credit-limit">Credit Limit</label><input id="uv-a-credit-limit" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="number" step="0.01" min="0"></div>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-add-save" class="primary-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700" type="button">Create User</button>
        </div>
    </div>
</div>

<div id="uv-import-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card max-h-[92vh] w-[min(720px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Import Users CSV</h4>
            <button id="uv-import-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="field space-y-1">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-import-file">CSV File</label>
            <input id="uv-import-file" class="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700" type="file" accept=".csv,text/csv">
            <small class="text-sm text-slate-500">Required columns: name, email, role, user_type. Optional: employee_id, base_salary, credit_limit, is_active.</small>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-import-submit" class="primary-btn inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700" type="button">Import</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-user-view.js') ?>"></script>
<?= $this->endSection() ?>
