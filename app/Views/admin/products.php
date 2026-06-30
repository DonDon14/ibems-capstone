<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell space-y-5">
    <div class="admin-overview-head">
        <h3 class="text-3xl font-bold tracking-tight text-slate-900">Product Oversight</h3>
        <p class="mt-1 text-base text-slate-600">Inspect product availability, stock risk, supplier, and bin/location across all stores.</p>
    </div>

    <div class="summary-grid grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <?= view('components/stat_card', ['title' => 'Visible Products', 'value' => '0', 'valueId' => 'ap-visible-products', 'icon' => 'bi bi-box-seam', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Low Stock', 'value' => '0', 'valueId' => 'ap-low-stock', 'icon' => 'bi bi-exclamation-triangle', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Out of Stock', 'value' => '0', 'valueId' => 'ap-out-stock', 'icon' => 'bi bi-x-octagon', 'tone' => 'alerts']) ?>
        <?= view('components/stat_card', ['title' => 'Inactive Products', 'value' => '0', 'valueId' => 'ap-inactive-products', 'icon' => 'bi bi-pause-circle', 'tone' => 'users']) ?>
    </div>

    <div class="overview-filter ap-filter-panel rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="ap-filter-main grid gap-3 lg:grid-cols-[minmax(240px,1fr)_180px_180px_180px_180px_auto]">
            <input id="ap-search" class="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm" type="search" placeholder="Search product, SKU, barcode, supplier, bin, or store">
            <select id="ap-store-filter" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                <option value="">All Stores</option>
            </select>
            <select id="ap-stock-filter" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                <option value="">All Stock</option>
                <option value="healthy">Healthy</option>
                <option value="low">Low Stock</option>
                <option value="out">Out of Stock</option>
            </select>
            <select id="ap-category-filter" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                <option value="">All Categories</option>
            </select>
            <select id="ap-supplier-filter" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                <option value="">All Suppliers</option>
            </select>
            <label class="util-inline-flex-gap-6 h-11 items-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700">
                <input id="ap-include-inactive" type="checkbox">
                Inactive
            </label>
        </div>
        <div class="ap-filter-actions">
            <button id="ap-search-btn" class="primary-btn" type="button"><i class="bi bi-search"></i> Search</button>
            <button id="ap-refresh-btn" class="history-action alt" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
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
                <tr><td colspan="8">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</section>

<div id="ap-view-modal" class="admin-modal is-hidden">
    <div class="admin-modal-card max-h-[92vh] w-[min(760px,95vw)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="admin-modal-head">
            <h4 class="text-lg font-bold text-slate-900">Product Snapshot</h4>
            <button id="ap-view-close" type="button" class="admin-modal-close">x</button>
        </div>
        <div id="ap-view-content" class="ap-detail-grid"></div>
        <div class="admin-modal-actions">
            <a id="ap-store-link" class="secondary-btn inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700" href="<?= site_url('admin/stores') ?>">Open Store Details</a>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-products.js') ?>"></script>
<?= $this->endSection() ?>
