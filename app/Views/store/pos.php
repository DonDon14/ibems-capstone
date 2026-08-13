<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-pos.css') ?>?v=20260813l">
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="pos-shell" data-user-role="<?= esc((string) session()->get('role')) ?>">
    <div class="pos-page-header">
        <?= view('components/page_header', [
            'eyebrow' => 'Point of sale',
            'title' => 'Store checkout',
            'description' => 'Search products, manage the current order, and complete store transactions.',
            'icon' => 'bi bi-cart-check',
            'actions' => '<a class="secondary-btn pos-back-btn" href="' . site_url('store/dashboard') . '"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to dashboard</a>',
        ]) ?>
    </div>

    <div class="pos-left panel">
        <header class="pos-toolbar">
            <div class="search-wrap">
                <label for="product-search">Search Products</label>
                <input id="product-search" type="search" placeholder="Search product, SKU, barcode, supplier, or bin">
            </div>
        </header>

        <div id="category-tabs" class="category-tabs">
            <button class="category-tab active" data-category="All" type="button">All</button>
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
            <label id="checkout-customer-label" for="debt-customer-search">Customer (optional)</label>
            <div class="debt-search-wrap">
                <input id="debt-customer-search" type="search" placeholder="Walk-in or search employee name / ID">
                <button id="open-debt-scanner-btn" type="button" class="debt-scan-btn search-scan-btn" aria-label="Scan employee QR or ID" title="Scan employee QR/ID">
                    <i class="bi bi-qr-code-scan"></i>
                </button>
                <div id="debt-customer-suggestions" class="debt-suggestions is-hidden"></div>
            </div>
            <small id="checkout-customer-help">Leave blank for a walk-in sale, or select an employee to record this transaction in their history.</small>
            <section id="debt-credit-meter" class="debt-credit-meter is-hidden" aria-live="polite">
                <div class="debt-credit-head"><div><span>Employee credit</span><strong id="debt-credit-status">Select an employee</strong></div><strong id="debt-credit-available">PHP 0.00 available</strong></div>
                <div class="debt-credit-track" role="progressbar" aria-label="Employee credit used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="debt-credit-fill"></span></div>
                <div class="debt-credit-values"><span id="debt-credit-current">Current debt PHP 0.00</span><span id="debt-credit-limit">Limit PHP 0.00</span></div>
                <p id="debt-credit-message">The Debt portion of this checkout will appear here.</p>
            </section>
            <div id="debt-pin-wrap" class="debt-pin-wrap is-hidden">
                <label><i class="bi bi-shield-lock"></i> Debt Authorization PIN</label>
                <small id="debt-pin-help">PIN is verified securely when the transaction is submitted.</small>
                <button id="open-debt-pin-modal" type="button" class="secondary-btn debt-pin-open-btn">
                    <i class="bi bi-key"></i> Enter PIN
                </button>
            </div>
        </div>

        <button id="submit-transaction" class="primary-btn" type="button"><i class="bi bi-check2-circle"></i> Complete Transaction</button>
        <p id="result" class="result-msg"></p>
        <div id="pos-success-strip" class="pos-success-strip is-hidden"></div>
    </aside>
</section>

<div id="customer-qr-modal" class="receipt-modal customer-qr-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="customer-qr-title">
    <div class="receipt-card customer-qr-card">
        <div class="receipt-head">
            <div>
                <span class="customer-qr-eyebrow">Customer payment</span>
                <h3 id="customer-qr-title">Scan to pay</h3>
            </div>
            <button id="customer-qr-close" type="button" class="receipt-close" aria-label="Close customer payment QR">&times;</button>
        </div>
        <div class="customer-qr-content">
            <img id="customer-qr-image" alt="Customer payment QR">
            <div class="customer-qr-details">
                <span id="customer-qr-method">Payment method</span>
                <strong id="customer-qr-account">Receiving account</strong>
                <small id="customer-qr-number"></small>
            </div>
            <p>Scan this QR using your payment app. Confirm the receiving account before sending payment.</p>
        </div>
        <div class="confirm-actions customer-qr-actions">
            <button id="customer-qr-done" type="button" class="primary-btn"><i class="bi bi-check2-circle"></i> Done</button>
        </div>
    </div>
</div>

<div id="product-variant-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="product-variant-title">
    <div class="receipt-card product-variant-modal-card">
        <div class="receipt-head">
            <div>
                <h3 id="product-variant-title">Choose a variant</h3>
                <p id="product-variant-guidance">Select a size to add it to the current order.</p>
            </div>
            <button id="product-variant-close" type="button" class="receipt-close" aria-label="Close variant picker">&times;</button>
        </div>
        <div id="product-variant-options" class="product-variant-options"></div>
        <div class="confirm-actions">
            <button id="product-variant-cancel" type="button" class="secondary-btn">Cancel</button>
        </div>
    </div>
