<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>?v=20260821b">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="dashboard-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Product directory',
        'title' => 'Stores and available products',
        'description' => 'Browse the active stores and see which products are currently available before visiting.',
        'icon' => 'bi bi-shop-window',
    ]) ?>

    <div class="dashboard-grid dashboard-grid-three">
        <?= view('components/stat_card', ['title' => 'Active Stores', 'value' => '0', 'valueId' => 'us-store-count', 'icon' => 'bi bi-shop', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Matching Products', 'value' => '0', 'valueId' => 'us-product-count', 'icon' => 'bi bi-box-seam', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Available on This Page', 'value' => '0', 'valueId' => 'us-available-count', 'icon' => 'bi bi-check-circle', 'tone' => 'users']) ?>
    </div>

    <div class="user-filters user-product-filters">
        <div class="field user-search-field">
            <label for="us-search">Search</label>
            <input id="us-search" type="search" placeholder="Product, category, SKU, or store">
        </div>
        <div class="field">
            <label for="us-store">Store</label>
            <select id="us-store"><option value="">All stores</option></select>
        </div>
        <div class="field">
            <label for="us-category">Category</label>
            <select id="us-category"><option value="">All categories</option></select>
        </div>
        <div class="field">
            <label for="us-availability">Availability</label>
            <select id="us-availability">
                <option value="">All products</option>
                <option value="available">Available now</option>
                <option value="out">Out of stock</option>
            </select>
        </div>
        <div class="field">
            <label for="us-sort">Sort</label>
            <select id="us-sort">
                <option value="store:asc">Store A-Z</option>
                <option value="name:asc">Product A-Z</option>
                <option value="name:desc">Product Z-A</option>
                <option value="price:asc">Lowest price</option>
                <option value="price:desc">Highest price</option>
                <option value="category:asc">Category A-Z</option>
            </select>
        </div>
        <button id="us-reset-filters" class="secondary-btn is-hidden" type="button"><i class="bi bi-arrow-counterclockwise"></i> Reset filters</button>
    </div>

    <p id="us-context" class="user-results-context" aria-live="polite">Loading products...</p>
    <div id="us-products" class="user-product-grid">
        <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading store products...']) ?>
    </div>
    <div id="us-pager" class="user-pager" aria-label="Product directory pages"></div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/user-stores.js') ?>?v=20260821b"></script>
<?= $this->endSection() ?>
