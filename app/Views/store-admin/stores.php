<?= $this->extend('layouts/store_admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-stores.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-stores-shell" data-can-manage-stores="0" data-stores-data-url="<?= site_url('store-admin/stores/data') ?>" data-store-detail-prefix="<?= site_url('store-admin/stores') ?>">
    <div class="admin-stores-head">
        <div>
            <h3>Assigned Stores</h3>
            <p>Review store-day variances and monitor stores assigned to you.</p>
        </div>
    </div>

    <div class="admin-stores-filters">
        <div class="field">
            <label for="store-search">Search</label>
            <input id="store-search" type="search" placeholder="Store name or officer">
        </div>
        <div class="field">
            <label for="store-status-filter">Status</label>
            <select id="store-status-filter">
                <option value="">All Stores</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="unassigned">Unassigned</option>
            </select>
        </div>
        <button id="store-search-btn" class="primary-btn" type="button">Search</button>
        <button id="store-refresh-btn" class="secondary-btn" type="button">Refresh</button>
    </div>

    <div id="stores-gallery" class="stores-gallery">
        <?= view('components/data_state', [
            'type' => 'loading',
            'message' => 'Loading assigned stores...',
        ]) ?>
    </div>

    <p id="stores-result" class="stores-result"></p>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-stores.js') ?>"></script>
<?= $this->endSection() ?>
