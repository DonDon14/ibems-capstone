<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/department-debt.css') ?>?v=20260824g">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section id="department-admin-page" class="department-page">
    <?= view('components/page_header', [
        'eyebrow' => 'Institution structure',
        'title' => 'Department management',
        'description' => 'Create departments and assign one accountable department head who may approve department purchases.',
        'icon' => 'bi bi-diagram-3',
        'actions' => '<button id="department-add" class="primary-btn" type="button"><i class="bi bi-plus-lg"></i> Add Department</button>',
    ]) ?>

    <div class="department-summary-grid">
        <article><span>Total departments</span><strong id="department-total">0</strong></article>
        <article><span>Active</span><strong id="department-active">0</strong></article>
        <article><span>With department head</span><strong id="department-with-head">0</strong></article>
    </div>

    <div class="department-toolbar" data-compact-filters>
        <label><span>Search</span><input id="department-search" type="search" placeholder="Code, department, or head"></label>
        <label><span>Status</span><select id="department-status"><option value="">All departments</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        <button id="department-refresh" class="secondary-btn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
    </div>

    <div class="department-table-wrap">
        <table class="table table-standard">
            <thead><tr><th>Department</th><th>Department head</th><th>Status</th><th>Action</th></tr></thead>
            <tbody id="department-admin-body"><tr><td colspan="4">Loading departments...</td></tr></tbody>
        </table>
    </div>
    <p id="department-admin-result" class="department-result" role="status" aria-live="polite"></p>
</section>

<div id="department-modal" class="department-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="department-modal-title">
    <form id="department-form" class="department-modal-card app-inset-modal-scroll">
        <header><div><span>Department setup</span><h3 id="department-modal-title">Add department</h3></div><button id="department-close" class="app-modal-close" type="button" aria-label="Close department form">&times;</button></header>
        <input id="department-id" type="hidden">
        <div class="department-form-grid">
            <label><span>Department code</span><input id="department-code" maxlength="40" required placeholder="e.g. CEA"></label>
            <label><span>Department name</span><input id="department-name" maxlength="160" required placeholder="College of Engineering and Architecture"></label>
            <div class="department-wide department-head-field">
                <label class="department-field-label" for="department-head-search">Department head</label>
                <div id="department-head-summary" class="assignment-summary"></div>
                <div id="department-head-editor" class="assignment-editor is-hidden">
                    <input id="department-head" type="hidden">
                    <div id="department-head-picker" class="department-head-picker">
                        <input id="department-head-search" type="search" role="combobox" autocomplete="off" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="department-head-suggestions" placeholder="Search name, employee ID, or email">
                    </div>
                    <div id="department-head-suggestions" class="department-head-suggestions is-hidden" role="listbox" aria-label="Department head suggestions"></div>
                </div>
            </div>
            <label><span>Status</span><select id="department-active-input"><option value="1">Active</option><option value="0">Inactive</option></select></label>
            <label><span>Status reason</span><input id="department-status-reason" maxlength="500" placeholder="Required when inactive"></label>
        </div>
        <p id="department-modal-result" class="department-result" role="status" aria-live="polite"></p>
        <footer><button id="department-cancel" class="secondary-btn" type="button">Cancel</button><button id="department-save" class="primary-btn" type="submit"><i class="bi bi-check2-circle"></i> Save Department</button></footer>
    </form>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/department-debt-pages.js') ?>?v=20260824f"></script>
<?= $this->endSection() ?>
