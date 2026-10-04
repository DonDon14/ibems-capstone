function invIsCreateFormDirty() {
    if (!invCreateSnapshot) return false;
    const current = invCaptureCreateFormState();
    return JSON.stringify(current) !== JSON.stringify(invCreateSnapshot);
}

async function invRequestCloseProductModal() {
    if (invIsCreatingProduct || invCreateClosePending) return;
    const modal = document.getElementById("inventory-product-modal");
    if (modal.style.display !== "grid") return;
    const version = invProductCreateVersion;
    const storeId = invActiveStoreId;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    try {
        invCreateClosePending = true;
        if (invIsCreateFormDirty()) {
            const confirmed = await window.IbemsDialog.confirm("Your unsaved product details will be lost.", {
                title: "Discard product changes?",
                confirmLabel: "Discard changes",
                cancelLabel: "Continue editing",
                tone: "danger",
            });
            if (!confirmed) return;
        }
        if (signal?.aborted || !modal.isConnected || modal !== document.getElementById("inventory-product-modal") ||
            version !== invProductCreateVersion || storeId !== invActiveStoreId || invIsCreatingProduct) return;
        invCloseProductModal();
    } finally {
        invCreateClosePending = false;
    }
}

function invSetProductResult(mode, message, type = "progress", focus = false) {
    if (mode === "edit") return invSetStockResult(message, type, focus);
    const result = document.getElementById("modal-product-result");
    result.textContent = message;
    result.hidden = !message;
    result.dataset.type = type;
    if (focus && message) result.focus();
}

async function invSaveProductDetails(mode, formData, successMessage) {
    if (invIsCreatingProduct || invIsUpdatingProduct || invStockSavePending || invCreateClosePending) return;
    const create = mode === "create";
    const modal = document.getElementById(create ? "inventory-product-modal" : "inventory-product-action-modal");
    const version = create ? invProductCreateVersion : invProductActionVersion;
    const storeId = Number(formData.get("store_id"));
    const productId = Number(formData.get("product_id"));
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const sameVersion = () => version === (create ? invProductCreateVersion : invProductActionVersion);
    const current = () => !signal?.aborted && modal.isConnected && modal === document.getElementById(modal.id) &&
        modal.style.display === "grid" && sameVersion() && Number(invActiveStoreId) === storeId &&
        (create || Number(invModalProductId) === productId);
    if (!current()) return;
    const button = document.getElementById(create ? "new-product-submit" : "modal-save-product");
    const close = document.getElementById(create ? "close-product-modal" : "close-product-action-modal");
    const label = button.textContent;
    const controls = Array.from(modal.querySelectorAll('button, input, select, textarea'))
        .filter(control => control !== close).map(control => [control, control.disabled]);
    if (create) invIsCreatingProduct = true; else invIsUpdatingProduct = true;
    modal.setAttribute("aria-busy", "true");
    controls.forEach(([control]) => {control.disabled = true;});
    button.textContent = create ? "Creating…" : "Saving…";
    invSetProductResult(mode, create ? "Creating product variants…" : "Saving product details…");
    close.focus();
    let confirmed = false;
    let closed = false;
    try {
        const response = await fetch(create ? "/store/inventory/add-product-family" : "/store/inventory/update-product", {
            method: "POST", body: formData,
        });
        const data = await response.json();
        if (!current()) return;
        if (!response.ok || data?.status !== "success") {
            invSetProductResult(mode, data?.message || "Product could not be saved. Review the details and try again.", "error", true);
            return;
        }
        confirmed = true;
        const message = successMessage(data);
        invSetProductResult(mode, "Product saved. Refreshing inventory…");
        let refreshed = true;
        const reloads = create ? [invLoadCategories, invLoadProducts, invLoadMovements] : [invLoadCategories, invLoadProducts];
        for (const reload of reloads) {
            try {if (await reload() === false) refreshed = false;} catch (error) {refreshed = false;}
            if (!current()) return;
        }
        if (create) invCloseProductModal(true); else invCloseProductActionModal(true);
        closed = true;
        invSetResult(refreshed ? message : `${message} Inventory could not fully refresh. Reload this page before making another product change.`, refreshed ? "ok" : "warning", true);
    } catch (error) {
        if (current()) invSetProductResult(mode, confirmed ? "Product saved. Reload this page to see the latest inventory." :
            "The product request could not be confirmed. Check inventory before trying again.", confirmed ? "warning" : "error", true);
    } finally {
        if (create) invIsCreatingProduct = false; else invIsUpdatingProduct = false;
        if (modal.isConnected && modal === document.getElementById(modal.id) &&
            (sameVersion() || (closed && version + 1 === (create ? invProductCreateVersion : invProductActionVersion)))) {
            modal.setAttribute("aria-busy", "false");
            controls.forEach(([control, disabled]) => {control.disabled = disabled;});
            button.textContent = label;
            if (create) invUpdateCreateProductProjection(); else invUpdateAdjustmentPreview();
        }
    }
}

