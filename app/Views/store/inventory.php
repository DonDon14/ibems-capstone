<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-inventory.css') ?>?v=20260813a">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="inventory-shell">
    <?php ob_start(); ?>
        <div class="inventory-top-actions">
            <a href="/store/settings" class="secondary-btn link-reset"><i class="bi bi-tags"></i> Manage Categories</a>
            <button id="open-product-modal-top" class="primary-btn inventory-add-btn" type="button"><i class="bi bi-plus-circle"></i> Add New Product</button>
        </div>
    <?php $inventoryHeaderActions = ob_get_clean(); ?>
    <?= view('components/page_header', [
        'eyebrow' => 'Stock operations',
        'title' => 'Inventory management',
        'description' => 'View all products, adjust real stock counts, and add new stock.',
        'icon' => 'bi bi-box-seam',
        'actions' => $inventoryHeaderActions,
    ]) ?>

    <article class="inventory-card">
        <div class="inventory-list-head">
            <div>
                <h4>Products</h4>
                <p class="inventory-card-subtitle">Families stay grouped; expand them to manage exact variants.</p>
            </div>
            <div class="inventory-filter-controls">
                <label class="inventory-filter-field" for="inventory-search">
                    <span>Search</span>
                    <input id="inventory-search" type="search" placeholder="Product, SKU, barcode, supplier, or bin">
                </label>
                <label class="inventory-filter-field" for="inventory-category-filter">
                    <span>Category</span>
                    <select id="inventory-category-filter">
                        <option value="">All Categories</option>
                    </select>
                </label>
                <label class="inventory-filter-field" for="inventory-stock-filter">
                    <span>Stock</span>
                    <select id="inventory-stock-filter">
                        <option value="">All Stock</option>
                        <option value="in">In Stock</option>
                        <option value="low">Low Stock</option>
                        <option value="out">Out of Stock</option>
                    </select>
                </label>
                <button id="inventory-clear-filters" class="secondary-btn" type="button"><i class="bi bi-x-circle"></i> Clear</button>
            </div>
        </div>
        <div id="inventory-stock-summary" class="inventory-stock-summary" aria-live="polite">
            <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading stock summary...']) ?>
        </div>
        <div class="inventory-results-toolbar">
            <p id="inventory-results-count" aria-live="polite">Preparing inventory results...</p>
            <button id="inventory-toggle-families" class="secondary-btn btn-sm is-hidden" type="button"><i class="bi bi-arrows-expand"></i> Expand all variants</button>
        </div>
        <div class="inventory-table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="inventory-product-body">
                    <?= view('components/data_state', ['tag' => 'tr', 'colspan' => 5, 'type' => 'loading', 'message' => 'Loading products...']) ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="inventory-card">
        <div class="inventory-list-head">
            <div>
                <h4>Recent Stock Activity</h4>
                <p class="inventory-card-subtitle">Latest stock-in, adjustment, and sale movements for the selected store.</p>
            </div>
            <div class="inventory-movement-controls">
                <label for="inventory-movement-type">Activity Type</label>
                <select id="inventory-movement-type">
                    <option value="">All Activity</option>
                    <option value="restock">Stock In</option>
                    <option value="adjustment">Adjustments</option>
                    <option value="sale">Sales</option>
                </select>
                <button id="refresh-inventory-movements" class="secondary-btn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            </div>
        </div>
        <div id="inventory-movement-list" class="inventory-movement-list">
            <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading stock activity...']) ?>
        </div>
    </article>

    <p id="inventory-result" class="inventory-result"></p>
</section>

