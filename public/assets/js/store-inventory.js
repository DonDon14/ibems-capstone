let invStores = [];
let invActiveStoreId = null;
let invProducts = [];
let invIsSubmitting = false;
let invIsCreatingProduct = false;
let invIsUpdatingProduct = false;
let invModalProductId = null;
let invProductEditMode = false;

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

function invRenderStoreSelect() {
    const select = document.getElementById("inventory-store-select");
    const wrap = document.querySelector(".inventory-store-wrap");
    select.innerHTML = invStores
        .map((store) => `<option value="${store.id}">${invEscape(store.store_name)}</option>`)
        .join("");
    select.value = String(invActiveStoreId);
    const multi = invStores.length > 1;
    select.disabled = !multi;
    wrap.style.display = multi ? "flex" : "none";
}

function invRenderProductSelect() {
    const restockSelect = document.getElementById("restock-product");
    const options = invProducts
        .map((product) => `<option value="${product.id}">${invEscape(product.name)} (${invEscape(product.sku)})</option>`)
        .join("");
    restockSelect.innerHTML = options || '<option value="">No products</option>';
}

function invGetSelectedProduct() {
    const productId = Number(document.getElementById("restock-product").value || 0);
    return invProducts.find((product) => Number(product.id) === productId) || null;
}

function invRenderProductTable() {
    const body = document.getElementById("inventory-product-body");
    const search = (document.getElementById("inventory-search").value || "").trim().toLowerCase();

    const rows = invProducts.filter((product) => {
        if (!search) return true;
        const haystack = `${product.name || ""} ${product.sku || ""}`.toLowerCase();
        return haystack.includes(search);
    });

    if (rows.length === 0) {
        body.innerHTML = '<tr><td colspan="6">No products found.</td></tr>';
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
    document.getElementById("product-action-info").innerHTML = `
        <div><strong>${invEscape(product.name)}</strong> (${invEscape(product.sku || "-")})</div>
        <div>Current Stock: <strong>${Number(product.stock_qty || 0)}</strong> | Price: <strong>${invEscape(invMoney(product.price))}</strong></div>
    `;
    document.getElementById("modal-product-sku").value = product.sku || "";
    document.getElementById("modal-product-name").value = product.name || "";
    document.getElementById("modal-product-category").value = product.category || "";
    document.getElementById("modal-product-price").value = Number(product.price || 0).toFixed(2);
    document.getElementById("modal-product-image-url").value = product.image_url || "";
    document.getElementById("modal-product-image-file").value = "";
    document.getElementById("modal-product-image-source").value = product.image_url ? "url" : "upload";
    invToggleModalProductImageInput();
    document.getElementById("product-view-sku").textContent = product.sku || "-";
    document.getElementById("product-view-name").textContent = product.name || "-";
    document.getElementById("product-view-category").textContent = product.category || "-";
    document.getElementById("product-view-price").textContent = invMoney(product.price);
    document.getElementById("product-view-image").textContent = product.image_url || "Not set";
    document.getElementById("modal-actual-stock").value = Number(product.stock_qty || 0);
    document.getElementById("modal-stock-reason").value = "Physical count adjustment";
    invSetProductEditMode(false);
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

function invUpdateProjection() {
    const product = invGetSelectedProduct();
    const qty = Number(document.getElementById("restock-qty").value || 0);
    const unitCost = Number(document.getElementById("restock-unit-cost").value || 0);
    const sellInput = Number(document.getElementById("restock-sell-price").value || 0);
    const sellPrice = sellInput > 0 ? sellInput : Number(product?.price || 0);

    const totalCost = qty * unitCost;
    const profitPerPiece = sellPrice - unitCost;
    const expectedProfit = qty * profitPerPiece;

    document.getElementById("proj-price").textContent = invMoney(sellPrice);
    document.getElementById("proj-profit-piece").textContent = invMoney(profitPerPiece);
    document.getElementById("proj-cost").textContent = invMoney(totalCost);
    document.getElementById("proj-profit").textContent = invMoney(expectedProfit);
}

async function invLoadStores() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();

    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }

    invStores = data.stores;
    invActiveStoreId = Number(data.default_store_id || invStores[0].id);
    invRenderStoreSelect();
}

