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
let isSubmitting = false;
let lastReceipt = null;

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

function getCartQty(productId) {
    const item = cart.find((entry) => Number(entry.product_id) === Number(productId));
    return item ? Number(item.qty) : 0;
}

function setResult(message, type) {
    const resultEl = document.getElementById("result");
    resultEl.textContent = message || "";
    resultEl.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function getStoreNameById(storeId) {
    const store = myStores.find((s) => Number(s.id) === Number(storeId));
    return store ? store.store_name : `Store #${storeId}`;
}

function getDebtCustomerLabelById(customerId) {
    const customer = debtCustomers.find((c) => Number(c.id) === Number(customerId));
    if (!customer) return "N/A";
    return `${customer.name} (${customer.user_type})`;
}

function buildReceiptHtml(receipt) {
    const rows = receipt.items
        .map(
            (item) => `
            <tr>
                <td>${escapeHtml(item.name)}</td>
                <td>${item.qty}</td>
                <td>${formatMoney(item.price)}</td>
                <td>${formatMoney(item.qty * item.price)}</td>
            </tr>
        `
        )
        .join("");

    const debtorLine =
        receipt.paymentMethod === "debt"
            ? `<div><strong>Debtor:</strong> ${escapeHtml(receipt.debtCustomerLabel)}</div>`
            : "";

    return `
        <div class="receipt-content-head">
            <div><strong>Transaction #:</strong> ${escapeHtml(receipt.transactionId)}</div>
            <div><strong>Date:</strong> ${escapeHtml(receipt.dateTime)}</div>
            <div><strong>Store:</strong> ${escapeHtml(receipt.storeName)}</div>
            <div><strong>Payment:</strong> ${escapeHtml(receipt.paymentMethod.toUpperCase())}</div>
            ${debtorLine}
        </div>
        <table class="receipt-table">
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
        <div class="receipt-total">Total: ${formatMoney(receipt.totalAmount)}</div>
    `;
}

function openReceiptModal(receipt) {
    lastReceipt = receipt;
    const modal = document.getElementById("receipt-modal");
    const content = document.getElementById("receipt-content");
    content.innerHTML = buildReceiptHtml(receipt);
    modal.style.display = "grid";
}

function closeReceiptModal() {
    const modal = document.getElementById("receipt-modal");
    modal.style.display = "none";
}

function printReceipt() {
    if (!lastReceipt) return;

    const printWindow = window.open("", "_blank", "width=800,height=900");
    if (!printWindow) {
        setResult("Popup blocked. Please allow popups to print receipt.", "error");
        return;
    }

    const html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt ${escapeHtml(lastReceipt.transactionId)}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; color: #111; }
                h2 { margin-top: 0; color: #003366; }
                .meta { margin-bottom: 12px; font-size: 14px; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { border: 1px solid #ddd; padding: 8px; font-size: 13px; }
                th { background: #f4f4f4; text-align: left; }
                .total { margin-top: 12px; text-align: right; font-weight: bold; font-size: 16px; }
            </style>
        </head>
        <body>
            <h2>USTP Store Receipt</h2>
            ${buildReceiptHtml(lastReceipt)}
        </body>
        </html>
    `;

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
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
        const sku = String(product.sku || "").toLowerCase();
        const category = String(product.category || "General");

        const matchesQuery = !query || name.includes(query) || sku.includes(query);
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
            const label = stock <= 0 ? "Out of stock" : "Add";
            const category = String(product.category || "General");
            const imageUrl = String(product.image_url || "").trim();
            const useImage = imageUrl !== "";
            const visualHtml = useImage
                ? `<img class="product-visual" src="${escapeHtml(imageUrl)}" alt="${escapeHtml(product.name)}">`
                : `<div class="product-visual placeholder">${escapeHtml(getInitials(product.name))}</div>`;

            return `
                <article class="product-card">
                    ${visualHtml}
                    <h5 class="product-name">${escapeHtml(product.name)}</h5>
                    <p class="product-meta">${escapeHtml(category)} | SKU: ${escapeHtml(product.sku)}</p>
                    <div class="product-bottom">
                        <div>
                            <div class="product-price">${formatMoney(product.price)}</div>
                            <div class="product-stock">Stock: ${stock} | In cart: ${inCart}</div>
                        </div>
                        <button
                            class="product-add"
                            data-product-id="${product.id}"
                            data-name="${escapeHtml(product.name)}"
                            data-price="${Number(product.price)}"
                            type="button"
                            ${canAdd ? "" : "disabled"}
                        >
                            ${label}
                        </button>
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

function renderStoreSelector() {
    const selectEl = document.getElementById("store-select");

    selectEl.innerHTML = myStores
        .map((store) => `<option value="${store.id}">${escapeHtml(store.store_name)}</option>`)
        .join("");
    selectEl.value = String(activeStoreId);
    selectEl.disabled = myStores.length <= 1;
}

function renderDebtCustomerSelect() {
    const selectEl = document.getElementById("debt-customer-select");

    const options = [
        '<option value="">Select customer</option>',
        ...debtCustomers.map((customer) => {
            const label = `${escapeHtml(customer.name)} (${escapeHtml(customer.user_type)}) - ${escapeHtml(customer.employee_id || customer.email)} - ${escapeHtml(formatCredit(customer))}`;
            return `<option value="${customer.id}">${label}</option>`;
        }),
    ];

    selectEl.innerHTML = options.join("");

    if (selectedDebtCustomerId) {
        selectEl.value = String(selectedDebtCustomerId);
    }
}

async function loadDebtCustomers(query = "") {
    const response = await fetch(`/store/debt-customers?q=${encodeURIComponent(query)}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        debtCustomers = [];
        renderDebtCustomerSelect();
        return;
    }

    debtCustomers = Array.isArray(data.customers) ? data.customers : [];
    renderDebtCustomerSelect();
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
    document.getElementById("debt-customer-search").value = "";
    document.getElementById("debt-customer-select").value = "";
}

function refreshUi() {
    applyProductFilters();
    renderProducts();
    renderCart();
}

function addToCart(productId, name, price) {
    const product = getProductById(productId);
    if (!product) return;

    const stock = Number(product.stock_qty || 0);
    const inCart = getCartQty(productId);
    if (inCart >= stock) {
        setResult("Cannot add more. Reached available stock.", "error");
        return;
    }

    const existing = cart.find((item) => Number(item.product_id) === Number(productId));
    if (existing) {
        existing.qty += 1;
    } else {
        cart.push({
            product_id: Number(productId),
            name,
            price: Number(price),
            qty: 1,
        });
    }

    setResult("", "ok");
    refreshUi();
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
    renderStoreSelector();
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

async function submitTransaction() {
    if (isSubmitting) {
        return;
    }

    const paymentMethod = document.getElementById("payment-method").value;
    const submitBtn = document.getElementById("submit-transaction");

    if (!activeStoreId) {
        setResult("No active store selected.", "error");
        return;
    }

    if (cart.length === 0) {
        setResult("Add at least one item before submitting.", "error");
        return;
    }

    if (paymentMethod === "debt" && !selectedDebtCustomerId) {
        setResult("Select a faculty/staff customer for debt payment.", "error");
        return;
    }

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

    try {
        isSubmitting = true;
        submitBtn.disabled = true;
        submitBtn.textContent = "Processing...";

        const response = await fetch("/pos/transactions", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json();

        if (data.status === "success") {
            setResult("Transaction successful.", "ok");
            const receipt = {
                transactionId: String(data.transaction_id),
                dateTime: formatDateTime(new Date()),
                storeName: getStoreNameById(activeStoreId),
                paymentMethod,
                debtCustomerLabel:
                    paymentMethod === "debt" && selectedDebtCustomerId
                        ? getDebtCustomerLabelById(selectedDebtCustomerId)
                        : "N/A",
                totalAmount,
                items: cartSnapshot,
            };

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
        submitBtn.textContent = "Complete Transaction";
    }
}

document.getElementById("product-grid").addEventListener("click", (event) => {
    const button = event.target.closest(".product-add");
    if (!button) return;

    addToCart(
        Number(button.dataset.productId),
        button.dataset.name || "",
        Number(button.dataset.price)
    );
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

document.getElementById("store-select").addEventListener("change", async (event) => {
    activeStoreId = Number(event.target.value);
    cart = [];
    setResult("", "ok");
    await loadProducts();
});

document.getElementById("payment-method").addEventListener("change", () => {
    updateDebtCustomerVisibility();
});

document.getElementById("debt-customer-search").addEventListener("input", async (event) => {
    const query = event.target.value || "";
    selectedDebtCustomerId = null;
    await loadDebtCustomers(query);
});

document.getElementById("debt-customer-select").addEventListener("change", (event) => {
    const value = Number(event.target.value || 0);
    selectedDebtCustomerId = value > 0 ? value : null;
});

document.getElementById("submit-transaction").addEventListener("click", submitTransaction);
document.getElementById("receipt-close").addEventListener("click", closeReceiptModal);
document.getElementById("receipt-print").addEventListener("click", printReceipt);
document.getElementById("receipt-modal").addEventListener("click", (event) => {
    if (event.target.id === "receipt-modal") {
        closeReceiptModal();
    }
});

(async () => {
    try {
        renderCategoryTabs();
        await loadMyStores();
        await loadProducts();
        updateDebtCustomerVisibility();
    } catch (error) {
        setResult(error.message || "Unable to load store context.", "error");
    }
})();
