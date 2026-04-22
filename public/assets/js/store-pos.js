let cart = [];
let productsCache = [];
let filteredProducts = [];
let activeStoreId = null;
let myStores = [];
let searchQuery = "";
let categories = ["All"];
let activeCategory = "All";
let debtCustomers = [];
let selectedDebtCustomerId = null;
let selectedDebtCustomer = null;
let isSubmitting = false;
let lastReceipt = null;
let lastCardAddAt = 0;
let scannerEngine = null;
let scannerRunning = false;
let scannerLastDetected = "";
let scannerLastDetectedAt = 0;
let scannerMode = "product";
let pendingTransaction = null;
let paymentMethodsCache = [];
let openingBalanceReady = false;
const currentUserRole = String(document.querySelector(".pos-shell")?.dataset.userRole || "").toUpperCase();
const isAdminUser = currentUserRole === "ADMIN";
let openingBalanceMode = "create";
let currentOpeningBalance = null;

function updateOpeningBalanceDisplay(opening = null, businessDate = null) {
    const displayEl = document.getElementById("opening-balance-display");
    const dateEl = document.getElementById("opening-balance-date");
    const openBtn = document.getElementById("opening-balance-open-btn");
    if (!displayEl || !dateEl) return;

    if (!opening) {
        openingBalanceMode = "create";
        displayEl.textContent = "Not set";
        dateEl.textContent = "Set this once per store";
        if (openBtn) {
            openBtn.disabled = false;
            openBtn.innerHTML = '<i class="bi bi-pencil-square"></i> Set Initial Opening';
        }
        return;
    }

    displayEl.textContent = formatMoney(opening.opening_balance || 0);
    dateEl.textContent = businessDate ? `Initial date: ${businessDate}` : "Initial opening set";
    if (openBtn) {
        if (isAdminUser) {
            openingBalanceMode = "reset";
            openBtn.disabled = false;
            openBtn.innerHTML = '<i class="bi bi-shield-lock"></i> Admin Reset Opening';
        } else {
            openingBalanceMode = "locked";
            openBtn.disabled = true;
            openBtn.innerHTML = '<i class="bi bi-lock"></i> Opening Locked';
        }
    }
}

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function formatMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function formatDateTime(value) {
    const date = value ? new Date(value) : new Date();
    return date.toLocaleString();
}

function getInitials(text) {
    return String(text || "")
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0] || "")
        .join("")
        .toUpperCase() || "PR";
}

function getProductById(productId) {
    return productsCache.find((p) => Number(p.id) === Number(productId));
}

function sanitizeIconClass(iconClass) {
    const value = String(iconClass || "").trim();
    if (!value) return "";
    if (!/^bi\s+bi\-[a-z0-9\-]+$/i.test(value)) return "";
    return value;
}

function getProductDisplayName(product) {
    const name = String(product?.name || "").trim();
    const variant = String(product?.variant_label || "").trim();
    return variant ? `${name} (${variant})` : name;
}

function getProductByScanCode(rawCode) {
    const code = String(rawCode || "").trim();
    if (code === "") return null;
    const normalized = code.toLowerCase();

    return (
        productsCache.find((p) => String(p.sku || "").trim().toLowerCase() === normalized) ||
        productsCache.find((p) => String(p.barcode || "").trim().toLowerCase() === normalized) ||
        productsCache.find((p) => String(p.id || "").trim() === code) ||
        null
    );
}

function renderScanProductSuggestions() {
    const box = document.getElementById("scan-product-suggestions");
    const query = String(document.getElementById("scan-code-input").value || "").trim().toLowerCase();

    if (!query) {
        box.innerHTML = "";
        box.style.display = "none";
        return;
    }

    const matches = productsCache.filter((product) => {
        const sku = String(product.sku || "").toLowerCase();
        const name = String(product.name || "").toLowerCase();
        const barcode = String(product.barcode || "").toLowerCase();
        const category = String(product.category || "").toLowerCase();
        return sku.includes(query) || name.includes(query) || barcode.includes(query) || category.includes(query);
    });

    if (matches.length === 0) {
        box.innerHTML = '<div class="scan-suggestion-empty">No matching products.</div>';
        box.style.display = "block";
        return;
    }

    const top = matches.slice(0, 3);
    box.innerHTML = top
        .map((product) => {
            const category = String(product.category || "General");
            const displayName = getProductDisplayName(product);
            return `
                <button type="button" class="scan-suggestion-item" data-scan-product-id="${product.id}">
                    <span class="name">${escapeHtml(displayName)}</span>
                    <span class="meta">SKU: ${escapeHtml(product.sku || "-")} • ${escapeHtml(category)} • ${escapeHtml(formatMoney(product.price))}</span>
                </button>
            `;
        })
        .join("");
    box.style.display = "block";
}

function getCartQty(productId) {
    const item = cart.find((entry) => Number(entry.product_id) === Number(productId));
    return item ? Number(item.qty) : 0;
}

