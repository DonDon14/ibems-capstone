<?= $this->extend('layouts/store_admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>?v=20260813c">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell store-admin-dashboard" data-dashboard-url="<?= site_url('store-admin/dashboard/data') ?>" data-review-url-prefix="<?= site_url('store-admin/store-day-sessions') ?>" data-store-detail-prefix="<?= site_url('store-admin/stores') ?>">
    <?= view('components/page_header', [
        'eyebrow' => 'Assigned store oversight',
        'title' => 'Store operations overview',
        'description' => 'Monitor assigned stores, close-day readiness, variance reviews, and operator accountability.',
        'icon' => 'bi bi-shop-window',
    ]) ?>

    <div class="summary-grid store-admin-summary">
        <?= view('components/stat_card', ['title' => 'Assigned Stores', 'value' => '0', 'valueId' => 'sad-assigned-stores', 'icon' => 'bi bi-shop-window', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Open Today', 'value' => '0', 'valueId' => 'sad-open-days', 'icon' => 'bi bi-door-open', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Pending Reviews', 'value' => '0', 'valueId' => 'sad-pending-reviews', 'icon' => 'bi bi-exclamation-triangle', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Today Sales', 'value' => 'PHP 0.00', 'valueId' => 'sad-today-sales', 'icon' => 'bi bi-graph-up-arrow', 'tone' => 'finance']) ?>
    </div>

    <div class="overview-grid store-admin-grid">
        <article class="data-panel store-admin-panel">
            <header class="data-panel-head store-admin-section-head">
                <div>
                    <h4><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Pending Variance Reviews</h4>
                    <p>Independent decisions waiting for an assigned reviewer.</p>
                </div>
                <span id="sad-shortage-total" class="store-admin-exposure">Shortage exposure: PHP 0.00</span>
            </header>
            <div class="data-panel-body store-admin-panel-body">
                <div id="sad-pending-reviews-list" class="store-admin-review-list">
                    <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading variance reviews...']) ?>
                </div>
            </div>
        </article>

        <article class="data-panel store-admin-panel">
            <header class="data-panel-head store-admin-section-head">
                <div>
                    <h4><i class="bi bi-building-check" aria-hidden="true"></i> Assigned Store Status</h4>
                    <p>Today&apos;s sales, activity, and close-day readiness.</p>
                </div>
                <a class="secondary-btn btn-sm" href="<?= site_url('store-admin/stores') ?>">View all stores <i class="bi bi-arrow-right"></i></a>
            </header>
            <div class="data-panel-body store-admin-panel-body">
                <div id="sad-store-list" class="store-admin-store-list">
                    <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading assigned stores...']) ?>
                </div>
            </div>
        </article>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-admin-dashboard.js') ?>"></script>
<?= $this->endSection() ?>
