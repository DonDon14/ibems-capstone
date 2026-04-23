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
            <a href="/store/settings" class="secondary-btn link-reset">Manage Categories</a>
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
                        <th>Variant</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Current Stock</th>
                    </tr>
                </thead>
                <tbody id="inventory-product-body">
                    <tr><td colspan="7">Loading products...</td></tr>
                </tbody>
            </table>
        </div>
    </article>

    <p id="inventory-result" class="inventory-result"></p>
</section>

<div id="inventory-product-modal" class="inv-modal is-hidden">
    <div class="inv-modal-card">
        <div class="inv-modal-head">
            <h4>Add New Product</h4>
            <button id="close-product-modal" type="button" class="inv-modal-close">x</button>
        </div>

        <div class="create-product-layout">
            <div class="create-product-section section-product-info">
                <h5>Product Information</h5>
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
                        <label for="new-product-variant-label">Variant/Size (optional)</label>
                        <input id="new-product-variant-label" type="text" placeholder="e.g. 1.5L, 750ml, Can">
                    </div>
                    <div class="field">
                        <label for="new-product-category">Category</label>
                        <select id="new-product-category"></select>
                    </div>
                    <div class="field">
                        <label for="new-product-supplier">Supplier (optional)</label>
                        <input id="new-product-supplier" type="text" placeholder="Search...">
                    </div>
                    <div class="field field-wide">
                        <label for="new-product-barcode">Barcode (optional)</label>
                        <input id="new-product-barcode" type="text" placeholder="Scan or barcode or entry">
                    </div>
                </div>
            </div>

            <div class="create-product-section section-media">
                <h5>Image & Media</h5>
                <div class="form-grid image-grid">
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
                    <div class="field is-hidden" id="new-product-image-url-wrap">
                        <label for="new-product-image-url">Image URL</label>
                        <input id="new-product-image-url" type="url" placeholder="https://...">
                    </div>
                    <div class="image-preview-box field-wide">
                        <img id="new-product-image-preview" alt="Preview" class="is-hidden">
                        <span id="new-product-image-preview-empty">No image preview</span>
                    </div>
                </div>
            </div>

            <div class="create-product-section section-pricing">
                <h5>Pricing & Cost</h5>
                <div class="form-grid">
                    <div class="field field-number">
                        <label for="new-product-unit-cost">Unit Cost</label>
                        <input id="new-product-unit-cost" type="number" min="0" step="0.01" value="0">
                    </div>
                    <div class="field field-number">
                        <label for="new-product-sell-price">Sell Price</label>
                        <input id="new-product-sell-price" type="number" min="0" step="0.01" value="0">
                    </div>
                    <div class="field readonly-field field-number">
                        <label>Initial Markup</label>
                        <div id="new-product-markup" class="readonly-value">Markup: 0.00%</div>
                    </div>
                    <div class="field readonly-field field-number">
                        <label>Total Stock Value</label>
                        <div id="new-product-stock-value" class="readonly-value">Value: PHP 0.00</div>
                    </div>
                </div>
            </div>

            <div class="create-product-section section-inventory">
                <h5>Inventory & Stock</h5>
                <div class="form-grid">
                    <div class="field field-number">
                        <label for="new-product-initial-stock">Initial Stock</label>
                        <input id="new-product-initial-stock" type="number" min="0" step="1" value="0">
                    </div>
                    <div class="field">
                        <label for="new-product-location">Location/Bin (optional)</label>
                        <input id="new-product-location" type="text" placeholder="e.g. Aisle 3, Bin 12">
                    </div>
                    <div class="field field-number">
                        <label for="new-product-low-stock">Low Stock Threshold (optional)</label>
                        <input id="new-product-low-stock" type="number" min="0" step="1" value="0">
                        <small class="field-help">Threshold to trigger low stock alerts.</small>
                    </div>
                </div>
            </div>

        </div>

        <div class="inv-modal-actions">
            <button id="new-product-cancel" class="secondary-btn" type="button">Cancel</button>
            <button id="new-product-submit" class="primary-btn" type="button">Create Product</button>
        </div>
    </div>
</div>

<div id="inventory-product-action-modal" class="inv-modal is-hidden">
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
                    <span>Variant</span>
                    <strong id="product-view-variant">-</strong>
                </div>
                <div class="detail-item">
                    <span>Category</span>
                    <strong id="product-view-category">-</strong>
                </div>
                <div class="detail-item">
                    <span>Barcode</span>
                    <strong id="product-view-barcode">-</strong>
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

            <div id="product-edit-wrap" class="is-hidden">
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
                        <label for="modal-product-variant-label">Variant/Size (optional)</label>
                        <input id="modal-product-variant-label" type="text" placeholder="e.g. 1.5L, 750ml, Can">
                    </div>
                    <div class="field">
                        <label for="modal-product-category">Category</label>
                        <select id="modal-product-category"></select>
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
                    <div class="field">
                        <label for="modal-product-barcode">Barcode (optional)</label>
                        <input id="modal-product-barcode" type="text" placeholder="Barcode">
                    </div>
                    <div class="field" id="modal-product-image-upload-wrap">
                        <label for="modal-product-image-file">Image Upload</label>
                        <input id="modal-product-image-file" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
                    </div>
                    <div class="field is-hidden" id="modal-product-image-url-wrap">
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

            <div id="modal-restock-panel" class="action-panel is-hidden">
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
            <button id="modal-cancel-edit-product" class="secondary-btn is-hidden" type="button">Cancel Edit</button>
            <button id="modal-save-product" class="secondary-btn is-hidden" type="button">Save Details</button>
            <button id="modal-restock-submit" class="secondary-btn is-hidden" type="button">Submit Stock In</button>
            <button id="modal-save-adjustment" class="primary-btn" type="button">Save Adjustment</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-inventory.js') ?>"></script>
<?= $this->endSection() ?>
