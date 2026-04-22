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
let invCategories = [];
let invCreateSnapshot = null;

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
}

function invRenderProductTable() {
    const body = document.getElementById("inventory-product-body");
    const search = (document.getElementById("inventory-search").value || "").trim().toLowerCase();

    const rows = invProducts.filter((product) => {
        if (!search) return true;
        const haystack = `${product.name || ""} ${product.variant_label || ""} ${product.sku || ""} ${product.barcode || ""}`.toLowerCase();
        return haystack.includes(search);
    });

    if (rows.length === 0) {
        body.innerHTML = '<tr><td colspan="7">No products found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((product) => {
        const thumb = product.image_url
            ? `<img src="${invEscape(product.image_url)}" alt="${invEscape(product.name)}" class="prod-thumb">`
            : `<div class="prod-thumb-fallback">No Img</div>`;

        return `
            <tr class="product-row" data-product-row="${product.id}">
                <td>${thumb}</td>
                <td>${invEscape(product.sku || "-")}</td>
                <td>${invEscape(product.name)}</td>
                <td>${invEscape(product.variant_label || "-")}</td>
                <td>${invEscape(product.category || "-")}</td>
                <td>${invEscape(invMoney(product.price))}</td>
                <td>${Number(product.stock_qty || 0)}</td>
            </tr>
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
    document.getElementById("product-action-info").innerHTML = `
        <div><strong>${invEscape(displayName)}</strong> (${invEscape(product.sku || "-")})</div>
        <div>Current Stock: <strong>${Number(product.stock_qty || 0)}</strong> | Price: <strong>${invEscape(invMoney(product.price))}</strong></div>
    `;
    document.getElementById("modal-product-sku").value = product.sku || "";
    document.getElementById("modal-product-name").value = product.name || "";
    document.getElementById("modal-product-variant-label").value = product.variant_label || "";
    invEnsureCategoryOption(document.getElementById("modal-product-category"), product.category || "General");
    document.getElementById("modal-product-category").value = product.category || "";
    document.getElementById("modal-product-price").value = Number(product.price || 0).toFixed(2);
    document.getElementById("modal-product-barcode").value = product.barcode || "";
    document.getElementById("modal-product-image-url").value = product.image_url || "";
    document.getElementById("modal-product-image-file").value = "";
    document.getElementById("modal-product-image-source").value = product.image_url ? "url" : "upload";
    invToggleModalProductImageInput();
    document.getElementById("product-view-sku").textContent = product.sku || "-";
    document.getElementById("product-view-name").textContent = product.name || "-";
    document.getElementById("product-view-variant").textContent = product.variant_label || "-";
    document.getElementById("product-view-category").textContent = product.category || "-";
    document.getElementById("product-view-barcode").textContent = product.barcode || "-";
    document.getElementById("product-view-price").textContent = invMoney(product.price);
    document.getElementById("product-view-image").textContent = product.image_url || "Not set";
    document.getElementById("modal-actual-stock").value = Number(product.stock_qty || 0);
    document.getElementById("modal-stock-reason").value = "Physical count adjustment";
    document.getElementById("modal-restock-qty").value = "1";
    document.getElementById("modal-restock-unit-cost").value = "0";
    document.getElementById("modal-restock-sell-price").value = Number(product.price || 0).toFixed(2);
    document.getElementById("modal-restock-reason").value = "Stock in";
    invUpdateModalRestockProjection();
    invSetProductEditMode(false);
    invSetModalPanel("adjust");
    document.getElementById("inventory-product-action-modal").style.display = "grid";
}

function invCloseProductActionModal() {
    invModalProductId = null;
    invSetProductEditMode(false);
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
}

function invUpdateCreateProductProjection() {
    const initialStock = Number(document.getElementById("new-product-initial-stock").value || 0);
    const unitCost = Number(document.getElementById("new-product-unit-cost").value || 0);
    const sellPrice = Number(document.getElementById("new-product-sell-price").value || 0);

    const markup = unitCost > 0 ? ((sellPrice - unitCost) / unitCost) * 100 : 0;
    const stockValue = Math.max(0, initialStock) * Math.max(0, unitCost);

    document.getElementById("new-product-markup").textContent = `Markup: ${markup.toFixed(2)}%`;
    document.getElementById("new-product-stock-value").textContent = `Value: ${invMoney(stockValue)}`;
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
    const barcode = (document.getElementById("new-product-barcode").value || "").trim();
    const imageSource = (document.getElementById("new-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("new-product-image-url").value || "").trim();
    const imageFile = document.getElementById("new-product-image-file").files[0] || null;
    const sellPrice = Number(document.getElementById("new-product-sell-price").value || 0);
    const initialStock = Number(document.getElementById("new-product-initial-stock").value || 0);
    const unitCost = Number(document.getElementById("new-product-unit-cost").value || 0);
    const reason = (invGetElementValue("new-product-reason", "Initial stock") || "Initial stock").trim();
    const button = document.getElementById("new-product-submit");

    if (!invActiveStoreId || !sku || !name || sellPrice < 0 || initialStock < 0 || unitCost < 0) {
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
        formData.append("barcode", barcode);
        formData.append("sell_price", String(sellPrice));
        formData.append("initial_stock", String(initialStock));
        formData.append("unit_cost", String(unitCost));
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
    } catch (error) {
        invSetResult("Product creation request failed.", "error");
    } finally {
        invIsCreatingProduct = false;
        button.disabled = false;
        button.textContent = "Create Product";
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
    const barcode = (document.getElementById("modal-product-barcode").value || "").trim();
    const sellPrice = Number(document.getElementById("modal-product-price").value || 0);
    const imageSource = (document.getElementById("modal-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("modal-product-image-url").value || "").trim();
    const imageFile = document.getElementById("modal-product-image-file").files[0] || null;
    const button = document.getElementById("modal-save-product");

    if (!invActiveStoreId || !productId || !sku || !name || sellPrice < 0) {
        invSetResult("Please complete valid product details.", "error");
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
        formData.append("barcode", barcode);
        formData.append("sell_price", String(sellPrice));
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

document.getElementById("open-product-modal-top").addEventListener("click", () => {
    invOpenProductModal().catch((error) => invSetResult(error.message || "Unable to open product modal.", "error"));
});
document.getElementById("close-product-modal").addEventListener("click", invRequestCloseProductModal);
document.getElementById("new-product-cancel").addEventListener("click", invRequestCloseProductModal);
document.getElementById("close-product-action-modal").addEventListener("click", invCloseProductActionModal);
document.getElementById("new-product-image-source").addEventListener("change", invToggleProductImageInput);
document.getElementById("new-product-image-file").addEventListener("change", invUpdateCreateProductImagePreview);
document.getElementById("new-product-image-url").addEventListener("input", invUpdateCreateProductImagePreview);
document.getElementById("new-product-unit-cost").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-sell-price").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-initial-stock").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("modal-product-image-source").addEventListener("change", invToggleModalProductImageInput);
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
    const row = event.target.closest("[data-product-row]");
    if (!row) return;
    invOpenProductActionModal(row.getAttribute("data-product-row"));
});

document.getElementById("modal-restock-qty").addEventListener("input", invUpdateModalRestockProjection);
document.getElementById("modal-restock-unit-cost").addEventListener("input", invUpdateModalRestockProjection);
document.getElementById("modal-restock-sell-price").addEventListener("input", invUpdateModalRestockProjection);
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
        invToggleProductImageInput();
        invUpdateCreateProductProjection();
    } catch (error) {
        invSetResult(error.message || "Unable to initialize inventory page.", "error");
    }
})();