function setResult(message, type) {
    const resultEl = document.getElementById("result");
    resultEl.textContent = message || "";
    resultEl.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function setOpeningBalanceResult(message, type) {
    const resultEl = document.getElementById("opening-balance-result");
    if (!resultEl) return;
    resultEl.textContent = message || "";
    resultEl.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function setPosTransactionEnabled(enabled) {
    const submitBtn = document.getElementById("submit-transaction");
    if (!submitBtn) return;
    submitBtn.disabled = !enabled;
}

function openOpeningBalanceModal(prefill = null) {
    const modal = document.getElementById("opening-balance-modal");
    const titleEl = document.getElementById("opening-balance-title");
    const descEl = document.getElementById("opening-balance-description");
    const labelEl = document.getElementById("opening-balance-label");
    const saveBtn = document.getElementById("opening-balance-save");
    if (!modal) return;
    if (prefill && typeof prefill.opening_balance !== "undefined") {
        document.getElementById("opening-balance-input").value = Number(prefill.opening_balance || 0).toFixed(2);
        document.getElementById("opening-balance-note").value = String(prefill.note || "");
    }
    if (openingBalanceMode === "reset") {
        if (titleEl) titleEl.innerHTML = '<i class="bi bi-shield-lock"></i> Admin Reset Initial Opening';
        if (descEl) descEl.textContent = "Admin action: update the one-time initial opening balance for this store.";
        if (labelEl) labelEl.textContent = "New Initial Opening Balance";
        if (saveBtn) saveBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Save Reset';
    } else {
        if (titleEl) titleEl.innerHTML = '<i class="bi bi-safe2"></i> Set Initial Opening Balance';
        if (descEl) descEl.textContent = "Set this once when the store is first activated. Use cash in/out for adjustments after this.";
        if (labelEl) labelEl.textContent = "Initial Opening Balance";
        if (saveBtn) saveBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Save Initial Opening';
    }
    setOpeningBalanceResult("", "ok");
    modal.style.display = "grid";
}

function closeOpeningBalanceModal() {
    const modal = document.getElementById("opening-balance-modal");
    if (!modal) return;
    modal.style.display = "none";
}

async function loadOpeningBalanceStatus() {
    if (!activeStoreId) return;
    try {
        const response = await fetch(`/store/opening-balance/status?store_id=${activeStoreId}`);
        const data = await response.json();
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Unable to load opening balance status.");
        }

        openingBalanceReady = !!data.is_opened;
        currentOpeningBalance = data.opening || null;
        updateOpeningBalanceDisplay(data.opening, data.business_date);
        setPosTransactionEnabled(openingBalanceReady);
        if (!openingBalanceReady) {
            openOpeningBalanceModal();
        } else {
            closeOpeningBalanceModal();
        }
    } catch (error) {
        openingBalanceReady = false;
        currentOpeningBalance = null;
        updateOpeningBalanceDisplay(null, null);
        setPosTransactionEnabled(false);
        openOpeningBalanceModal();
        setOpeningBalanceResult("Unable to verify opening balance status.", "error");
    }
}

async function saveOpeningBalance() {
    if (!activeStoreId) return;

    const amount = Number(document.getElementById("opening-balance-input").value || 0);
    const note = String(document.getElementById("opening-balance-note").value || "").trim();
    const saveBtn = document.getElementById("opening-balance-save");

    if (amount < 0) {
        setOpeningBalanceResult("Initial opening balance must be 0 or greater.", "error");
        return;
    }

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    try {
        const endpoint = openingBalanceMode === "reset" ? "/store/opening-balance/reset" : "/store/opening-balance/set";
        const response = await fetch(endpoint, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                store_id: activeStoreId,
                opening_balance: amount,
                note,
            }),
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Failed to save opening balance.");
        }

        openingBalanceReady = true;
        currentOpeningBalance = data.opening || null;
        setPosTransactionEnabled(true);
        updateOpeningBalanceDisplay(data.opening, data.opening?.business_date || null);
        closeOpeningBalanceModal();
        setResult(
            openingBalanceMode === "reset"
                ? `Initial opening balance reset: ${formatMoney(amount)}`
                : `Initial opening balance set: ${formatMoney(amount)}`,
            "ok"
        );
    } catch (error) {
        setOpeningBalanceResult(error.message || "Failed to save opening balance.", "error");
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML =
            openingBalanceMode === "reset"
                ? '<i class="bi bi-check2-circle"></i> Save Reset'
                : '<i class="bi bi-check2-circle"></i> Save Initial Opening';
    }
}

function setScannerStatus(message, isError = false) {
    const el = document.getElementById("scanner-status");
    el.textContent = message || "";
    el.style.color = isError ? "#b91c1c" : "#475569";
}

function setScannerUiState(running) {
    const startBtn = document.getElementById("scanner-start");
    const stopBtn = document.getElementById("scanner-stop");
    const openBtn = document.getElementById("open-scanner-btn");
    const openDebtBtn = document.getElementById("open-debt-scanner-btn");

    if (running) {
        if (startBtn) {
            startBtn.style.display = "none";
            startBtn.disabled = true;
        }
        stopBtn.disabled = false;
        openBtn.disabled = true;
        if (openDebtBtn) openDebtBtn.disabled = true;
        return;
    }

    if (startBtn) {
        startBtn.style.display = "inline-flex";
        startBtn.disabled = false;
    }
    stopBtn.disabled = true;
    openBtn.disabled = false;
    if (openDebtBtn) openDebtBtn.disabled = false;
}

