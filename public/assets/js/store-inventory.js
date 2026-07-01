let invStores = [];
let invActiveStoreId = null;
let invProducts = [];
let invIsRestocking = false;
let invIsCreatingProduct = false;
let invIsUpdatingProduct = false;
let invModalProductId = null;
let invProductEditMode = false;
let invModalPanel = "adjust";
let invCreatePreviewObjectUrl = null;
let invModalPreviewObjectUrl = null;
let invCategories = [];
let invCreateSnapshot = null;
let invMovements = [];

function invGetElementValue(id, fallback = "") {
    const el = document.getElementById(id);
    return el ? String(el.value ?? "") : fallback;
}

function invEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function invMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function invSkuExists(sku, excludeProductId = 0) {
    const normalizedSku = String(sku || "").trim().toLowerCase();
    const excludedId = Number(excludeProductId || 0);
    if (normalizedSku === "") return false;

    return invProducts.some((product) => (
        Number(product.id || 0) !== excludedId
        && String(product.sku || "").trim().toLowerCase() === normalizedSku
    ));
}

function invBarcodeExists(barcode, excludeProductId = 0) {
    const normalizedBarcode = String(barcode || "").trim().toLowerCase();
    const excludedId = Number(excludeProductId || 0);
    if (normalizedBarcode === "") return false;

    return invProducts.some((product) => (
        Number(product.id || 0) !== excludedId
        && String(product.barcode || "").trim().toLowerCase() === normalizedBarcode
    ));
}

