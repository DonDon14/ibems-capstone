<?= $this->extend('layouts/store_admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell store-admin-dashboard" data-dashboard-url="<?= site_url('store-admin/dashboard/data') ?>" data-review-url-prefix="<?= site_url('store-admin/store-day-sessions') ?>" data-store-detail-prefix="<?= site_url('store-admin/stores') ?>">
    <div class="admin-overview-head">
        <h3>Store Admin Dashboard</h3>
        <p>Monitor assigned stores, pending close-day reviews, and operator accountability.</p>
    </div>

    <div class="summary-grid store-admin-summary">
        <?= view('components/stat_card', ['title' => 'Assigned Stores', 'value' => '0', 'valueId' => 'sad-assigned-stores', 'icon' => 'bi bi-shop-window', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Open Today', 'value' => '0', 'valueId' => 'sad-open-days', 'icon' => 'bi bi-door-open', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Pending Reviews', 'value' => '0', 'valueId' => 'sad-pending-reviews', 'icon' => 'bi bi-exclamation-triangle', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Today Sales', 'value' => 'PHP 0.00', 'valueId' => 'sad-today-sales', 'icon' => 'bi bi-graph-up-arrow', 'tone' => 'finance']) ?>
    </div>

    <div class="overview-grid store-admin-grid">
        <article class="overview-table-wrap">
            <div class="store-admin-section-head">
                <h4>Pending Variance Review</h4>
                <span id="sad-shortage-total">Shortage PHP 0.00</span>
            </div>
            <div id="sad-pending-reviews-list" class="store-admin-review-list">
                <div class="mini-bar-empty">Loading reviews...</div>
            </div>
        </article>

        <article class="overview-table-wrap">
            <div class="store-admin-section-head">
                <h4>Assigned Store Status</h4>
                <a href="<?= site_url('store-admin/stores') ?>">View all</a>
            </div>
            <div id="sad-store-list" class="store-admin-store-list">
                <div class="mini-bar-empty">Loading stores...</div>
            </div>
        </article>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-admin-dashboard.js') ?>"></script>
<?= $this->endSection() ?>
