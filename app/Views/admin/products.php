<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Catalog intelligence',
        'title' => 'Product oversight',
        'description' => 'Inspect availability, stock risk, suppliers, and storage locations across every store.',
        'icon' => 'bi bi-box-seam',
    ]) ?>

    <div class="summary-grid grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <?= view('components/stat_card', ['title' => 'Visible Products', 'value' => '0', 'valueId' => 'ap-visible-products', 'icon' => 'bi bi-box-seam', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Low Stock', 'value' => '0', 'valueId' => 'ap-low-stock', 'icon' => 'bi bi-exclamation-triangle', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Out of Stock', 'value' => '0', 'valueId' => 'ap-out-stock', 'icon' => 'bi bi-x-octagon', 'tone' => 'alerts']) ?>
        <?= view('components/stat_card', ['title' => 'Inactive Products', 'value' => '0', 'valueId' => 'ap-inactive-products', 'icon' => 'bi bi-pause-circle', 'tone' => 'users']) ?>
    </div>

    <div class="overview-filter ap-filter-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="ap-filter-main grid gap-3 lg:grid-cols-[minmax(240px,1fr)_180px_180px_180px_180px_auto]">
            <label class="ap-filter-field" for="ap-search">
                <span>Search</span>
                <input id="ap-search" class="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm" type="search" placeholder="Product, SKU, barcode, supplier, bin, or store">
            </label>
            <label class="ap-filter-field" for="ap-store-filter">
                <span>Store</span>
                <select id="ap-store-filter" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="">All Stores</option>
                </select>
            </label>
            <label class="ap-filter-field" for="ap-stock-filter">
                <span>Stock</span>
                <select id="ap-stock-filter" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="">All Stock</option>
                    <option value="healthy">Healthy</option>
                    <option value="low">Low Stock</option>
                    <option value="out">Out of Stock</option>
                </select>
            </label>
            <label class="ap-filter-field" for="ap-category-filter">
                <span>Category</span>
                <select id="ap-category-filter" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="">All Categories</option>
                </select>
            </label>
            <label class="ap-filter-field" for="ap-supplier-filter">
                <span>Supplier</span>
                <select id="ap-supplier-filter" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="">All Suppliers</option>
                </select>
            </label>
            <label class="ap-filter-field" for="ap-sort">
                <span>Sort</span>
                <select id="ap-sort" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="store:asc">Store A-Z</option>
                    <option value="name:asc">Product A-Z</option>
                    <option value="name:desc">Product Z-A</option>
                    <option value="stock:asc">Lowest stock</option>
                    <option value="stock:desc">Highest stock</option>
                    <option value="price:asc">Lowest price</option>
                    <option value="price:desc">Highest price</option>
                    <option value="updated:desc">Recently updated</option>
                </select>
            </label>
            <label class="ap-filter-field" for="ap-page-size">
                <span>Rows</span>
                <select id="ap-page-size" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </label>
            <label class="ap-filter-check util-inline-flex-gap-6 h-11 items-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700">
                <input id="ap-include-inactive" type="checkbox">
                Inactive
            </label>
        </div>
        <div class="ap-filter-actions">
            <button id="ap-search-btn" class="primary-btn" type="button"><i class="bi bi-search"></i> Search</button>
            <button id="ap-refresh-btn" class="secondary-btn" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        </div>
    </div>

    <p id="ap-result" class="stores-result text-sm font-semibold"></p>
    <p id="ap-count-text" class="uv-count-text text-sm text-slate-500">Showing 0 products</p>

    <div class="overview-table-wrap rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
        <table class="table ap-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Store</th>
                    <th>Category</th>
                    <th>Supplier / Bin</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody id="ap-body">
                <?= view('components/data_state', [
                    'tag' => 'tr',
                    'colspan' => 8,
                    'type' => 'loading',
                    'message' => 'Loading products...',
                ]) ?>
            </tbody>
        </table>
    </div>
    <div id="ap-pager" class="overview-pager" aria-label="Product pages"></div>
</section>

<div id="ap-view-modal" class="admin-modal admin-overview-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="ap-view-title">
    <div class="admin-modal-card max-h-[92vh] w-[min(760px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 id="ap-view-title" class="text-lg font-bold text-slate-900">Product Snapshot</h4>
            <button id="ap-view-close" type="button" class="admin-modal-close" aria-label="Close product snapshot">x</button>
        </div>
        <div id="ap-view-content" class="ap-detail-grid"></div>
        <div class="admin-modal-actions">
            <a id="ap-store-link" class="secondary-btn" href="<?= site_url('admin/stores') ?>">Open Store Details</a>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-products.js') ?>"></script>
<?= $this->endSection() ?>
