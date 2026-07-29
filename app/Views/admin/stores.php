<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-stores.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $canManageStores = (bool) ($canManageStores ?? false); ?>
<section class="admin-stores-shell" data-can-manage-stores="<?= $canManageStores ? '1' : '0' ?>">
    <div class="admin-stores-head">
        <div>
            <h3><?= $canManageStores ? 'Store Management' : 'Assigned Stores' ?></h3>
            <p><?= $canManageStores ? 'Create stores, assign officers, and manage store status.' : 'Review store-day variances for stores assigned to you.' ?></p>
        </div>
        <?php if ($canManageStores): ?>
            <button id="open-store-modal" class="primary-btn" type="button">+ Add Store</button>
        <?php endif; ?>
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
        <button id="store-refresh-btn" class="history-action alt" type="button">Refresh</button>
    </div>

    <div id="stores-gallery" class="stores-gallery">
        <div class="store-card-empty">Loading stores...</div>
    </div>

    <p id="stores-result" class="stores-result"></p>
</section>

<div id="store-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <h4 id="store-modal-title">Add Store</h4>
            <button id="close-store-modal" type="button" class="admin-modal-close">x</button>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="store-name">Store Name</label>
                <input id="store-name" type="text" placeholder="Store name">
            </div>
            <div class="field">
                <label for="store-officer-search">Store Officer</label>
                <div class="officer-picker-row">
                    <input id="store-officer-search" type="search" placeholder="Optional: type name, email, or employee ID">
                    <button id="clear-store-officer" type="button" class="field-icon-btn" title="Clear assigned officer">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <input id="store-officer-id" type="hidden">
                <div id="store-officer-suggestions" class="officer-suggestions is-hidden"></div>
                <small id="store-officer-help" class="field-help">Stores can be created first and assigned to an officer later.</small>
            </div>
            <div class="field">
                <label for="store-supervisor-search">Store Supervisors</label>
                <div class="officer-picker-row">
                    <input id="store-supervisor-search" type="search" placeholder="Optional: type name, email, or employee ID">
                    <button id="clear-store-supervisor-search" type="button" class="field-icon-btn" title="Clear supervisor search">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div id="store-supervisor-selected" class="supervisor-selected-list"></div>
                <div id="store-supervisor-suggestions" class="officer-suggestions is-hidden"></div>
                <small class="field-help">Supervisors can review store-day shortages for this store only.</small>
            </div>
            <div class="field" id="store-logo-upload-wrap">
                <label for="store-logo-file">Store Logo</label>
                <input id="store-logo-file" type="file" accept="image/*">
                <button id="store-logo-toggle" class="field-toggle-link" type="button">Use URL instead</button>
            </div>
            <div class="field is-hidden" id="store-logo-url-wrap">
                <label for="store-logo-url">Store Logo URL</label>
                <input id="store-logo-url" type="url" placeholder="https://...">
                <button id="store-logo-toggle-url" class="field-toggle-link" type="button">Use Upload instead</button>
            </div>
            <div class="field">
                <label for="store-active">Status</label>
                <select id="store-active">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
        </div>

        <div class="admin-modal-actions">
            <button id="store-save-btn" class="primary-btn" type="button">Save</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-stores.js') ?>"></script>
<?= $this->endSection() ?>
