<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-inventory.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="inventory-shell">
    <div class="inventory-top">
        <div>
            <h3>Inventory Management</h3>
            <p>View all products, adjust real stock counts, and add new stock.</p>
        </div>
        <div class="inventory-top-actions">
            <div class="inventory-store-wrap">
                <label for="inventory-store-select">Store</label>
                <select id="inventory-store-select"></select>
            </div>
            <button id="open-product-modal-top" class="primary-btn inventory-add-btn" type="button"><span class="plus">+</span> Add New Product</button>
        </div>
    </div>

    <article class="inventory-card">
        <div class="inventory-list-head">
            <h4>Products</h4>
            <input id="inventory-search" type="search" placeholder="Search product name or SKU">
        </div>
        <div class="inventory-table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Current Stock</th>
                    </tr>
                </thead>
                <tbody id="inventory-product-body">
                    <tr><td colspan="6">Loading products...</td></tr>
                </tbody>
            </table>
        </div>
    </article>

    <p id="inventory-result" class="inventory-result"></p>
</section>

<div id="inventory-product-modal" class="inv-modal" style="display:none;">
    <div class="inv-modal-card">
        <div class="inv-modal-head">
            <h4>Add New Product</h4>
            <button id="close-product-modal" type="button" class="inv-modal-close">x</button>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="new-product-sku">SKU</label>
                <input id="new-product-sku" type="text" placeholder="e.g. SNACK-001">
            </div>
            <div class="field">
                <label for="new-product-name">Product Name</label>
                <input id="new-product-name" type="text" placeholder="Product name">
            </div>
            <div class="field">
                <label for="new-product-category">Category</label>
                <input id="new-product-category" type="text" placeholder="e.g. Drinks, Snacks">
            </div>
            <div class="field">
                <label for="new-product-image-source">Product Image Source</label>
                <select id="new-product-image-source">
                    <option value="upload">Upload File</option>
                    <option value="url">Use Image URL</option>
                </select>
            </div>
            <div class="field" id="new-product-image-upload-wrap">
                <label for="new-product-image-file">Image Upload</label>
                <input id="new-product-image-file" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
            </div>
            <div class="field" id="new-product-image-url-wrap" style="display:none;">
                <label for="new-product-image-url">Image URL</label>
                <input id="new-product-image-url" type="url" placeholder="https://...">
            </div>
            <div class="field">
                <label for="new-product-sell-price">Sell Price</label>
                <input id="new-product-sell-price" type="number" min="0" step="0.01" value="0">
            </div>
            <div class="field">
                <label for="new-product-initial-stock">Initial Stock</label>
                <input id="new-product-initial-stock" type="number" min="0" step="1" value="0">
            </div>
            <div class="field">
                <label for="new-product-unit-cost">Unit Cost (if initial stock > 0)</label>
                <input id="new-product-unit-cost" type="number" min="0" step="0.01" value="0">
            </div>
            <div class="field">
                <label for="new-product-reason">Reason</label>
                <input id="new-product-reason" type="text" value="Initial stock">
            </div>
        </div>

        <div class="inv-modal-actions">
            <button id="new-product-submit" class="primary-btn" type="button">Create Product</button>
        </div>
    </div>
</div>

