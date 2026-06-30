<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell space-y-5">
    <div class="admin-overview-head">
        <h3 class="text-3xl font-bold tracking-tight text-slate-900">Audit Log</h3>
        <p class="mt-1 text-base text-slate-600">Review critical system actions across admin, store, accounting, POS, settlement, and user security flows.</p>
    </div>

    <div class="summary-grid grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <?= view('components/stat_card', ['title' => 'Total Events', 'value' => '0', 'valueId' => 'audit-total-events', 'icon' => 'bi bi-journal-text', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Today', 'value' => '0', 'valueId' => 'audit-today-events', 'icon' => 'bi bi-calendar-check', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Actors', 'value' => '0', 'valueId' => 'audit-actor-count', 'icon' => 'bi bi-person-badge', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Action Types', 'value' => '0', 'valueId' => 'audit-action-count', 'icon' => 'bi bi-diagram-3', 'tone' => 'alerts']) ?>
    </div>

    <div class="overview-filter audit-filter-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="audit-filter-main">
            <input id="audit-search" type="search" placeholder="Search action, actor, entity, or payload">
            <select id="audit-action-filter">
                <option value="">All Actions</option>
            </select>
            <select id="audit-entity-filter">
                <option value="">All Entities</option>
            </select>
            <input id="audit-date-from" type="date" aria-label="Date from">
            <input id="audit-date-to" type="date" aria-label="Date to">
            <select id="audit-limit">
                <option value="50">50 rows</option>
                <option value="100" selected>100 rows</option>
                <option value="200">200 rows</option>
            </select>
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
                <tr><td colspan="5">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</section>

<div id="audit-view-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card max-h-[92vh] w-[min(840px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Audit Event</h4>
            <button id="audit-view-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div id="audit-view-content" class="audit-detail-grid"></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-audit.js') ?>"></script>
<?= $this->endSection() ?>
