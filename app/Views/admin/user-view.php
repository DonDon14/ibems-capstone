<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'Identity administration',
        'title' => 'Employee records',
        'description' => 'Manage identities, roles, salary-grade profiles, and each employee\'s adjustable credit percentage.',
        'icon' => 'bi bi-people',
    ]) ?>

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
            <label class="uv-search-field min-w-[260px] grow">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Search users</span>
                <span class="uv-search-wrap relative block">
                    <input id="uv-search" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm text-slate-700 outline-none ring-0 transition focus:border-blue-300 focus:bg-white" type="search" placeholder="Search name, ID, office...">
                </span>
            </label>
            <label class="uv-select-field min-w-[170px] grow basis-[170px]">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Role</span>
                <select id="uv-role-filter" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-blue-300">
                    <option value="">All Roles</option>
                    <option value="ADMIN">Admin</option>
                    <option value="ACCOUNTING_OFFICE">Accounting Office</option>
                    <option value="STORE_SUPERVISOR">Store Supervisor</option>
                    <option value="STORE_SYSTEM">Store System</option>
                    <option value="USER">User</option>
                </select>
            </label>
            <label class="uv-select-field min-w-[170px] grow basis-[170px]">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Sort</span>
                <select id="uv-sort" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-blue-300">
                    <option value="name:asc">Name A-Z</option>
                    <option value="name:desc">Name Z-A</option>
                    <option value="debt:desc">Highest debt</option>
                    <option value="debt:asc">Lowest debt</option>
                    <option value="credit:desc">Highest credit limit</option>
                    <option value="type:asc">Employment type</option>
                </select>
            </label>
            <label class="uv-select-field min-w-[120px] grow basis-[120px]">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Rows</span>
                <select id="uv-page-size" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-blue-300">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
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
            <button id="uv-search-btn" class="secondary-btn" type="button"><i class="bi bi-search"></i> Search</button>
            <button id="uv-refresh-btn" class="secondary-btn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            <button id="uv-import-btn" class="secondary-btn" type="button"><i class="bi bi-upload"></i> Import CSV</button>
            <button id="uv-export-btn" class="primary-btn" type="button"><i class="bi bi-download"></i> Export CSV</button>
            <button id="uv-add-btn" class="primary-btn" type="button"><i class="bi bi-plus-lg"></i> Add User</button>
        </div>
    </div>

    <p id="uv-result" class="stores-result text-sm font-semibold"></p>
    <p id="uv-count-text" class="uv-count-text text-sm text-slate-500">Showing 0 of 0 records</p>

    <section class="record-panel uv-list-wrap">
        <div id="uv-body" class="record-list uv-record-list">
            <?= view('components/data_state', [
                'type' => 'loading',
                'message' => 'Loading employee records...',
            ]) ?>
        </div>
    </section>
    <div id="uv-pager" class="overview-pager" aria-label="User management pages"></div>
</section>

<div id="uv-edit-modal" class="admin-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="uv-edit-title">
    <div class="admin-modal-card max-h-[92vh] w-[min(980px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 id="uv-edit-title" class="text-lg font-bold text-slate-900">Edit User</h4>
            <button id="uv-edit-close" type="button" class="admin-modal-close" aria-label="Close edit user dialog">x</button>
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
                    <label class="inline-flex items-center gap-2"><input class="h-4 w-4" type="checkbox" name="uv-e-roles" value="STORE_SUPERVISOR"> Store Supervisor</label>
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
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-e-active">Status</label>
                <select id="uv-e-active" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            <div id="uv-e-financial-fields" class="grid gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 md:col-span-2 md:grid-cols-3">
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-e-employment-type">Employment Type</label><select id="uv-e-employment-type" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="plantilla">Plantilla</option><option value="cos">COS</option><option value="part_time">Part-time</option></select></div>
                <div class="field space-y-1 md:col-span-2"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-e-schedule">Salary Schedule</label><select id="uv-e-schedule" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></select></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-e-grade">Salary Grade</label><select id="uv-e-grade" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></select></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-e-step">Step</label><select id="uv-e-step" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></select></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-e-effective">Effective Date</label><input id="uv-e-effective" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="date"></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-e-credit-percent">Credit Percentage</label><div class="relative"><input id="uv-e-credit-percent" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 pr-9 text-sm" type="number" min="0" max="100" step="0.01"><span class="pointer-events-none absolute right-3 top-3 text-sm text-slate-500">%</span></div></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-e-salary-preview">Monthly Salary</label><input id="uv-e-salary-preview" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="text" readonly></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-e-limit-preview">Credit Limit</label><input id="uv-e-limit-preview" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="text" readonly></div>
                <p class="text-xs text-slate-600 md:col-span-3">Salary comes from the selected schedule. Changing the percentage recalculates future available credit without changing existing debt.</p>
            </div>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-edit-save" class="primary-btn" type="button">Save Changes</button>
        </div>
    </div>
