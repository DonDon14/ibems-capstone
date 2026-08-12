<?= $this->extend('layouts/store_admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell" data-store-id="<?= (int) $storeId ?>" data-details-url="<?= site_url('store-admin/stores/' . (int) $storeId . '/data') ?>" data-review-url-prefix="<?= site_url('store-admin/store-day-sessions') ?>">
    <?= view('components/page_header', [
        'eyebrow' => 'Store operations',
        'title' => 'Store details',
        'titleId' => 'sd-store-name',
        'description' => 'Loading store profile...',
        'descriptionId' => 'sd-store-meta',
        'icon' => 'bi bi-shop-window',
    ]) ?>

    <div class="summary-grid">
        <?= view('components/stat_card', ['title' => 'Total Transactions', 'value' => '0', 'valueId' => 'sd-txn-count', 'icon' => 'bi bi-receipt-cutoff', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Total Sales', 'value' => 'PHP 0.00', 'valueId' => 'sd-sales-total', 'icon' => 'bi bi-graph-up-arrow', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Products', 'value' => '0', 'valueId' => 'sd-product-count', 'icon' => 'bi bi-box-seam', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Total Stock Units', 'value' => '0', 'valueId' => 'sd-stock-units', 'icon' => 'bi bi-boxes', 'tone' => 'warning']) ?>
        <?= view('components/stat_card', ['title' => 'Today Sales', 'value' => 'PHP 0.00', 'valueId' => 'sd-today-sales', 'icon' => 'bi bi-calendar2-check', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Debt Txns Today', 'value' => '0', 'valueId' => 'sd-debt-txns', 'icon' => 'bi bi-credit-card-2-front', 'tone' => 'debt']) ?>
        <?= view('components/stat_card', ['title' => 'Active Products', 'value' => '0', 'valueId' => 'sd-active-products', 'icon' => 'bi bi-box-seam', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Low Stock', 'value' => '0', 'valueId' => 'sd-low-stock', 'icon' => 'bi bi-exclamation-triangle', 'tone' => 'warning']) ?>
    </div>

    <div class="overview-grid store-detail-overview-grid">
        <article class="dash-panel">
            <h4>Store Day</h4>
            <div id="sd-day-session" class="stack-list">
                <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading store day status...']) ?>
            </div>
        </article>

        <article class="dash-panel">
            <h4>Assigned Officers</h4>
            <div id="sd-officers" class="stack-list">
                <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading officers...']) ?>
            </div>
        </article>
    </div>

    <section class="data-panel">
        <header class="data-panel-head"><h4>Inventory</h4></header>
        <div class="data-panel-body table-standard-wrap">
        <table class="table table-standard">
            <thead><tr><th>Product</th><th>Stock</th><th>Price</th></tr></thead>
            <tbody id="sd-inventory-body"><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 3, 'type' => 'loading', 'message' => 'Loading inventory...']) ?></tbody>
        </table>
        </div>
    </section>

    <section class="data-panel">
        <header class="data-panel-head"><h4>Recent Transactions</h4></header>
        <div class="data-panel-body table-standard-wrap">
        <table class="table table-standard">
            <thead><tr><th>Date</th><th>Customer</th><th>Payment</th><th>Amount</th></tr></thead>
            <tbody id="sd-transactions-body"><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 4, 'type' => 'loading', 'message' => 'Loading transactions...']) ?></tbody>
        </table>
        </div>
    </section>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-store-details.js') ?>"></script>
<?= $this->endSection() ?>
