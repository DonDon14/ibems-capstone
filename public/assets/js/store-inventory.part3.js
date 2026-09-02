function invIsCreateFormDirty() {
    if (!invCreateSnapshot) return false;
    const current = invCaptureCreateFormState();
    return JSON.stringify(current) !== JSON.stringify(invCreateSnapshot);
}

async function invRequestCloseProductModal() {
    if (invIsCreatingProduct) return;

    if (invIsCreateFormDirty()) {
        const confirmed = await window.IbemsDialog.confirm("Your unsaved product details will be lost.", {
            title: "Discard product changes?",
            confirmLabel: "Discard changes",
            cancelLabel: "Continue editing",
            tone: "danger",
        });
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
    const itemType = invGetElementValue("new-product-item-type", "stock_item");
    const stockPolicy = invGetElementValue("new-product-stock-policy", "tracked");
    const unitCode = invGetElementValue("new-product-unit-code", "piece");
    const button = document.getElementById("new-product-submit");
    const additionalVariants = invAdditionalVariants();

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

    const familySkus = [sku, ...additionalVariants.map(v => v.sku)].map(v => v.toLowerCase());
    const familyBarcodes = [barcode, ...additionalVariants.map(v => v.barcode)].filter(Boolean).map(v => v.toLowerCase());
    if (additionalVariants.some(v => !v.label || !v.sku || v.cost < 0 || v.price < 0 || v.stock < 0 || v.low < 0)) {
        invSetResult("Complete every variant with valid size, SKU, pricing, and stock.", "error"); return;
    }
    if (new Set(familySkus).size !== familySkus.length || new Set(familyBarcodes).size !== familyBarcodes.length) {
        invSetResult("Each variant must have a unique SKU and barcode.", "error"); return;
    }

    try {
        invIsCreatingProduct = true;
        button.disabled = true;
        button.textContent = "Creating...";

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

        const response = await fetch("/store/inventory/add-product-family", {
            method: "POST",
            body: formData,
        });
        const data = await response.json();

        if (!data || data.status !== "success") {
            invSetResult(data?.message || "Product creation failed.", "error");
            return;
        }

        invSetResult(`${data.variant_count} variant${data.variant_count === 1 ? "" : "s"} created atomically for ${name}.`, "ok");
        invCloseProductModal();
        await invLoadCategories();
        await invLoadProducts();
        await invLoadMovements();
    } catch (error) {
        invSetResult(error.message || "Product creation request failed.", "error");
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
    const itemType = invGetElementValue("modal-product-item-type", "stock_item");
    const stockPolicy = invGetElementValue("modal-product-stock-policy", "tracked");
    const unitCode = invGetElementValue("modal-product-unit-code", "piece");
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
        formData.append("item_type", itemType);
        formData.append("stock_policy", stockPolicy);
        formData.append("unit_code", unitCode);
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
}

function invCloseProductModal() {
    if (invCreatePreviewObjectUrl) {
        URL.revokeObjectURL(invCreatePreviewObjectUrl);
        invCreatePreviewObjectUrl = null;
    }
    document.getElementById("inventory-product-modal").style.display = "none";
    invSyncModalBodyLock();
    invCreateSnapshot = null;
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
document.getElementById("inventory-barcode-camera-cancel").addEventListener("click", invCloseBarcodeCamera);
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
    if (event.key !== "Escape") return;
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
        }
        if (document.querySelector("#inventory-stock-summary .data-state--loading")) {
            document.getElementById("inventory-stock-summary").innerHTML = invDataState("error", message);
        }
        if (document.querySelector("#inventory-movement-list .data-state--loading")) {
            document.getElementById("inventory-movement-list").innerHTML = invDataState("error", message);
        }
        invSetResult(message, "error");
    }
})();