<div id="inventory-product-action-modal" class="inv-modal" style="display:none;">
    <div class="inv-modal-card">
        <div class="inv-modal-head">
            <h4>Product Actions</h4>
            <button id="close-product-action-modal" type="button" class="inv-modal-close">x</button>
        </div>

        <div class="product-actions-body">
            <div id="product-action-info" class="product-action-info"></div>

            <div class="product-detail-view">
                <div class="detail-item">
                    <span>SKU</span>
                    <strong id="product-view-sku">-</strong>
                </div>
                <div class="detail-item">
                    <span>Name</span>
                    <strong id="product-view-name">-</strong>
                </div>
                <div class="detail-item">
                    <span>Category</span>
                    <strong id="product-view-category">-</strong>
                </div>
                <div class="detail-item">
                    <span>Price</span>
                    <strong id="product-view-price">PHP 0.00</strong>
                </div>
                <div class="detail-item detail-item-wide">
                    <span>Image</span>
                    <strong id="product-view-image">Not set</strong>
                </div>
            </div>

            <div id="product-edit-wrap" style="display:none;">
                <div class="form-grid">
                    <div class="field">
                        <label for="modal-product-sku">SKU</label>
                        <input id="modal-product-sku" type="text" placeholder="SKU">
                    </div>
                    <div class="field">
                        <label for="modal-product-name">Product Name</label>
                        <input id="modal-product-name" type="text" placeholder="Product name">
                    </div>
                    <div class="field">
                        <label for="modal-product-category">Category</label>
                        <input id="modal-product-category" type="text" placeholder="Category">
                    </div>
                    <div class="field">
                        <label for="modal-product-price">Sell Price</label>
                        <input id="modal-product-price" type="number" min="0" step="0.01" value="0">
                    </div>
                    <div class="field">
                        <label for="modal-product-image-source">Product Image Source</label>
                        <select id="modal-product-image-source">
                            <option value="upload">Upload File</option>
                            <option value="url">Use Image URL</option>
                        </select>
                    </div>
                    <div class="field" id="modal-product-image-upload-wrap">
                        <label for="modal-product-image-file">Image Upload</label>
                        <input id="modal-product-image-file" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
                    </div>
                    <div class="field" id="modal-product-image-url-wrap" style="display:none;">
                        <label for="modal-product-image-url">Image URL</label>
                        <input id="modal-product-image-url" type="url" placeholder="https://...">
                    </div>
                </div>
            </div>

            <div class="modal-action-tabs">
                <button id="modal-panel-adjust-btn" class="panel-tab is-active" type="button">Adjust Stock</button>
                <button id="modal-panel-restock-btn" class="panel-tab" type="button">Stock In</button>
            </div>

            <div id="modal-adjust-panel" class="action-panel">
                <div class="form-grid">
                    <div class="field">
                        <label for="modal-actual-stock">Actual Stock</label>
                        <input id="modal-actual-stock" type="number" min="0" step="1" value="0">
                    </div>
                    <div class="field">
                        <label for="modal-stock-reason">Reason</label>
                        <input id="modal-stock-reason" type="text" value="Physical count adjustment">
                    </div>
                </div>
            </div>

            <div id="modal-restock-panel" class="action-panel" style="display:none;">
                <div class="form-grid">
                    <div class="field">
                        <label for="modal-restock-qty">Quantity</label>
                        <input id="modal-restock-qty" type="number" min="1" step="1" value="1">
                    </div>
                    <div class="field">
                        <label for="modal-restock-unit-cost">Unit Cost</label>
                        <input id="modal-restock-unit-cost" type="number" min="0" step="0.01" value="0">
                    </div>
                    <div class="field">
                        <label for="modal-restock-sell-price">Sell Price (Per Piece)</label>
                        <input id="modal-restock-sell-price" type="number" min="0" step="0.01" value="0">
                    </div>
                    <div class="field">
                        <label for="modal-restock-reason">Reason</label>
                        <input id="modal-restock-reason" type="text" value="Stock in">
                    </div>
                </div>

                <div class="projection">
                    <div><span>Selling Price:</span> <strong id="modal-proj-price">PHP 0.00</strong></div>
                    <div><span>Profit Per Piece:</span> <strong id="modal-proj-profit-piece">PHP 0.00</strong></div>
                    <div><span>Total Cost:</span> <strong id="modal-proj-cost">PHP 0.00</strong></div>
                    <div><span>Expected Profit:</span> <strong id="modal-proj-profit">PHP 0.00</strong></div>
                </div>
            </div>
        </div>

        <div class="inv-modal-actions">
            <button id="modal-start-edit-product" class="secondary-btn" type="button">Edit Product</button>
            <button id="modal-cancel-edit-product" class="secondary-btn" type="button" style="display:none;">Cancel Edit</button>
            <button id="modal-save-product" class="secondary-btn" type="button" style="display:none;">Save Details</button>
            <button id="modal-restock-submit" class="secondary-btn" type="button" style="display:none;">Submit Stock In</button>
            <button id="modal-save-adjustment" class="primary-btn" type="button">Save Adjustment</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-inventory.js') ?>"></script>
<?= $this->endSection() ?>