</div>

<div id="barcode-scanner-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="scanner-title">
    <div class="receipt-card scanner-card">
        <div class="receipt-head">
            <h3 id="scanner-title"><i class="bi bi-upc-scan"></i> Barcode Scanner</h3>
            <button id="scanner-close" type="button" class="receipt-close" aria-label="Close barcode scanner">x</button>
        </div>
        <div class="scanner-body">
            <div id="scanner-reader"></div>
            <p id="scanner-status" class="scanner-status">Ready to scan.</p>
            <div class="scanner-actions">
                <button id="scanner-stop" type="button" class="secondary-btn"><i class="bi bi-stop-fill"></i> Stop</button>
            </div>
        </div>
    </div>
</div>

<div id="debt-pin-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="debt-pin-modal-title">
    <div class="receipt-card debt-pin-modal-card">
        <div class="receipt-head">
            <h3 id="debt-pin-modal-title"><i class="bi bi-shield-lock"></i> Debt Authorization PIN</h3>
            <button id="debt-pin-close" type="button" class="receipt-close" aria-label="Close debt authorization PIN">&times;</button>
        </div>
        <p id="debt-pin-modal-summary" class="scanner-status">Ask the debtor to enter their PIN.</p>
        <div class="payment-wrap">
            <label for="debt-pin-input">PIN</label>
            <input id="debt-pin-input" type="password" inputmode="numeric" autocomplete="off" maxlength="6" placeholder="4 to 6 digits">
        </div>
        <p id="debt-pin-modal-result" class="result-msg"></p>
        <div class="confirm-actions">
            <button id="debt-pin-cancel" type="button" class="secondary-btn">Cancel</button>
            <button id="debt-pin-save" type="button" class="primary-btn"><i class="bi bi-check2-circle"></i> Use PIN</button>
        </div>
    </div>
</div>

<div id="confirm-transaction-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="confirm-transaction-title">
    <div class="receipt-card confirm-card">
        <div class="receipt-head">
            <h3 id="confirm-transaction-title"><i class="bi bi-check2-square"></i> Confirm Transaction</h3>
            <button id="confirm-close" type="button" class="receipt-close" aria-label="Close transaction confirmation">x</button>
        </div>

        <div id="confirm-transaction-content"></div>

        <div class="confirm-actions">
            <button id="confirm-cancel" type="button" class="secondary-btn">Cancel</button>
            <button id="confirm-proceed" type="button" class="primary-btn"><i class="bi bi-check2-circle"></i> Proceed</button>
        </div>
    </div>
</div>

<div id="receipt-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="receipt-modal-title">
    <div class="receipt-card">
        <div class="receipt-head">
            <h3 id="receipt-modal-title">Transaction Receipt</h3>
            <button id="receipt-close" type="button" class="receipt-close" aria-label="Close transaction receipt">&times;</button>
        </div>

        <div id="receipt-content"></div>

        <div class="receipt-actions">
            <button id="receipt-new" type="button" class="secondary-btn"><i class="bi bi-plus-circle"></i> New Transaction</button>
            <button id="receipt-view" type="button" class="secondary-btn"><i class="bi bi-box-arrow-up-right"></i> View Receipt</button>
            <button id="receipt-print" type="button" class="primary-btn"><i class="bi bi-printer"></i> Print Receipt</button>
        </div>
    </div>
</div>

<div id="debt-payment-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="debt-payment-title">
    <div class="receipt-card confirm-card debt-payment-modal-card">
        <div class="receipt-head">
            <h3 id="debt-payment-title"><i class="bi bi-cash-coin"></i> Record Debt Collection</h3>
            <button id="debt-payment-close" type="button" class="receipt-close" aria-label="Close direct debt payment">&times;</button>
        </div>
        <p class="scanner-status">Use this when a debtor pays the store directly. The amount reduces existing debt and is added to today&apos;s cash or e-cash. It cannot create an advance credit.</p>
        <div class="payment-wrap">
            <label for="debt-payment-search">Debtor (Faculty/Staff)</label>
            <div class="debt-search-wrap">
                <input id="debt-payment-search" type="search" placeholder="Search by name, email, or employee ID">
                <div id="debt-payment-suggestions" class="debt-suggestions is-hidden"></div>
            </div>
        </div>
        <div id="debt-payment-profile" class="debt-payment-profile is-hidden"></div>
        <div class="debt-payment-grid">
            <div class="payment-wrap">
                <label for="debt-payment-amount">Payment Amount</label>
                <input id="debt-payment-amount" type="number" min="0" step="0.01" placeholder="0.00">
            </div>
            <div class="payment-wrap">
                <label for="debt-payment-channel">Channel</label>
                <select id="debt-payment-channel">
                    <option value="cash">Cash</option>
                    <option value="ecash">E-Cash / Bank / Wallet</option>
                </select>
            </div>
        </div>
        <div class="payment-wrap">
            <label for="debt-payment-reference">Receipt / Reference No. (optional)</label>
            <input id="debt-payment-reference" type="text" placeholder="Official receipt, GCash ref, deposit slip">
        </div>
        <div class="payment-wrap">
            <label for="debt-payment-remarks">Remarks (optional)</label>
            <input id="debt-payment-remarks" type="text" placeholder="e.g. Paid directly at Main Campus Store">
        </div>
        <p id="debt-payment-result" class="result-msg"></p>
        <div class="confirm-actions">
            <button id="debt-payment-cancel" type="button" class="secondary-btn">Cancel</button>
            <button id="debt-payment-save" type="button" class="primary-btn"><i class="bi bi-check2-circle"></i> Record Payment</button>
        </div>
    </div>
