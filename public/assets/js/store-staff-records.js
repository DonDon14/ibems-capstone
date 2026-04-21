let srStores = [];
let srStoreId = null;

function srEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function srMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function srSetResult(message, type) {
    const el = document.getElementById("staff-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function srRenderStoreSelect() {
    const select = document.getElementById("staff-store-select");
    const wrap = document.querySelector(".staff-store-wrap");

    select.innerHTML = srStores
        .map((store) => `<option value="${store.id}">${srEscape(store.store_name)}</option>`)
        .join("");
    select.value = String(srStoreId);
    const multi = srStores.length > 1;
    select.disabled = !multi;
    wrap.style.display = multi ? "flex" : "none";
}

async function srLoadStores() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();
    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }
    srStores = data.stores;
    srStoreId = Number(data.default_store_id || data.stores[0].id);
    srRenderStoreSelect();
}

function srRenderDebts(rows) {
    const body = document.getElementById("debt-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="6">No debt records found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${srEscape(row.employee_id || "-")}</td>
            <td>${srEscape(row.name)}</td>
            <td>${srEscape(row.email)}</td>
            <td>${srEscape(srMoney(row.current_debt))}</td>
            <td>${srEscape(srMoney(row.credit_limit))}</td>
            <td>${srEscape(srMoney(row.available_credit))}</td>
        </tr>
    `).join("");
}

function srRenderTransactions(rows) {
    const body = document.getElementById("txn-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="6">No staff transactions found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${srEscape(new Date(row.created_at).toLocaleString())}</td>
            <td>${srEscape(row.staff.name)}</td>
            <td>${srEscape(row.staff.employee_id || "-")}</td>
            <td>${srEscape(String(row.payment_method || "").toUpperCase())}</td>
            <td>${srEscape(srMoney(row.amount))}</td>
            <td>${srEscape(srMoney(row.staff.current_debt))}</td>
        </tr>
    `).join("");
}

async function srLoadDebtRecords() {
    const q = document.getElementById("debt-search").value.trim();
    const params = new URLSearchParams();
    if (q) params.set("q", q);

    const response = await fetch(`/store/debt-customers?${params.toString()}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        srSetResult(data?.message || "Unable to load debt records.", "error");
        srRenderDebts([]);
        return;
    }
    srRenderDebts(data.customers);
}

async function srLoadStaffTransactions() {
    const params = new URLSearchParams({
        store_id: String(srStoreId),
        limit: "120",
    });

    const q = document.getElementById("txn-search").value.trim();
    const dateFrom = document.getElementById("txn-date-from").value || "";
    const dateTo = document.getElementById("txn-date-to").value || "";
    const debtOnly = document.getElementById("txn-debt-only").checked;

    if (q) params.set("q", q);
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    if (debtOnly) params.set("debt_only", "1");

    const response = await fetch(`/store/staff-transactions?${params.toString()}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        srSetResult(data?.message || "Unable to load staff transactions.", "error");
        srRenderTransactions([]);
        return;
    }
    srRenderTransactions(data.transactions);
}

document.getElementById("debt-search-btn").addEventListener("click", async () => {
    srSetResult("", "ok");
    await srLoadDebtRecords();
});

document.getElementById("txn-search-btn").addEventListener("click", async () => {
    srSetResult("", "ok");
    await srLoadStaffTransactions();
});

document.getElementById("staff-store-select").addEventListener("change", async (event) => {
    srStoreId = Number(event.target.value);
    await srLoadStaffTransactions();
});

(async () => {
    try {
        await srLoadStores();
        await srLoadDebtRecords();
        await srLoadStaffTransactions();
    } catch (error) {
        srSetResult(error.message || "Unable to load staff records.", "error");
    }
})();
