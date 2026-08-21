<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-stores.css') ?>?v=20260821c">
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

        <div class="admin-stores-modal-body">
        <div class="form-grid">
            <div class="field">
                <label for="store-name">Store Name</label>
                <input id="store-name" type="text" placeholder="Store name">
            </div>
            <div class="field">
                <label for="store-officer-search">Store Officer</label>
                <div id="store-officer-picker" class="people-picker" data-selection-mode="single">
                    <div id="store-officer-selected" class="people-picker-values"></div>
                    <input id="store-officer-search" type="search" role="combobox" autocomplete="off"
                        aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="store-officer-suggestions"
                        placeholder="Optional: type name, email, or employee ID">
                </div>
                <input id="store-officer-id" type="hidden">
                <div id="store-officer-suggestions" class="officer-suggestions is-hidden" role="listbox" aria-label="Store officer suggestions"></div>
                <small id="store-officer-help" class="field-help">Optional while inactive. An active store requires a primary officer.</small>
            </div>
            <div class="field store-supervisors-field">
                <label for="store-supervisor-search">Store Supervisors</label>
                <div id="store-supervisor-picker" class="people-picker" data-selection-mode="multiple">
                    <div id="store-supervisor-selected" class="people-picker-values"></div>
                    <input id="store-supervisor-search" type="search" role="combobox" autocomplete="off"
                        aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="store-supervisor-suggestions"
                        placeholder="Optional: type name, email, or employee ID">
                </div>
                <div id="store-supervisor-suggestions" class="officer-suggestions is-hidden" role="listbox" aria-multiselectable="true" aria-label="Store supervisor suggestions"></div>
                <small class="field-help">Optional while inactive. An active store requires at least one supervisor.</small>
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
                    <option value="0">Inactive — finish assignments later</option>
                    <option value="1">Active — officer and supervisor required</option>
                </select>
                <small class="field-help">New stores default to inactive so they can be configured safely before opening.</small>
            </div>
            <div class="field is-hidden" id="store-deactivation-reason-field" hidden>
                <label for="store-deactivation-reason">Deactivation Reason</label>
                <textarea id="store-deactivation-reason" rows="3" placeholder="Required when deactivating a store"></textarea>
                <small class="field-help">Open store days and unresolved variance cases must be completed first. History will remain available.</small>
            </div>
        </div>
        </div>

        <div class="admin-modal-actions">
            <button id="store-save-btn" class="primary-btn" type="button">Save</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-stores.js') ?>?v=20260821c"></script>
<?= $this->endSection() ?>
