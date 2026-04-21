let invStores = [];
let invActiveStoreId = null;
let invProducts = [];
let invIsSubmitting = false;
let invIsCreatingProduct = false;

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
        body.innerHTML = '<tr><td colspan="8">No products found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((product) => {
        const thumb = product.image_url
            ? `<img src="${invEscape(product.image_url)}" alt="${invEscape(product.name)}" class="prod-thumb">`
            : `<div class="prod-thumb-fallback">No Img</div>`;

        return `
            <tr>
                <td>${thumb}</td>
                <td>${invEscape(product.sku || "-")}</td>
                <td>${invEscape(product.name)}</td>
                <td>${invEscape(invMoney(product.price))}</td>
                <td>${Number(product.stock_qty || 0)}</td>
                <td>
                    <input class="stock-input" type="number" min="0" step="1" value="${Number(product.stock_qty || 0)}" data-actual-input="${product.id}">
                </td>
                <td>
                    <input class="stock-reason" type="text" value="Physical count adjustment" data-reason-input="${product.id}">
                </td>
                <td>
                    <button class="stock-adjust-btn" type="button" data-adjust-btn="${product.id}">Save</button>
                </td>
            </tr>
        `;
    }).join("");
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

async function invCreateProduct() {
    if (invIsCreatingProduct) return;

    const sku = (document.getElementById("new-product-sku").value || "").trim();
    const name = (document.getElementById("new-product-name").value || "").trim();
    const category = (document.getElementById("new-product-category").value || "").trim();
    const imageUrl = (document.getElementById("new-product-image-url").value || "").trim();
    const sellPrice = Number(document.getElementById("new-product-sell-price").value || 0);
    const initialStock = Number(document.getElementById("new-product-initial-stock").value || 0);
    const unitCost = Number(document.getElementById("new-product-unit-cost").value || 0);
    const reason = (document.getElementById("new-product-reason").value || "Initial stock").trim();
    const button = document.getElementById("new-product-submit");

    if (!invActiveStoreId || !sku || !name || sellPrice < 0 || initialStock < 0 || unitCost < 0) {
        invSetResult("Please complete valid product details.", "error");
        return;
    }

    try {
        invIsCreatingProduct = true;
        button.disabled = true;
        button.textContent = "Creating...";

        const response = await fetch("/store/inventory/add-product", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                store_id: invActiveStoreId,
                sku,
                name,
                category,
                image_url: imageUrl,
                sell_price: sellPrice,
                initial_stock: initialStock,
                unit_cost: unitCost,
                reason,
            }),
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
    const qtyInput = document.querySelector(`[data-actual-input="${productId}"]`);
    const reasonInput = document.querySelector(`[data-reason-input="${productId}"]`);
    const button = document.querySelector(`[data-adjust-btn="${productId}"]`);
    if (!qtyInput || !reasonInput || !button) return;

    const actualQty = Number(qtyInput.value || 0);
    const reason = (reasonInput.value || "Physical count adjustment").trim();

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
        await invLoadProducts();
    } catch (error) {
        invSetResult("Stock adjustment request failed.", "error");
    } finally {
        button.disabled = false;
        button.textContent = "Save";
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

document.getElementById("inventory-product-body").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-adjust-btn]");
    if (!button) return;
    await invAdjustStock(button.getAttribute("data-adjust-btn"));
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

(async () => {
    try {
        await invLoadStores();
        await invLoadProducts();
    } catch (error) {
        invSetResult(error.message || "Unable to initialize inventory page.", "error");
    }
})();
