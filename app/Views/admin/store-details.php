<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell" data-store-id="<?= (int) $storeId ?>">
    <div class="admin-overview-head">
        <h3 id="sd-store-name">Store Details</h3>
        <p id="sd-store-meta">Loading store profile...</p>
    </div>

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

    <div class="overview-grid">
        <article class="overview-table-wrap">
            <h4>Store Day</h4>
            <div id="sd-day-session" class="stack-list">
                <div class="mini-bar-empty">Loading store day status...</div>
            </div>
        </article>

        <article class="overview-table-wrap">
            <h4>Assigned Officers</h4>
            <div id="sd-officers" class="stack-list">
                <div class="mini-bar-empty">Loading officers...</div>
            </div>
        </article>
    </div>

    <div class="overview-table-wrap">
        <h4>Inventory</h4>
        <table class="table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Stock</th>
                    <th>Price</th>
                </tr>
            </thead>
            <tbody id="sd-inventory-body">
                <tr><td colspan="3">Loading inventory...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="overview-table-wrap">
        <h4>Recent Transactions</h4>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Payment</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody id="sd-transactions-body">
                <tr><td colspan="4">Loading transactions...</td></tr>
            </tbody>
        </table>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin-store-details.js') ?>"></script>
<?= $this->endSection() ?>
