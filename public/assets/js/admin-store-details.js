function sdMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function sdDateTime(value) {
    return new Date(value).toLocaleString();
}

function sdEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function sdInitials(text) {
    return String(text || "")
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => (word[0] || "").toUpperCase())
        .join("") || "PR";
}

async function loadStoreDetails() {
    const root = document.querySelector("[data-store-id]");
    if (!root) return;
    const storeId = Number(root.getAttribute("data-store-id") || 0);
    if (!storeId) return;

    const response = await fetch(`/admin/stores/${storeId}/data`);
    const data = await response.json();
    if (!data || data.status !== "success") return;

    const store = data.store || {};
    const summary = data.summary || {};

    document.getElementById("sd-store-name").textContent = sdEscape(store.store_name || "Store Details");
    document.getElementById("sd-store-meta").textContent =
        `${store.officer_name || "No officer"} • ${store.is_active ? "Active" : "Inactive"}`;
    document.getElementById("sd-txn-count").textContent = String(summary.txn_count || 0);
    document.getElementById("sd-sales-total").textContent = sdMoney(summary.sales_total || 0);
    document.getElementById("sd-product-count").textContent = String(summary.product_count || 0);
    document.getElementById("sd-stock-units").textContent = String(summary.stock_units || 0);

    const inventory = Array.isArray(data.inventory) ? data.inventory : [];
    const inventoryBody = document.getElementById("sd-inventory-body");
    if (inventory.length === 0) {
        inventoryBody.innerHTML = '<tr><td colspan="3">No products in this store.</td></tr>';
    } else {
        inventoryBody.innerHTML = inventory.map((row) => `
            <tr>
                <td>
                    <div class="inv-product">
                        ${row.image_url
                            ? `<img src="${sdEscape(row.image_url)}" alt="${sdEscape(row.name)}" class="inv-product-img">`
                            : `<div class="inv-product-fallback">${sdEscape(sdInitials(row.name))}</div>`
                        }
                        <span>${sdEscape(row.name)}</span>
                    </div>
                </td>
                <td>${Number(row.stock_qty || 0)}</td>
                <td>${sdEscape(sdMoney(row.price || 0))}</td>
            </tr>
        `).join("");
    }

    const txns = Array.isArray(data.recent_transactions) ? data.recent_transactions : [];
    const txnBody = document.getElementById("sd-transactions-body");
    if (txns.length === 0) {
        txnBody.innerHTML = '<tr><td colspan="4">No transactions yet.</td></tr>';
    } else {
        txnBody.innerHTML = txns.map((row) => `
            <tr>
                <td>${sdEscape(sdDateTime(row.created_at))}</td>
                <td>${sdEscape(row.customer_name || "Walk-in")}</td>
                <td>${sdEscape(String(row.payment_method || "").toUpperCase())}</td>
                <td>${sdEscape(sdMoney(row.amount || 0))}</td>
            </tr>
        `).join("");
    }
}

loadStoreDetails();