</div>

<div id="uv-view-modal" class="admin-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="uv-view-title">
    <div class="admin-modal-card max-h-[92vh] w-[min(980px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 id="uv-view-title" class="text-lg font-bold text-slate-900">Employee Details</h4>
            <button id="uv-view-close" type="button" class="admin-modal-close" aria-label="Close employee details dialog">x</button>
        </div>
        <div class="form-grid grid gap-3 md:grid-cols-2">
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-employee-id">Employee ID</label><input id="uv-v-employee-id" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-name">Name</label><input id="uv-v-name" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-email">Email</label><input id="uv-v-email" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-roles">Roles</label><input id="uv-v-roles" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-type">Category</label><input id="uv-v-type" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-status">Status</label><input id="uv-v-status" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-salary">Base Salary</label><input id="uv-v-salary" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-employment">Employment Type</label><input id="uv-v-employment" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-grade">Salary Grade</label><input id="uv-v-grade" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-effective">Salary Effective Date</label><input id="uv-v-effective" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label id="uv-v-credit-limit-label" class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-credit-limit">Credit Limit</label><input id="uv-v-credit-limit" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-current-debt">Current Debt</label><input id="uv-v-current-debt" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
            <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-v-created-at">Created At</label><input id="uv-v-created-at" class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm" type="text" readonly></div>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-view-edit" class="primary-btn" type="button">Edit User</button>
        </div>
    </div>
</div>

<div id="uv-add-modal" class="admin-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="uv-add-title">
    <div class="admin-modal-card max-h-[92vh] w-[min(980px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 id="uv-add-title" class="text-lg font-bold text-slate-900">Add User</h4>
            <button id="uv-add-close" type="button" class="admin-modal-close" aria-label="Close add user dialog">x</button>
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
                    <label class="inline-flex items-center gap-2"><input class="h-4 w-4" type="checkbox" name="uv-a-roles" value="STORE_SUPERVISOR"> Store Supervisor</label>
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
            <div id="uv-a-financial-fields" class="grid gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 md:col-span-2 md:grid-cols-3">
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-a-employment-type">Employment Type</label><select id="uv-a-employment-type" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="plantilla">Plantilla</option><option value="cos">COS</option><option value="part_time">Part-time</option></select></div>
                <div class="field space-y-1 md:col-span-2"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-a-schedule">Salary Schedule</label><select id="uv-a-schedule" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></select></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-a-grade">Salary Grade</label><select id="uv-a-grade" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></select></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-a-step">Step</label><select id="uv-a-step" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></select></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-a-effective">Effective Date</label><input id="uv-a-effective" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="date"></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-a-credit-percent">Credit Percentage</label><div class="relative"><input id="uv-a-credit-percent" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 pr-9 text-sm" type="number" min="0" max="100" step="0.01" value="25"><span class="pointer-events-none absolute right-3 top-3 text-sm text-slate-500">%</span></div></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-a-salary-preview">Monthly Salary</label><input id="uv-a-salary-preview" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="text" readonly></div>
                <div class="field space-y-1"><label class="text-xs font-semibold uppercase tracking-wide text-slate-600" for="uv-a-limit-preview">Credit Limit</label><input id="uv-a-limit-preview" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="text" readonly></div>
                <p class="text-xs text-slate-600 md:col-span-3">New employees default to the standard SG 11, Step 1 profile and 25% credit. Both remain editable by Admin or Accounting.</p>
            </div>
        </div>
        <div class="admin-modal-actions">
            <button id="uv-add-save" class="primary-btn" type="button">Create User</button>
        </div>
    </div>
</div>

<div id="uv-import-modal" class="admin-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="uv-import-title">
    <div class="admin-modal-card max-h-[92vh] w-[min(720px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 id="uv-import-title" class="text-lg font-bold text-slate-900">Import Users CSV</h4>
            <button id="uv-import-close" type="button" class="admin-modal-close" aria-label="Close import users dialog">x</button>
        </div>
        <div class="field space-y-1">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="uv-import-file">CSV File</label>
            <input id="uv-import-file" class="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700" type="file" accept=".csv,text/csv">
            <small class="text-sm text-slate-500">Required columns: name, email, user_type. Role is optional and defaults to USER. Optional: employee_id, role, is_active. New Faculty and Staff use the current standard SG 11, Step 1 profile and the default credit percentage.</small>
            <p id="uv-import-result" class="min-h-5 text-sm font-semibold" role="status" aria-live="polite"></p>
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
