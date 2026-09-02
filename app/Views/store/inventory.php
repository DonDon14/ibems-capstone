<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-inventory.css') ?>?v=20260824e">
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
            <div class="inventory-filter-controls" data-compact-filters>
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
                <label class="inventory-filter-field" for="inventory-sort">
                    <span>Sort</span>
                    <select id="inventory-sort">
                        <option value="name:asc">Product A-Z</option>
                        <option value="name:desc">Product Z-A</option>
                        <option value="stock:asc">Lowest stock</option>
                        <option value="stock:desc">Highest stock</option>
                        <option value="price:asc">Lowest price</option>
                        <option value="price:desc">Highest price</option>
                    </select>
                </label>
                <label class="inventory-filter-field" for="inventory-page-size">
                    <span>Rows</span>
                    <select id="inventory-page-size">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                    </select>
                </label>
                <button id="inventory-clear-filters" class="secondary-btn is-hidden" type="button"><i class="bi bi-arrow-counterclockwise"></i> Reset filters</button>
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
        <div id="inventory-pager" class="inventory-pager" aria-label="Inventory pages"></div>
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

<?= view('components/store_inventory_modals') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js" data-portal-page-once></script>
<script src="<?= base_url('assets/js/store-inventory.js') ?>?v=20260824e"></script>
<script src="<?= base_url('assets/js/store-inventory.part2.js') ?>?v=20260824e"></script>
<script src="<?= base_url('assets/js/store-inventory.part3.js') ?>?v=20260824e"></script>
<?= $this->endSection() ?>
