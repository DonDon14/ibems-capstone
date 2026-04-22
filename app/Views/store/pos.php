<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-pos.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="pos-shell" data-user-role="<?= esc((string) session()->get('role')) ?>">
    <div class="pos-left panel">
        <header class="pos-toolbar">
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
        <h4><i class="bi bi-cart3"></i> Current Order</h4>
        <div class="opening-balance-banner" id="opening-balance-banner">
            <div class="opening-balance-info">
                <span class="label"><i class="bi bi-safe2"></i> Initial Opening Balance</span>
                <strong id="opening-balance-display">Not set</strong>
                <small id="opening-balance-date">Set this once per store</small>
            </div>
            <button id="opening-balance-open-btn" type="button" class="scan-btn">
                <i class="bi bi-pencil-square"></i> Set Initial Opening
            </button>
        </div>

        <div class="scan-quick-wrap">
            <label for="scan-code-input"><i class="bi bi-upc-scan"></i> Scan QR/Barcode</label>
            <div class="scan-quick-row">
                <div class="scan-code-wrap">
                    <input id="scan-code-input" type="text" placeholder="Scan or type SKU/barcode then press Enter">
                    <button id="open-scanner-btn" type="button" class="scan-inline-btn" title="Scan product QR/barcode">
                        <i class="bi bi-qr-code-scan"></i>
                    </button>
                    <div id="scan-product-suggestions" class="scan-suggestions" style="display:none;"></div>
                </div>
                <input id="scan-qty-input" type="number" min="1" step="1" value="1" title="Quantity">
                <button id="scan-add-btn" type="button" class="scan-add-btn"><i class="bi bi-plus-circle"></i> Add</button>
            </div>
        </div>

        <div id="cart-body" class="cart-list"></div>

        <div class="order-summary">
            <div class="summary-row">
                <span><i class="bi bi-receipt"></i> Subtotal</span>
                <strong id="subtotal-amount">PHP 0.00</strong>
            </div>
            <div class="summary-row">
                <span><i class="bi bi-basket"></i> Items</span>
                <strong id="item-count">0</strong>
            </div>
            <div class="summary-row total-row">
                <span><i class="bi bi-cash-stack"></i> Total</span>
                <strong id="grand-total">PHP 0.00</strong>
            </div>
        </div>

        <div class="payment-wrap">
            <label><i class="bi bi-credit-card-2-front"></i> Payment Method</label>
            <div class="payment-quick" id="payment-quick"></div>
            <select id="payment-method" style="display:none;"></select>
        </div>

        <div id="debt-customer-wrap" class="payment-wrap" style="display:none;">
            <label for="debt-customer-search">Debt Customer (Faculty/Staff)</label>
            <div class="debt-search-wrap">
                <input id="debt-customer-search" type="search" placeholder="Search by name, email, or employee ID">
                <button id="open-debt-scanner-btn" type="button" class="debt-scan-btn" title="Scan employee QR/ID">
                    <i class="bi bi-qr-code-scan"></i>
                </button>
                <div id="debt-customer-suggestions" class="debt-suggestions" style="display:none;"></div>
            </div>
        </div>

        <button id="submit-transaction" class="primary-btn" type="button"><i class="bi bi-check2-circle"></i> Complete Transaction</button>
        <p id="result" class="result-msg"></p>
    </aside>
</section>

<div id="barcode-scanner-modal" class="receipt-modal" style="display:none;">
    <div class="receipt-card scanner-card">
        <div class="receipt-head">
            <h3 id="scanner-title"><i class="bi bi-upc-scan"></i> Barcode Scanner</h3>
            <button id="scanner-close" type="button" class="receipt-close">x</button>
        </div>
        <div class="scanner-body">
            <div id="scanner-reader"></div>
            <p id="scanner-status" class="scanner-status">Ready to scan.</p>
            <div class="scanner-actions">
                <button id="scanner-stop" type="button" class="scan-btn"><i class="bi bi-stop-fill"></i> Stop</button>
            </div>
        </div>
    </div>
</div>

<div id="confirm-transaction-modal" class="receipt-modal" style="display:none;">
    <div class="receipt-card confirm-card">
        <div class="receipt-head">
            <h3><i class="bi bi-check2-square"></i> Confirm Transaction</h3>
            <button id="confirm-close" type="button" class="receipt-close">x</button>
        </div>

        <div id="confirm-transaction-content"></div>

        <div class="confirm-actions">
            <button id="confirm-cancel" type="button" class="scan-btn">Cancel</button>
            <button id="confirm-proceed" type="button" class="primary-btn"><i class="bi bi-check2-circle"></i> Proceed</button>
        </div>
    </div>
</div>

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

<div id="opening-balance-modal" class="receipt-modal" style="display:none;">
    <div class="receipt-card confirm-card">
        <div class="receipt-head">
            <h3 id="opening-balance-title"><i class="bi bi-safe2"></i> Set Initial Opening Balance</h3>
        </div>
        <p id="opening-balance-description" class="scanner-status">Set this once when the store is first activated. Use cash in/out for adjustments after this.</p>
        <div class="payment-wrap">
            <label id="opening-balance-label" for="opening-balance-input">Initial Opening Balance</label>
            <input id="opening-balance-input" type="number" min="0" step="0.01" value="0">
        </div>
        <div class="payment-wrap">
            <label for="opening-balance-note">Note (optional)</label>
            <input id="opening-balance-note" type="text" placeholder="e.g. Start of day float">
        </div>
        <p id="opening-balance-result" class="result-msg"></p>
        <div class="confirm-actions">
            <button id="opening-balance-save" type="button" class="primary-btn"><i class="bi bi-check2-circle"></i> Save Initial Opening</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>"></script>
<script src="<?= base_url('assets/js/store-pos.js') ?>"></script>
<?= $this->endSection() ?>
