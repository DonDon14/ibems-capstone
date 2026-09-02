<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-pos.css') ?>?v=20260824f">
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>?v=20260825a">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$posAccountAction = '<button class="pos-account-trigger" type="button" data-account-menu-toggle aria-label="Open account and settings" title="Account and settings" aria-expanded="false" aria-controls="account-menu">'
    . '<span class="profile-avatar-wrap"><img src="' . esc(ibems_profile_image_url(session()->get('profile_image_url'))) . '" alt="" class="profile-avatar-img" data-profile-avatar data-current-user-avatar>'
    . '<span class="profile-avatar-status" aria-hidden="true"><i class="bi bi-chevron-down"></i></span></span>'
    . '<span class="pos-account-label"><strong>' . esc((string) session()->get('name')) . '</strong><small>Store Cashier</small></span>'
    . '</button>';
?>
<section class="pos-shell" data-user-role="<?= esc((string) session()->get('role')) ?>">
    <div class="pos-page-header">
        <?= view('components/page_header', [
            'eyebrow' => 'Point of sale',
            'title' => 'Store checkout',
            'description' => 'Search products, manage the current order, and complete store transactions.',
            'icon' => 'bi bi-cart-check',
            'actions' => $posAccountAction,
        ]) ?>
        <div class="pos-context-bar" role="status" aria-live="polite">
            <span class="pos-context-label"><i class="bi bi-shop-window" aria-hidden="true"></i> Active store</span>
            <strong id="pos-store-name">Loading store...</strong>
            <span class="pos-context-separator" aria-hidden="true"></span>
            <span id="pos-store-role">Store operations</span>
        </div>
    </div>

    <div class="pos-left panel">
        <header class="pos-toolbar">
            <div class="search-wrap">
                <label for="product-search">Search Products</label>
                <input id="product-search" type="search" placeholder="Search product, SKU, barcode, supplier, or bin">
            </div>
        </header>

        <p id="pos-transaction-note" class="pos-transaction-note is-locked" role="status" aria-live="polite">
            <i class="bi bi-lock" aria-hidden="true"></i>
            <span>Open today&apos;s store day before adding items.</span>
        </p>

        <div id="category-tabs" class="category-tabs" role="tablist" aria-label="Product categories">
            <button class="category-tab active" data-category="All" type="button" role="tab" aria-selected="true" aria-controls="product-grid">All</button>
        </div>

        <div id="product-grid" class="product-grid">
            <?= view('components/data_state', [
                'type' => 'loading',
                'message' => 'Loading products...',
                'detail' => 'Preparing the active store catalog.',
            ]) ?>
        </div>
    </div>

    <aside class="pos-right panel">
        <h4><i class="bi bi-cart3"></i> Current Order</h4>
        <div class="opening-balance-banner is-checking" id="opening-balance-banner">
            <div class="opening-balance-info">
                <span class="label"><i class="bi bi-safe2"></i> POS Readiness</span>
                <span class="opening-status-pill" id="opening-balance-status">Checking</span>
                <strong id="opening-balance-display">Not set</strong>
                <small id="opening-balance-date">Checking store day status...</small>
                <small id="opening-balance-guidance">Transactions stay locked until today&apos;s store day is open.</small>
            </div>
            <div class="opening-balance-actions">
                <button id="opening-balance-open-btn" type="button" class="secondary-btn">
                    <i class="bi bi-pencil-square"></i> Open Store Day
                </button>
                <button id="store-day-close-btn" type="button" class="secondary-btn is-hidden">
                    <i class="bi bi-door-closed"></i> Close Day
                </button>
            </div>
        </div>

        <div class="debt-payment-card">
            <div>
                <strong><i class="bi bi-cash-coin"></i> Debt Collection</strong>
                <small>Apply a direct cash or e-cash collection to an employee&apos;s existing debt.</small>
            </div>
            <button id="open-debt-payment-modal" type="button" class="secondary-btn">
                <i class="bi bi-plus-circle"></i> Record Payment
            </button>
        </div>

        <div class="scan-quick-wrap">
            <div class="scan-quick-row">
                <div class="scan-code-field">
                    <label for="scan-code-input"><i class="bi bi-upc-scan"></i> Scan QR/Barcode</label>
                    <div class="scan-code-wrap">
                        <input id="scan-code-input" type="text" placeholder="Scan or type SKU/barcode then press Enter">
                        <button id="open-scanner-btn" type="button" class="scan-inline-btn" title="Scan product QR/barcode">
                            <i class="bi bi-qr-code-scan"></i>
                        </button>
                        <div id="scan-product-suggestions" class="scan-suggestions is-hidden"></div>
                    </div>
                </div>
                <label class="scan-qty-field" for="scan-qty-input">
                    <span>Quantity</span>
                    <input id="scan-qty-input" type="number" min="1" step="1" value="1">
                </label>
                <button id="scan-add-btn" type="button" class="scan-add-btn"><i class="bi bi-plus-circle"></i> Add</button>
            </div>
        </div>

        <div id="cart-body" class="cart-list"></div>

        <div class="payment-wrap">
            <label id="payment-method-label" for="payment-method"><i class="bi bi-credit-card-2-front"></i> Payment Method</label>
            <div class="payment-selector" id="payment-quick" role="group" aria-labelledby="payment-method-label"></div>
            <select id="payment-method" class="is-hidden" data-no-enhance aria-hidden="true" tabindex="-1"></select>
            <small id="payment-method-help" class="payment-method-help" aria-live="polite">Choose how this sale will be settled.</small>
            <div id="payment-account-picker" class="payment-account-picker is-hidden" aria-live="polite"></div>
            <button id="split-payment-toggle" type="button" class="secondary-btn split-payment-toggle" aria-pressed="false">
                <i class="bi bi-intersect" aria-hidden="true"></i> Split payment
            </button>
            <div id="split-payment-editor" class="split-payment-editor is-hidden" aria-live="polite"></div>
        </div>

        <div id="debt-customer-wrap" class="payment-wrap is-hidden">
            <div id="debt-account-type-wrap" class="payment-wrap is-hidden" hidden>
                <label for="debt-account-type">Charge debt to</label>
                <select id="debt-account-type">
                    <option value="employee">Employee account</option>
                    <option value="department">Department account</option>
                </select>
                <small>Department charges use the department&apos;s monthly allocation and never affect an employee balance or payroll deduction.</small>
            </div>
            <div id="employee-debt-customer-fields">
                <label id="checkout-customer-label" for="debt-customer-search">Customer (optional)</label>
                <div class="debt-search-wrap">
                    <input id="debt-customer-search" type="search" placeholder="Walk-in or search employee name / ID">
                    <button id="open-debt-scanner-btn" type="button" class="debt-scan-btn search-scan-btn" aria-label="Scan employee QR or ID" title="Scan employee QR/ID">
                        <i class="bi bi-qr-code-scan"></i>
                    </button>
                    <div id="debt-customer-suggestions" class="debt-suggestions is-hidden"></div>
                </div>
                <small id="checkout-customer-help">Leave blank for a walk-in sale, or select an employee to record this transaction in their history.</small>
            </div>
            <section id="department-debt-fields" class="department-debt-fields is-hidden" aria-label="Department debt authorization" hidden>
                <label for="department-debt-account">Department</label>
                <select id="department-debt-account"><option value="">Select a department</option></select>
                <small id="department-debt-help">Only departments with an open allocation for this month are available.</small>
                <label for="department-requester-name">Requested by</label>
                <input id="department-requester-name" type="text" maxlength="160" autocomplete="name" placeholder="Name of person receiving the items">
                <label for="department-approver">Department head</label>
                <select id="department-approver"><option value="">Select an approver</option></select>
                <small id="department-approver-help">The department head must enter their separate department approval PIN.</small>
            </section>
            <section id="debt-credit-meter" class="debt-credit-meter is-hidden" aria-live="polite">
                <div class="debt-credit-head"><div><span>Employee credit</span><strong id="debt-credit-status">Select an employee</strong></div><strong id="debt-credit-available">PHP 0.00 available</strong></div>
                <div class="debt-credit-track" role="progressbar" aria-label="Employee credit used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="debt-credit-fill"></span></div>
                <div class="debt-credit-values"><span id="debt-credit-current">Current debt PHP 0.00</span><span id="debt-credit-limit">Limit PHP 0.00</span></div>
                <p id="debt-credit-message">The Debt portion of this checkout will appear here.</p>
            </section>
            <div id="debt-pin-wrap" class="debt-pin-wrap is-hidden">
                <label id="debt-pin-label"><i class="bi bi-shield-lock"></i> Debt Authorization PIN</label>
                <small id="debt-pin-help">PIN is verified securely when the transaction is submitted.</small>
                <button id="open-debt-pin-modal" type="button" class="secondary-btn debt-pin-open-btn">
                    <i class="bi bi-key"></i> Enter PIN
                </button>
            </div>
        </div>

        <div class="pos-checkout-footer">
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
            <button id="submit-transaction" class="primary-btn" type="button"><i class="bi bi-check2-circle"></i> Complete Transaction</button>
            <p id="result" class="result-msg"></p>
            <div id="pos-success-strip" class="pos-success-strip is-hidden"></div>
        </div>
    </aside>
</section>

<?= view('components/store_pos_modals') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js" data-portal-page-once></script>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>?v=20260825a"></script>
<script src="<?= base_url('assets/js/store-pos.js') ?>?v=20260825a"></script>
<script src="<?= base_url('assets/js/store-pos.part2.js') ?>?v=20260825c"></script>
<script src="<?= base_url('assets/js/store-pos.part3.js') ?>?v=20260825c"></script>
<script src="<?= base_url('assets/js/store-pos.part4.js') ?>?v=20260825c"></script>
<script src="<?= base_url('assets/js/store-pos.part5.js') ?>?v=20260825c"></script>
<script src="<?= base_url('assets/js/store-pos.part6.js') ?>?v=20260825c"></script>
<?= $this->endSection() ?>

