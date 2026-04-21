<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-pos.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="pos-shell">
    <div class="pos-left panel">
        <header class="pos-toolbar">
            <div class="store-switch">
                <label for="store-select">Store</label>
                <select id="store-select"></select>
            </div>

            <div class="search-wrap">
                <input id="product-search" type="search" placeholder="Search products by name or SKU">
            </div>
        </header>

        <div id="category-tabs" class="category-tabs">
            <button class="category-tab active" data-category="All" type="button">All</button>
        </div>

        <div id="product-grid" class="product-grid">
            <div class="empty-state">Loading products...</div>
        </div>
    </div>

    <aside class="pos-right panel">
        <h4>Current Order</h4>

        <div id="cart-body" class="cart-list"></div>

        <div class="order-summary">
            <div class="summary-row">
                <span>Subtotal</span>
                <strong id="subtotal-amount">PHP 0.00</strong>
            </div>
            <div class="summary-row">
                <span>Items</span>
                <strong id="item-count">0</strong>
            </div>
            <div class="summary-row total-row">
                <span>Total</span>
                <strong id="grand-total">PHP 0.00</strong>
            </div>
        </div>

        <div class="payment-wrap">
            <label for="payment-method">Payment Method</label>
            <select id="payment-method">
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
                <option value="card">Card</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="other">Other</option>
                <option value="debt">Debt</option>
                <option value="advance_payment">Advance Payment</option>
            </select>
        </div>

        <div id="debt-customer-wrap" class="payment-wrap" style="display:none;">
            <label for="debt-customer-search">Debt Customer (Faculty/Staff)</label>
            <input id="debt-customer-search" type="search" placeholder="Search by name, email, or employee ID">
            <select id="debt-customer-select">
                <option value="">Select customer</option>
            </select>
        </div>

        <button id="submit-transaction" class="primary-btn" type="button">Complete Transaction</button>
        <p id="result" class="result-msg"></p>
    </aside>
</section>

<div id="receipt-modal" class="receipt-modal" style="display:none;">
    <div class="receipt-card">
        <div class="receipt-head">
            <h3>Transaction Receipt</h3>
            <button id="receipt-close" type="button" class="receipt-close">×</button>
        </div>

        <div id="receipt-content"></div>

        <div class="receipt-actions">
            <button id="receipt-print" type="button" class="primary-btn">Print Receipt</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-pos.js') ?>"></script>
<?= $this->endSection() ?>
