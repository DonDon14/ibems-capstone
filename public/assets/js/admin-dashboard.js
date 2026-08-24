let adSalesTrendChart = null;
let adSalesTrendRows = [];
let adAlertPage = 1;
let adAlertsLoading = false;

function adMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function adCompactMoney(value) {
    return new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP",
        minimumFractionDigits: 0,
        maximumFractionDigits: Number(value || 0) < 100 ? 1 : 0,
    }).format(Number(value || 0));
}

function adDateLabel(value, options = { month: "short", day: "numeric" }) {
    const parts = String(value || "").split("-").map(Number);
    if (parts.length !== 3 || parts.some((part) => !Number.isFinite(part))) return String(value || "-");
    return new Intl.DateTimeFormat("en-PH", options).format(new Date(parts[0], parts[1] - 1, parts[2]));
}

function adChartPalette() {
    const dark = document.documentElement.dataset.theme === "dark";
    return {
        line: dark ? "#70a8ff" : "#2563eb",
        fill: dark ? "rgba(69, 133, 230, 0.18)" : "rgba(37, 99, 235, 0.10)",
        grid: dark ? "rgba(148, 163, 184, 0.13)" : "rgba(148, 163, 184, 0.20)",
        ticks: dark ? "#aebed1" : "#64748b",
        tooltipBackground: dark ? "#e8eef8" : "#0f172a",
        tooltipText: dark ? "#0f172a" : "#f8fafc",
        tooltipBorder: dark ? "#c7d3e3" : "#1e293b",
    };
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
}

function adRenderSalesTrendChart(rows) {
    const canvas = document.getElementById("ad-sales-trend-chart");
    if (!canvas || typeof window.Chart === "undefined") return;

    adSalesTrendRows = Array.isArray(rows) ? rows.map((row) => ({ ...row })) : [];
    const labels = adSalesTrendRows.map((row) => adDateLabel(row.date));
    const sales = adSalesTrendRows.map((row) => Number(row.sales || 0));
    const palette = adChartPalette();
    const total = sales.reduce((sum, value) => sum + value, 0);
    const peakIndex = sales.indexOf(Math.max(...sales, 0));
    const peakValue = peakIndex >= 0 ? sales[peakIndex] : 0;
    const totalEl = document.getElementById("ad-sales-total");
    const peakEl = document.getElementById("ad-sales-peak");
    const summaryEl = document.getElementById("ad-sales-chart-summary");

    if (totalEl) totalEl.textContent = adMoney(total);
    if (peakEl) {
        peakEl.textContent = peakValue > 0
            ? `Peak ${adMoney(peakValue)} on ${adDateLabel(adSalesTrendRows[peakIndex]?.date, { month: "short", day: "numeric", year: "numeric" })}`
            : "No revenue recorded yet.";
    }
    if (summaryEl) {
        summaryEl.textContent = total > 0
            ? `Total revenue was ${adMoney(total)}. ${peakEl?.textContent || ""}`
            : "No revenue was recorded during the last seven days.";
    }

    adSalesTrendChart = new window.Chart(canvas, {
        type: "line",
        data: {
            labels,
            datasets: [
                {
                    label: "Revenue",
                    data: sales,
                    borderColor: palette.line,
                    backgroundColor: palette.fill,
                    borderWidth: 2.5,
                    tension: 0,
                    fill: true,
                    pointRadius: sales.length <= 7 ? 3 : 0,
                    pointHoverRadius: 5,
                    pointBorderWidth: 2,
                    pointBorderColor: palette.line,
                    pointBackgroundColor: darkModeFillColor(),
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: "index", intersect: false },
            animation: { duration: 450 },
            layout: { padding: { top: 8, right: 8 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    displayColors: false,
                    backgroundColor: palette.tooltipBackground,
                    titleColor: palette.tooltipText,
                    bodyColor: palette.tooltipText,
                    borderColor: palette.tooltipBorder,
                    borderWidth: 1,
                    cornerRadius: 10,
                    padding: 11,
                    callbacks: {
                        title(items) {
                            const index = items[0]?.dataIndex ?? 0;
                            return adDateLabel(adSalesTrendRows[index]?.date, { month: "long", day: "numeric", year: "numeric" });
                        },
                        label(context) {
                            return `Revenue  ${adMoney(context.parsed.y)}`;
                        },
                    },
                },
            },
            scales: {
                x: {
                    border: { display: false },
                    grid: { display: false },
                    ticks: { color: palette.ticks, maxRotation: 0, autoSkip: false },
                },
                y: {
                    beginAtZero: true,
                    border: { display: false },
                    grid: { color: palette.grid, drawTicks: false },
                    ticks: {
                        color: palette.ticks,
                        maxTicksLimit: 5,
                        padding: 10,
                        callback(value) {
                            return adCompactMoney(value);
                        },
                    },
                },
            },
        },
    });
}

function darkModeFillColor() {
    return document.documentElement.dataset.theme === "dark" ? "#14243b" : "#ffffff";
}

function adRenderTopItemsRanking(rows) {
    const container = document.getElementById("ad-top-items-ranking");
    if (!container) return;
    const items = Array.isArray(rows) ? rows.slice(0, 5) : [];
    container.removeAttribute("aria-busy");

    if (items.length === 0) {
        container.removeAttribute("role");
        container.innerHTML = adDataState("empty", "No product sales in the last seven days.");
        return;
    }

    container.setAttribute("role", "list");
    const highestQty = Math.max(...items.map((row) => Number(row.qty_sold || 0)), 1);
    container.innerHTML = items.map((row, index) => {
        const quantity = Math.max(0, Number(row.qty_sold || 0));
        const sales = Math.max(0, Number(row.sales || 0));
        const progress = Math.max(quantity > 0 ? 8 : 0, Math.round((quantity / highestQty) * 100));
        const unitLabel = `${quantity} unit${quantity === 1 ? "" : "s"}`;
        return `
            <article class="dashboard-rank-item" role="listitem">
                <div class="dashboard-rank-row">
                    <span class="dashboard-rank-number" aria-label="Rank ${index + 1}">${index + 1}</span>
                    <div class="dashboard-rank-copy">
                        <strong title="${adEscape(row.name || "Item")}">${adEscape(row.name || "Item")}</strong>
                        <small>${adEscape(unitLabel)} <span aria-hidden="true">·</span> ${adEscape(adMoney(sales))}</small>
                    </div>
                    <strong class="dashboard-rank-value">${quantity}</strong>
                </div>
                <div class="dashboard-rank-track" aria-hidden="true">
                    <i style="width: ${progress}%;"></i>
                </div>
            </article>
        `;
    }).join("");
}

function adDataState(type, message, colspan = 0) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    const content = `<div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${adEscape(message)}</strong></div></div>`;
    return colspan > 0 ? `<tr class="data-state-row"><td colspan="${Number(colspan)}">${content}</td></tr>` : content;
}

