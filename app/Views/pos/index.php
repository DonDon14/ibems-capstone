<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $pageTitle = 'POS Terminal'; $pageSubtitle = 'Tap products, review cart, and checkout.'; ?>

<style>
.pos-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); }
.product-tile { border: 1px solid #dbe3ef; border-radius: 12px; padding: .75rem; background: #fff; cursor: pointer; transition: .15s ease; }
.product-tile:hover { border-color: #3b82f6; box-shadow: 0 6px 16px rgba(59,130,246,.12); }
.product-tile.disabled { opacity: .55; cursor: not-allowed; }
.product-photo { width: 100%; height: 110px; object-fit: cover; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: .5rem; background: #f8fafc; }
.product-name { font-weight: 600; font-size: .95rem; }
.product-price { color: #0f766e; font-weight: 700; }
.cart-row-actions button { min-width: 28px; }
.muted-label { font-size: .8rem; color: #64748b; }
.shortcut-list kbd { padding: .1rem .35rem; border: 1px solid #cbd5e1; border-radius: 4px; font-size: .75rem; background: #f8fafc; }
</style>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card page-card mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Store</label>
                        <select id="storeSelect" class="form-select" <?= $stores === [] ? 'disabled' : '' ?>>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?= esc($store['id']) ?>"><?= esc($store['store_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Search Products</label>
                        <input id="productSearch" type="text" class="form-control" placeholder="Type product name or SKU...">
                    </div>
                </div>
                <div class="mt-3" id="categoryTabs"></div>
            </div>
        </div>

        <div class="card page-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Products</h2>
                    <span class="muted-label" id="productCount"></span>
                </div>
                <div id="productGrid" class="pos-grid"></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <form id="checkoutForm" class="card page-card mb-3">
            <div class="card-body">
                <?= csrf_field() ?>
                <?php if ($stores === []): ?>
                    <div class="alert alert-warning mb-3">
                        No active store is assigned to this account. Ask admin to assign your user as a store officer.
                    </div>
                <?php endif; ?>
                <h2 class="h6">Customer</h2>
                <div class="row g-2 mb-2">
                    <div class="col-12">
                        <label class="form-label">Customer Type</label>
                        <select id="customerType" class="form-select">
                            <option value="walk_in">Walk-In</option>
                            <option value="faculty">Faculty</option>
                            <option value="staff">Staff</option>
                            <option value="student">Student</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">User ID (required for non walk-in)</label>
                        <input id="userId" class="form-control" placeholder="e.g., 3">
                    </div>
                    <div class="col-8">
                        <label class="form-label">QR Token Lookup</label>
                        <input id="qrToken" class="form-control" placeholder="e.g., QR-FAC-001">
                    </div>
                    <div class="col-4 d-grid">
                        <label class="form-label">&nbsp;</label>
                        <button id="lookupBtn" type="button" class="btn btn-outline-primary">Lookup</button>
                    </div>
                    <div class="col-12">
                        <div id="lookupStatus" class="small text-secondary">No user loaded.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Walk-In Note (optional)</label>
                        <input id="walkinNote" class="form-control" placeholder="Visitor / Parent / Guest">
                    </div>
                </div>

                <h2 class="h6 mt-3">Payment</h2>
                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label">Method</label>
                        <select id="paymentMethod" class="form-select">
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                            <option value="card">Card</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="other">Other</option>
                            <option value="debt">Debt</option>
                            <option value="advance_payment">Advance Payment</option>
                        </select>
                    </div>
                    <div class="col-12" id="otherLabelWrap" style="display:none;">
                        <label class="form-label">Other Label</label>
                        <input id="otherPaymentLabel" class="form-control" placeholder="Maya / Cheque / etc.">
                    </div>
                </div>
            </div>
        </form>

        <div class="card page-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">Cart</h2>
                    <div class="shortcut-list small text-secondary">Shortcuts: <kbd>F2</kbd> Search <kbd>F4</kbd> Customer <kbd>F8</kbd> Checkout</div>
                </div>
                <div id="cartEmpty" class="text-secondary small mb-2 mt-2">No items yet.</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2">
                        <tbody id="cartBody"></tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between fw-semibold">
                    <span>Total</span>
                    <span id="cartTotal">PHP 0.00</span>
                </div>
                <div class="d-grid gap-2 mt-3">
                    <button type="button" id="checkoutBtn" class="btn btn-success" <?= $stores === [] ? 'disabled' : '' ?>>Checkout</button>
                    <button type="button" id="clearCartBtn" class="btn btn-outline-secondary">Clear Cart</button>
                    <button type="button" id="syncPendingBtn" class="btn btn-outline-primary">Sync Pending (<span id="pendingCount">0</span>)</button>
                </div>
                <div id="checkoutStatus" class="small mt-2 text-secondary">Ready.</div>
            </div>
        </div>
    </div>
</div>

<script>
const allProducts = <?= json_encode(array_values($products), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const cart = new Map();
let selectedCategory = 'ALL';
const pendingKey = 'ibems_pending_transactions_v1';

const storeSelect = document.getElementById('storeSelect');
const productSearch = document.getElementById('productSearch');
const productGrid = document.getElementById('productGrid');
const productCount = document.getElementById('productCount');
const categoryTabs = document.getElementById('categoryTabs');
const cartBody = document.getElementById('cartBody');
const cartTotal = document.getElementById('cartTotal');
const cartEmpty = document.getElementById('cartEmpty');
const customerType = document.getElementById('customerType');
const paymentMethod = document.getElementById('paymentMethod');
const otherLabelWrap = document.getElementById('otherLabelWrap');
const checkoutStatus = document.getElementById('checkoutStatus');
const userId = document.getElementById('userId');
const walkinNote = document.getElementById('walkinNote');
const otherPaymentLabel = document.getElementById('otherPaymentLabel');
const qrToken = document.getElementById('qrToken');
const lookupBtn = document.getElementById('lookupBtn');
const lookupStatus = document.getElementById('lookupStatus');
const pendingCount = document.getElementById('pendingCount');
const fallbackImage = 'data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 320 200\"><rect width=\"320\" height=\"200\" fill=\"%23eef2f7\"/><text x=\"50%\" y=\"50%\" dominant-baseline=\"middle\" text-anchor=\"middle\" fill=\"%236b7280\" font-size=\"20\" font-family=\"Arial\">No Photo</text></svg>';

function loadPending() {
    try {
        const raw = localStorage.getItem(pendingKey);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
}

function savePending(items) {
    localStorage.setItem(pendingKey, JSON.stringify(items));
    pendingCount.textContent = String(items.length);
}

function queuePending(payload) {
    const items = loadPending();
    items.push(payload);
    savePending(items);
}

function formatMoney(value) {
    return 'PHP ' + Number(value).toFixed(2);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function categoriesForStore() {
    const storeId = Number(storeSelect.value);
    const set = new Set(['ALL']);
    for (const product of allProducts) {
        if (Number(product.store_id) === storeId) {
            set.add((product.category || 'General').toUpperCase());
        }
    }
    return [...set];
}

function renderCategoryTabs() {
    const categories = categoriesForStore();
    if (!categories.includes(selectedCategory)) {
        selectedCategory = 'ALL';
    }
    categoryTabs.innerHTML = '';
    for (const category of categories) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm ' + (selectedCategory === category ? 'btn-primary' : 'btn-outline-primary') + ' me-1 mb-1';
        btn.textContent = category;
        btn.addEventListener('click', () => {
            selectedCategory = category;
            renderCategoryTabs();
            renderProducts();
        });
        categoryTabs.appendChild(btn);
    }
}

function getVisibleProducts() {
    const storeId = Number(storeSelect.value);
    const keyword = productSearch.value.trim().toLowerCase();
    return allProducts.filter((product) => {
        if (Number(product.store_id) !== storeId) return false;
        const category = String(product.category || 'General').toUpperCase();
        if (selectedCategory !== 'ALL' && category !== selectedCategory) return false;
        if (!keyword) return true;
        return String(product.name).toLowerCase().includes(keyword) || String(product.sku).toLowerCase().includes(keyword);
    });
}

function renderProducts() {
    const products = getVisibleProducts();
    productCount.textContent = products.length + ' item(s)';
    productGrid.innerHTML = '';

    for (const product of products) {
        const inStock = Number(product.stock_qty) > 0;
        const tile = document.createElement('button');
        tile.type = 'button';
        tile.className = 'product-tile text-start' + (inStock ? '' : ' disabled');
        tile.disabled = !inStock;
        const imageSrc = product.image_path ? escapeHtml(product.image_path) : fallbackImage;
        tile.innerHTML = `
            <img class="product-photo" src="${imageSrc}" alt="${escapeHtml(product.name)}">
            <div class="product-name">${escapeHtml(product.name)}</div>
            <div class="muted-label">${escapeHtml(product.category || 'General')} • SKU: ${escapeHtml(product.sku)}</div>
            <div class="d-flex justify-content-between mt-2">
                <span class="product-price">${formatMoney(product.price)}</span>
                <span class="muted-label">Stock: ${product.stock_qty}</span>
            </div>
        `;
        tile.addEventListener('click', () => addToCart(product));
        productGrid.appendChild(tile);
    }
}

function addToCart(product) {
    const key = Number(product.id);
    const current = cart.get(key) || { product, qty: 0 };
    if (current.qty + 1 > Number(product.stock_qty)) {
        checkoutStatus.textContent = 'Cannot exceed stock quantity.';
        checkoutStatus.className = 'small mt-2 text-danger';
        return;
    }
    current.qty += 1;
    cart.set(key, current);
    renderCart();
}

function renderCart() {
    cartBody.innerHTML = '';
    let total = 0;

    for (const [key, item] of cart.entries()) {
        const line = item.qty * Number(item.product.price);
        total += line;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <div class="fw-semibold">${escapeHtml(item.product.name)}</div>
                <div class="muted-label">${formatMoney(item.product.price)}</div>
            </td>
            <td class="text-end">
                <div class="d-inline-flex align-items-center gap-1 cart-row-actions">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="dec">-</button>
                    <span>${item.qty}</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-action="inc">+</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" data-action="rm">x</button>
                </div>
            </td>
        `;

        tr.querySelector('[data-action="dec"]').addEventListener('click', () => {
            if (item.qty <= 1) {
                cart.delete(key);
            } else {
                item.qty -= 1;
                cart.set(key, item);
            }
            renderCart();
        });

        tr.querySelector('[data-action="inc"]').addEventListener('click', () => {
            if (item.qty + 1 <= Number(item.product.stock_qty)) {
                item.qty += 1;
                cart.set(key, item);
                renderCart();
            }
        });

        tr.querySelector('[data-action="rm"]').addEventListener('click', () => {
            cart.delete(key);
            renderCart();
        });

        cartBody.appendChild(tr);
    }

    cartEmpty.style.display = cart.size === 0 ? 'block' : 'none';
    cartTotal.textContent = formatMoney(total);
}

function updatePaymentRules() {
    const type = customerType.value;
    const debtBlocked = type === 'walk_in' || type === 'student';

    [...paymentMethod.options].forEach((opt) => {
        if (opt.value === 'debt' || opt.value === 'advance_payment') {
            opt.disabled = debtBlocked;
        }
    });

    if (debtBlocked && (paymentMethod.value === 'debt' || paymentMethod.value === 'advance_payment')) {
        paymentMethod.value = 'cash';
    }

    otherLabelWrap.style.display = paymentMethod.value === 'other' ? 'block' : 'none';
}

async function lookupByQr() {
    const token = qrToken.value.trim();
    if (!token) {
        lookupStatus.textContent = 'Enter a QR token first.';
        lookupStatus.className = 'small text-danger';
        return;
    }

    const formData = new FormData();
    const csrfInput = document.querySelector('#checkoutForm input[type="hidden"]');
    if (csrfInput) {
        formData.append(csrfInput.name, csrfInput.value);
    }
    formData.append('qr_token', token);

    const response = await fetch('/pos/scan', { method: 'POST', body: formData });
    const data = await response.json();

    if (!response.ok) {
        lookupStatus.textContent = data.message || 'QR lookup failed.';
        lookupStatus.className = 'small text-danger';
        return;
    }

    userId.value = data.id;
    customerType.value = data.user_type;
    updatePaymentRules();
    lookupStatus.textContent = `Loaded: ${data.name} (${data.user_type})`;
    lookupStatus.className = 'small text-success';
}

function buildClientTxnId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'txn-' + Date.now() + '-' + Math.random().toString(16).slice(2);
}

function buildPayload() {
    const type = customerType.value;
    const method = paymentMethod.value;
    const uid = userId.value.trim();

    if (cart.size === 0) {
        throw new Error('Cart is empty.');
    }

    if (type !== 'walk_in' && !uid) {
        throw new Error('User ID is required for non walk-in transactions.');
    }

    if ((type === 'walk_in' || type === 'student') && (method === 'debt' || method === 'advance_payment')) {
        throw new Error('Debt/Advance payment is not allowed for this customer type.');
    }

    if (method === 'other' && !otherPaymentLabel.value.trim()) {
        throw new Error('Other payment label is required.');
    }

    const items = [];
    for (const item of cart.values()) {
        items.push({ product_id: Number(item.product.id), qty: item.qty });
    }

    return {
        client_txn_id: buildClientTxnId(),
        user_id: type === 'walk_in' ? null : Number(uid),
        customer_type: type,
        store_id: Number(storeSelect.value),
        payment_method: method,
        other_payment_label: otherPaymentLabel.value.trim(),
        walkin_note: walkinNote.value.trim(),
        items,
    };
}

async function checkout() {
    let payload;
    try {
        payload = buildPayload();
    } catch (error) {
        checkoutStatus.textContent = error.message;
        checkoutStatus.className = 'small mt-2 text-danger';
        return;
    }

    const formData = new FormData();
    const csrfInput = document.querySelector('#checkoutForm input[type="hidden"]');
    if (csrfInput) {
        formData.append(csrfInput.name, csrfInput.value);
    }

    formData.append('client_txn_id', payload.client_txn_id);
    formData.append('user_id', payload.user_id === null ? '' : String(payload.user_id));
    formData.append('customer_type', payload.customer_type);
    formData.append('store_id', String(payload.store_id));
    formData.append('payment_method', payload.payment_method);
    formData.append('other_payment_label', payload.other_payment_label);
    formData.append('walkin_note', payload.walkin_note);
    formData.append('items_json', JSON.stringify(payload.items));

    checkoutStatus.textContent = 'Submitting transaction...';
    checkoutStatus.className = 'small mt-2 text-secondary';

    try {
        const response = await fetch('/pos/transactions', { method: 'POST', body: formData });
        const data = await response.json();

        if (!response.ok) {
            checkoutStatus.textContent = data.message || 'Checkout failed.';
            checkoutStatus.className = 'small mt-2 text-danger';
            return;
        }

        cart.clear();
        renderCart();
        checkoutStatus.innerHTML = `Saved. Reference: <strong>${data.reference_no}</strong> <a href="/pos/receipt/${data.reference_no}" target="_blank" rel="noopener">Open receipt</a>`;
        checkoutStatus.className = 'small mt-2 text-success';
    } catch (error) {
        queuePending(payload);
        cart.clear();
        renderCart();
        checkoutStatus.textContent = 'Network unavailable. Transaction queued for sync.';
        checkoutStatus.className = 'small mt-2 text-warning';
    }
}

async function syncPending() {
    const items = loadPending();
    if (items.length === 0) {
        checkoutStatus.textContent = 'No pending transactions to sync.';
        checkoutStatus.className = 'small mt-2 text-secondary';
        return;
    }

    checkoutStatus.textContent = 'Syncing pending transactions...';
    checkoutStatus.className = 'small mt-2 text-secondary';

    try {
        const response = await fetch('/sync/transactions/batch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ items }),
        });

        const data = await response.json();
        if (!response.ok) {
            checkoutStatus.textContent = data.message || 'Sync failed.';
            checkoutStatus.className = 'small mt-2 text-danger';
            return;
        }

        const failedIds = new Set();
        for (const result of data.results || []) {
            if (result.status !== 'synced' && result.status !== 'already_synced') {
                failedIds.add(result.client_txn_id);
            }
        }

        const remaining = items.filter((item) => failedIds.has(item.client_txn_id));
        savePending(remaining);

        const successCount = items.length - remaining.length;
        checkoutStatus.textContent = `Sync complete: ${successCount} synced, ${remaining.length} remaining.`;
        checkoutStatus.className = remaining.length === 0 ? 'small mt-2 text-success' : 'small mt-2 text-warning';
    } catch (error) {
        checkoutStatus.textContent = 'Sync request failed. Check connection and try again.';
        checkoutStatus.className = 'small mt-2 text-danger';
    }
}

function setupKeyboardShortcuts() {
    document.addEventListener('keydown', (event) => {
        if (event.key === 'F2') {
            event.preventDefault();
            productSearch.focus();
        }

        if (event.key === 'F4') {
            event.preventDefault();
            customerType.focus();
        }

        if (event.key === 'F8') {
            event.preventDefault();
            checkout();
        }

        if (event.key === 'Escape') {
            productSearch.value = '';
            renderProducts();
        }
    });
}

storeSelect.addEventListener('change', () => {
    renderCategoryTabs();
    renderProducts();
});
productSearch.addEventListener('input', renderProducts);
customerType.addEventListener('change', updatePaymentRules);
paymentMethod.addEventListener('change', updatePaymentRules);
document.getElementById('clearCartBtn').addEventListener('click', () => { cart.clear(); renderCart(); });
document.getElementById('checkoutBtn').addEventListener('click', checkout);
document.getElementById('syncPendingBtn').addEventListener('click', syncPending);
lookupBtn.addEventListener('click', lookupByQr);

renderCategoryTabs();
renderProducts();
renderCart();
updatePaymentRules();
setupKeyboardShortcuts();
savePending(loadPending());
</script>
<?= $this->endSection() ?>
