let adSalesTrendChart = null;
let adTopItemsChart = null;

function adMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function adEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function adDestroyCharts() {
    if (adSalesTrendChart) {
        adSalesTrendChart.destroy();
        adSalesTrendChart = null;
    }
    if (adTopItemsChart) {
        adTopItemsChart.destroy();
        adTopItemsChart = null;
    }
}

function adRenderSalesTrendChart(rows) {
    const canvas = document.getElementById("ad-sales-trend-chart");
    if (!canvas || typeof window.Chart === "undefined") return;

    const labels = Array.isArray(rows) ? rows.map((row) => String(row.date || "").slice(5)) : [];
    const sales = Array.isArray(rows) ? rows.map((row) => Number(row.sales || 0)) : [];

    adSalesTrendChart = new window.Chart(canvas, {
        type: "line",
        data: {
            labels,
            datasets: [
                {
                    label: "Revenue",
                    data: sales,
                    borderColor: "#1d4ed8",
                    backgroundColor: "rgba(37, 99, 235, 0.14)",
                    tension: 0.34,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 4,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label(context) {
                            return adMoney(context.parsed.y);
                        },
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback(value) {
                            return adMoney(value);
                        },
                    },
                },
            },
        },
    });
}

function adRenderTopItemsChart(rows) {
    const canvas = document.getElementById("ad-top-items-chart");
    if (!canvas || typeof window.Chart === "undefined") return;

    const labels = Array.isArray(rows) ? rows.map((row) => String(row.name || "Item")) : [];
    const qty = Array.isArray(rows) ? rows.map((row) => Number(row.qty_sold || 0)) : [];

    adTopItemsChart = new window.Chart(canvas, {
        type: "bar",
        data: {
            labels,
            datasets: [
                {
                    label: "Units Sold",
                    data: qty,
                    backgroundColor: "rgba(14, 165, 233, 0.72)",
                    borderColor: "#0284c7",
                    borderWidth: 1,
                    borderRadius: 8,
                },
            ],
        },
        options: {
            indexAxis: "y",
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                },
            },
        },
    });
}

function adRenderTopStores(rows) {
    const body = document.getElementById("ad-top-stores-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="3">No store sales yet.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${adEscape(row.store_name || "Store")}</td>
            <td>${Number(row.transactions || 0)}</td>
            <td>${adEscape(adMoney(row.sales || 0))}</td>
        </tr>
    `).join("");
}

async function adLoadDashboard() {
    const totalStoresEl = document.getElementById("ad-total-stores");
    const activeOfficersEl = document.getElementById("ad-active-store-officers");
    const debtAccountsEl = document.getElementById("ad-debt-accounts");
    const openAlertsEl = document.getElementById("ad-open-alerts");
    const healthMessageEl = document.getElementById("ad-health-message");
    const healthBreakdownEl = document.getElementById("ad-health-breakdown");

    try {
        const response = await fetch("/admin/dashboard/data");
        const data = await response.json();
        if (!response.ok || !data || data.status !== "success") {
            throw new Error(data?.message || "Failed to load dashboard summary.");
        }

        const summary = data.summary || {};
        totalStoresEl.textContent = String(Number(summary.total_stores || 0));
        activeOfficersEl.textContent = String(Number(summary.active_store_officers || 0));
        debtAccountsEl.textContent = String(Number(summary.debt_accounts || 0));
        openAlertsEl.textContent = String(Number(summary.open_alerts || 0));

        healthMessageEl.textContent = (data.health && data.health.message) ? data.health.message : "No health data available.";
        healthBreakdownEl.textContent = [
            `Today Txns: ${Number(summary.today_transactions || 0)}`,
            `Today Sales: ${adMoney(summary.today_sales || 0)}`,
            `Total Debt: ${adMoney(summary.total_debt || 0)}`,
        ].join(" | ");

        const analytics = data.analytics || {};
        adDestroyCharts();
        adRenderSalesTrendChart(analytics.trend || []);
        adRenderTopItemsChart(analytics.top_selling_items || []);
        adRenderTopStores(analytics.top_stores || []);
    } catch (error) {
        totalStoresEl.textContent = "-";
        activeOfficersEl.textContent = "-";
        debtAccountsEl.textContent = "-";
        openAlertsEl.textContent = "-";
        healthMessageEl.textContent = "Unable to load operations status right now.";
        healthBreakdownEl.textContent = "";
        adDestroyCharts();
        adRenderTopStores([]);
    }
}

adLoadDashboard();