function adRenderTopStores(rows) {
    const body = document.getElementById("ad-top-stores-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = adDataState("empty", "No store sales yet.", 3);
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

function adPaymentLabel(value) {
    return String(value || "UNKNOWN")
        .toLowerCase()
        .replace(/_/g, " ")
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function adRenderStoreDayStatus(summary) {
    const container = document.getElementById("ad-store-day-status");
    if (!container) return;

    const rows = [
        { label: "Open Today", value: Number(summary.stores_open_today || 0), tone: "ready" },
        { label: "Closed Today", value: Number(summary.stores_closed_today || 0), tone: "neutral" },
        { label: "Not Opened", value: Number(summary.stores_not_open_today || 0), tone: Number(summary.stores_not_open_today || 0) > 0 ? "warning" : "ready" },
    ];

    container.innerHTML = rows.map((row) => `
        <div class="admin-status-pill is-${adEscape(row.tone)}">
            <span>${adEscape(row.label)}</span>
            <strong>${row.value}</strong>
        </div>
    `).join("");
}

function adRenderAlerts(rows) {
    const container = document.getElementById("ad-alerts-list");
    if (!container) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        container.innerHTML = adDataState("success", "No operational alerts right now.");
        return;
    }

    container.innerHTML = rows.map((row) => {
        const href = String(row.href || "").trim();
        const tag = href ? "a" : "div";
        const hrefAttr = href ? ` href="${adEscape(href)}"` : "";
        const detailItems = Array.isArray(row.detail_items) ? row.detail_items : [];
        const detail = detailItems.length > 0
            ? `<span class="dashboard-meta-line">${detailItems.map((item) => `
                <span class="dashboard-meta-item">
                    <i class="bi ${adEscape(item.icon || "bi-dot")}" aria-hidden="true"></i>
                    <span>${adEscape(item.text || "-")}</span>
                </span>
            `).join("")}</span>`
            : adEscape(row.detail || "");
        return `
            <${tag}${hrefAttr} class="admin-alert-item is-${adEscape(row.tone || "warning")}">
                <span class="admin-alert-label">${adEscape(row.label || "Alert")}</span>
                <span class="admin-alert-copy">
                    <strong>${adEscape(row.title || "Attention needed")}</strong>
                    <small>${detail}</small>
                </span>
                ${href ? '<i class="bi bi-arrow-right"></i>' : ""}
            </${tag}>
        `;
    }).join("");
}

function adRenderAlertPagination(pagination = {}) {
    const pager = document.getElementById("ad-alerts-pager");
    const summary = document.getElementById("ad-alerts-summary");
    if (!pager || !summary) return;

    const page = Math.max(1, Number(pagination.page || 1));
    const totalPages = Math.max(1, Number(pagination.total_pages || 1));
    const total = Math.max(0, Number(pagination.total || 0));
    adAlertPage = page;
    summary.textContent = total === 1 ? "1 alert" : `${total} alerts`;

    if (total === 0 || totalPages <= 1) {
        pager.innerHTML = "";
        return;
    }

    pager.innerHTML = `
        <button class="secondary-btn btn-sm" type="button" data-alert-page="${page - 1}" aria-label="Previous alert page" ${pagination.has_previous ? "" : "disabled"}><i class="bi bi-chevron-left" aria-hidden="true"></i> Previous</button>
        <span>Page ${page} of ${totalPages}</span>
        <button class="secondary-btn btn-sm" type="button" data-alert-page="${page + 1}" aria-label="Next alert page" ${pagination.has_next ? "" : "disabled"}>Next <i class="bi bi-chevron-right" aria-hidden="true"></i></button>
    `;
}

function adRenderPaymentBreakdown(rows) {
    const container = document.getElementById("ad-payment-breakdown");
    if (!container) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        container.innerHTML = adDataState("empty", "No payments recorded in the last 7 days.");
        return;
    }

    const totalSales = rows.reduce((sum, row) => sum + Number(row.sales || 0), 0);
    container.innerHTML = rows.map((row) => {
        const sales = Number(row.sales || 0);
        const pct = totalSales > 0 ? Math.round((sales / totalSales) * 100) : 0;
        return `
            <div class="admin-payment-item">
                <div class="admin-payment-row">
                    <strong>${adEscape(adPaymentLabel(row.payment_method))}</strong>
                    <span>${adEscape(adMoney(sales))}</span>
                </div>
                <div class="admin-payment-meta">
                    <span>${Number(row.transactions || 0)} transaction${Number(row.transactions || 0) === 1 ? "" : "s"}</span>
                    <span>${pct}%</span>
                </div>
                <div class="admin-payment-track" aria-hidden="true">
                    <i style="width: ${pct}%;"></i>
                </div>
            </div>
        `;
    }).join("");
}

async function adFetchDashboard(alertsPage = 1) {
    const query = new URLSearchParams({ alerts_page: String(Math.max(1, Number(alertsPage || 1))) });
    const response = await fetch(`/admin/dashboard/data?${query.toString()}`);
    const data = await response.json();
    if (!response.ok || !data || data.status !== "success") {
        throw new Error(data?.message || "Failed to load dashboard summary.");
    }
    return data;
}

async function adLoadAlertPage(page) {
    if (adAlertsLoading || page < 1 || page === adAlertPage) return;
    const container = document.getElementById("ad-alerts-list");
    const pager = document.getElementById("ad-alerts-pager");
    adAlertsLoading = true;
    pager?.querySelectorAll("button").forEach((button) => { button.disabled = true; });
    container?.setAttribute("aria-busy", "true");

    try {
        const data = await adFetchDashboard(page);
        adRenderAlerts(data.alerts || []);
        adRenderAlertPagination(data.alerts_pagination || {});
    } catch (error) {
        if (container) container.innerHTML = adDataState("error", "Unable to load this alert page.");
    } finally {
        adAlertsLoading = false;
        container?.removeAttribute("aria-busy");
    }
}

async function adLoadDashboard() {
    const totalStoresEl = document.getElementById("ad-total-stores");
    const activeOfficersEl = document.getElementById("ad-active-store-officers");
    const debtAccountsEl = document.getElementById("ad-debt-accounts");
    const openAlertsEl = document.getElementById("ad-open-alerts");
    const healthMessageEl = document.getElementById("ad-health-message");
    const healthBreakdownEl = document.getElementById("ad-health-breakdown");

    try {
        const data = await adFetchDashboard(1);

        const summary = data.summary || {};
        totalStoresEl.textContent = String(Number(summary.total_stores || 0));
        activeOfficersEl.textContent = String(Number(summary.active_store_officers || 0));
        debtAccountsEl.textContent = String(Number(summary.debt_accounts || 0));
        openAlertsEl.textContent = String(Number(summary.open_alerts || 0));

        const health = data.health || {};
        const healthItems = Array.isArray(health.items) ? health.items : [];
        healthMessageEl.innerHTML = healthItems.length > 0
            ? `
                <span class="dashboard-health-message-lead"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> Attention needed</span>
                <span class="dashboard-meta-line">
                    ${healthItems.map((item) => `
                        <span class="dashboard-meta-item">
                            <i class="bi ${adEscape(item.icon || "bi-dot")}" aria-hidden="true"></i>
                            <span>${adEscape(item.text || "-")}</span>
                        </span>
                    `).join("")}
                </span>
            `
            : adEscape(health.message || "No health data available.");
        healthBreakdownEl.innerHTML = `
            <span class="dashboard-meta-line">
                <span class="dashboard-meta-item"><i class="bi bi-receipt" aria-hidden="true"></i><span>Today Transactions: ${Number(summary.today_transactions || 0)}</span></span>
                <span class="dashboard-meta-item"><i class="bi bi-cash-coin" aria-hidden="true"></i><span>Today Sales: ${adEscape(adMoney(summary.today_sales || 0))}</span></span>
                <span class="dashboard-meta-item"><i class="bi bi-wallet2" aria-hidden="true"></i><span>Total Debt: ${adEscape(adMoney(summary.total_debt || 0))}</span></span>
            </span>
        `;
        adRenderStoreDayStatus(summary);
        adRenderAlerts(data.alerts || []);
        adRenderAlertPagination(data.alerts_pagination || {});

        const analytics = data.analytics || {};
        adDestroyCharts();
        adRenderSalesTrendChart(analytics.trend || []);
        adRenderTopItemsRanking(analytics.top_selling_items || []);
        adRenderTopStores(analytics.top_stores || []);
        adRenderPaymentBreakdown(analytics.payment_breakdown || []);
    } catch (error) {
        totalStoresEl.textContent = "-";
        activeOfficersEl.textContent = "-";
        debtAccountsEl.textContent = "-";
        openAlertsEl.textContent = "-";
        healthMessageEl.textContent = "Unable to load operations status right now.";
        healthBreakdownEl.textContent = "";
        adRenderStoreDayStatus({});
        document.getElementById("ad-alerts-list").innerHTML = adDataState("error", "Unable to load operational alerts.");
        document.getElementById("ad-alerts-summary").textContent = "Unavailable";
        document.getElementById("ad-alerts-pager").innerHTML = "";
        document.getElementById("ad-payment-breakdown").innerHTML = adDataState("error", "Unable to load payment breakdown.");
        const rankingEl = document.getElementById("ad-top-items-ranking");
        rankingEl.removeAttribute("role");
        rankingEl.removeAttribute("aria-busy");
        rankingEl.innerHTML = adDataState("error", "Unable to load product ranking.");
        adDestroyCharts();
        document.getElementById("ad-top-stores-body").innerHTML = adDataState("error", "Unable to load top stores.", 3);
    }
}

document.getElementById("ad-alerts-pager")?.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-alert-page]");
    if (!button || button.disabled) return;
    adLoadAlertPage(Number(button.dataset.alertPage || 1));
});

adLoadDashboard();

window.addEventListener("ibems:themechange", () => {
    if (adSalesTrendRows.length === 0) return;
    adSalesTrendChart?.destroy();
    adSalesTrendChart = null;
    adRenderSalesTrendChart(adSalesTrendRows);
});