async function openScannerModal(mode = "product") {
    scannerMode = mode === "debt" ? "debt" : "product";
    const titleEl = document.getElementById("scanner-title");
    if (titleEl) {
        titleEl.innerHTML =
            scannerMode === "debt"
                ? '<i class="bi bi-person-badge"></i> Employee QR Scanner'
                : '<i class="bi bi-upc-scan"></i> Barcode Scanner';
    }

    document.getElementById("barcode-scanner-modal").style.display = "grid";
    setScannerUiState(scannerRunning);
    setScannerStatus("Starting scanner...");
    await startScanner();
}

async function closeScannerModal() {
    await stopScanner();
    document.getElementById("barcode-scanner-modal").style.display = "none";
}

async function startScanner() {
    if (scannerRunning) return;

    if (typeof window.Html5Qrcode === "undefined") {
        setScannerStatus("Scanner library not loaded. Check internet/CDN access.", true);
        setScannerUiState(false);
        return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setScannerStatus("Camera API not available in this browser.", true);
        setScannerUiState(false);
        return;
    }

    const readerId = "scanner-reader";
    const readerEl = document.getElementById(readerId);
    readerEl.innerHTML = "";
    scannerEngine = new window.Html5Qrcode(readerId);

    try {
        await scannerEngine.start(
            { facingMode: "environment" },
            {
                fps: 10,
                qrbox: { width: 260, height: 180 },
                aspectRatio: 1.7777778,
            },
            async (decodedText) => {
                const rawValue = String(decodedText || "").trim();
                if (rawValue === "") return;

                const now = Date.now();
                if (rawValue === scannerLastDetected && now - scannerLastDetectedAt <= 1200) {
                    return;
                }

                scannerLastDetected = rawValue;
                scannerLastDetectedAt = now;
                setScannerStatus(`Detected: ${rawValue}`);
                await handleDetectedScannerCode(rawValue);
            },
            () => {}
        );
    } catch (error) {
        scannerEngine = null;
        scannerRunning = false;
        setScannerStatus("Failed to start camera scanner.", true);
        setScannerUiState(false);
        return;
    }

    scannerRunning = true;
    setScannerUiState(true);
    setScannerStatus("Scanning... Point camera to barcode/QR.");
}

async function stopScanner() {
    if (!scannerRunning || !scannerEngine) {
        scannerRunning = false;
        setScannerUiState(false);
        return;
    }

    try {
        await scannerEngine.stop();
    } catch (error) {
        // Ignore stop errors on already-stopped sessions.
    }

    try {
        await scannerEngine.clear();
    } catch (error) {
        // Ignore clear errors.
    }

    scannerEngine = null;
    scannerRunning = false;
    setScannerUiState(false);
}

function autoSelectDebtCustomerByCode(rawCode) {
    const code = String(rawCode || "").trim().toLowerCase();
    if (!code || !Array.isArray(debtCustomers) || debtCustomers.length === 0) return false;

    const exact = debtCustomers.find((customer) => {
        const employeeId = String(customer.employee_id || "").trim().toLowerCase();
        const email = String(customer.email || "").trim().toLowerCase();
        const name = String(customer.name || "").trim().toLowerCase();
        const id = String(customer.id || "").trim().toLowerCase();
        return employeeId === code || email === code || id === code || name === code;
    });

    if (!exact) return false;

    selectedDebtCustomerId = Number(exact.id);
    selectedDebtCustomer = exact;
    document.getElementById("debt-customer-search").value = exact.name;
    document.getElementById("debt-customer-suggestions").style.display = "none";
    setResult(`Debt customer selected: ${exact.name}`, "ok");
    return true;
}

async function handleDetectedScannerCode(rawValue) {
    if (scannerMode === "debt") {
        const searchInput = document.getElementById("debt-customer-search");
        searchInput.value = rawValue;
        selectedDebtCustomerId = null;
        await loadDebtCustomers(rawValue);
        autoSelectDebtCustomerByCode(rawValue);
        await closeScannerModal();
        searchInput.focus();
        return;
    }

    if (/\/store\/receipt\/\d+/i.test(rawValue)) {
        await closeScannerModal();
        window.open(rawValue, "_blank", "noopener");
        return;
    }

    document.getElementById("scan-code-input").value = rawValue;
    await closeScannerModal();
    document.getElementById("scan-qty-input").focus();
}

function getStoreNameById(storeId) {
    const store = myStores.find((s) => Number(s.id) === Number(storeId));
    return store ? store.store_name : `Store #${storeId}`;
}

