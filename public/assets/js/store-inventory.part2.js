function invGetProductById(productId) {
    return invProducts.find((product) => Number(product.id) === Number(productId)) || null;
}
function invOpenProductActionModal(productId) {
    if (invStockSavePending || invIsUpdatingProduct || invIsCreatingProduct) return;
    const product = invGetProductById(productId);
    if (!product) return;

    const modal = document.getElementById("inventory-product-action-modal");
    if (modal.style.display !== "grid") invProductActionOpener = document.activeElement;
    const version = ++invProductActionVersion;
    invSetStockResult("");
    modal.querySelector('.product-actions-body').scrollTop = 0;

    invModalProductId = Number(product.id);
    const displayName = product.variant_label ? `${product.name} (${product.variant_label})` : product.name;
    const stock = invStockState(product);
    const productThumb = product.image_url
        ? `<img src="${invEscape(product.image_url)}" alt="${invEscape(displayName)}" class="product-action-thumb">`
        : `<div class="product-action-thumb product-action-thumb-fallback" role="img" aria-label="No product image">${invEscape(invInitials(product.name))}</div>`;
    document.getElementById("product-action-info").innerHTML = `
        <div class="product-action-main">
            <div class="product-action-identity">
                ${productThumb}
                <div>
                    <span class="product-action-label">Selected Product</span>
                    <strong>${invEscape(displayName)}</strong>
                    <div class="product-action-meta">
                        <span>SKU ${invEscape(product.sku || "-")}</span>
                        <span>${invEscape(product.category || "Uncategorized")}</span>
                    </div>
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
                <span>POS Availability</span>
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
    invSyncBehaviorFields('modal-product');
    document.getElementById("modal-product-item-type").value = product.item_type || "stock_item";
    document.getElementById("modal-product-stock-policy").value = product.stock_policy || "tracked";
    document.getElementById("modal-product-unit-code").value = product.unit_code || "piece";
    document.getElementById("modal-product-barcode").value = product.barcode || "";
    document.getElementById("modal-product-image-url").value = product.image_url || "";
    document.getElementById("modal-product-image-file").value = "";
    document.getElementById("modal-product-image-source").value = "upload";
    invToggleModalProductImageInput();
    invRenderModalProductCurrentImage(product.image_url || "");
    invUpdateModalProductImagePreview();
    document.getElementById("product-view-variant").textContent = product.variant_label || "-";
    document.getElementById("product-view-supplier").textContent = product.supplier || "-";
    document.getElementById("product-view-location").textContent = product.location_bin || "-";
    document.getElementById("product-view-barcode").textContent = product.barcode || "-";
    document.getElementById("product-view-low-stock").textContent = String(Number(product.low_stock_threshold ?? product.reorder_level ?? 10));
    document.getElementById("product-view-item-type").textContent = invBehaviorLabel(product.item_type || "stock_item");
    document.getElementById("product-view-stock-policy").textContent = invBehaviorLabel(product.stock_policy || "tracked");
    document.getElementById("product-view-unit-code").textContent = String(product.unit_code || "piece").replaceAll("_", " ");
    document.getElementById("modal-actual-stock").value = Number(product.stock_qty || 0);
    document.getElementById("modal-stock-reason").value = "Physical count adjustment";
    document.getElementById("modal-stock-waste-disposition").value = "";
    document.getElementById("modal-restock-qty").value = "1";
    document.getElementById("modal-restock-unit-cost").value = "0";
    document.getElementById("modal-restock-sell-price").value = Number(product.price || 0).toFixed(2);
    document.getElementById("modal-restock-reason").value = "Stock in";
    invUpdateModalRestockProjection();
    invUpdateAdjustmentPreview();
    document.querySelectorAll('#inventory-product-action-modal select').forEach((select) => {
        select.dispatchEvent(new Event('change', {bubbles: true}));
    });
    invSetProductEditMode(false);
    invSetModalPanel("adjust");
    document.getElementById("inventory-product-action-modal").style.display = "grid";
    invSyncModalBodyLock();
    window.requestAnimationFrame(() => {
        if (version === invProductActionVersion && modal.isConnected && modal.style.display === "grid" && !invProductEditMode) {
            document.getElementById("close-product-action-modal")?.focus();
        }
    });
}

function invCloseProductActionModal(force = false) {
    if ((invStockSavePending || invIsUpdatingProduct) && force !== true) return;
    const modal = document.getElementById("inventory-product-action-modal");
    if (modal.style.display !== "grid") return;
    ++invProductActionVersion;
    modal.style.display = "none";
    invModalProductId = null;
    invSetProductEditMode(false, true);
    if (invModalPreviewObjectUrl) {
        URL.revokeObjectURL(invModalPreviewObjectUrl);
        invModalPreviewObjectUrl = null;
    }
    document.getElementById("inventory-product-action-modal").style.display = "none";
    invSyncModalBodyLock();
    if (invProductActionOpener?.isConnected) invProductActionOpener.focus();
    invProductActionOpener = null;
}

function invSetProductEditMode(enabled, force = false) {
    if ((invStockSavePending || invIsUpdatingProduct) && force !== true) return;
    if (invProductEditMode !== !!enabled) invSetStockResult("");
    invProductEditMode = !!enabled;
    document.getElementById("product-edit-wrap").style.display = invProductEditMode ? "block" : "none";
    document.getElementById("modal-start-edit-product").style.display = invProductEditMode ? "none" : "inline-flex";
    document.getElementById("modal-cancel-edit-product").style.display = invProductEditMode ? "inline-flex" : "none";
    document.getElementById("modal-save-product").style.display = invProductEditMode ? "inline-flex" : "none";
    invSyncProductStockPanels();
    if (document.getElementById("inventory-product-action-modal").style.display === "grid") {
        document.getElementById(invProductEditMode ? "modal-product-sku" : "modal-start-edit-product")?.focus();
    }
}

function invSetModalPanel(panel) {
    if (invStockSavePending || invIsUpdatingProduct) return;
    if (invModalPanel !== panel) invSetStockResult("");
    invModalPanel = panel === "restock" ? "restock" : "adjust";
    const isAdjust = invModalPanel === "adjust";
    document.getElementById("modal-panel-adjust-btn").classList.toggle("is-active", isAdjust);
    document.getElementById("modal-panel-restock-btn").classList.toggle("is-active", !isAdjust);
    document.getElementById("modal-panel-adjust-btn").setAttribute("aria-selected", isAdjust ? "true" : "false");
    document.getElementById("modal-panel-restock-btn").setAttribute("aria-selected", isAdjust ? "false" : "true");
    invSyncProductStockPanels();
}

function invSyncProductStockPanels() {
    const product = invGetProductById(invModalProductId);
    const showStock = !invProductEditMode && String(product?.stock_policy || "tracked") === "tracked";
    const tabs = document.querySelector("#inventory-product-action-modal .modal-action-tabs");
    if (tabs) tabs.style.display = showStock ? "flex" : "none";
    for (const panel of ["adjust", "restock"]) {
        const visible = showStock && invModalPanel === panel;
        const element = document.getElementById(`modal-${panel}-panel`);
        element.style.display = visible ? "block" : "none";
        element.setAttribute("aria-hidden", visible ? "false" : "true");
        document.getElementById(panel === "adjust" ? "modal-save-adjustment" : "modal-restock-submit").style.display = visible ? "inline-flex" : "none";
    }
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
    const hintEl = document.getElementById("modal-adjust-hint");

    document.getElementById("modal-adjust-current").textContent = String(currentQty);
    document.getElementById("modal-adjust-target").textContent = Number.isFinite(targetQty) ? String(targetQty) : "Invalid";
    diffEl.classList.remove("is-positive", "is-negative", "is-neutral");

    if (!document.getElementById("modal-actual-stock").value.trim() || !Number.isInteger(targetQty) || targetQty < 0) {
        diffEl.textContent = "Invalid";
        diffEl.classList.add("is-negative");
        saveButton.disabled = true;
        saveButton.title = "Enter a valid whole-number stock count.";
        if (hintEl) hintEl.textContent = "Enter a valid whole-number stock count to continue.";
        return;
    }

    if (diff === 0) {
        diffEl.textContent = "No change";
        diffEl.classList.add("is-neutral");
        saveButton.disabled = true;
        saveButton.title = "No stock change to save.";
        if (hintEl) hintEl.textContent = "No change detected. Enter a different stock count to enable saving.";
        return;
    }

    diffEl.textContent = `${diff > 0 ? "+" : ""}${diff}`;
    diffEl.classList.add(diff > 0 ? "is-positive" : "is-negative");
    saveButton.disabled = invStockSavePending || invIsUpdatingProduct;
    saveButton.removeAttribute("title");
    if (hintEl) hintEl.textContent = `This will record a ${diff > 0 ? "+" : ""}${diff} stock adjustment.`;
}

async function invLoadStores() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();

    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }

    invStores = data.stores;
    invActiveStoreId = window.IbemsStoreScope.resolve(invStores, data.default_store_id);
    document.querySelectorAll('.inventory-top-actions a[href="/store/settings"]').forEach(link => { link.href = `/store/settings?store_id=${invActiveStoreId}`; });
}

function invSetProductLoadState(state) {
    invProductLoadState = state;
    const button = document.getElementById("refresh-inventory-products");
    if (button) {
        button.dataset.state = state;
        button.disabled = state === "loading" || !invActiveStoreId;
        button.textContent = state === "loading" ? "Refreshing products…" : state === "error" ? "Retry products" : "Refresh products";
    }
    document.getElementById("inventory-products-region")?.setAttribute("aria-busy", state === "loading" ? "true" : "false");
}

async function invRefreshProducts() {
    if (invProductLoadState === "loading" || !invActiveStoreId) return;
    try {return await invLoadProducts();}
    catch (error) {return false;}
}

async function invLoadProducts() {
    const sequence = ++invProductSequence;
    const storeId = invActiveStoreId;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const body = document.getElementById("inventory-product-body");
    const summary = document.getElementById("inventory-stock-summary");
    const current = () => sequence === invProductSequence && storeId === invActiveStoreId && !signal?.aborted && body?.isConnected;
    if (!current()) return false;
    invSetProductLoadState("loading");
    invProducts = [];
    document.getElementById("inventory-pager").innerHTML = "";
    document.getElementById("inventory-results-count").textContent = "Loading products…";
    document.getElementById("inventory-toggle-families").classList.add("is-hidden");
    body.innerHTML = invDataState("loading", "Loading products...", 5);
    summary.innerHTML = invDataState("loading", "Loading stock summary...");

    let data;
    try {
        const response = await fetch(`/store/products?store_id=${storeId}`, {signal});
        data = await response.json();
        if (!current()) return false;
        if (!response.ok) throw new Error(data?.message || "Unable to load products.");
    } catch (error) {
        if (!current()) return false;
        const message = error?.message || "Unable to load products.";
        body.innerHTML = invDataState("error", message, 5);
        summary.innerHTML = invDataState("error", message);
        invSetProductLoadState("error");
        document.getElementById("inventory-results-count").textContent = "Products unavailable. Use Retry products to try again.";
        throw new Error(message);
    }

    if (!data || data.status !== "success" || !Array.isArray(data.products)) {
        invProducts = [];
        const message = data?.message || "Unable to load products.";
        body.innerHTML = invDataState("error", message, 5);
        summary.innerHTML = invDataState("error", message);
        invSetProductLoadState("error");
        document.getElementById("inventory-results-count").textContent = "Products unavailable. Use Retry products to try again.";
        throw new Error(message);
    }

    invProducts = Array.isArray(data.products) ? data.products : [];
    invAllowedItemTypes = Array.isArray(data.allowed_item_types) ? data.allowed_item_types : [];
    invSetProductLoadState("ready");
    invRenderProductTable();
    return true;
}

async function invLoadCategories() {
    const sequence = ++invCategorySequence;
    const storeId = invActiveStoreId;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const current = () => sequence === invCategorySequence && storeId === invActiveStoreId && !signal?.aborted;
    let data;
    try {
        const response = await fetch(`/store/categories?store_id=${storeId}`, {signal});
        data = await response.json();
        if (!current()) return false;
        if (!response.ok) throw new Error(data?.message || "Unable to load categories.");
    } catch (error) {
        if (!current()) return false;
        throw error;
    }

    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Unable to load categories.");
    }

    invCategories = Array.isArray(data.categories) ? data.categories : [];
    invRenderCategorySelects();
    return true;
}

async function invLoadMovements() {
    if (!invActiveStoreId) return;
    const sequence = ++invMovementSequence;
    const storeId = invActiveStoreId;
    const signal = window.IbemsPortalNavigation?.currentSignal;

    const movementType = encodeURIComponent(invGetElementValue("inventory-movement-type").trim());
    const typeQuery = movementType ? `&type=${movementType}` : "";
    const list = document.getElementById("inventory-movement-list");
    const current = () => sequence === invMovementSequence && storeId === invActiveStoreId && !signal?.aborted && list?.isConnected;
    if (!current()) return false;
    list.innerHTML = invDataState("loading", "Loading stock activity...");

    let data;
    try {
        const response = await fetch(`/store/inventory/movements?store_id=${storeId}&limit=8${typeQuery}`, {signal});
        data = await response.json();
        if (!current()) return false;
        if (!response.ok) throw new Error(data?.message || "Unable to load stock activity.");
    } catch (error) {
        if (!current()) return false;
        invMovements = [];
        const message = error?.message || "Unable to load stock activity.";
        list.innerHTML = invDataState("error", message);
        throw new Error(message);
    }

    if (!data || data.status !== "success") {
        invMovements = [];
        const message = data?.message || "Unable to load stock activity.";
        list.innerHTML = invDataState("error", message);
        throw new Error(message);
    }

    invMovements = Array.isArray(data.movements) ? data.movements : [];
    invRenderMovements();
    return true;
}

function invSetStockResult(message, type = "progress", focus = false) {
    const result = document.getElementById("modal-stock-result");
    result.textContent = message;
    result.hidden = !message;
    result.dataset.type = type;
    if (focus && message) result.focus();
}

async function invSaveStock(action, payload, successMessage) {
    if (invStockSavePending || invIsUpdatingProduct || invIsCreatingProduct) return;
    const modal = document.getElementById("inventory-product-action-modal");
    const version = invProductActionVersion;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const current = () => !signal?.aborted && modal.isConnected &&
        modal === document.getElementById("inventory-product-action-modal") &&
        version === invProductActionVersion && modal.style.display === "grid" &&
        Number(invActiveStoreId) === Number(payload.store_id) && Number(invModalProductId) === payload.product_id;
    if (!current()) return;
    const button = document.getElementById(action === "restock" ? "modal-restock-submit" : "modal-save-adjustment");
    const originalLabel = button.textContent;
    const controls = Array.from(modal.querySelectorAll('button, input, select, textarea'))
        .filter(control => control.id !== "close-product-action-modal")
        .map(control => [control, control.disabled]);
    invStockSavePending = true;
    invIsRestocking = action === "restock";
    modal.setAttribute("aria-busy", "true");
    controls.forEach(([control]) => { control.disabled = true; });
    button.textContent = "Saving…";
    invSetStockResult(action === "restock" ? "Recording stock in…" : "Saving stock adjustment…");
    document.getElementById("close-product-action-modal").focus();
    let confirmed = false;
    let closed = false;
    try {
        const response = await fetch(`/store/inventory/${action === "restock" ? "restock" : "adjust-stock"}`, {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (!current()) return;
        if (!response.ok || data?.status !== "success") {
            invSetStockResult(data?.message || "Stock could not be saved. Review the form and try again.", "error", true);
            return;
        }
        confirmed = true;
        const message = successMessage(data);
        invSetStockResult("Stock saved. Refreshing inventory…");
        let refreshed = true;
        // Reads can fail after a confirmed write. Never describe that as a failed save.
        for (const reload of [invLoadProducts, invLoadMovements]) {
            try { if (await reload() === false) refreshed = false; }
            catch (error) { refreshed = false; }
            if (!current()) return;
        }
        invCloseProductActionModal(true);
        closed = true;
        invSetResult(refreshed ? message : `${message} Inventory could not fully refresh. Reload this page before making another stock change.`, refreshed ? "ok" : "warning", true);
    } catch (error) {
        if (current()) invSetStockResult(confirmed ? "Stock saved. Reload this page to see the latest inventory." : "The stock request could not be confirmed. Check inventory before trying again.", confirmed ? "warning" : "error", true);
    } finally {
        invStockSavePending = false;
        invIsRestocking = false;
        if (modal.isConnected && modal === document.getElementById("inventory-product-action-modal") &&
            (version === invProductActionVersion || (closed && version + 1 === invProductActionVersion))) {
            modal.setAttribute("aria-busy", "false");
            controls.forEach(([control, disabled]) => { control.disabled = disabled; });
            button.textContent = originalLabel;
            invUpdateAdjustmentPreview();
        }
    }
}

async function invSubmitModalRestock() {
    if (invStockSavePending || invIsUpdatingProduct || invIsCreatingProduct) return;

    const productId = Number(invModalProductId || 0);
    const qty = Number(document.getElementById("modal-restock-qty").value || 0);
    const unitCost = Number(document.getElementById("modal-restock-unit-cost").value || 0);
    const sellPrice = Number(document.getElementById("modal-restock-sell-price").value || 0);
    const reason = (document.getElementById("modal-restock-reason").value || "").trim();

    if (!invActiveStoreId || !invGetProductById(productId) || productId <= 0 || !Number.isInteger(qty) || qty <= 0 || !Number.isFinite(unitCost) || !Number.isFinite(sellPrice) || unitCost < 0 || sellPrice < 0 || reason === "") {
        invSetStockResult("Please complete a valid stock-in form.", "error", true);
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

    await invSaveStock("restock", payload, data =>
        `Stock-in successful. Total Cost: ${invMoney(data.total_cost)} | Profit/Piece: ${invMoney(data.profit_per_piece)} | Expected Profit: ${invMoney(data.expected_profit)}`);
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
    const variants = invAdditionalVariants();
    const skus = [sku, ...variants.map(variant => variant.sku)].map(value => value.toLowerCase());
    const barcodes = [barcode, ...variants.map(variant => variant.barcode)].filter(Boolean).map(value => value.toLowerCase());

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
            ok: invProductNumbersValid(sellPrice, unitCost, initialStock, lowStock),
            ready: "Pricing, threshold, and stock values are valid.",
            pending: "Use valid price, cost, threshold, and whole-number stock values.",
        },
        {
            ok: imageSource === "url" ? imageUrl !== "" : !!imageFile && imageFile.size > 0,
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
        {
            ok: variants.every(invVariantIsValid) && new Set(skus).size === skus.length &&
                new Set(barcodes).size === barcodes.length && !variants.some(variant => invSkuExists(variant.sku) || (variant.barcode && invBarcodeExists(variant.barcode))),
            ready: "Additional variants are ready.",
            pending: "Complete each variant with a unique SKU/barcode, valid pricing, and whole-number stock/threshold.",
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
        imageMode: invGetElementValue("new-product-image-mode", "shared"),
        variants: invAdditionalVariants().map(({row, image, ...variant}) => ({...variant, imageName:image?.name || "", imageSize:Number(image?.size || 0)})),
    };
}
