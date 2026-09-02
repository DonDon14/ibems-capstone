<?= $this->extend('layouts/department') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/department-debt.css') ?>?v=20260825a" data-portal-page-style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section id="department-portal-dashboard" class="department-page dashboard-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Department workspace',
        'title' => 'Department dashboard',
        'description' => 'Monitor the current allocation and recent activity for departments under your authority.',
        'icon' => 'bi bi-buildings',
    ]) ?>
    <?= view('components/dashboard_period_filter') ?>
    <div id="department-portal-summary" class="dashboard-grid">
        <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading department accounts...']) ?>
    </div>
    <article class="dash-panel">
        <div class="panel-heading"><div><span>Authorization activity</span><h3>Recent department entries</h3></div><a class="secondary-btn" href="<?= site_url('department/authorizations') ?>"><i class="bi bi-shield-lock"></i> Authorization settings</a></div>
        <div id="department-portal-entries" class="stack-list" aria-live="polite">
            <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading recent entries...']) ?>
        </div>
    </article>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/department-portal.js') ?>?v=20260902a" data-portal-page-script></script>
<?= $this->endSection() ?>