async function invCreateProduct() {
    if (invIsCreatingProduct || invIsUpdatingProduct || invStockSavePending || invCreateClosePending) return;

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
    const lowStock = Number(document.getElementById("new-product-low-stock").value || 0);
    const locationBin = (document.getElementById("new-product-location").value || "").trim();
    const reason = invGetElementValue("new-product-reason", "Initial stock").trim();
    const itemType = invGetElementValue("new-product-item-type", "stock_item");
    const stockPolicy = invGetElementValue("new-product-stock-policy", "tracked");
    const unitCode = invGetElementValue("new-product-unit-code", "piece");
    const additionalVariants = invAdditionalVariants();

    if (!invActiveStoreId || !sku || !name || !invProductNumbersValid(sellPrice, unitCost, initialStock, lowStock) || (initialStock > 0 && !reason)) {
        invSetProductResult("create", "Complete the product name, SKU, valid prices, whole-number stock/threshold, and initial stock reason.", "error", true);
        return;
    }
    if (imageSource === "url" && imageUrl === "") {
        invSetProductResult("create", "Please provide an image URL or switch to upload.", "error", true);
        return;
    }
    if (imageSource === "upload" && (!imageFile || imageFile.size <= 0)) {
        invSetProductResult("create", "Please upload a valid image file or switch to URL.", "error", true);
        return;
    }

    const familySkus = [sku, ...additionalVariants.map(v => v.sku)].map(v => v.toLowerCase());
    const familyBarcodes = [barcode, ...additionalVariants.map(v => v.barcode)].filter(Boolean).map(v => v.toLowerCase());
    if (!additionalVariants.every(invVariantIsValid)) {
        invSetProductResult("create", "Complete every variant with a size, SKU, valid prices, whole-number stock/threshold, and valid image override.", "error", true); return;
    }
    if (new Set(familySkus).size !== familySkus.length || new Set(familyBarcodes).size !== familyBarcodes.length ||
        familySkus.some(value => invSkuExists(value)) || familyBarcodes.some(value => invBarcodeExists(value))) {
        invSetProductResult("create", "Each variant must have a unique SKU and barcode in this store.", "error", true); return;
    }

    const formData = new FormData();
    formData.append("store_id", String(invActiveStoreId));
    formData.append("name", name);
    formData.append("category", category);
    formData.append("supplier", supplier);
    formData.append("location_bin", locationBin);
    formData.append("reason", reason);
    formData.append("item_type", itemType);
    formData.append("stock_policy", stockPolicy);
    formData.append("unit_code", unitCode);
    formData.append("variants", JSON.stringify([
        {sku, label:variantLabel || "Default", barcode, price:sellPrice, stock:initialStock, cost:unitCost, low:lowStock},
        ...additionalVariants.map(({row,image,...variant}) => variant),
    ]));
    if (imageSource === "url") {
        formData.append("image_url", imageUrl);
    } else if (imageFile) {
        formData.append("image_file", imageFile);
    }
    if (document.getElementById("new-product-image-mode").value === "per_variant") {
        additionalVariants.forEach((variant, index) => { if (variant.image) formData.append(`variant_image_${index + 1}`, variant.image); });
    }

    await invSaveProductDetails("create", formData, data =>
        `${data.variant_count} variant${data.variant_count === 1 ? "" : "s"} created for ${name}.`);
}