async function invLoadProducts() {
    const response = await fetch(`/store/products?store_id=${invActiveStoreId}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        invProducts = [];
        invRenderProductSelect();
        invRenderProductTable();
        invUpdateProjection();
        throw new Error(data?.message || "Unable to load products.");
    }

    invProducts = Array.isArray(data.products) ? data.products : [];
    invRenderProductSelect();
    invRenderProductTable();
    const product = invGetSelectedProduct();
    document.getElementById("restock-sell-price").value = product ? Number(product.price || 0).toFixed(2) : "0";
    invUpdateProjection();
}

async function invSubmitRestock() {
    if (invIsSubmitting) return;

    const productId = Number(document.getElementById("restock-product").value || 0);
    const qty = Number(document.getElementById("restock-qty").value || 0);
    const unitCost = Number(document.getElementById("restock-unit-cost").value || 0);
    const sellPrice = Number(document.getElementById("restock-sell-price").value || 0);
    const reason = document.getElementById("restock-reason").value || "Stock in";
    const button = document.getElementById("restock-submit");

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
        invIsSubmitting = true;
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

        invCloseStockModal();
        await invLoadProducts();
    } catch (error) {
        invSetResult("Stock-in request failed.", "error");
    } finally {
        invIsSubmitting = false;
        button.disabled = false;
        button.textContent = "Submit Stock In";
    }
}

function invToggleProductImageInput() {
    const source = document.getElementById("new-product-image-source").value;
    document.getElementById("new-product-image-upload-wrap").style.display = source === "upload" ? "flex" : "none";
    document.getElementById("new-product-image-url-wrap").style.display = source === "url" ? "flex" : "none";
}

function invToggleModalProductImageInput() {
    const source = document.getElementById("modal-product-image-source").value;
    document.getElementById("modal-product-image-upload-wrap").style.display = source === "upload" ? "flex" : "none";
    document.getElementById("modal-product-image-url-wrap").style.display = source === "url" ? "flex" : "none";
}

async function invCreateProduct() {
    if (invIsCreatingProduct) return;

    const sku = (document.getElementById("new-product-sku").value || "").trim();
    const name = (document.getElementById("new-product-name").value || "").trim();
    const category = (document.getElementById("new-product-category").value || "").trim();
    const imageSource = (document.getElementById("new-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("new-product-image-url").value || "").trim();
    const imageFile = document.getElementById("new-product-image-file").files[0] || null;
    const sellPrice = Number(document.getElementById("new-product-sell-price").value || 0);
    const initialStock = Number(document.getElementById("new-product-initial-stock").value || 0);
    const unitCost = Number(document.getElementById("new-product-unit-cost").value || 0);
    const reason = (document.getElementById("new-product-reason").value || "Initial stock").trim();
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
        formData.append("category", category);
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
    const category = (document.getElementById("modal-product-category").value || "").trim();
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
        formData.append("category", category);
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

function invToggleAddMenu() {
    const menu = document.getElementById("add-menu");
    menu.style.display = menu.style.display === "none" ? "block" : "none";
}

function invCloseAddMenu() {
    document.getElementById("add-menu").style.display = "none";
}

function invOpenStockModal() {
    invCloseAddMenu();
    document.getElementById("inventory-stockin-modal").style.display = "grid";
}

function invCloseStockModal() {
    document.getElementById("inventory-stockin-modal").style.display = "none";
}

function invOpenProductModal() {
    invCloseAddMenu();
    document.getElementById("inventory-product-modal").style.display = "grid";
}

function invCloseProductModal() {
    document.getElementById("inventory-product-modal").style.display = "none";
}

document.getElementById("inventory-store-select").addEventListener("change", async (event) => {
    invActiveStoreId = Number(event.target.value);
    invSetResult("", "ok");
    await invLoadProducts();
});

document.getElementById("inventory-search").addEventListener("input", invRenderProductTable);

document.getElementById("open-add-menu").addEventListener("click", invToggleAddMenu);
document.getElementById("open-stockin-modal").addEventListener("click", invOpenStockModal);
document.getElementById("open-product-modal").addEventListener("click", invOpenProductModal);
document.getElementById("close-stockin-modal").addEventListener("click", invCloseStockModal);
document.getElementById("close-product-modal").addEventListener("click", invCloseProductModal);
document.getElementById("close-product-action-modal").addEventListener("click", invCloseProductActionModal);
document.getElementById("new-product-image-source").addEventListener("change", invToggleProductImageInput);
document.getElementById("modal-product-image-source").addEventListener("change", invToggleModalProductImageInput);
document.getElementById("modal-start-edit-product").addEventListener("click", () => invSetProductEditMode(true));
document.getElementById("modal-cancel-edit-product").addEventListener("click", () => invSetProductEditMode(false));

document.addEventListener("click", (event) => {
    const wrap = event.target.closest(".add-menu-wrap");
    if (!wrap) invCloseAddMenu();
});

document.getElementById("inventory-stockin-modal").addEventListener("click", (event) => {
    if (event.target.id === "inventory-stockin-modal") {
        invCloseStockModal();
    }
});

document.getElementById("inventory-product-modal").addEventListener("click", (event) => {
    if (event.target.id === "inventory-product-modal") {
        invCloseProductModal();
    }
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

document.getElementById("restock-product").addEventListener("change", () => {
    const product = invGetSelectedProduct();
    if (product) {
        document.getElementById("restock-sell-price").value = Number(product.price || 0).toFixed(2);
    }
    invUpdateProjection();
});

document.getElementById("restock-qty").addEventListener("input", invUpdateProjection);
document.getElementById("restock-unit-cost").addEventListener("input", invUpdateProjection);
document.getElementById("restock-sell-price").addEventListener("input", invUpdateProjection);
document.getElementById("restock-submit").addEventListener("click", invSubmitRestock);
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
        await invLoadProducts();
        invToggleProductImageInput();
    } catch (error) {
        invSetResult(error.message || "Unable to initialize inventory page.", "error");
    }
})();
