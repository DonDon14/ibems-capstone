function invGetProductById(productId) {
    return invProducts.find((product) => Number(product.id) === Number(productId)) || null;
}
function invOpenProductActionModal(productId) {
    const product = invGetProductById(productId);
    if (!product) return;

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
    document.getElementById("modal-restock-qty").value = "1";
    document.getElementById("modal-restock-unit-cost").value = "0";
    document.getElementById("modal-restock-sell-price").value = Number(product.price || 0).toFixed(2);
    document.getElementById("modal-restock-reason").value = "Stock in";
    invUpdateModalRestockProjection();
    invUpdateAdjustmentPreview();
    invSetProductEditMode(false);
    invSetModalPanel("adjust");
    const stockControls = document.querySelector("#inventory-product-action-modal .modal-action-tabs");
    const tracksStock = String(product.stock_policy || "tracked") === "tracked";
    if (stockControls) stockControls.style.display = tracksStock ? "flex" : "none";
    document.getElementById("modal-adjust-panel").style.display = tracksStock ? "block" : "none";
    document.getElementById("modal-save-adjustment").style.display = tracksStock ? "inline-flex" : "none";
    document.getElementById("inventory-product-action-modal").style.display = "grid";
    invSyncModalBodyLock();
    window.requestAnimationFrame(() => document.getElementById("close-product-action-modal")?.focus());
}

function invCloseProductActionModal() {
    invModalProductId = null;
    invSetProductEditMode(false);
    if (invModalPreviewObjectUrl) {
        URL.revokeObjectURL(invModalPreviewObjectUrl);
        invModalPreviewObjectUrl = null;
    }
    document.getElementById("inventory-product-action-modal").style.display = "none";
    invSyncModalBodyLock();
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
    document.getElementById("modal-panel-adjust-btn").setAttribute("aria-selected", isAdjust ? "true" : "false");
    document.getElementById("modal-panel-restock-btn").setAttribute("aria-selected", isAdjust ? "false" : "true");
    document.getElementById("modal-adjust-panel").setAttribute("aria-hidden", isAdjust ? "false" : "true");
    document.getElementById("modal-restock-panel").setAttribute("aria-hidden", isAdjust ? "true" : "false");
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
    const hintEl = document.getElementById("modal-adjust-hint");

    document.getElementById("modal-adjust-current").textContent = String(currentQty);
    document.getElementById("modal-adjust-target").textContent = Number.isFinite(targetQty) ? String(targetQty) : "Invalid";
    diffEl.classList.remove("is-positive", "is-negative", "is-neutral");

    if (!Number.isInteger(targetQty) || targetQty < 0) {
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
    saveButton.disabled = false;
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
    invActiveStoreId = Number(data.default_store_id || invStores[0].id);
}

async function invLoadProducts() {
    const body = document.getElementById("inventory-product-body");
    const summary = document.getElementById("inventory-stock-summary");
    body.innerHTML = invDataState("loading", "Loading products...", 5);
    summary.innerHTML = invDataState("loading", "Loading stock summary...");

    let data;
    try {
        const response = await fetch(`/store/products?store_id=${invActiveStoreId}`);
        data = await response.json();
    } catch (error) {
        const message = error?.message || "Unable to load products.";
        body.innerHTML = invDataState("error", message, 5);
        summary.innerHTML = invDataState("error", message);
        throw new Error(message);
    }

    if (!data || data.status !== "success") {
        invProducts = [];
        const message = data?.message || "Unable to load products.";
        body.innerHTML = invDataState("error", message, 5);
        summary.innerHTML = invDataState("error", message);
        throw new Error(message);
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
    const list = document.getElementById("inventory-movement-list");
    list.innerHTML = invDataState("loading", "Loading stock activity...");

    let data;
    try {
        const response = await fetch(`/store/inventory/movements?store_id=${invActiveStoreId}&limit=8${typeQuery}`);
        data = await response.json();
    } catch (error) {
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
}

async function invSubmitModalRestock() {
    if (invIsRestocking) return;

    const productId = Number(invModalProductId || 0);
    const qty = Number(document.getElementById("modal-restock-qty").value || 0);
    const unitCost = Number(document.getElementById("modal-restock-unit-cost").value || 0);
    const sellPrice = Number(document.getElementById("modal-restock-sell-price").value || 0);
    const reason = (document.getElementById("modal-restock-reason").value || "Stock in").trim();
    const button = document.getElementById("modal-restock-submit");

    if (!invActiveStoreId || productId <= 0 || !Number.isInteger(qty) || qty <= 0 || unitCost < 0 || sellPrice < 0 || reason === "") {
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
        imageMode: invGetElementValue("new-product-image-mode", "shared"),
        variants: invAdditionalVariants().map(({row, image, ...variant}) => ({...variant, imageName:image?.name || "", imageSize:Number(image?.size || 0)})),
    };
}