function getDebtCustomerLabelById(customerId) {
    const customer =
        selectedDebtCustomer && Number(selectedDebtCustomer.id) === Number(customerId)
            ? selectedDebtCustomer
            : debtCustomers.find((c) => Number(c.id) === Number(customerId));
    if (!customer) return "N/A";
    const category = String(customer.user_type || "");
    const categoryLabel = category ? category.charAt(0).toUpperCase() + category.slice(1).toLowerCase() : "N/A";
    return `${customer.name} (${categoryLabel})`;
}

function openReceiptModal(receipt) {
    lastReceipt = receipt;
    const modal = document.getElementById("receipt-modal");
    if (window.IbemsReceipt) {
        window.IbemsReceipt.renderReceipt("receipt-content", receipt);
    }
    modal.style.display = "grid";
}

function closeReceiptModal() {
    const modal = document.getElementById("receipt-modal");
    modal.style.display = "none";
}

function buildConfirmTransactionHtml(data) {
    const rows = data.cartSnapshot
        .map((item) => `
            <tr>
                <td>${escapeHtml(item.name)}</td>
                <td>${item.qty}</td>
                <td>${formatMoney(item.price)}</td>
                <td>${formatMoney(item.qty * item.price)}</td>
            </tr>
        `)
        .join("");

    const paymentLabel = String(data.paymentMethod || "").replace(/_/g, " ").toUpperCase();
    const debtorLine = data.paymentMethod === "debt"
        ? `<div class="confirm-meta-item"><span>Debtor</span><strong>${escapeHtml(data.debtCustomerLabel)}</strong></div>`
        : "";

    return `
        <div class="confirm-meta-grid">
            <div class="confirm-meta-item"><span>Store</span><strong>${escapeHtml(data.storeName)}</strong></div>
            <div class="confirm-meta-item"><span>Payment</span><strong>${escapeHtml(paymentLabel)}</strong></div>
            ${debtorLine}
            <div class="confirm-meta-item"><span>Total Items</span><strong>${data.cartSnapshot.reduce((sum, item) => sum + Number(item.qty || 0), 0)}</strong></div>
            <div class="confirm-meta-item"><span>Total Amount</span><strong>${formatMoney(data.totalAmount)}</strong></div>
        </div>
        <table class="receipt-table confirm-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Line Total</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    `;
}

function openConfirmTransactionModal(data) {
    pendingTransaction = data;
    const content = document.getElementById("confirm-transaction-content");
    content.innerHTML = buildConfirmTransactionHtml(data);
    document.getElementById("confirm-transaction-modal").style.display = "grid";
}

function closeConfirmTransactionModal(force = false) {
    if (isSubmitting && !force) return;
    pendingTransaction = null;
    document.getElementById("confirm-transaction-modal").style.display = "none";
}

function printReceipt() {
    if (!lastReceipt) return;
    if (!window.IbemsReceipt || !window.IbemsReceipt.printReceipt(lastReceipt)) {
        setResult("Popup blocked. Please allow popups to print receipt.", "error");
    }
}

function formatCredit(customer) {
    const available = Number(customer.available_credit || 0);
    const debt = Number(customer.current_debt || 0);
    return `Avail ${formatMoney(available)} | Debt ${formatMoney(debt)}`;
}

function applyProductFilters() {
    const query = searchQuery.trim().toLowerCase();

    filteredProducts = productsCache.filter((product) => {
        const name = String(product.name || "").toLowerCase();
        const variant = String(product.variant_label || "").toLowerCase();
        const sku = String(product.sku || "").toLowerCase();
        const barcode = String(product.barcode || "").toLowerCase();
        const category = String(product.category || "General");

        const matchesQuery = !query || name.includes(query) || variant.includes(query) || sku.includes(query) || barcode.includes(query);
        const matchesCategory = activeCategory === "All" || category === activeCategory;
        return matchesQuery && matchesCategory;
    });
}

function buildCategories() {
    const derived = new Set(["All"]);
    productsCache.forEach((product) => {
        const category = String(product.category || "General").trim() || "General";
        derived.add(category);
    });

    categories = Array.from(derived);
    if (!categories.includes(activeCategory)) {
        activeCategory = "All";
    }
}

function renderCategoryTabs() {
    const tabsEl = document.getElementById("category-tabs");

    tabsEl.innerHTML = categories
        .map(
            (category) =>
                `<button class="category-tab ${category === activeCategory ? "active" : ""}" data-category="${escapeHtml(category)}" type="button">${escapeHtml(category)}</button>`
        )
        .join("");
}

function renderProducts() {
    const grid = document.getElementById("product-grid");

    if (productsCache.length === 0) {
        grid.innerHTML = '<div class="empty-state">No active products found for this store.</div>';
        return;
    }

    if (filteredProducts.length === 0) {
        grid.innerHTML = '<div class="empty-state">No products match your filter.</div>';
        return;
    }

    grid.innerHTML = filteredProducts
        .map((product) => {
            const stock = Number(product.stock_qty || 0);
            const inCart = getCartQty(product.id);
            const canAdd = inCart < stock;
            const category = String(product.category || "General");
            const displayName = getProductDisplayName(product);
            const imageUrl = String(product.image_url || "").trim();
            const useImage = imageUrl !== "";
            const visualHtml = useImage
                ? `<img class="product-visual" src="${escapeHtml(imageUrl)}" alt="${escapeHtml(displayName)}">`
                : `<div class="product-visual placeholder">${escapeHtml(getInitials(displayName))}</div>`;

            return `
                <article class="product-card ${canAdd ? "" : "out-of-stock"}" data-product-card="${product.id}">
                    ${visualHtml}
                    <h5 class="product-name">${escapeHtml(displayName)}</h5>
                    <p class="product-meta">${escapeHtml(category)} | SKU: ${escapeHtml(product.sku)}</p>
                    <div class="product-bottom">
                        <div>
                            <div class="product-price">${formatMoney(product.price)}</div>
                            <div class="product-stock">Stock: ${stock} | In cart: ${inCart}</div>
                        </div>
                    </div>
                </article>
            `;
        })
        .join("");
}