function invFormatDateTime(value) {
    const date = new Date(String(value || "").replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return value || "-";
    return date.toLocaleString([], {
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
    });
}

function invStockState(product) {
    const qty = Number(product?.stock_qty || 0);
    const rawLowStock = product?.low_stock_threshold ?? product?.reorder_level ?? 10;
    const parsedLowStock = Number(rawLowStock);
    const lowStock = Number.isFinite(parsedLowStock) ? Math.max(0, parsedLowStock) : 10;

    if (qty <= 0) {
        return {
            key: "out",
            label: "Out of stock",
            detail: "Needs restock",
            qty,
        };
    }

    if (qty <= lowStock) {
        return {
            key: "low",
            label: "Low stock",
            detail: `At or below ${lowStock}`,
            qty,
        };
    }

    return {
        key: "in",
        label: "In stock",
        detail: "Ready for POS",
        qty,
    };
}

function invSetResult(message, type) {
    const el = document.getElementById("inventory-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function invEnsureCategoryOption(selectEl, categoryName) {
    if (!selectEl) return;
    const name = String(categoryName || "").trim();
    if (!name) return;
    const exists = Array.from(selectEl.options).some((opt) => String(opt.value) === name);
    if (!exists) {
        const option = document.createElement("option");
        option.value = name;
        option.textContent = name;
        selectEl.appendChild(option);
    }
}

function invRenderCategorySelects() {
    const createSelect = document.getElementById("new-product-category");
    const editSelect = document.getElementById("modal-product-category");
    const filterSelect = document.getElementById("inventory-category-filter");
    const names = invCategories.length > 0
        ? invCategories.map((row) => String(row.name || "").trim()).filter((name) => name !== "")
        : ["General"];

    [createSelect, editSelect].forEach((selectEl) => {
        if (!selectEl) return;
        const currentValue = String(selectEl.value || "");
        selectEl.innerHTML = names.map((name) => `<option value="${invEscape(name)}">${invEscape(name)}</option>`).join("");
        if (currentValue !== "") {
            invEnsureCategoryOption(selectEl, currentValue);
            selectEl.value = currentValue;
        } else {
            selectEl.value = "General";
        }
    });

    if (filterSelect) {
        const currentValue = String(filterSelect.value || "");
        filterSelect.innerHTML = `<option value="">All Categories</option>${names
            .map((name) => `<option value="${invEscape(name)}">${invEscape(name)}</option>`)
            .join("")}`;
        if (currentValue !== "" && names.includes(currentValue)) {
            filterSelect.value = currentValue;
        }
    }
}

function invRenderProductTable() {
    const body = document.getElementById("inventory-product-body");
    const search = (document.getElementById("inventory-search").value || "").trim().toLowerCase();
    const categoryFilter = (document.getElementById("inventory-category-filter").value || "").trim().toLowerCase();
    const stockFilter = (document.getElementById("inventory-stock-filter").value || "").trim();

    const rows = invProducts.filter((product) => {
        const haystack = `${product.name || ""} ${product.variant_label || ""} ${product.sku || ""} ${product.barcode || ""} ${product.supplier || ""} ${product.location_bin || ""}`.toLowerCase();
        const category = String(product.category || "").trim().toLowerCase();
        const stock = invStockState(product).key;

        if (search && !haystack.includes(search)) return false;
        if (categoryFilter && category !== categoryFilter) return false;
        if (stockFilter && stock !== stockFilter) return false;
        return true;
    });

    invRenderStockSummary(rows);

    if (rows.length === 0) {
        body.innerHTML = '<tr><td colspan="5" class="inventory-empty">No products found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((product) => {
        const stock = invStockState(product);
        const variant = product.variant_label ? `<span>${invEscape(product.variant_label)}</span>` : "";
        const sku = product.sku ? `<span>SKU ${invEscape(product.sku)}</span>` : "";
        const supplier = product.supplier ? `<span>Supplier ${invEscape(product.supplier)}</span>` : "";
        const location = product.location_bin ? `<span>Bin ${invEscape(product.location_bin)}</span>` : "";
        const operationalMeta = supplier || location
            ? `<div class="inventory-product-meta inventory-product-meta-secondary">${supplier}${location}</div>`
            : "";
        const thumb = product.image_url
            ? `<img src="${invEscape(product.image_url)}" alt="${invEscape(product.name)}" class="prod-thumb">`
            : `<div class="prod-thumb-fallback">No Img</div>`;

        return `
            <tr class="product-row inventory-row-${stock.key}">
                <td>
                    <div class="inventory-product-cell">
                        ${thumb}
                        <div class="inventory-product-copy">
                            <strong>${invEscape(product.name || "Unnamed product")}</strong>
                            <div class="inventory-product-meta">${sku}${variant}</div>
                            ${operationalMeta}
                        </div>
                    </div>
                </td>
                <td>${invEscape(product.category || "-")}</td>
                <td>${invEscape(invMoney(product.price))}</td>
                <td>
                    <div class="inv-stock-block">
                        <span class="inv-stock-badge inv-stock-${stock.key}">${stock.label}</span>
                        <span class="inv-stock-detail">${stock.qty} item${stock.qty === 1 ? "" : "s"} - ${invEscape(stock.detail)}</span>
                    </div>
                </td>
                <td>
                    <button class="inventory-manage-btn" type="button" data-product-action="${product.id}">Manage</button>
                </td>
            </tr>
        `;
    }).join("");
}

function invRenderStockSummary(rows) {
    const summary = document.getElementById("inventory-stock-summary");
    if (!summary) return;

    const counts = rows.reduce((acc, product) => {
        const state = invStockState(product).key;
        acc.total += 1;
        acc[state] += 1;
        return acc;
    }, { total: 0, in: 0, low: 0, out: 0 });

    summary.innerHTML = `
        <div class="inventory-summary-pill">
            <span>Visible Products</span>
            <strong>${counts.total}</strong>
        </div>
        <div class="inventory-summary-pill inventory-summary-in">
            <span>In Stock</span>
            <strong>${counts.in}</strong>
        </div>
        <div class="inventory-summary-pill inventory-summary-low">
            <span>Low Stock</span>
            <strong>${counts.low}</strong>
        </div>
        <div class="inventory-summary-pill inventory-summary-out">
            <span>Out</span>
            <strong>${counts.out}</strong>
        </div>
    `;
}

function invMovementMeta(type) {
    if (type === "restock") {
        return { label: "Stock In", className: "movement-restock", sign: "+" };
    }
    if (type === "sale") {
        return { label: "Sale", className: "movement-sale", sign: "" };
    }
    return { label: "Adjustment", className: "movement-adjustment", sign: "" };
}

function invRenderMovements() {
    const list = document.getElementById("inventory-movement-list");
    if (!list) return;

    if (invMovements.length === 0) {
        list.innerHTML = '<div class="inventory-movement-empty">No stock activity recorded yet.</div>';
        return;
    }

    list.innerHTML = invMovements.map((movement) => {
        const meta = invMovementMeta(String(movement.type || ""));
        const qty = Number(movement.qty || 0);
        const qtyText = qty > 0 && meta.sign ? `${meta.sign}${qty}` : String(qty);
        const product = movement.product || {};
        const cost = movement.total_cost !== null && movement.total_cost !== undefined
            ? `<span>Total Cost ${invEscape(invMoney(movement.total_cost))}</span>`
            : "";
        const reason = movement.reason ? `<span>${invEscape(movement.reason)}</span>` : "";

        return `
            <div class="inventory-movement-item">
                <div class="inventory-movement-type ${meta.className}">
                    <strong>${qtyText}</strong>
                    <span>${meta.label}</span>
                </div>
                <div class="inventory-movement-copy">
                    <strong>${invEscape(product.name || "Unknown product")}</strong>
                    <div class="inventory-movement-meta">
                        <span>${invEscape(product.sku || "No SKU")}</span>
                        <span>${invEscape(invFormatDateTime(movement.created_at))}</span>
                        ${reason}
                        ${cost}
                    </div>
                </div>
            </div>
        `;
    }).join("");
}

function invGetProductById(productId) {
    return invProducts.find((product) => Number(product.id) === Number(productId)) || null;
}

function invOpenProductActionModal(productId) {
    const product = invGetProductById(productId);
    if (!product) return;

    invModalProductId = Number(product.id);
    const displayName = product.variant_label ? `${product.name} (${product.variant_label})` : product.name;
    const stock = invStockState(product);
    document.getElementById("product-action-info").innerHTML = `
        <div class="product-action-main">
            <div>
                <span class="product-action-label">Selected Product</span>
                <strong>${invEscape(displayName)}</strong>
                <div class="product-action-meta">
                    <span>SKU ${invEscape(product.sku || "-")}</span>
                    <span>${invEscape(product.category || "Uncategorized")}</span>
                </div>
            </div>
            <span class="inv-stock-badge inv-stock-${stock.key}">${stock.label}</span>
        </div>
        <div class="product-action-stats">
            <div>
                <span>Current Stock</span>
                <strong>${stock.qty}</strong>
            </div>
            <div>
                <span>Price</span>
                <strong>${invEscape(invMoney(product.price))}</strong>
            </div>
            <div>
                <span>Status Note</span>
                <strong>${invEscape(stock.detail)}</strong>
            </div>
        </div>
    `;
    document.getElementById("modal-product-sku").value = product.sku || "";
    document.getElementById("modal-product-name").value = product.name || "";
    document.getElementById("modal-product-variant-label").value = product.variant_label || "";
    invEnsureCategoryOption(document.getElementById("modal-product-category"), product.category || "General");
    document.getElementById("modal-product-category").value = product.category || "";
    document.getElementById("modal-product-supplier").value = product.supplier || "";
    document.getElementById("modal-product-location").value = product.location_bin || "";
    document.getElementById("modal-product-price").value = Number(product.price || 0).toFixed(2);
    document.getElementById("modal-product-low-stock").value = String(Number(product.low_stock_threshold ?? product.reorder_level ?? 10));
    document.getElementById("modal-product-barcode").value = product.barcode || "";
    document.getElementById("modal-product-image-url").value = product.image_url || "";
    document.getElementById("modal-product-image-file").value = "";
    document.getElementById("modal-product-image-source").value = "upload";
    invToggleModalProductImageInput();
    invRenderModalProductCurrentImage(product.image_url || "");
    invUpdateModalProductImagePreview();
    document.getElementById("product-view-sku").textContent = product.sku || "-";
    document.getElementById("product-view-name").textContent = product.name || "-";
    document.getElementById("product-view-variant").textContent = product.variant_label || "-";
    document.getElementById("product-view-category").textContent = product.category || "-";
    document.getElementById("product-view-supplier").textContent = product.supplier || "-";
    document.getElementById("product-view-location").textContent = product.location_bin || "-";
    document.getElementById("product-view-barcode").textContent = product.barcode || "-";
    document.getElementById("product-view-price").textContent = invMoney(product.price);
    document.getElementById("product-view-low-stock").textContent = String(Number(product.low_stock_threshold ?? product.reorder_level ?? 10));
    document.getElementById("product-view-image").textContent = product.image_url || "Not set";
    document.getElementById("modal-actual-stock").value = Number(product.stock_qty || 0);
    document.getElementById("modal-stock-reason").value = "Physical count adjustment";
    document.getElementById("modal-restock-qty").value = "1";
    document.getElementById("modal-restock-unit-cost").value = "0";
    document.getElementById("modal-restock-sell-price").value = Number(product.price || 0).toFixed(2);
    document.getElementById("modal-restock-reason").value = "Stock in";
    invUpdateModalRestockProjection();
    invUpdateAdjustmentPreview();
    invSetProductEditMode(false);
    invSetModalPanel("adjust");
    document.getElementById("inventory-product-action-modal").style.display = "grid";
}

function invCloseProductActionModal() {
    invModalProductId = null;
    invSetProductEditMode(false);
    if (invModalPreviewObjectUrl) {
        URL.revokeObjectURL(invModalPreviewObjectUrl);
        invModalPreviewObjectUrl = null;
    }
    document.getElementById("inventory-product-action-modal").style.display = "none";
}

function invSetProductEditMode(enabled) {
    invProductEditMode = !!enabled;
    document.getElementById("product-edit-wrap").style.display = invProductEditMode ? "block" : "none";
    document.getElementById("modal-start-edit-product").style.display = invProductEditMode ? "none" : "inline-flex";
    document.getElementById("modal-cancel-edit-product").style.display = invProductEditMode ? "inline-flex" : "none";
    document.getElementById("modal-save-product").style.display = invProductEditMode ? "inline-flex" : "none";
}

function invSetModalPanel(panel) {
    invModalPanel = panel === "restock" ? "restock" : "adjust";
    const isAdjust = invModalPanel === "adjust";
    document.getElementById("modal-adjust-panel").style.display = isAdjust ? "block" : "none";
    document.getElementById("modal-restock-panel").style.display = isAdjust ? "none" : "block";
    document.getElementById("modal-panel-adjust-btn").classList.toggle("is-active", isAdjust);
    document.getElementById("modal-panel-restock-btn").classList.toggle("is-active", !isAdjust);
    document.getElementById("modal-save-adjustment").style.display = isAdjust ? "inline-flex" : "none";
    document.getElementById("modal-restock-submit").style.display = isAdjust ? "none" : "inline-flex";
}

function invUpdateModalRestockProjection() {
    const product = invGetProductById(invModalProductId);
    const qty = Number(document.getElementById("modal-restock-qty").value || 0);
    const unitCost = Number(document.getElementById("modal-restock-unit-cost").value || 0);
    const sellInput = Number(document.getElementById("modal-restock-sell-price").value || 0);
    const sellPrice = sellInput > 0 ? sellInput : Number(product?.price || 0);

    const totalCost = qty * unitCost;
    const profitPerPiece = sellPrice - unitCost;
    const expectedProfit = qty * profitPerPiece;

    document.getElementById("modal-proj-price").textContent = invMoney(sellPrice);
    document.getElementById("modal-proj-profit-piece").textContent = invMoney(profitPerPiece);
    document.getElementById("modal-proj-cost").textContent = invMoney(totalCost);
    document.getElementById("modal-proj-profit").textContent = invMoney(expectedProfit);
}

function invUpdateAdjustmentPreview() {
    const product = invGetProductById(invModalProductId);
    const currentQty = Number(product?.stock_qty || 0);
    const targetQty = Number(document.getElementById("modal-actual-stock").value || 0);
    const diff = targetQty - currentQty;
    const saveButton = document.getElementById("modal-save-adjustment");
    const diffEl = document.getElementById("modal-adjust-diff");

    document.getElementById("modal-adjust-current").textContent = String(currentQty);
    document.getElementById("modal-adjust-target").textContent = Number.isFinite(targetQty) ? String(targetQty) : "Invalid";
    diffEl.classList.remove("is-positive", "is-negative", "is-neutral");

    if (!Number.isInteger(targetQty) || targetQty < 0) {
        diffEl.textContent = "Invalid";
        diffEl.classList.add("is-negative");
        saveButton.disabled = true;
        return;
    }

    if (diff === 0) {
        diffEl.textContent = "No change";
        diffEl.classList.add("is-neutral");
        saveButton.disabled = true;
        return;
    }

    diffEl.textContent = `${diff > 0 ? "+" : ""}${diff}`;
    diffEl.classList.add(diff > 0 ? "is-positive" : "is-negative");
    saveButton.disabled = false;
}

async function invLoadStores() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();

    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }

    invStores = data.stores;
    invActiveStoreId = Number(data.default_store_id || invStores[0].id);
}

