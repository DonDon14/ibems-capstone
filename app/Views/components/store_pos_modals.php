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
                <label for="debt-payment-channel">Payment Method</label>
                <select id="debt-payment-channel">
                    <option value="cash">Cash</option>
                </select>
            </div>
            <div id="debt-payment-destination-wrap" class="payment-wrap is-hidden">
                <label for="debt-payment-destination">Receiving Account</label>
                <select id="debt-payment-destination"></select>
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
        <div class="opening-balance-body">
        <p id="opening-balance-description" class="scanner-status">Count the opening balance held in cash and in every receiving account.</p>
        <div id="store-day-reopen-preview" class="store-day-reopen-preview is-hidden"></div>
        <div class="opening-balance-fields">
            <div class="payment-wrap">
                <label id="opening-balance-label" for="opening-balance-input">Opening Cash</label>
                <div class="cash-count-input-row">
                    <input id="opening-balance-input" type="number" min="0" step="0.01" value="0">
                    <button type="button" class="secondary-btn cash-denomination-toggle" data-denomination-target="opening" aria-expanded="false"><i class="bi bi-calculator"></i> Count denominations</button>
                </div>
                <div id="opening-denomination-counter" class="cash-denomination-counter is-hidden"></div>
            </div>
            <input id="opening-ecash-input" type="hidden" value="0">
            <div class="payment-wrap">
                <label id="opening-balance-note-label" for="opening-balance-note">Note (optional)</label>
                <input id="opening-balance-note" type="text" placeholder="e.g. Start of day float">
            </div>
            <div id="opening-payment-account-balances" class="opening-payment-account-balances"></div>
        </div>
        <p id="opening-balance-result" class="result-msg"></p>
        </div>
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
        <div class="store-day-close-body">
        <p id="store-day-close-summary" class="scanner-status store-day-close-summary">Count cash and every receiving account independently.</p>
        <div id="store-day-close-reconcile" class="store-day-close-reconcile"></div>
        <div id="store-day-account-counts" class="store-day-account-counts"></div>
        <div id="store-day-unassigned-counts" class="store-day-account-counts"></div>
        <div class="store-day-close-fields">
            <div class="payment-wrap">
                <label for="closing-cash-input">Counted Cash</label>
                <div class="cash-count-input-row">
                    <input id="closing-cash-input" type="number" min="0" step="0.01" value="0">
                    <button type="button" class="secondary-btn cash-denomination-toggle" data-denomination-target="closing" aria-expanded="false"><i class="bi bi-calculator"></i> Count denominations</button>
                </div>
                <div id="closing-denomination-counter" class="cash-denomination-counter is-hidden"></div>
                <small id="closing-cash-variance" class="closing-variance">Variance PHP 0.00</small>
            </div>
            <input id="closing-ecash-input" type="hidden" value="0">
            <small id="closing-ecash-variance" class="closing-variance is-hidden">Variance PHP 0.00</small>
            <div class="payment-wrap">
                <label id="closing-note-label" for="closing-note-input">Closing Note (optional)</label>
                <input id="closing-note-input" type="text" placeholder="e.g. Cash count verified">
            </div>
        </div>
        <p id="store-day-close-result" class="result-msg"></p>
        </div>
        <div class="confirm-actions">
            <button id="store-day-close-cancel" type="button" class="secondary-btn">Cancel</button>
            <button id="store-day-close-save" type="button" class="primary-btn"><i class="bi bi-check2-circle"></i> Close Store Day</button>
        </div>
    </div>
</div>
