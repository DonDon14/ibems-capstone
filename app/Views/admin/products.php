<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell">
    <div class="admin-overview-head">
        <h3>Product Management</h3>
        <p>Manage products across stores: search, review, and update product details.</p>
    </div>

    <div class="overview-actions util-flex-end mb-10">
        <button id="ap-add-btn" class="primary-btn" type="button">+ Add Product</button>
    </div>

    <div class="overview-filter">
        <input id="ap-search" type="search" placeholder="Search product, SKU, category, or store">
        <select id="ap-store-filter">
            <option value="">All Stores</option>
        </select>
        <label class="util-inline-flex-gap-6">
            <input id="ap-include-inactive" type="checkbox">
            Include inactive
        </label>
        <button id="ap-search-btn" class="primary-btn" type="button">Search</button>
        <button id="ap-refresh-btn" class="history-action alt" type="button">Refresh</button>
    </div>

    <p id="ap-result" class="stores-result"></p>

    <div class="overview-table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Store</th>
                    <th>SKU</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="ap-body">
                <tr><td colspan="9">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</section>

<div id="ap-create-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <h4>Add Product</h4>
            <button id="ap-create-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="form-grid">
            <div class="field">
                <label for="ap-c-store-id">Store</label>
                <select id="ap-c-store-id"></select>
            </div>
            <div class="field"><label for="ap-c-sku">SKU</label><input id="ap-c-sku" type="text"></div>
            <div class="field"><label for="ap-c-name">Product Name</label><input id="ap-c-name" type="text"></div>
            <div class="field"><label for="ap-c-variant">Variant/Size</label><input id="ap-c-variant" type="text"></div>
            <div class="field"><label for="ap-c-category">Category</label><input id="ap-c-category" type="text" value="General"></div>
            <div class="field"><label for="ap-c-barcode">Barcode</label><input id="ap-c-barcode" type="text"></div>
            <div class="field">
                <label for="ap-c-image-source">Image Source</label>
                <select id="ap-c-image-source">
                    <option value="upload">Upload File</option>
                    <option value="url">Image URL</option>
                </select>
            </div>
            <div class="field" id="ap-c-image-upload-wrap">
                <label for="ap-c-image-file">Image Upload</label>
                <input id="ap-c-image-file" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
            </div>
            <div class="field is-hidden" id="ap-c-image-url-wrap">
                <label for="ap-c-image-url">Image URL</label>
                <input id="ap-c-image-url" type="url" placeholder="https://...">
            </div>
            <div class="field"><label for="ap-c-price">Sell Price</label><input id="ap-c-price" type="number" min="0" step="0.01" value="0"></div>
            <div class="field"><label for="ap-c-stock">Initial Stock</label><input id="ap-c-stock" type="number" min="0" step="1" value="0"></div>
            <div class="field"><label for="ap-c-active">Status</label>
                <select id="ap-c-active">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
        </div>
        <div class="admin-modal-actions">
            <button id="ap-create-save" class="primary-btn" type="button">Create Product</button>
        </div>
    </div>
</div>

<div id="ap-edit-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card">
        <div class="admin-modal-head">
            <h4>Edit Product</h4>
            <button id="ap-edit-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div class="form-grid">
            <div class="field"><label for="ap-e-store-name">Store</label><input id="ap-e-store-name" type="text" readonly></div>
            <div class="field"><label for="ap-e-sku">SKU</label><input id="ap-e-sku" type="text"></div>
            <div class="field"><label for="ap-e-name">Product Name</label><input id="ap-e-name" type="text"></div>
            <div class="field"><label for="ap-e-variant">Variant/Size</label><input id="ap-e-variant" type="text"></div>
            <div class="field"><label for="ap-e-category">Category</label><input id="ap-e-category" type="text"></div>
            <div class="field"><label for="ap-e-barcode">Barcode</label><input id="ap-e-barcode" type="text"></div>
            <div class="field">
                <label for="ap-e-image-source">Image Source</label>
                <select id="ap-e-image-source">
                    <option value="upload">Upload File</option>
                    <option value="url">Image URL</option>
                </select>
            </div>
            <div class="field" id="ap-e-image-upload-wrap">
                <label for="ap-e-image-file">Image Upload</label>
                <input id="ap-e-image-file" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
            </div>
            <div class="field is-hidden" id="ap-e-image-url-wrap">
                <label for="ap-e-image-url">Image URL</label>
                <input id="ap-e-image-url" type="url" placeholder="https://...">
            </div>
            <div class="field"><label for="ap-e-price">Sell Price</label><input id="ap-e-price" type="number" min="0" step="0.01"></div>
            <div class="field"><label for="ap-e-active">Status</label>
                <select id="ap-e-active">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
        </div>
        <div class="admin-modal-actions">
            <button id="ap-edit-save" class="primary-btn" type="button">Save Changes</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-products.js') ?>"></script>
<?= $this->endSection() ?>