async function invLoadProducts() {
    const response = await fetch(`/store/products?store_id=${invActiveStoreId}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        invProducts = [];
        invRenderProductTable();
        throw new Error(data?.message || "Unable to load products.");
    }

    invProducts = Array.isArray(data.products) ? data.products : [];
    invRenderProductTable();
}

async function invLoadCategories() {
    const response = await fetch(`/store/categories?store_id=${invActiveStoreId}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Unable to load categories.");
    }

    invCategories = Array.isArray(data.categories) ? data.categories : [];
    invRenderCategorySelects();
}

async function invLoadMovements() {
    if (!invActiveStoreId) return;

    const movementType = encodeURIComponent(invGetElementValue("inventory-movement-type").trim());
    const typeQuery = movementType ? `&type=${movementType}` : "";
    const response = await fetch(`/store/inventory/movements?store_id=${invActiveStoreId}&limit=8${typeQuery}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        invMovements = [];
        invRenderMovements();
        throw new Error(data?.message || "Unable to load stock activity.");
    }

    invMovements = Array.isArray(data.movements) ? data.movements : [];
    invRenderMovements();
}

async function invSubmitModalRestock() {
    if (invIsRestocking) return;

    const productId = Number(invModalProductId || 0);
    const qty = Number(document.getElementById("modal-restock-qty").value || 0);
    const unitCost = Number(document.getElementById("modal-restock-unit-cost").value || 0);
    const sellPrice = Number(document.getElementById("modal-restock-sell-price").value || 0);
    const reason = (document.getElementById("modal-restock-reason").value || "Stock in").trim();
    const button = document.getElementById("modal-restock-submit");

    if (!invActiveStoreId || productId <= 0 || qty <= 0 || unitCost < 0 || sellPrice < 0) {
        invSetResult("Please complete a valid stock-in form.", "error");
        return;
    }

    const payload = {
        store_id: invActiveStoreId,
        product_id: productId,
        qty,
        unit_cost: unitCost,
        sell_price: sellPrice,
        reason,
    };

    try {
        invIsRestocking = true;
        button.disabled = true;
        button.textContent = "Submitting...";

        const response = await fetch("/store/inventory/restock", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
        const data = await response.json();

        if (!data || data.status !== "success") {
            invSetResult(data?.message || "Stock-in failed.", "error");
            return;
        }

        invSetResult(
            `Stock-in successful. Total Cost: ${invMoney(data.total_cost)} | Profit/Piece: ${invMoney(data.profit_per_piece)} | Expected Profit: ${invMoney(data.expected_profit)}`,
            "ok"
        );
        await invLoadProducts();
        await invLoadMovements();
        invOpenProductActionModal(productId);
    } catch (error) {
        invSetResult("Stock-in request failed.", "error");
    } finally {
        invIsRestocking = false;
        button.disabled = false;
        button.textContent = "Submit Stock In";
    }
}

function invToggleProductImageInput() {
    const source = document.getElementById("new-product-image-source").value;
    document.getElementById("new-product-image-upload-wrap").style.display = source === "upload" ? "flex" : "none";
    document.getElementById("new-product-image-url-wrap").style.display = source === "url" ? "flex" : "none";
    invUpdateCreateProductImagePreview();
}

function invToggleModalProductImageInput() {
    const source = document.getElementById("modal-product-image-source").value;
    document.getElementById("modal-product-image-upload-wrap").style.display = source === "upload" ? "flex" : "none";
    document.getElementById("modal-product-image-url-wrap").style.display = source === "url" ? "flex" : "none";
    invUpdateModalProductImagePreview();
}

function invRenderModalProductCurrentImage(imageUrl) {
    const img = document.getElementById("modal-product-current-image");
    const empty = document.getElementById("modal-product-current-image-empty");
    const resolved = String(imageUrl || "").trim();
    if (!img || !empty) return;

    if (resolved !== "") {
        img.src = resolved;
        img.style.display = "block";
        empty.style.display = "none";
    } else {
        img.removeAttribute("src");
        img.style.display = "none";
        empty.style.display = "block";
    }
}

function invUpdateModalProductImagePreview() {
    const source = (document.getElementById("modal-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("modal-product-image-url").value || "").trim();
    const imageFile = document.getElementById("modal-product-image-file").files[0] || null;
    const preview = document.getElementById("modal-product-new-image");
    const empty = document.getElementById("modal-product-new-image-empty");
    if (!preview || !empty) return;

    if (invModalPreviewObjectUrl) {
        URL.revokeObjectURL(invModalPreviewObjectUrl);
        invModalPreviewObjectUrl = null;
    }

    let resolved = "";
    if (source === "upload" && imageFile) {
        invModalPreviewObjectUrl = URL.createObjectURL(imageFile);
        resolved = invModalPreviewObjectUrl;
    } else if (source === "url" && imageUrl !== "") {
        resolved = imageUrl;
    }

    if (resolved !== "") {
        preview.src = resolved;
        preview.style.display = "block";
        empty.style.display = "none";
    } else {
        preview.removeAttribute("src");
        preview.style.display = "none";
        empty.style.display = "block";
    }
}

function invUpdateCreateProductProjection() {
    const sku = invGetElementValue("new-product-sku").trim();
    const name = invGetElementValue("new-product-name").trim();
    const imageSource = invGetElementValue("new-product-image-source", "upload").trim();
    const imageUrl = invGetElementValue("new-product-image-url").trim();
    const imageFile = document.getElementById("new-product-image-file").files[0] || null;
    const barcode = invGetElementValue("new-product-barcode").trim();
    const initialStock = Number(document.getElementById("new-product-initial-stock").value || 0);
    const unitCost = Number(document.getElementById("new-product-unit-cost").value || 0);
    const sellPrice = Number(document.getElementById("new-product-sell-price").value || 0);
    const lowStock = Number(document.getElementById("new-product-low-stock").value || 0);

    const markup = unitCost > 0 ? ((sellPrice - unitCost) / unitCost) * 100 : 0;
    const stockValue = Math.max(0, initialStock) * Math.max(0, unitCost);
    const skuExists = invSkuExists(sku);
    const barcodeExists = invBarcodeExists(barcode);

    document.getElementById("new-product-markup").textContent = `Markup: ${markup.toFixed(2)}%`;
    document.getElementById("new-product-stock-value").textContent = `Value: ${invMoney(stockValue)}`;
    invRenderCreateReadiness([
        {
            ok: sku !== "" && name !== "" && !skuExists,
            ready: "Product name and SKU are ready.",
            pending: skuExists ? "Use a unique SKU." : "Add product name and SKU.",
        },
        {
            ok: sellPrice >= 0 && unitCost >= 0 && initialStock >= 0 && Number.isInteger(initialStock) && lowStock >= 0 && Number.isInteger(lowStock),
            ready: "Pricing, threshold, and stock values are valid.",
            pending: "Use valid price, cost, threshold, and whole-number stock values.",
        },
        {
            ok: imageSource === "url" ? imageUrl !== "" : !!imageFile,
            ready: "Product image is selected.",
            pending: "Add an image upload or URL.",
        },
        {
            ok: !barcodeExists,
            ready: barcode === "" ? "Barcode is optional." : "Barcode is unique.",
            pending: "Use a unique barcode or leave it blank.",
        },
        {
            ok: initialStock <= 0 || invGetElementValue("new-product-reason", "Initial stock").trim() !== "",
            ready: "Initial stock reason is recorded.",
            pending: "Add an initial stock reason.",
        },
    ]);
}

function invRenderCreateReadiness(items) {
    const wrap = document.getElementById("new-product-readiness");
    const submitButton = document.getElementById("new-product-submit");
    if (!wrap) return;

    const isReady = items.every((item) => item.ok);
    if (submitButton) {
        submitButton.disabled = !isReady || invIsCreatingProduct;
    }

    wrap.innerHTML = items.map((item) => `
        <div class="readiness-item ${item.ok ? "is-ready" : "is-pending"}">
            <span>${item.ok ? "OK" : "!"}</span>
            <strong>${invEscape(item.ok ? item.ready : item.pending)}</strong>
        </div>
    `).join("");
}

function invUpdateCreateProductImagePreview() {
    const source = (document.getElementById("new-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("new-product-image-url").value || "").trim();
    const imageFile = document.getElementById("new-product-image-file").files[0] || null;
    const preview = document.getElementById("new-product-image-preview");
    const empty = document.getElementById("new-product-image-preview-empty");

    if (invCreatePreviewObjectUrl) {
        URL.revokeObjectURL(invCreatePreviewObjectUrl);
        invCreatePreviewObjectUrl = null;
    }

    let resolved = "";
    if (source === "upload" && imageFile) {
        invCreatePreviewObjectUrl = URL.createObjectURL(imageFile);
        resolved = invCreatePreviewObjectUrl;
    } else if (source === "url" && imageUrl !== "") {
        resolved = imageUrl;
    }

    if (resolved !== "") {
        preview.src = resolved;
        preview.style.display = "block";
        empty.style.display = "none";
    } else {
        preview.removeAttribute("src");
        preview.style.display = "none";
        empty.style.display = "block";
    }
    invUpdateCreateProductProjection();
}

function invCaptureCreateFormState() {
    const fileInput = document.getElementById("new-product-image-file");
    const imageFile = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

    return {
        sku: invGetElementValue("new-product-sku").trim(),
        name: invGetElementValue("new-product-name").trim(),
        variantLabel: invGetElementValue("new-product-variant-label").trim(),
        category: invGetElementValue("new-product-category", "General").trim(),
        supplier: invGetElementValue("new-product-supplier").trim(),
        barcode: invGetElementValue("new-product-barcode").trim(),
        imageSource: invGetElementValue("new-product-image-source", "upload").trim(),
        imageUrl: invGetElementValue("new-product-image-url").trim(),
        sellPrice: invGetElementValue("new-product-sell-price", "0").trim(),
        initialStock: invGetElementValue("new-product-initial-stock", "0").trim(),
        unitCost: invGetElementValue("new-product-unit-cost", "0").trim(),
        location: invGetElementValue("new-product-location").trim(),
        lowStock: invGetElementValue("new-product-low-stock", "0").trim(),
        reason: invGetElementValue("new-product-reason", "Initial stock").trim(),
        imageFileName: imageFile ? imageFile.name : "",
        imageFileSize: imageFile ? Number(imageFile.size || 0) : 0,
    };
}

function invIsCreateFormDirty() {
    if (!invCreateSnapshot) return false;
    const current = invCaptureCreateFormState();
    return JSON.stringify(current) !== JSON.stringify(invCreateSnapshot);
}

function invRequestCloseProductModal() {
    if (invIsCreatingProduct) return;

    if (invIsCreateFormDirty()) {
        const confirmed = window.confirm("Discard unsaved product changes?");
        if (!confirmed) return;
    }

    invCloseProductModal();
}

async function invCreateProduct() {
    if (invIsCreatingProduct) return;

    const sku = (document.getElementById("new-product-sku").value || "").trim();
    const name = (document.getElementById("new-product-name").value || "").trim();
    const variantLabel = (document.getElementById("new-product-variant-label").value || "").trim();
    const category = (document.getElementById("new-product-category").value || "General").trim() || "General";
    const supplier = (document.getElementById("new-product-supplier").value || "").trim();
    const barcode = (document.getElementById("new-product-barcode").value || "").trim();
    const imageSource = (document.getElementById("new-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("new-product-image-url").value || "").trim();
    const imageFile = document.getElementById("new-product-image-file").files[0] || null;
    const sellPrice = Number(document.getElementById("new-product-sell-price").value || 0);
    const initialStock = Number(document.getElementById("new-product-initial-stock").value || 0);
    const unitCost = Number(document.getElementById("new-product-unit-cost").value || 0);
    const lowStock = Number(document.getElementById("new-product-low-stock").value || 10);
    const locationBin = (document.getElementById("new-product-location").value || "").trim();
    const reason = (invGetElementValue("new-product-reason", "Initial stock") || "Initial stock").trim();
    const button = document.getElementById("new-product-submit");

    if (!invActiveStoreId || !sku || !name || sellPrice < 0 || initialStock < 0 || unitCost < 0 || lowStock < 0) {
        invSetResult("Please complete valid product details.", "error");
        return;
    }
    if (imageSource === "url" && imageUrl === "") {
        invSetResult("Please provide an image URL or switch to upload.", "error");
        return;
    }
    if (imageSource === "upload" && !imageFile) {
        invSetResult("Please upload an image file or switch to URL.", "error");
        return;
    }

    try {
        invIsCreatingProduct = true;
        button.disabled = true;
        button.textContent = "Creating...";

        const formData = new FormData();
        formData.append("store_id", String(invActiveStoreId));
        formData.append("sku", sku);
        formData.append("name", name);
        formData.append("variant_label", variantLabel);
        formData.append("category", category);
        formData.append("supplier", supplier);
        formData.append("barcode", barcode);
        formData.append("sell_price", String(sellPrice));
        formData.append("initial_stock", String(initialStock));
        formData.append("unit_cost", String(unitCost));
        formData.append("low_stock_threshold", String(lowStock));
        formData.append("location_bin", locationBin);
        formData.append("reason", reason);
        if (imageSource === "url") {
            formData.append("image_url", imageUrl);
        } else if (imageFile) {
            formData.append("image_file", imageFile);
        }

        const response = await fetch("/store/inventory/add-product", {
            method: "POST",
            body: formData,
        });
        const data = await response.json();

        if (!data || data.status !== "success") {
            invSetResult(data?.message || "Product creation failed.", "error");
            return;
        }

        invSetResult(`Product created: ${data.product?.name || name}`, "ok");
        invCloseProductModal();
        await invLoadCategories();
        await invLoadProducts();
        await invLoadMovements();
    } catch (error) {
        invSetResult("Product creation request failed.", "error");
    } finally {
        invIsCreatingProduct = false;
        button.textContent = "Create Product";
        invUpdateCreateProductProjection();
    }
}

async function invAdjustStock(productId) {
    const actualQty = Number(document.getElementById("modal-actual-stock").value || 0);
    const reason = (document.getElementById("modal-stock-reason").value || "Physical count adjustment").trim();
    const button = document.getElementById("modal-save-adjustment");

    if (!Number.isInteger(actualQty) || actualQty < 0) {
        invSetResult("Actual stock must be 0 or higher.", "error");
        return;
    }
    const product = invGetProductById(productId);
    if (product && actualQty === Number(product.stock_qty || 0)) {
        invSetResult("No stock change needed for this product.", "ok");
        invUpdateAdjustmentPreview();
        return;
    }

    button.disabled = true;
    button.textContent = "Saving...";

    try {
        const response = await fetch("/store/inventory/adjust-stock", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                store_id: invActiveStoreId,
                product_id: Number(productId),
                actual_qty: actualQty,
                reason,
            }),
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            invSetResult(data?.message || "Stock adjustment failed.", "error");
            return;
        }

        const diff = Number(data.diff_qty || 0);
        if (diff === 0) {
            invSetResult("No stock change needed for this product.", "ok");
        } else {
            invSetResult(`Stock updated. Previous: ${data.previous_qty}, New: ${data.actual_qty}, Difference: ${diff > 0 ? "+" : ""}${diff}`, "ok");
        }
        invCloseProductActionModal();
        await invLoadProducts();
        await invLoadMovements();
    } catch (error) {
        invSetResult("Stock adjustment request failed.", "error");
    } finally {
        button.disabled = false;
        button.textContent = "Save Adjustment";
    }
}

async function invUpdateProduct(productId) {
    if (invIsUpdatingProduct) return;

    const sku = (document.getElementById("modal-product-sku").value || "").trim();
    const name = (document.getElementById("modal-product-name").value || "").trim();
    const variantLabel = (document.getElementById("modal-product-variant-label").value || "").trim();
    const category = (document.getElementById("modal-product-category").value || "General").trim() || "General";
    const supplier = (document.getElementById("modal-product-supplier").value || "").trim();
    const barcode = (document.getElementById("modal-product-barcode").value || "").trim();
    const sellPrice = Number(document.getElementById("modal-product-price").value || 0);
    const lowStock = Number(document.getElementById("modal-product-low-stock").value || 10);
    const locationBin = (document.getElementById("modal-product-location").value || "").trim();
    const imageSource = (document.getElementById("modal-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("modal-product-image-url").value || "").trim();
    const imageFile = document.getElementById("modal-product-image-file").files[0] || null;
    const button = document.getElementById("modal-save-product");

    if (!invActiveStoreId || !productId || !sku || !name || sellPrice < 0 || lowStock < 0) {
        invSetResult("Please complete valid product details.", "error");
        return;
    }
    if (invSkuExists(sku, productId)) {
        invSetResult("SKU already exists in this store. Use a unique SKU before saving.", "error");
        document.getElementById("modal-product-sku").focus();
        return;
    }
    if (barcode !== "" && invBarcodeExists(barcode, productId)) {
        invSetResult("Barcode already exists in this store. Use a unique barcode or leave it blank.", "error");
        document.getElementById("modal-product-barcode").focus();
        return;
    }
    if (imageSource === "upload" && imageFile && imageFile.size <= 0) {
        invSetResult("Selected product image file is invalid.", "error");
        return;
    }

    try {
        invIsUpdatingProduct = true;
        button.disabled = true;
        button.textContent = "Saving...";

        const formData = new FormData();
        formData.append("store_id", String(invActiveStoreId));
        formData.append("product_id", String(productId));
        formData.append("sku", sku);
        formData.append("name", name);
        formData.append("variant_label", variantLabel);
        formData.append("category", category);
        formData.append("supplier", supplier);
        formData.append("barcode", barcode);
        formData.append("sell_price", String(sellPrice));
        formData.append("low_stock_threshold", String(lowStock));
        formData.append("location_bin", locationBin);
        if (imageSource === "url" && imageUrl !== "") {
            formData.append("image_url", imageUrl);
        } else if (imageSource === "upload" && imageFile) {
            formData.append("image_file", imageFile);
        }

        const response = await fetch("/store/inventory/update-product", {
            method: "POST",
            body: formData,
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            invSetResult(data?.message || "Product update failed.", "error");
            return;
        }

        invSetResult(`Product updated: ${data.product?.name || name}`, "ok");
        await invLoadCategories();
        await invLoadProducts();
        const refreshed = invGetProductById(productId);
        if (refreshed) {
            invOpenProductActionModal(productId);
        }
    } catch (error) {
        invSetResult("Product update request failed.", "error");
    } finally {
        invIsUpdatingProduct = false;
        button.disabled = false;
        button.textContent = "Save Details";
    }
}

async function invOpenProductModal() {
    await invLoadCategories();
    document.getElementById("new-product-sku").value = "";
    document.getElementById("new-product-name").value = "";
    document.getElementById("new-product-variant-label").value = "";
    document.getElementById("new-product-category").value = "General";
    document.getElementById("new-product-supplier").value = "";
    document.getElementById("new-product-barcode").value = "";
    document.getElementById("new-product-image-source").value = "upload";
    document.getElementById("new-product-image-file").value = "";
    document.getElementById("new-product-image-url").value = "";
    document.getElementById("new-product-sell-price").value = "0";
    document.getElementById("new-product-initial-stock").value = "0";
    document.getElementById("new-product-unit-cost").value = "0";
    document.getElementById("new-product-location").value = "";
    document.getElementById("new-product-low-stock").value = "0";
    document.getElementById("new-product-reason").value = "Initial stock";
    invToggleProductImageInput();
    invUpdateCreateProductProjection();
    document.getElementById("inventory-product-modal").style.display = "grid";
    invCreateSnapshot = invCaptureCreateFormState();
}

function invCloseProductModal() {
    if (invCreatePreviewObjectUrl) {
        URL.revokeObjectURL(invCreatePreviewObjectUrl);
        invCreatePreviewObjectUrl = null;
    }
    document.getElementById("inventory-product-modal").style.display = "none";
    invCreateSnapshot = null;
}

document.getElementById("inventory-search").addEventListener("input", invRenderProductTable);
document.getElementById("inventory-category-filter").addEventListener("change", invRenderProductTable);
document.getElementById("inventory-stock-filter").addEventListener("change", invRenderProductTable);
document.getElementById("inventory-clear-filters").addEventListener("click", () => {
    document.getElementById("inventory-search").value = "";
    document.getElementById("inventory-category-filter").value = "";
    document.getElementById("inventory-stock-filter").value = "";
    invRenderProductTable();
});
document.getElementById("inventory-movement-type").addEventListener("change", () => {
    invLoadMovements().catch((error) => invSetResult(error.message || "Unable to filter stock activity.", "error"));
});
document.getElementById("refresh-inventory-movements").addEventListener("click", () => {
    invLoadMovements().catch((error) => invSetResult(error.message || "Unable to refresh stock activity.", "error"));
});

document.getElementById("open-product-modal-top").addEventListener("click", () => {
    invOpenProductModal().catch((error) => invSetResult(error.message || "Unable to open product modal.", "error"));
});
document.getElementById("close-product-modal").addEventListener("click", invRequestCloseProductModal);
document.getElementById("new-product-cancel").addEventListener("click", invRequestCloseProductModal);
document.getElementById("close-product-action-modal").addEventListener("click", invCloseProductActionModal);
document.getElementById("new-product-sku").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-name").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-barcode").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-image-source").addEventListener("change", invToggleProductImageInput);
document.getElementById("new-product-image-file").addEventListener("change", invUpdateCreateProductImagePreview);
document.getElementById("new-product-image-url").addEventListener("input", invUpdateCreateProductImagePreview);
document.getElementById("new-product-unit-cost").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-sell-price").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-initial-stock").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-low-stock").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-reason").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("modal-product-image-source").addEventListener("change", invToggleModalProductImageInput);
document.getElementById("modal-product-image-file").addEventListener("change", invUpdateModalProductImagePreview);
document.getElementById("modal-product-image-url").addEventListener("input", invUpdateModalProductImagePreview);
document.getElementById("modal-start-edit-product").addEventListener("click", () => invSetProductEditMode(true));
document.getElementById("modal-cancel-edit-product").addEventListener("click", () => invSetProductEditMode(false));
document.getElementById("modal-panel-adjust-btn").addEventListener("click", () => invSetModalPanel("adjust"));
document.getElementById("modal-panel-restock-btn").addEventListener("click", () => invSetModalPanel("restock"));

document.getElementById("inventory-product-modal").addEventListener("click", (event) => {
    if (event.target.id === "inventory-product-modal") {
        invRequestCloseProductModal();
    }
});

document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    const modal = document.getElementById("inventory-product-modal");
    if (!modal || modal.style.display !== "grid") return;
    event.preventDefault();
    invRequestCloseProductModal();
});

document.getElementById("inventory-product-action-modal").addEventListener("click", (event) => {
    if (event.target.id === "inventory-product-action-modal") {
        invCloseProductActionModal();
    }
});

document.getElementById("inventory-product-body").addEventListener("click", (event) => {
    const action = event.target.closest("[data-product-action]");
    if (!action) return;
    invOpenProductActionModal(action.getAttribute("data-product-action"));
});

document.getElementById("modal-restock-qty").addEventListener("input", invUpdateModalRestockProjection);
document.getElementById("modal-restock-unit-cost").addEventListener("input", invUpdateModalRestockProjection);
document.getElementById("modal-restock-sell-price").addEventListener("input", invUpdateModalRestockProjection);
document.getElementById("modal-actual-stock").addEventListener("input", invUpdateAdjustmentPreview);
document.getElementById("modal-restock-submit").addEventListener("click", invSubmitModalRestock);
document.getElementById("new-product-submit").addEventListener("click", invCreateProduct);
document.getElementById("modal-save-adjustment").addEventListener("click", async () => {
    if (!invModalProductId) return;
    await invAdjustStock(invModalProductId);
});
document.getElementById("modal-save-product").addEventListener("click", async () => {
    if (!invModalProductId) return;
    await invUpdateProduct(invModalProductId);
});

(async () => {
    try {
        await invLoadStores();
        await invLoadCategories();
        await invLoadProducts();
        await invLoadMovements();
        invToggleProductImageInput();
        invUpdateCreateProductProjection();
    } catch (error) {
        invSetResult(error.message || "Unable to initialize inventory page.", "error");
    }
})();
