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
                        <label for="new-product-item-type">Product Type</label>
                        <select id="new-product-item-type"><option value="stock_item">Stocked item</option><option value="prepared_item">Prepared food</option><option value="manufactured_item">Manufactured product</option><option value="service">Service</option><option value="deposit">Refundable deposit</option></select>
                    </div>
                    <div class="field">
                        <label for="new-product-unit-code">Selling Unit</label>
                        <select id="new-product-unit-code"><option value="piece">Piece</option><option value="bottle">Bottle</option><option value="can">Can</option><option value="pack">Pack</option><option value="serving">Serving</option><option value="meal">Meal</option><option value="tray">Tray</option><option value="gallon">Gallon</option><option value="liter">Liter</option><option value="container">Container</option><option value="service">Service</option></select>
                    </div>
                    <div class="field field-wide">
                        <label for="new-product-stock-policy">Inventory Behavior</label>
                        <select id="new-product-stock-policy"><option value="tracked">Track and deduct stock</option><option value="untracked">Sell without stock deduction</option></select>
                        <small id="new-product-behavior-help">Use tracked stock for physical goods and counted prepared batches.</small>
                    </div>
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
    <div class="inv-modal-card app-inset-modal-card inventory-product-action-card">
        <div class="inv-modal-head">
            <h4 id="inventory-product-action-title">Manage Product</h4>
            <button id="close-product-action-modal" type="button" class="inv-modal-close" aria-label="Close product management">x</button>
        </div>

        <div class="product-actions-body app-inset-modal-scroll">
            <div id="product-action-info" class="product-action-info"></div>

            <div class="product-detail-view">
                <div class="detail-item"><span>Product Type</span><strong id="product-view-item-type">Stocked item</strong></div>
                <div class="detail-item"><span>Selling Unit</span><strong id="product-view-unit-code">Piece</strong></div>
                <div class="detail-item"><span>Inventory Behavior</span><strong id="product-view-stock-policy">Tracked</strong></div>
                <div class="detail-item">
                    <span>Variant</span>
                    <strong id="product-view-variant">-</strong>
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
                    <span>Low Stock Threshold</span>
                    <strong id="product-view-low-stock">10</strong>
                </div>
            </div>

            <div id="product-edit-wrap" class="is-hidden">
                <div class="form-grid">
                    <div class="field"><label for="modal-product-item-type">Product Type</label><select id="modal-product-item-type"><option value="stock_item">Stocked item</option><option value="prepared_item">Prepared food</option><option value="manufactured_item">Manufactured product</option><option value="service">Service</option><option value="deposit">Refundable deposit</option></select></div>
                    <div class="field"><label for="modal-product-unit-code">Selling Unit</label><select id="modal-product-unit-code"><option value="piece">Piece</option><option value="bottle">Bottle</option><option value="can">Can</option><option value="pack">Pack</option><option value="serving">Serving</option><option value="meal">Meal</option><option value="tray">Tray</option><option value="gallon">Gallon</option><option value="liter">Liter</option><option value="container">Container</option><option value="service">Service</option></select></div>
                    <div class="field"><label for="modal-product-stock-policy">Inventory Behavior</label><select id="modal-product-stock-policy"><option value="tracked">Track and deduct stock</option><option value="untracked">Sell without stock deduction</option></select></div>
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

            <div class="modal-action-tabs" role="tablist" aria-label="Stock action">
                <button id="modal-panel-adjust-btn" class="panel-tab is-active" role="tab" aria-selected="true" aria-controls="modal-adjust-panel" type="button">Adjust Stock</button>
                <button id="modal-panel-restock-btn" class="panel-tab" role="tab" aria-selected="false" aria-controls="modal-restock-panel" type="button">Stock In</button>
            </div>

            <div id="modal-adjust-panel" class="action-panel" role="tabpanel" aria-labelledby="modal-panel-adjust-btn" aria-hidden="false">
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
                <p id="modal-adjust-hint" class="adjust-save-hint" role="status" aria-live="polite">Enter a different stock count to enable saving.</p>
            </div>

            <div id="modal-restock-panel" class="action-panel is-hidden" role="tabpanel" aria-labelledby="modal-panel-restock-btn" aria-hidden="true">
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
