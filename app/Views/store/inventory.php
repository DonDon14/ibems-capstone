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
            <div class="add-menu-wrap">
                <button id="open-add-menu" class="primary-btn" type="button">+ Add</button>
                <div id="add-menu" class="add-menu" style="display:none;">
                    <button id="open-stockin-modal" type="button">Stock In</button>
                    <button id="open-product-modal" type="button">Product</button>
                </div>
            </div>
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
                        <th>Price</th>
                        <th>Current Stock</th>
                        <th>Actual Stock</th>
                        <th>Reason</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="inventory-product-body">
                    <tr><td colspan="8">Loading products...</td></tr>
                </tbody>
            </table>
        </div>
    </article>

    <p id="inventory-result" class="inventory-result"></p>
</section>

<div id="inventory-stockin-modal" class="inv-modal" style="display:none;">
    <div class="inv-modal-card">
        <div class="inv-modal-head">
            <h4>Add Inventory (Stock In)</h4>
            <button id="close-stockin-modal" type="button" class="inv-modal-close">x</button>
        </div>

        <div class="form-grid">
                <div class="field">
                    <label for="restock-product">Product</label>
                    <select id="restock-product"></select>
                </div>
                <div class="field">
                    <label for="restock-qty">Quantity</label>
                    <input id="restock-qty" type="number" min="1" step="1" value="1">
                </div>
                <div class="field">
                    <label for="restock-unit-cost">Unit Cost</label>
                    <input id="restock-unit-cost" type="number" min="0" step="0.01" value="0">
                </div>
                <div class="field">
                    <label for="restock-sell-price">Sell Price (Per Piece)</label>
                    <input id="restock-sell-price" type="number" min="0" step="0.01" value="0">
                </div>
                <div class="field">
                    <label for="restock-reason">Reason</label>
                    <input id="restock-reason" type="text" value="Stock in">
                </div>
        </div>

        <div class="projection">
            <div><span>Selling Price:</span> <strong id="proj-price">PHP 0.00</strong></div>
            <div><span>Profit Per Piece:</span> <strong id="proj-profit-piece">PHP 0.00</strong></div>
            <div><span>Total Cost:</span> <strong id="proj-cost">PHP 0.00</strong></div>
            <div><span>Expected Profit:</span> <strong id="proj-profit">PHP 0.00</strong></div>
        </div>

        <div class="inv-modal-actions">
            <button id="restock-submit" class="primary-btn" type="button">Submit Stock In</button>
        </div>
    </div>
</div>

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
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-inventory.js') ?>"></script>
<?= $this->endSection() ?>