async function invAdjustStock(productId) {
    if (invStockSavePending || invIsUpdatingProduct || invIsCreatingProduct) return;
    const actualQty = Number(document.getElementById("modal-actual-stock").value || 0);
    const reason = (document.getElementById("modal-stock-reason").value || "").trim();
    const wasteDisposition = document.getElementById("modal-stock-waste-disposition").value;

    if (!document.getElementById("modal-actual-stock").value.trim() || !Number.isInteger(actualQty) || actualQty < 0) {
        invSetStockResult("Actual stock must be a whole number, 0 or higher.", "error", true);
        return;
    }
    const product = invGetProductById(productId);
    if (!invActiveStoreId || !product || Number(productId) !== Number(invModalProductId) || !reason) {
        invSetStockResult("Select a product and enter a reason for this adjustment.", "error", true);
        return;
    }
    if (product && actualQty === Number(product.stock_qty || 0)) {
        invSetStockResult("No stock change needed for this product.", "ok", true);
        invUpdateAdjustmentPreview();
        return;
    }
    if (wasteDisposition && product && actualQty >= Number(product.stock_qty || 0)) {
        invSetStockResult("Discarded or spoiled stock must reduce the current quantity.", "error", true);
        return;
    }

    await invSaveStock("adjust", {
        store_id: invActiveStoreId,
        product_id: Number(productId),
        actual_qty: actualQty,
        expected_qty: product ? Number(product.stock_qty || 0) : undefined,
        reason,
        waste_disposition: wasteDisposition,
    }, data => {
        const diff = Number(data.diff_qty || 0);
        return diff === 0 ? "No stock change needed for this product." :
            `Stock updated. Previous: ${data.previous_qty}, New: ${data.actual_qty}, Difference: ${diff > 0 ? "+" : ""}${diff}`;
    });
}