function renderCart() {
    const cartBody = document.getElementById("cart-body");
    const subtotalEl = document.getElementById("subtotal-amount");
    const totalEl = document.getElementById("grand-total");
    const itemCountEl = document.getElementById("item-count");

    if (cart.length === 0) {
        cartBody.innerHTML = '<div class="empty-state">Cart is empty.</div>';
        subtotalEl.textContent = formatMoney(0);
        totalEl.textContent = formatMoney(0);
        itemCountEl.textContent = "0";
        return;
    }

    let subtotal = 0;
    let itemCount = 0;

    cartBody.innerHTML = cart
        .map((item) => {
            const lineTotal = Number(item.qty) * Number(item.price);
            subtotal += lineTotal;
            itemCount += Number(item.qty);

            const product = getProductById(item.product_id);
            const maxedOut = product ? Number(item.qty) >= Number(product.stock_qty || 0) : false;

            return `
                <div class="cart-item">
                    <div class="cart-top">
                        <p class="cart-name">${escapeHtml(item.name)}</p>
                        <span class="cart-line-total">${formatMoney(lineTotal)}</span>
                    </div>
                    <div class="cart-controls">
                        <button class="cart-btn cart-dec" data-product-id="${item.product_id}" type="button">-</button>
                        <span>${item.qty}</span>
                        <button class="cart-btn cart-inc" data-product-id="${item.product_id}" type="button" ${maxedOut ? "disabled" : ""}>+</button>
                        <button class="cart-btn remove cart-remove" data-product-id="${item.product_id}" type="button">Remove</button>
                    </div>
                </div>
            `;
        })
        .join("");

    subtotalEl.textContent = formatMoney(subtotal);
    totalEl.textContent = formatMoney(subtotal);
    itemCountEl.textContent = String(itemCount);
}

function renderDebtSuggestions() {
    const box = document.getElementById("debt-customer-suggestions");
    const query = String(document.getElementById("debt-customer-search").value || "").trim().toLowerCase();

    if (!query || debtCustomers.length === 0) {
        box.innerHTML = "";
        box.style.display = "none";
        return;
    }

    const items = debtCustomers.slice(0, 8).map((customer) => {
        const category = String(customer.user_type || "");
        const categoryLabel = category ? category.charAt(0).toUpperCase() + category.slice(1).toLowerCase() : "N/A";
        return `
            <button type="button" class="debt-suggestion-item" data-customer-id="${customer.id}">
                <span class="name">${escapeHtml(customer.name)}</span>
                <span class="meta">${escapeHtml(customer.employee_id || customer.email)} • ${escapeHtml(categoryLabel)} • ${escapeHtml(formatCredit(customer))}</span>
            </button>
        `;
    });

    box.innerHTML = items.join("");
    box.style.display = "block";
}

