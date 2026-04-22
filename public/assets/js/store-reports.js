let reportsStores = [];
let reportsActiveStoreId = null;
let reportsPeriod = "today";

function rMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function rEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function rSetResult(message, type) {
    const el = document.getElementById("reports-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function rSetPeriod(nextPeriod) {
    reportsPeriod = nextPeriod;
    document.querySelectorAll(".period-chip").forEach((chip) => {
        chip.classList.toggle("is-active", chip.dataset.period === reportsPeriod);
    });
    document.getElementById("reports-custom-range").classList.toggle("hidden", reportsPeriod !== "custom");
}

function rRenderStoreSelect() {
    const select = document.getElementById("reports-store-select");
    const wrap = document.querySelector(".reports-store-wrap");
    select.innerHTML = reportsStores
        .map((store) => `<option value="${store.id}">${rEscape(store.store_name)}</option>`)
        .join("");
    select.value = String(reportsActiveStoreId);
    const multi = reportsStores.length > 1;
    select.disabled = !multi;
    wrap.style.display = multi ? "flex" : "none";
}

function rRenderSummary(data) {
    const summary = data?.summary || {};
    const stockIn = data?.stock_in || {};

    document.getElementById("sum-sales").textContent = rMoney(summary.total_sales || 0);
    document.getElementById("sum-cost").textContent = rMoney(summary.estimated_cost || 0);
    document.getElementById("sum-profit").textContent = rMoney(summary.estimated_profit || 0);
    document.getElementById("sum-margin").textContent = `${Number(summary.profit_margin_percent || 0).toFixed(2)}%`;
    document.getElementById("sum-transactions").textContent = String(summary.transactions || 0);
    document.getElementById("sum-items").textContent = String(summary.items_sold || 0);
    document.getElementById("sum-ticket").textContent = rMoney(summary.average_ticket || 0);
    document.getElementById("sum-stockin-cost").textContent = rMoney(stockIn.total_cost || 0);

    const note = data?.notes?.profit_basis || "";
    document.getElementById("reports-note").textContent = note;
}

function rRenderPaymentRows(rows) {
    const body = document.getElementById("payment-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="3">No payment data for this period.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${rEscape(String(row.payment_method || "-").toUpperCase())}</td>
            <td>${Number(row.transactions || 0)}</td>
            <td>${rEscape(rMoney(row.sales || 0))}</td>
        </tr>
    `).join("");
}

function rRenderProductRows(rows) {
    const body = document.getElementById("products-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="4">No product sales for this period.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${rEscape(row.name || "-")}<br><small>${rEscape(row.sku || "-")}</small></td>
            <td>${Number(row.qty_sold || 0)}</td>
            <td>${rEscape(rMoney(row.revenue || 0))}</td>
            <td>${rEscape(rMoney(row.estimated_profit || 0))}</td>
        </tr>
    `).join("");
}

function rRenderTrendRows(rows) {
    const body = document.getElementById("trend-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="3">No trend data for this period.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${rEscape(row.date || "-")}</td>
            <td>${Number(row.transactions || 0)}</td>
            <td>${rEscape(rMoney(row.sales || 0))}</td>
        </tr>
    `).join("");
}

async function rLoadStores() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();
    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }

    reportsStores = data.stores;
    reportsActiveStoreId = Number(data.default_store_id || reportsStores[0].id);
    rRenderStoreSelect();
}

async function rLoadSummary() {
    if (!reportsActiveStoreId) return;

    const params = new URLSearchParams({
        store_id: String(reportsActiveStoreId),
        period: reportsPeriod,
    });

    if (reportsPeriod === "custom") {
        const from = (document.getElementById("reports-date-from").value || "").trim();
        const to = (document.getElementById("reports-date-to").value || "").trim();
        if (!from || !to) {
            rSetResult("Please select date_from and date_to for custom range.", "error");
            return;
        }
        params.set("date_from", from);
        params.set("date_to", to);
    }

    const response = await fetch(`/store/reports/summary?${params.toString()}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        rSetResult(data?.message || "Unable to load report summary.", "error");
        rRenderPaymentRows([]);
        rRenderProductRows([]);
        rRenderTrendRows([]);
        return;
    }

    rRenderSummary(data);
    rRenderPaymentRows(data.payment_breakdown || []);
    rRenderProductRows(data.top_products || []);
    rRenderTrendRows(data.trend || []);
    rSetResult("", "ok");
}

document.getElementById("reports-store-select").addEventListener("change", async (event) => {
    reportsActiveStoreId = Number(event.target.value || 0);
    await rLoadSummary();
});

document.querySelectorAll(".period-chip").forEach((chip) => {
    chip.addEventListener("click", async () => {
        rSetPeriod(chip.dataset.period || "today");
        await rLoadSummary();
    });
});

document.getElementById("reports-refresh-btn").addEventListener("click", async () => {
    await rLoadSummary();
});

document.getElementById("reports-date-from").addEventListener("change", async () => {
    if (reportsPeriod === "custom") await rLoadSummary();
});

document.getElementById("reports-date-to").addEventListener("change", async () => {
    if (reportsPeriod === "custom") await rLoadSummary();
});

(async () => {
    try {
        await rLoadStores();
        rSetPeriod("today");
        await rLoadSummary();
    } catch (error) {
        rSetResult(error?.message || "Failed to initialize reports.", "error");
    }
})();