async function invUpdateProduct(productId) {
    if (invIsUpdatingProduct || invStockSavePending || invIsCreatingProduct) return;

    const sku = (document.getElementById("modal-product-sku").value || "").trim();
    const name = (document.getElementById("modal-product-name").value || "").trim();
    const variantLabel = (document.getElementById("modal-product-variant-label").value || "").trim();
    const category = (document.getElementById("modal-product-category").value || "General").trim() || "General";
    const supplier = (document.getElementById("modal-product-supplier").value || "").trim();
    const barcode = (document.getElementById("modal-product-barcode").value || "").trim();
    const sellPrice = Number(document.getElementById("modal-product-price").value || 0);
    const lowStock = Number(document.getElementById("modal-product-low-stock").value || 0);
    const locationBin = (document.getElementById("modal-product-location").value || "").trim();
    const imageSource = (document.getElementById("modal-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("modal-product-image-url").value || "").trim();
    const imageFile = document.getElementById("modal-product-image-file").files[0] || null;
    const itemType = invGetElementValue("modal-product-item-type", "stock_item");
    const stockPolicy = invGetElementValue("modal-product-stock-policy", "tracked");
    const unitCode = invGetElementValue("modal-product-unit-code", "piece");

    if (!invActiveStoreId || !invGetProductById(productId) || Number(invModalProductId) !== Number(productId) ||
        !sku || !name || !invProductNumbersValid(sellPrice, 0, 0, lowStock)) {
        invSetProductResult("edit", "Complete the product name, SKU, valid selling price, and whole-number low stock threshold.", "error", true);
        return;
    }
    if (invSkuExists(sku, productId)) {
        invSetProductResult("edit", "SKU already exists in this store. Use a unique SKU before saving.", "error", true);
        return;
    }
    if (barcode !== "" && invBarcodeExists(barcode, productId)) {
        invSetProductResult("edit", "Barcode already exists in this store. Use a unique barcode or leave it blank.", "error", true);
        return;
    }
    if (imageSource === "upload" && imageFile && imageFile.size <= 0) {
        invSetProductResult("edit", "Selected product image file is invalid.", "error", true);
        return;
    }

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
    formData.append("item_type", itemType);
    formData.append("stock_policy", stockPolicy);
    formData.append("unit_code", unitCode);
    if (imageSource === "url" && imageUrl !== "") {
        formData.append("image_url", imageUrl);
    } else if (imageSource === "upload" && imageFile) {
        formData.append("image_file", imageFile);
    }

    await invSaveProductDetails("edit", formData, data => `Product updated: ${data.product?.name || name}`);
}

async function invOpenProductModal() {
    if (invIsCreatingProduct || invIsUpdatingProduct || invStockSavePending || invCreateClosePending) return;
    const opener = document.activeElement;
    const modal = document.getElementById("inventory-product-modal");
    if (modal.style.display === "grid") return;
    const version = ++invProductCreateVersion;
    const storeId = invActiveStoreId;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    if (await invLoadCategories() === false || signal?.aborted || !modal?.isConnected ||
        modal !== document.getElementById("inventory-product-modal") || version !== invProductCreateVersion || storeId !== invActiveStoreId) return;
    invSetProductResult("create", "");
    modal.querySelector('.create-product-layout').scrollTop = 0;
    document.getElementById("new-product-sku").value = "";
    delete document.getElementById("new-product-sku").dataset.manual;
    document.getElementById("new-product-name").value = "";
    document.getElementById("new-product-variant-label").value = "";
    document.getElementById("new-product-category").value = "General";
    document.getElementById("new-product-supplier").value = "";
    document.getElementById("new-product-barcode").value = "";
    document.getElementById("new-product-image-source").value = "upload";
    document.getElementById("new-product-image-mode").value = "shared";
    document.getElementById("new-product-variants").innerHTML = "";
    document.getElementById("new-product-variants").classList.remove("is-per-variant");
    invVariantSequence = 0;
    invUpdateVariantCount();
    document.getElementById("new-product-image-file").value = "";
    document.getElementById("new-product-image-url").value = "";
    document.getElementById("new-product-sell-price").value = "0";
    document.getElementById("new-product-initial-stock").value = "0";
    document.getElementById("new-product-unit-cost").value = "0";
    document.getElementById("new-product-location").value = "";
    document.getElementById("new-product-low-stock").value = "0";
    document.getElementById("new-product-reason").value = "Initial stock";
    document.getElementById("new-product-item-type").value = "stock_item";
    document.getElementById("new-product-stock-policy").value = "tracked";
    document.getElementById("new-product-unit-code").value = "piece";
    invSyncBehaviorFields("new-product");
    invToggleProductImageInput();
    invUpdateCreateProductProjection();
    document.getElementById("inventory-product-modal").style.display = "grid";
    invSyncModalBodyLock();
    invCreateSnapshot = invCaptureCreateFormState();
    invProductCreateOpener = opener;
    document.getElementById("close-product-modal")?.focus();
}

function invCloseProductModal(force = false) {
    if (invIsCreatingProduct && force !== true) return;
    const modal = document.getElementById("inventory-product-modal");
    if (modal.style.display !== "grid") return;
    ++invProductCreateVersion;
    if (invCreatePreviewObjectUrl) {
        URL.revokeObjectURL(invCreatePreviewObjectUrl);
        invCreatePreviewObjectUrl = null;
    }
    modal.style.display = "none";
    invSyncModalBodyLock();
    invCreateSnapshot = null;
    if (invProductCreateOpener?.isConnected) invProductCreateOpener.focus();
    invProductCreateOpener = null;
}

document.getElementById("inventory-search").addEventListener("input", () => { invPage = 1; invRenderProductTable(); });
["inventory-category-filter", "inventory-stock-filter", "inventory-sort", "inventory-page-size"].forEach((id) => {
    document.getElementById(id).addEventListener("change", () => { invPage = 1; invRenderProductTable(); });
});
document.getElementById("inventory-pager").addEventListener("click", (event) => {
    const button = event.target.closest("[data-page]");
    if (!button || button.disabled) return;
    invPage = Math.max(1, Number(button.dataset.page || 1));
    invRenderProductTable();
});
document.getElementById("inventory-stock-summary").addEventListener("click", (event) => {
    const button = event.target.closest("[data-stock-summary]");
    if (!button) return;
    document.getElementById("inventory-stock-filter").value = button.dataset.stockSummary || "";
    invPage = 1;
    invRenderProductTable();
});
document.getElementById("inventory-toggle-families").addEventListener("click", (event) => {
    const button = event.currentTarget;
    let keys = [];
    try {
        keys = JSON.parse(button.dataset.familyKeys || "[]");
    } catch (error) {
        keys = [];
    }
    const shouldExpand = button.dataset.expand === "true";
    keys.forEach((key) => {
        if (shouldExpand) invExpandedFamilies.add(key);
        else invExpandedFamilies.delete(key);
    });
    invRenderProductTable();
});
document.getElementById("inventory-clear-filters").addEventListener("click", () => {
    document.getElementById("inventory-search").value = "";
    document.getElementById("inventory-category-filter").value = "";
    document.getElementById("inventory-stock-filter").value = "";
    document.getElementById("inventory-sort").value = "name:asc";
    document.getElementById("inventory-page-size").value = "25";
    invPage = 1;
    invRenderProductTable();
});
document.getElementById("refresh-inventory-products").addEventListener("click", invRefreshProducts);
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
document.getElementById("new-product-sku").addEventListener("input", (event) => { event.target.dataset.manual = "true"; invUpdateCreateProductProjection(); });
document.getElementById("new-product-name").addEventListener("input", () => { invSyncFirstVariantSku(); document.querySelectorAll("[data-variant-row]").forEach((row,index) => { const sku=row.querySelector('[data-v="sku"]'); if(sku.dataset.manual!=="true") sku.value=invGeneratedSku(invGetElementValue("new-product-name"),row.querySelector('[data-v="label"]').value,index+2); }); invUpdateCreateProductProjection(); });
document.getElementById("new-product-variant-label").addEventListener("input", () => { invSyncFirstVariantSku(); invUpdateCreateProductProjection(); });
document.getElementById("add-product-variant").addEventListener("click", () => { invAddVariantRow(); invUpdateCreateProductProjection(); });
document.getElementById("new-product-item-type")?.addEventListener("change", () => invSyncBehaviorFields("new-product"));
document.getElementById("new-product-stock-policy")?.addEventListener("change", () => invSyncBehaviorFields("new-product"));
document.getElementById("modal-product-item-type")?.addEventListener("change", () => invSyncBehaviorFields("modal-product"));
document.getElementById("modal-product-stock-policy")?.addEventListener("change", () => invSyncBehaviorFields("modal-product"));
document.getElementById("new-product-image-mode").addEventListener("change", (event) => { document.getElementById("new-product-variants").classList.toggle("is-per-variant", event.target.value === "per_variant"); });
document.querySelector('[data-barcode-camera-target="new-product-barcode"]').addEventListener("click", () => invOpenBarcodeCamera(document.getElementById("new-product-barcode")));
document.getElementById("inventory-barcode-camera-close").addEventListener("click", invCloseBarcodeCamera);
document.getElementById("inventory-barcode-camera-cancel").addEventListener("click", () => invCloseBarcodeCamera(true));
document.getElementById("inventory-barcode-camera-retry").addEventListener("click", invRetryBarcodeCamera);
document.getElementById("inventory-barcode-camera-modal").addEventListener("click", (event) => { if (event.target.id === "inventory-barcode-camera-modal") invCloseBarcodeCamera(); });
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
    if (event.defaultPrevented) return;
    const activeDialog = ["inventory-barcode-camera-modal", "inventory-product-action-modal", "inventory-product-modal"]
        .map(id => document.getElementById(id)).find(dialog => dialog?.style.display === "grid");
    if (event.key === "Tab" && activeDialog?.style.display === "grid" && activeDialog.contains(event.target)) {
        const controls = Array.from(activeDialog.querySelectorAll('button, input, select, textarea, a[href], [tabindex]'))
            .filter(control => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length > 0);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && (document.activeElement === last || ["modal-stock-result", "modal-product-result", "inventory-barcode-camera-status"].includes(document.activeElement.id))) {
            event.preventDefault();
            first?.focus();
        }
        return;
    }
    if (event.key !== "Escape") return;
    const cameraModal = document.getElementById("inventory-barcode-camera-modal");
    if (cameraModal?.style.display === "grid") {
        event.preventDefault();
        invCloseBarcodeCamera();
        return;
    }
    const actionModal = document.getElementById("inventory-product-action-modal");
    if (actionModal?.style.display === "grid") {
        event.preventDefault();
        invCloseProductActionModal();
        return;
    }
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
document.getElementById("inventory-product-body").addEventListener("click", (event) => {
    const toggle = event.target.closest("[data-family-toggle]");
    if (!toggle) return;
    const key = toggle.dataset.familyToggle;
    if (invExpandedFamilies.has(key)) invExpandedFamilies.delete(key);
    else invExpandedFamilies.add(key);
    invRenderProductTable();
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

window.IbemsPortalNavigation?.onCleanup(() => {
    invCloseBarcodeCamera();
    document.body.classList.remove("app-modal-open");
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
        const message = error.message || "Unable to initialize inventory page.";
        if (document.querySelector("#inventory-product-body .data-state--loading")) {
            document.getElementById("inventory-product-body").innerHTML = invDataState("error", message, 5);
            invSetProductLoadState("error");
            document.getElementById("inventory-results-count").textContent = invActiveStoreId
                ? "Products unavailable. Use Retry products to try again." : "Store unavailable. Reload this page to try again.";
        }
        if (document.querySelector("#inventory-stock-summary .data-state--loading")) {
            document.getElementById("inventory-stock-summary").innerHTML = invDataState("error", message);
        }
        if (document.querySelector("#inventory-movement-list .data-state--loading")) {
            document.getElementById("inventory-movement-list").innerHTML = invDataState("error", message);
        }
    }
})();
