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
        <div class="summary-card"><span>Total Transactions</span><strong id="sd-txn-count">0</strong></div>
        <div class="summary-card"><span>Total Sales</span><strong id="sd-sales-total">PHP 0.00</strong></div>
        <div class="summary-card"><span>Products</span><strong id="sd-product-count">0</strong></div>
        <div class="summary-card"><span>Total Stock Units</span><strong id="sd-stock-units">0</strong></div>
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
