<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-stores.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $canManageStores = (bool) ($canManageStores ?? false); ?>
<section class="admin-stores-shell" data-can-manage-stores="<?= $canManageStores ? '1' : '0' ?>">
    <?= view('components/page_header', [
        'eyebrow' => $canManageStores ? 'Store administration' : 'Store supervision',
        'title' => $canManageStores ? 'Store management' : 'Assigned stores',
        'description' => $canManageStores
            ? 'Create stores, assign officers, and manage store status.'
            : 'Review store-day variances for stores assigned to you.',
        'icon' => 'bi bi-shop',
        'actions' => $canManageStores
            ? '<button id="open-store-modal" class="primary-btn" type="button">+ Add Store</button>'
            : null,
    ]) ?>

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
            'message' => 'Loading stores...',
        ]) ?>
    </div>

    <p id="stores-result" class="stores-result"></p>
</section>

<div id="store-modal" class="admin-modal admin-stores-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="store-modal-title">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <h4 id="store-modal-title">Add Store</h4>
            <button id="close-store-modal" type="button" class="admin-modal-close" aria-label="Close store form">x</button>
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
                </div>
                <input id="store-officer-id" type="hidden">
                <div id="store-officer-suggestions" class="officer-suggestions is-hidden"></div>
                <small id="store-officer-help" class="field-help">Stores can be created first and assigned to an officer later.</small>
            </div>
            <div class="field store-supervisors-field">
                <label for="store-supervisor-search">Store Supervisors</label>
                <div class="officer-picker-row">
                    <input id="store-supervisor-search" type="search" placeholder="Optional: type name, email, or employee ID">
                </div>
                <div id="store-supervisor-selected" class="supervisor-selected-list"></div>
                <div id="store-supervisor-suggestions" class="officer-suggestions is-hidden"></div>
                <small class="field-help">Supervisors can review store-day shortages for this store only.</small>
            </div>
            <div class="field store-logo-field">
                <label for="store-logo-source">Store Logo</label>
                <div class="store-logo-input-grid">
                    <select id="store-logo-source" aria-label="Store logo source">
                        <option value="upload">Upload File</option>
                        <option value="url">Use Image URL</option>
                    </select>
                    <div id="store-logo-upload-wrap">
                        <input id="store-logo-file" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
                    </div>
                    <div class="is-hidden" id="store-logo-url-wrap">
                        <input id="store-logo-url" type="url" placeholder="https://...">
                    </div>
                </div>
                <div class="store-logo-preview-box">
                    <img id="store-logo-preview" alt="Store logo preview" class="is-hidden">
                    <span id="store-logo-preview-empty">No logo preview</span>
                </div>
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