<div id="inventory-product-modal" class="inv-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="inventory-product-modal-title">
    <div class="inv-modal-card">
        <div class="inv-modal-head">
            <h4 id="inventory-product-modal-title">Add New Product</h4>
            <button id="close-product-modal" type="button" class="inv-modal-close" aria-label="Close new product form">x</button>
        </div>

        <div class="create-product-layout">
            <div class="create-product-section section-product-info">
                <h5>Product Information</h5>
                <div class="form-grid">
                    <div class="field">
                        <label for="new-product-sku">First Variant SKU</label>
                        <input id="new-product-sku" type="text" placeholder="Generated from name and variant">
                    </div>
                    <div class="field">
                        <label for="new-product-name">Product Name</label>
                        <input id="new-product-name" type="text" placeholder="Product name">
                    </div>
                    <div class="field">
                        <label for="new-product-variant-label">First Variant/Size</label>
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
                        <label for="new-product-barcode">Barcode / Product Code (optional)</label>
                        <div class="barcode-entry-row"><input id="new-product-barcode" type="text" inputmode="numeric" autocomplete="off" placeholder="Type the barcode digits manually"><button class="secondary-btn barcode-camera-btn" type="button" data-barcode-camera-target="new-product-barcode" title="Scan barcode with camera"><i class="bi bi-camera"></i><span>Camera</span></button></div>
                        <small>No scanner required. Type the number printed below the barcode; a USB scanner can use this same field later.</small>
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
                    <div class="field">
                        <label for="new-product-image-mode">Variant Image Mode</label>
                        <select id="new-product-image-mode">
                            <option value="shared">One image for all variants</option>
                            <option value="per_variant">Different image per variant</option>
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
                    <div class="field field-wide">
                        <label for="new-product-reason">Initial Stock Reason</label>
                        <input id="new-product-reason" type="text" value="Initial stock">
                    </div>
                </div>
                <div class="variant-builder-head">
                    <div><strong>Additional Variants <span id="product-variant-count">0</span></strong><small>Add sizes without repeating shared product information.</small></div>
                    <button id="add-product-variant" class="secondary-btn" type="button"><i class="bi bi-plus-circle"></i> Add Variant</button>
                </div>
                <div id="new-product-variants" class="variant-builder-list"></div>
            </div>

            <div class="create-product-section section-review">
                <h5>Creation Readiness</h5>
                <div id="new-product-readiness" class="create-readiness">
                    <div class="readiness-item is-pending">
                        <span></span>
                        <strong>Complete required product details.</strong>
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

<div id="inventory-product-action-modal" class="inv-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="inventory-product-action-title">
    <div class="inv-modal-card">
        <div class="inv-modal-head">
            <h4 id="inventory-product-action-title">Manage Product</h4>
            <button id="close-product-action-modal" type="button" class="inv-modal-close" aria-label="Close product management">x</button>
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
                    <span>Supplier</span>
                    <strong id="product-view-supplier">-</strong>
                </div>
                <div class="detail-item">
                    <span>Location/Bin</span>
                    <strong id="product-view-location">-</strong>
                </div>
                <div class="detail-item">
                    <span>Barcode</span>
                    <strong id="product-view-barcode">-</strong>
                </div>
                <div class="detail-item">
                    <span>Price</span>
                    <strong id="product-view-price">PHP 0.00</strong>
                </div>
                <div class="detail-item">
                    <span>Low Stock Threshold</span>
                    <strong id="product-view-low-stock">10</strong>
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
                        <label for="modal-product-supplier">Supplier (optional)</label>
                        <input id="modal-product-supplier" type="text" placeholder="Supplier name">
                    </div>
                    <div class="field">
                        <label for="modal-product-location">Location/Bin (optional)</label>
                        <input id="modal-product-location" type="text" placeholder="e.g. Aisle 3, Bin 12">
                    </div>
                    <div class="field">
                        <label for="modal-product-price">Sell Price</label>
                        <input id="modal-product-price" type="number" min="0" step="0.01" value="0">
                    </div>
                    <div class="field">
                        <label for="modal-product-low-stock">Low Stock Threshold</label>
                        <input id="modal-product-low-stock" type="number" min="0" step="1" value="10">
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
                    <div class="field field-wide">
                        <label>Product Image Preview</label>
                        <div class="modal-image-preview-grid">
                            <div class="image-preview-box">
                                <span>Current</span>
                                <img id="modal-product-current-image" alt="Current product image">
                                <small id="modal-product-current-image-empty">No current image</small>
                            </div>
                            <div class="image-preview-box">
                                <span>New</span>
                                <img id="modal-product-new-image" alt="New product image preview">
                                <small id="modal-product-new-image-empty">Upload a file or enter a URL</small>
                            </div>
                        </div>
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
                <div id="modal-adjust-preview" class="adjust-preview">
                    <div>
                        <span>Current</span>
                        <strong id="modal-adjust-current">0</strong>
                    </div>
                    <div>
                        <span>Target</span>
                        <strong id="modal-adjust-target">0</strong>
                    </div>
                    <div>
                        <span>Difference</span>
                        <strong id="modal-adjust-diff">No change</strong>
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

<div id="inventory-barcode-camera-modal" class="inv-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="inventory-barcode-camera-title">
    <div class="inv-modal-card barcode-camera-card">
        <div class="inv-modal-head"><h4 id="inventory-barcode-camera-title"><i class="bi bi-upc-scan"></i> Scan Product Barcode</h4><button id="inventory-barcode-camera-close" class="inv-modal-close" type="button" aria-label="Close barcode camera">x</button></div>
        <div id="inventory-barcode-camera-reader"></div>
        <p id="inventory-barcode-camera-status" class="inventory-result">Point the camera at the barcode. Manual entry and USB scanners remain supported.</p>
        <div class="inv-modal-actions"><button id="inventory-barcode-camera-cancel" class="secondary-btn" type="button">Cancel</button></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= base_url('assets/js/store-inventory.js') ?>?v=20260813a"></script>
<?= $this->endSection() ?>
