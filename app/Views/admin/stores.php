<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-stores.css') ?>?v=20260824a">
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

    <div class="admin-stores-filters" data-compact-filters>
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
                <option value="unassigned">Needs Assignment</option>
            </select>
        </div>
        <div class="field">
            <label for="store-sort">Sort By</label>
            <select id="store-sort">
                <option value="name_asc">Store Name (A–Z)</option>
                <option value="name_desc">Store Name (Z–A)</option>
                <option value="status">Status</option>
                <option value="newest">Newest Created</option>
                <option value="oldest">Oldest Created</option>
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

<div id="store-modal" class="admin-modal admin-stores-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="store-modal-title" aria-describedby="store-modal-description">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <div>
                <h4 id="store-modal-title">Add Store</h4>
                <p id="store-modal-description">Configure store details, staff assignments, and availability.</p>
            </div>
            <button id="close-store-modal" type="button" class="admin-modal-close" aria-label="Close store form">x</button>
        </div>

        <div class="admin-stores-modal-body">
            <section class="store-form-section">
                <div class="store-form-section-head">
                    <span><i class="bi bi-shop" aria-hidden="true"></i></span>
                    <div><h5>Store information</h5><p>Name and visual identity shown across the system.</p></div>
                </div>
                <div class="form-grid">
                    <div class="field store-name-field">
                        <label for="store-name">Store Name</label>
                        <input id="store-name" type="text" placeholder="Enter a clear store name">
                    </div>
                    <div class="field store-logo-field">
                        <span class="field-label">Store Logo <span class="optional-label">Optional</span></span>
                        <div class="store-logo-input-grid">
                            <div class="store-logo-control">
                                <label for="store-logo-source">Logo source</label>
                                <select id="store-logo-source" aria-label="Store logo source">
                                    <option value="upload">Upload File</option>
                                    <option value="url">Use Image URL</option>
                                </select>
                            </div>
                            <div id="store-logo-upload-wrap" class="store-logo-control">
                                <label for="store-logo-file">Logo file</label>
                                <input id="store-logo-file" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
                            </div>
                            <div class="store-logo-control is-hidden" id="store-logo-url-wrap"><label for="store-logo-url">Image URL</label><input id="store-logo-url" type="url" placeholder="https://..."></div>
                        </div>
                        <div class="store-logo-preview-box">
                            <img id="store-logo-preview" alt="Store logo preview" class="is-hidden">
                            <span id="store-logo-preview-empty"><i class="bi bi-image" aria-hidden="true"></i> No logo selected</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="store-form-section">
                <div class="store-form-section-head">
                    <span><i class="bi bi-people" aria-hidden="true"></i></span>
                    <div><h5>Staff assignments</h5><p>Choose the people responsible for operating and supervising this store.</p></div>
                </div>
                <div class="form-grid">
                    <div class="field store-officer-field">
                        <label for="store-officer-search">Primary Store Officer</label>
                        <div id="store-officer-summary" class="assignment-summary"></div>
                        <div id="store-officer-editor" class="assignment-editor is-hidden">
                            <div id="store-officer-picker" class="people-picker" data-selection-mode="single">
                                <div id="store-officer-selected" class="people-picker-values"></div>
                                <input id="store-officer-search" type="search" role="combobox" autocomplete="off" aria-label="Search primary store officer" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="store-officer-suggestions" placeholder="Search name, email, or employee ID">
                            </div>
                            <button id="store-officer-change-done" class="secondary-btn assignment-done" type="button">Done</button>
                        </div>
                        <input id="store-officer-id" type="hidden">
                        <div id="store-officer-suggestions" class="officer-suggestions is-hidden" role="listbox" aria-label="Store officer suggestions"></div>
                        <small id="store-officer-help" class="field-help">Required before the store can be activated.</small>
                    </div>
                    <div class="field store-supervisors-field">
                        <label for="store-supervisor-search">Store Supervisors</label>
                        <div id="store-supervisor-summary" class="assignment-summary assignment-summary-list"></div>
                        <div id="store-supervisor-editor" class="assignment-editor is-hidden">
                            <div id="store-supervisor-picker" class="people-picker" data-selection-mode="multiple">
                                <div id="store-supervisor-selected" class="people-picker-values"></div>
                                <input id="store-supervisor-search" type="search" role="combobox" autocomplete="off" aria-label="Search store supervisors" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="store-supervisor-suggestions" placeholder="Search and add one or more supervisors">
                            </div>
                            <button id="store-supervisor-change-done" class="secondary-btn assignment-done" type="button">Done</button>
                        </div>
                        <div id="store-supervisor-suggestions" class="officer-suggestions is-hidden" role="listbox" aria-multiselectable="true" aria-label="Store supervisor suggestions"></div>
                        <small class="field-help">At least one supervisor is required before activation.</small>
                    </div>
                </div>
            </section>

            <section class="store-form-section">
                <div class="store-form-section-head">
                    <span><i class="bi bi-toggle-on" aria-hidden="true"></i></span>
                    <div><h5>Availability</h5><p>Control whether this store can accept operations.</p></div>
                </div>
                <div class="form-grid">
                    <div class="field store-status-field">
                        <label for="store-active">Store Status</label>
                        <select id="store-active">
                            <option value="0">Inactive — finish setup first</option>
                            <option value="1">Active — available for operations</option>
                        </select>
                        <small class="field-help">New stores default to inactive until setup is complete. An active store must have a primary officer and at least one supervisor.</small>
                    </div>
                    <div class="field is-hidden" id="store-deactivation-reason-field" hidden>
                        <label for="store-deactivation-reason">Deactivation Reason</label>
                        <textarea id="store-deactivation-reason" rows="3" placeholder="Explain why this store is being deactivated"></textarea>
                        <small class="field-help">Open store days and unresolved variance cases must be completed first. History will remain available.</small>
                    </div>
                </div>
            </section>
        </div>

        <div class="admin-modal-actions">
            <button id="cancel-store-modal" class="secondary-btn" type="button">Cancel</button>
            <button id="store-save-btn" class="primary-btn" type="button">Create Store</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-stores.js') ?>?v=20260824b"></script>
<?= $this->endSection() ?>