</div>

<div id="opening-balance-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="opening-balance-title">
    <div class="receipt-card confirm-card opening-balance-card">
        <div class="receipt-head">
            <h3 id="opening-balance-title"><i class="bi bi-safe2"></i> Open Store Day</h3>
            <button id="opening-balance-close" type="button" class="receipt-close" aria-label="Close store-day opening form">&times;</button>
        </div>
        <p id="opening-balance-description" class="scanner-status">Enter today&apos;s starting cash and e-cash before accepting POS transactions.</p>
        <div class="opening-balance-fields">
            <div class="payment-wrap">
                <label id="opening-balance-label" for="opening-balance-input">Opening Cash</label>
                <input id="opening-balance-input" type="number" min="0" step="0.01" value="0">
            </div>
            <div class="payment-wrap">
                <label for="opening-ecash-input">Opening E-Cash</label>
                <input id="opening-ecash-input" type="number" min="0" step="0.01" value="0">
                <small>Compatibility total. Individual electronic accounts are reconciled separately at close.</small>
            </div>
            <div class="payment-wrap">
                <label for="opening-balance-note">Note (optional)</label>
                <input id="opening-balance-note" type="text" placeholder="e.g. Start of day float">
            </div>
            <div id="opening-payment-account-balances" class="opening-payment-account-balances"></div>
        </div>
        <p id="opening-balance-result" class="result-msg"></p>
        <div class="confirm-actions">
            <button id="opening-balance-cancel" type="button" class="secondary-btn">Later</button>
            <button id="opening-balance-save" type="button" class="primary-btn"><i class="bi bi-check2-circle"></i> Open Store Day</button>
        </div>
    </div>
</div>

<div id="store-day-close-modal" class="receipt-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="store-day-close-title">
    <div class="receipt-card confirm-card store-day-close-card">
        <div class="receipt-head">
            <h3 id="store-day-close-title"><i class="bi bi-door-closed"></i> Close Store Day</h3>
            <button id="store-day-close-x" type="button" class="receipt-close" aria-label="Close store-day closing form">&times;</button>
        </div>
        <p id="store-day-close-summary" class="scanner-status store-day-close-summary">Review expected cash and enter counted totals.</p>
        <div id="store-day-close-reconcile" class="store-day-close-reconcile"></div>
        <div id="store-day-account-counts" class="store-day-account-counts"></div>
        <div class="store-day-close-fields">
            <div class="payment-wrap">
                <label for="closing-cash-input">Counted Cash</label>
                <input id="closing-cash-input" type="number" min="0" step="0.01" value="0">
                <small id="closing-cash-variance" class="closing-variance">Variance PHP 0.00</small>
            </div>
            <div class="payment-wrap">
                <label for="closing-ecash-input">Counted E-Cash</label>
                <input id="closing-ecash-input" type="number" min="0" step="0.01" value="0">
                <small id="closing-ecash-variance" class="closing-variance">Variance PHP 0.00</small>
            </div>
            <div class="payment-wrap">
                <label id="closing-note-label" for="closing-note-input">Closing Note (optional)</label>
                <input id="closing-note-input" type="text" placeholder="e.g. Cash count verified">
            </div>
        </div>
        <p id="store-day-close-result" class="result-msg"></p>
        <div class="confirm-actions">
            <button id="store-day-close-cancel" type="button" class="secondary-btn">Cancel</button>
            <button id="store-day-close-save" type="button" class="primary-btn"><i class="bi bi-check2-circle"></i> Close Store Day</button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>?v=20260813i"></script>
<script src="<?= base_url('assets/js/store-pos.js') ?>?v=20260813t"></script>
<?= $this->endSection() ?>