async function loadDebtCustomers(query = "") {
    const response = await fetch(`/store/debt-customers?q=${encodeURIComponent(query)}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        debtCustomers = [];
        renderDebtSuggestions();
        return;
    }

    debtCustomers = Array.isArray(data.customers) ? data.customers : [];
    renderDebtSuggestions();
}

function updateDebtCustomerVisibility() {
    const paymentMethod = document.getElementById("payment-method").value;
    const wrap = document.getElementById("debt-customer-wrap");

    if (paymentMethod === "debt") {
        wrap.style.display = "flex";
        if (debtCustomers.length === 0) {
            loadDebtCustomers().catch(() => setResult("Unable to load debt customers.", "error"));
        }
        return;
    }

    wrap.style.display = "none";
    selectedDebtCustomerId = null;
    selectedDebtCustomer = null;
    document.getElementById("debt-customer-search").value = "";
    document.getElementById("debt-customer-suggestions").style.display = "none";
}

function renderPaymentMethods() {
    const chipsEl = document.getElementById("payment-quick");
    const selectEl = document.getElementById("payment-method");
    const methods = Array.isArray(paymentMethodsCache) ? paymentMethodsCache : [];

    if (methods.length === 0) {
        chipsEl.innerHTML = "";
        selectEl.innerHTML = "";
        return;
    }

    chipsEl.innerHTML = methods
        .map((method, index) => {
            const isActive = index === 0 ? " is-active" : "";
            const iconClass = sanitizeIconClass(method.icon_class);
            const iconHtml = iconClass ? `<i class="${escapeHtml(iconClass)}"></i>` : "";
            return `<button type="button" class="payment-chip${isActive}" data-method="${escapeHtml(method.code)}">${iconHtml}${escapeHtml(method.label)}</button>`;
        })
        .join("");

    selectEl.innerHTML = methods
        .map((method, index) => `<option value="${escapeHtml(method.code)}" ${index === 0 ? "selected" : ""}>${escapeHtml(method.label)}</option>`)
        .join("");
}

async function loadPaymentMethods() {
    if (!activeStoreId) return;

    try {
        const response = await fetch(`/store/payment-methods?store_id=${activeStoreId}`);
        const data = await response.json();
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Unable to load payment methods.");
        }

        paymentMethodsCache = Array.isArray(data.methods) ? data.methods : [];
        renderPaymentMethods();
        const preferred = paymentMethodsCache.find((m) => String(m.code) === "cash")?.code
            || paymentMethodsCache[0]?.code
            || "cash";
        setPaymentMethod(preferred);
    } catch (error) {
        paymentMethodsCache = [
            { code: "cash", label: "Cash", icon_class: "bi bi-cash" },
            { code: "gcash", label: "GCash", icon_class: "bi bi-wallet2" },
            { code: "debt", label: "Debt", icon_class: "bi bi-credit-card" },
        ];
        renderPaymentMethods();
        setPaymentMethod("cash");
        setResult("Unable to load dynamic payment methods. Using fallback.", "error");
    }
}

function setPaymentMethod(method) {
    const paymentMethodEl = document.getElementById("payment-method");
    const chips = document.querySelectorAll("#payment-quick .payment-chip");
    paymentMethodEl.value = method;
    chips.forEach((chip) => chip.classList.toggle("is-active", chip.dataset.method === method));

    updateDebtCustomerVisibility();
}

function refreshUi() {
    applyProductFilters();
    renderProducts();
    renderCart();
}

function addToCart(productId, name, price, qtyRequested = 1) {
    const product = getProductById(productId);
    if (!product) return;

    const stock = Number(product.stock_qty || 0);
    const inCart = getCartQty(productId);
    const qty = Number.isInteger(Number(qtyRequested)) ? Number(qtyRequested) : 1;
    const safeQty = qty > 0 ? qty : 1;
    const available = stock - inCart;

    if (available <= 0) {
        setResult("Cannot add more. Reached available stock.", "error");
        return false;
    }

    const qtyToAdd = Math.min(safeQty, available);

    const existing = cart.find((item) => Number(item.product_id) === Number(productId));
    if (existing) {
        existing.qty += qtyToAdd;
    } else {
        cart.push({
            product_id: Number(productId),
            name,
            price: Number(price),
            qty: qtyToAdd,
        });
    }

    if (qtyToAdd < safeQty) {
        setResult(`Only ${qtyToAdd} item(s) added. Reached available stock.`, "error");
    } else {
        setResult("", "ok");
    }
    refreshUi();
    return true;
}

function quickAddFromScan() {
    const codeInput = document.getElementById("scan-code-input");
    const qtyInput = document.getElementById("scan-qty-input");

    const code = String(codeInput.value || "").trim();
    const qty = Number(qtyInput.value || 1);

    if (code === "") {
        setResult("Scan or enter a product code first.", "error");
        return;
    }

    if (!Number.isInteger(qty) || qty <= 0) {
        setResult("Quantity must be a whole number greater than 0.", "error");
        return;
    }

    const product = getProductByScanCode(code);
    if (!product) {
        setResult(`No product found for code: ${code}`, "error");
        return;
    }

    const added = addToCart(Number(product.id), getProductDisplayName(product), Number(product.price || 0), qty);
    if (!added) return;

    document.getElementById("scan-product-suggestions").style.display = "none";
    codeInput.value = "";
    qtyInput.value = "1";
    codeInput.focus();
}

function increaseQty(productId) {
    const item = cart.find((entry) => Number(entry.product_id) === Number(productId));
    if (!item) return;

    const product = getProductById(productId);
    if (!product) return;

    if (Number(item.qty) >= Number(product.stock_qty || 0)) {
        setResult("Cannot increase. Reached available stock.", "error");
        return;
    }

    item.qty += 1;
    setResult("", "ok");
    refreshUi();
}

function decreaseQty(productId) {
    const item = cart.find((entry) => Number(entry.product_id) === Number(productId));
    if (!item) return;

    if (Number(item.qty) <= 1) {
        removeFromCart(productId);
        return;
    }

    item.qty -= 1;
    refreshUi();
}

function removeFromCart(productId) {
    cart = cart.filter((item) => Number(item.product_id) !== Number(productId));
    refreshUi();
}

async function loadMyStores() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();

    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No assigned store found.");
    }

    myStores = data.stores;
    activeStoreId = Number(data.default_store_id || myStores[0].id);
}

async function loadProducts() {
    const grid = document.getElementById("product-grid");

    if (!activeStoreId) {
        grid.innerHTML = '<div class="empty-state">No active store selected.</div>';
        return;
    }

    try {
        const response = await fetch(`/store/products?store_id=${activeStoreId}`);
        const data = await response.json();

        if (!data || data.status !== "success") {
            productsCache = [];
            refreshUi();
            setResult(data?.message || "Unable to load products.", "error");
            return;
        }

        productsCache = Array.isArray(data.products) ? data.products : [];
        buildCategories();
        renderCategoryTabs();
        refreshUi();
    } catch (error) {
        productsCache = [];
        refreshUi();
        setResult("Unable to load products.", "error");
    }
}

function buildPendingTransaction() {
    const paymentMethod = document.getElementById("payment-method").value;
    if (!activeStoreId) return null;

    if (cart.length === 0) return null;

    if (paymentMethod === "debt" && !selectedDebtCustomerId) return null;

    const payload = {
        customer_type: "walk_in",
        customer_user_id: paymentMethod === "debt" ? selectedDebtCustomerId : null,
        store_id: activeStoreId,
        payment_method: paymentMethod,
        items: cart.map((item) => ({
            product_id: Number(item.product_id),
            qty: Number(item.qty),
        })),
    };

    const cartSnapshot = cart.map((item) => ({
        name: item.name,
        qty: Number(item.qty),
        price: Number(item.price),
    }));
    const totalAmount = cartSnapshot.reduce((sum, item) => sum + item.qty * item.price, 0);

    return {
        payload,
        paymentMethod,
        cartSnapshot,
        totalAmount,
        storeName: getStoreNameById(activeStoreId),
        debtCustomerLabel:
            paymentMethod === "debt" && selectedDebtCustomerId
                ? getDebtCustomerLabelById(selectedDebtCustomerId)
                : "N/A",
    };
}

async function processConfirmedTransaction(dataToProcess) {
    if (isSubmitting || !dataToProcess) return;
    const submitBtn = document.getElementById("submit-transaction");
    const confirmBtn = document.getElementById("confirm-proceed");

    try {
        isSubmitting = true;
        submitBtn.disabled = true;
        submitBtn.textContent = "Processing...";
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';

        const response = await fetch("/pos/transactions", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify(dataToProcess.payload),
        });

        const data = await response.json();

        if (data.status === "success") {
            setResult("Transaction successful.", "ok");
            const receipt = {
                transactionId: String(data.transaction_id),
                clientTxnId: String(data.transaction_id),
                createdAt: new Date().toISOString(),
                storeName: dataToProcess.storeName,
                paymentMethod: dataToProcess.paymentMethod,
                customerName: dataToProcess.paymentMethod === "debt" ? dataToProcess.debtCustomerLabel : "Walk-in",
                debtCustomerLabel: dataToProcess.debtCustomerLabel,
                totalAmount: dataToProcess.totalAmount,
                items: dataToProcess.cartSnapshot,
                lookupUrl: `${window.location.origin}/store/receipt/${encodeURIComponent(String(data.transaction_id))}`,
            };

            closeConfirmTransactionModal(true);
            cart = [];
            await loadProducts();
            openReceiptModal(receipt);
            return;
        }

        const message = data?.message || data?.messages?.error || "Transaction failed.";
        setResult(message, "error");
    } catch (error) {
        setResult("Unable to submit transaction.", "error");
    } finally {
        isSubmitting = false;
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Complete Transaction';
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Proceed';
    }
}

function submitTransaction() {
    if (isSubmitting) return;
    if (!openingBalanceReady) {
        openOpeningBalanceModal();
        setResult("Set initial opening balance first before transaction.", "error");
        return;
    }

    if (!activeStoreId) {
        setResult("No active store selected.", "error");
        return;
    }

    if (cart.length === 0) {
        setResult("Add at least one item before submitting.", "error");
        return;
    }

    if (document.getElementById("payment-method").value === "debt" && !selectedDebtCustomerId) {
        setResult("Select an employee (Faculty/Staff) for debt payment.", "error");
        return;
    }

    const draft = buildPendingTransaction();
    if (!draft) {
        setResult("Unable to prepare transaction.", "error");
        return;
    }

    openConfirmTransactionModal(draft);
}

document.getElementById("product-grid").addEventListener("click", (event) => {
    const card = event.target.closest("[data-product-card]");
    if (!card) return;

    const now = Date.now();
    if (now - lastCardAddAt < 220) return;
    lastCardAddAt = now;

    const productId = Number(card.getAttribute("data-product-card") || 0);
    const product = getProductById(productId);
    if (!product) return;

    addToCart(productId, getProductDisplayName(product), Number(product.price || 0));
});

document.getElementById("cart-body").addEventListener("click", (event) => {
    const incBtn = event.target.closest(".cart-inc");
    const decBtn = event.target.closest(".cart-dec");
    const removeBtn = event.target.closest(".cart-remove");

    if (incBtn) {
        increaseQty(Number(incBtn.dataset.productId));
        return;
    }

    if (decBtn) {
        decreaseQty(Number(decBtn.dataset.productId));
        return;
    }

    if (removeBtn) {
        removeFromCart(Number(removeBtn.dataset.productId));
    }
});

document.getElementById("category-tabs").addEventListener("click", (event) => {
    const button = event.target.closest(".category-tab");
    if (!button) return;

    activeCategory = button.dataset.category || "All";
    renderCategoryTabs();
    applyProductFilters();
    renderProducts();
});

document.getElementById("product-search").addEventListener("input", (event) => {
    searchQuery = event.target.value || "";
    applyProductFilters();
    renderProducts();
});

document.getElementById("scan-code-input").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    quickAddFromScan();
});

document.getElementById("scan-code-input").addEventListener("input", () => {
    renderScanProductSuggestions();
});

document.getElementById("scan-qty-input").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    quickAddFromScan();
});

document.getElementById("scan-add-btn").addEventListener("click", () => {
    quickAddFromScan();
});

document.getElementById("scan-product-suggestions").addEventListener("click", (event) => {
    const btn = event.target.closest("[data-scan-product-id]");
    if (!btn) return;

    const productId = Number(btn.getAttribute("data-scan-product-id") || 0);
    const product = getProductById(productId);
    if (!product) return;

    document.getElementById("scan-code-input").value = product.sku || String(product.id);
    document.getElementById("scan-product-suggestions").style.display = "none";
    document.getElementById("scan-qty-input").focus();
});

document.getElementById("open-scanner-btn").addEventListener("click", () => {
    openScannerModal("product").catch(() => setScannerStatus("Unable to open scanner.", true));
});

document.getElementById("open-debt-scanner-btn").addEventListener("click", () => {
    openScannerModal("debt").catch(() => setScannerStatus("Unable to open scanner.", true));
});

document.getElementById("scanner-close").addEventListener("click", async () => {
    await closeScannerModal();
});

document.getElementById("scanner-stop").addEventListener("click", async () => {
    await stopScanner();
    setScannerStatus("Scanner stopped.");
});

document.getElementById("barcode-scanner-modal").addEventListener("click", async (event) => {
    if (event.target.id === "barcode-scanner-modal") {
        await closeScannerModal();
    }
});

document.getElementById("payment-quick").addEventListener("click", (event) => {
    const chip = event.target.closest(".payment-chip");
    if (!chip) return;
    setPaymentMethod(chip.dataset.method || "cash");
});

document.getElementById("debt-customer-search").addEventListener("input", async (event) => {
    const query = event.target.value || "";
    selectedDebtCustomerId = null;
    selectedDebtCustomer = null;
    await loadDebtCustomers(query);
});

document.getElementById("debt-customer-suggestions").addEventListener("click", (event) => {
    const btn = event.target.closest("[data-customer-id]");
    if (!btn) return;

    const customerId = Number(btn.getAttribute("data-customer-id") || 0);
    const picked = debtCustomers.find((c) => Number(c.id) === customerId);
    if (!picked) return;

    selectedDebtCustomerId = customerId;
    selectedDebtCustomer = picked;
    document.getElementById("debt-customer-search").value = picked.name;
    document.getElementById("debt-customer-suggestions").style.display = "none";
    setResult(`Debt customer selected: ${picked.name}`, "ok");
});

document.addEventListener("click", (event) => {
    if (event.target.closest(".debt-search-wrap")) return;
    const box = document.getElementById("debt-customer-suggestions");
    if (box) box.style.display = "none";
});

document.addEventListener("click", (event) => {
    if (event.target.closest(".scan-code-wrap")) return;
    const box = document.getElementById("scan-product-suggestions");
    if (box) box.style.display = "none";
});

document.getElementById("submit-transaction").addEventListener("click", submitTransaction);
document.getElementById("confirm-proceed").addEventListener("click", async () => {
    if (!pendingTransaction) return;
    await processConfirmedTransaction(pendingTransaction);
});
document.getElementById("confirm-cancel").addEventListener("click", closeConfirmTransactionModal);
document.getElementById("confirm-close").addEventListener("click", closeConfirmTransactionModal);
document.getElementById("confirm-transaction-modal").addEventListener("click", (event) => {
    if (event.target.id === "confirm-transaction-modal") {
        closeConfirmTransactionModal();
    }
});
document.getElementById("receipt-close").addEventListener("click", closeReceiptModal);
document.getElementById("receipt-print").addEventListener("click", printReceipt);
document.getElementById("receipt-modal").addEventListener("click", (event) => {
    if (event.target.id === "receipt-modal") {
        closeReceiptModal();
    }
});
document.getElementById("opening-balance-save").addEventListener("click", saveOpeningBalance);
document.getElementById("opening-balance-open-btn").addEventListener("click", () => {
    if (openingBalanceMode === "locked") return;
    openOpeningBalanceModal(currentOpeningBalance);
});
document.getElementById("opening-balance-input").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    saveOpeningBalance();
});

(async () => {
    try {
        renderCategoryTabs();
        setScannerUiState(false);
        await loadMyStores();
        await loadProducts();
        await loadPaymentMethods();
        await loadOpeningBalanceStatus();
    } catch (error) {
        setResult(error.message || "Unable to load store context.", "error");
    }
})();
