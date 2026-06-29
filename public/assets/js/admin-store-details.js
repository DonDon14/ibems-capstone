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

function sdRenderDaySession(session) {
    const wrap = document.getElementById("sd-day-session");
    if (!wrap) return;

    if (!session) {
        wrap.innerHTML = '<div class="mini-bar-empty">No store day session recorded yet.</div>';
        return;
    }

    const status = String(session.status || "").toUpperCase() || "UNKNOWN";
    const openedBy = session.opened_by_name || "Unknown";
    const closedBy = session.closed_by_name || "-";
    wrap.innerHTML = `
        <div class="stack-item">
            <div class="stack-item-head">
                <strong>${sdEscape(session.business_date || "-")}</strong>
                <span>${sdEscape(status)}</span>
            </div>
            <div class="stack-meta">
                Opening: ${sdEscape(sdMoney(session.opening_cash || 0))} cash | ${sdEscape(sdMoney(session.opening_ecash || 0))} e-cash
            </div>
            <div class="stack-meta">
                Opened by ${sdEscape(openedBy)}${session.opened_at ? ` at ${sdEscape(sdDateTime(session.opened_at))}` : ""}
            </div>
            <div class="stack-meta">
                Closed by ${sdEscape(closedBy)}${session.closed_at ? ` at ${sdEscape(sdDateTime(session.closed_at))}` : ""}
            </div>
        </div>
    `;
}

function sdRenderOfficers(officers) {
    const wrap = document.getElementById("sd-officers");
    if (!wrap) return;

    const rows = Array.isArray(officers) ? officers : [];
    if (rows.length === 0) {
        wrap.innerHTML = '<div class="mini-bar-empty">No assigned officer.</div>';
        return;
    }

    wrap.innerHTML = rows.map((row) => `
        <div class="stack-item">
            <div class="stack-item-head">
                <strong>${sdEscape(row.name || "No assigned officer")}</strong>
                <span>${sdEscape(row.role || "Officer")}</span>
            </div>
            <div class="stack-meta">${sdEscape(row.email || "-")}</div>
        </div>
    `).join("");
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
    document.getElementById("sd-today-sales").textContent = sdMoney(summary.today_sales_total || 0);
    document.getElementById("sd-debt-txns").textContent = String(summary.today_debt_txn_count || 0);
    document.getElementById("sd-active-products").textContent = String(summary.active_products || 0);
    document.getElementById("sd-low-stock").textContent = String(summary.low_stock_count || 0);

    sdRenderDaySession(data.day_session || null);
    sdRenderOfficers(data.officers || []);

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
                <td>
                    ${Number(row.stock_qty || 0)}
                    ${Number(row.stock_qty || 0) <= Number(row.low_stock_threshold ?? 10) && row.is_active ? '<span class="table-chip status-warning">Low</span>' : ""}
                </td>
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
