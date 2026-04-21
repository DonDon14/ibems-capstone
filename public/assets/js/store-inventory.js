let invStores = [];
let invActiveStoreId = null;
let invProducts = [];
let invIsSubmitting = false;

const invFilters = {
    type: "",
    productId: "",
    dateFrom: "",
    dateTo: "",
};

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

function invRenderProductSelects() {
    const restockSelect = document.getElementById("restock-product");
    const movSelect = document.getElementById("mov-product");

    const options = invProducts
        .map((product) => `<option value="${product.id}">${invEscape(product.name)} (${invEscape(product.sku)})</option>`)
        .join("");

    restockSelect.innerHTML = options || '<option value="">No products</option>';
    movSelect.innerHTML = `<option value="">All</option>${options}`;
}

function invGetSelectedProduct() {
    const productId = Number(document.getElementById("restock-product").value || 0);
    return invProducts.find((product) => Number(product.id) === productId) || null;
}

function invUpdateProjection() {
    const product = invGetSelectedProduct();
    const qty = Number(document.getElementById("restock-qty").value || 0);
    const unitCost = Number(document.getElementById("restock-unit-cost").value || 0);

    const price = product ? Number(product.price || 0) : 0;
    const totalCost = qty * unitCost;
    const expectedProfit = qty * (price - unitCost);

    document.getElementById("proj-price").textContent = invMoney(price);
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
        invRenderProductSelects();
        invUpdateProjection();
        throw new Error(data?.message || "Unable to load products.");
    }

    invProducts = Array.isArray(data.products) ? data.products : [];
    invRenderProductSelects();
    invUpdateProjection();
}

function invRenderMovements(movements) {
    const body = document.getElementById("movement-body");

    if (!Array.isArray(movements) || movements.length === 0) {
        body.innerHTML = '<tr><td colspan="6">No inventory movements found.</td></tr>';
        return;
    }

    body.innerHTML = movements
        .map((row) => {
            const typeLabel = String(row.type || "").toUpperCase();
            const date = new Date(row.created_at).toLocaleString();
            const totalCost = row.total_cost === null ? "-" : invMoney(row.total_cost);
            const expectedProfit = row.expected_profit === null ? "-" : invMoney(row.expected_profit);

            return `
                <tr>
                    <td>${invEscape(date)}</td>
                    <td>${invEscape(row.product?.name || "")}</td>
                    <td>${invEscape(typeLabel)}</td>
                    <td>${row.qty}</td>
                    <td>${invEscape(totalCost)}</td>
                    <td>${invEscape(expectedProfit)}</td>
                </tr>
            `;
        })
        .join("");
}

async function invLoadMovements() {
    const body = document.getElementById("movement-body");
    body.innerHTML = '<tr><td colspan="6">Loading movements...</td></tr>';

    const params = new URLSearchParams({
        store_id: String(invActiveStoreId),
        limit: "120",
    });

    if (invFilters.type) params.set("type", invFilters.type);
    if (invFilters.productId) params.set("product_id", invFilters.productId);
    if (invFilters.dateFrom) params.set("date_from", invFilters.dateFrom);
    if (invFilters.dateTo) params.set("date_to", invFilters.dateTo);

    const response = await fetch(`/store/inventory/movements?${params.toString()}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        invRenderMovements([]);
        invSetResult(data?.message || "Unable to load inventory movements.", "error");
        return;
    }

    invRenderMovements(data.movements);
}

async function invSubmitRestock() {
    if (invIsSubmitting) return;

    const productId = Number(document.getElementById("restock-product").value || 0);
    const qty = Number(document.getElementById("restock-qty").value || 0);
    const unitCost = Number(document.getElementById("restock-unit-cost").value || 0);
    const reason = document.getElementById("restock-reason").value || "Stock in";
    const button = document.getElementById("restock-submit");

    if (!invActiveStoreId || productId <= 0 || qty <= 0 || unitCost < 0) {
        invSetResult("Please complete a valid stock-in form.", "error");
        return;
    }

    const payload = {
        store_id: invActiveStoreId,
        product_id: productId,
        qty,
        unit_cost: unitCost,
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
            `Stock-in successful. Cost: ${invMoney(data.total_cost)} | Expected Profit: ${invMoney(data.expected_profit)}`,
            "ok"
        );

        await invLoadProducts();
        await invLoadMovements();
    } catch (error) {
        invSetResult("Stock-in request failed.", "error");
    } finally {
        invIsSubmitting = false;
        button.disabled = false;
        button.textContent = "Submit Stock In";
    }
}

document.getElementById("inventory-store-select").addEventListener("change", async (event) => {
    invActiveStoreId = Number(event.target.value);
    invSetResult("", "ok");
    await invLoadProducts();
    await invLoadMovements();
});

document.getElementById("restock-product").addEventListener("change", invUpdateProjection);
document.getElementById("restock-qty").addEventListener("input", invUpdateProjection);
document.getElementById("restock-unit-cost").addEventListener("input", invUpdateProjection);
document.getElementById("restock-submit").addEventListener("click", invSubmitRestock);

document.getElementById("mov-apply").addEventListener("click", async () => {
    invFilters.type = document.getElementById("mov-type").value || "";
    invFilters.productId = document.getElementById("mov-product").value || "";
    invFilters.dateFrom = document.getElementById("mov-date-from").value || "";
    invFilters.dateTo = document.getElementById("mov-date-to").value || "";
    await invLoadMovements();
});

document.getElementById("mov-clear").addEventListener("click", async () => {
    invFilters.type = "";
    invFilters.productId = "";
    invFilters.dateFrom = "";
    invFilters.dateTo = "";

    document.getElementById("mov-type").value = "";
    document.getElementById("mov-product").value = "";
    document.getElementById("mov-date-from").value = "";
    document.getElementById("mov-date-to").value = "";
    await invLoadMovements();
});

(async () => {
    try {
        await invLoadStores();
        await invLoadProducts();
        await invLoadMovements();
    } catch (error) {
        invSetResult(error.message || "Unable to initialize inventory page.", "error");
    }
})();
