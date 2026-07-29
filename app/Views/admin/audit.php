<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'System accountability',
        'title' => 'Audit log',
        'description' => 'Review critical actions across administration, stores, accounting, POS, settlements, and account security.',
        'icon' => 'bi bi-journal-text',
    ]) ?>

    <div class="summary-grid grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <?= view('components/stat_card', ['title' => 'Total Events', 'value' => '0', 'valueId' => 'audit-total-events', 'icon' => 'bi bi-journal-text', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Today', 'value' => '0', 'valueId' => 'audit-today-events', 'icon' => 'bi bi-calendar-check', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Actors', 'value' => '0', 'valueId' => 'audit-actor-count', 'icon' => 'bi bi-person-badge', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Action Types', 'value' => '0', 'valueId' => 'audit-action-count', 'icon' => 'bi bi-diagram-3', 'tone' => 'alerts']) ?>
    </div>

    <div class="overview-filter audit-filter-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="audit-filter-main">
            <?= view('components/form_field', [
                'id' => 'audit-search',
                'label' => 'Search',
                'type' => 'search',
                'value' => '',
                'placeholder' => 'Action, actor, entity, or payload',
            ]) ?>
            <label class="ui-field" for="audit-action-filter">
                <span>Action</span>
                <select id="audit-action-filter">
                    <option value="">All Actions</option>
                </select>
            </label>
            <label class="ui-field" for="audit-entity-filter">
                <span>Entity</span>
                <select id="audit-entity-filter">
                    <option value="">All Entities</option>
                </select>
            </label>
            <?= view('components/form_field', [
                'id' => 'audit-date-from',
                'label' => 'From',
                'type' => 'date',
                'value' => '',
                'placeholder' => 'Select start date',
            ]) ?>
            <?= view('components/form_field', [
                'id' => 'audit-date-to',
                'label' => 'To',
                'type' => 'date',
                'value' => '',
                'placeholder' => 'Select end date',
            ]) ?>
            <label class="ui-field" for="audit-limit">
                <span>Rows</span>
                <select id="audit-limit">
                    <option value="50">50 rows</option>
                    <option value="100" selected>100 rows</option>
                    <option value="200">200 rows</option>
                </select>
            </label>
        </div>
        <div class="audit-filter-actions">
            <button id="audit-search-btn" class="primary-btn" type="button"><i class="bi bi-search"></i> Search</button>
            <button id="audit-refresh-btn" class="history-action alt" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
    </div>

    <p id="audit-result" class="stores-result text-sm font-semibold"></p>
    <p id="audit-count-text" class="uv-count-text text-sm text-slate-500">Showing 0 events</p>

    <div class="overview-table-wrap rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
        <table class="table audit-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Action</th>
                    <th>Actor</th>
                    <th>Entity</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody id="audit-body">
                <?= view('components/data_state', [
                    'tag' => 'tr',
                    'colspan' => 5,
                    'type' => 'loading',
                    'message' => 'Loading audit records...',
                ]) ?>
            </tbody>
        </table>
    </div>
</section>

<div id="audit-view-modal" class="admin-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="audit-view-title">
    <div class="admin-modal-card max-h-[92vh] w-[min(840px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <div>
                <span class="modal-eyebrow">Event details</span>
                <h4 id="audit-view-title" class="text-lg font-bold text-slate-900">Audit Event</h4>
            </div>
            <button id="audit-view-close" type="button" class="admin-modal-close" aria-label="Close audit event">x</button>
        </div>
        <div id="audit-view-content" class="audit-detail-grid"></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-audit.js') ?>"></script>
<?= $this->endSection() ?>
