function acdMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function acdEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function acdDataState(title, detail = "", icon = "bi-inbox") {
    return `
        <div class="data-state">
            <i class="bi ${acdEscape(icon)}" aria-hidden="true"></i>
            <div>
                <strong>${acdEscape(title)}</strong>
                ${detail ? `<small>${acdEscape(detail)}</small>` : ""}
            </div>
        </div>`;
}

function acdTableState(title, detail = "", icon = "bi-inbox") {
    return `<tr class="data-state-row"><td colspan="4">${acdDataState(title, detail, icon)}</td></tr>`;
}

let acdTrendChart = null;
let acdTrendRows = [];

function acdRenderTrend(rows) {
    const canvas = document.getElementById("acd-trend-chart");
    if (!canvas || typeof window.Chart === "undefined") return;
    const wrap = canvas.parentElement;
    acdTrendRows = Array.isArray(rows) ? rows : [];
    wrap?.querySelector(".acd-trend-empty")?.remove();

    if (acdTrendChart) {
        acdTrendChart.destroy();
        acdTrendChart = null;
    }

    if (!Array.isArray(rows) || rows.length === 0) {
        canvas.hidden = true;
        return;
    }

    const labels = rows.map((row) => String(row.date || "").slice(5));
    const amounts = rows.map((row) => Number(row.amount || 0));
    if (!amounts.some((amount) => amount > 0)) {
        canvas.hidden = true;
        const empty = document.createElement("div");
        empty.className = "acd-trend-empty";
        empty.innerHTML = acdDataState("No confirmed deductions", "No payroll deductions were confirmed in the selected period.", "bi-bar-chart");
        wrap?.appendChild(empty);
        return;
    }
    canvas.hidden = false;

    const isDark = document.documentElement.dataset.theme === "dark";
    const tickColor = isDark ? "#c6d2e1" : "#475569";
    const gridColor = isDark ? "rgba(148, 163, 184, 0.18)" : "rgba(148, 163, 184, 0.24)";

    acdTrendChart = new window.Chart(canvas, {
        type: "bar",
        data: {
            labels,
            datasets: [{
                label: "Deducted Amount",
                data: amounts,
                borderRadius: 10,
                maxBarThickness: 34,
                backgroundColor: "rgba(37, 99, 235, 0.82)",
                borderColor: "rgba(30, 64, 175, 1)",
                borderWidth: 1,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label(context) {
                            return acdMoney(context.parsed.y || 0);
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: tickColor } },
                y: {
                    beginAtZero: true,
                    grid: { color: gridColor },
                    ticks: {
                        color: tickColor,
                        callback(value) {
                            return window.IbemsFormat?.money(value, { decimals: 0 }) || `PHP ${Number(value).toFixed(0)}`;
                        },
                    },
                },
            },
        },
    });
}

window.addEventListener("ibems:themechange", () => acdRenderTrend(acdTrendRows));

function acdRenderActivity(rows) {
    const container = document.getElementById("acd-activity");
    if (!container) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        container.innerHTML = acdDataState("No recent accounting activity", "Completed Accounting actions will appear here.", "bi-clock-history");
        return;
    }

    container.innerHTML = rows.map((row) => {
        const amount = Number(row.amount || 0);
        const amountText = amount > 0 ? acdMoney(amount) : "-";
        const actor = row.actor_name ? acdEscape(row.actor_name) : "Unknown";
        const target = row.target_name ? ` · ${acdEscape(row.target_name)}` : "";
        const when = row.created_at ? acdEscape(String(row.created_at).replace("T", " ")) : "-";
        return `
            <div class="stack-item">
                <div class="stack-item-head">
                    <strong>${acdEscape(row.label || "Activity")}</strong>
                    <span>${acdEscape(amountText)}</span>
                </div>
                <div class="stack-meta">${actor}${target} · ${when}</div>
            </div>
        `;
    }).join("");
}

function acdRenderTopDebt(rows) {
    const tbody = document.getElementById("acd-top-debt-body");
    if (!tbody) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        tbody.innerHTML = acdTableState("No debt accounts found", "Accounts with outstanding debt will appear here.", "bi-cash-stack");
        return;
    }

    tbody.innerHTML = rows.map((row) => `
        <tr>
            <td><span class="table-person-cell">${window.IbemsAvatar.html(row.name, row.profile_image_url, "table-person-avatar")}<span><strong>${acdEscape(row.name || "-")}</strong><small>${acdEscape(row.employee_id || "-")}</small></span></span></td>
            <td>${acdEscape(row.email || "-")}</td>
            <td>${acdEscape(acdMoney(row.current_debt || 0))}</td>
            <td>${acdEscape(acdMoney(row.credit_limit || 0))}</td>
        </tr>
    `).join("");
}

function acdDashboardMeta(items) {
    return `
        <div class="dashboard-meta-line">
            ${items.map((item) => `
                <span class="dashboard-meta-item">
                    <i class="bi ${acdEscape(item.icon || "bi-dot")}" aria-hidden="true"></i>
                    <span>${acdEscape(item.text || "-")}</span>
                </span>
            `).join("")}
        </div>
    `;
}

function acdAlertItem(title, detailItems, tone = "warning") {
    return `
        <div class="stack-item acd-alert-item is-${acdEscape(tone)}">
            <div class="stack-item-head">
                <strong>${acdEscape(title)}</strong>
            </div>
            <div class="stack-meta">${acdDashboardMeta(detailItems)}</div>
        </div>
    `;
}

function acdRenderAlerts(alerts) {
    const container = document.getElementById("acd-alerts");
    if (!container) return;

    const safe = alerts || {};
    const overLimit = Array.isArray(safe.over_limit) ? safe.over_limit : [];
    const staleDebts = Array.isArray(safe.stale_debts) ? safe.stale_debts : [];
    const failedImports = Array.isArray(safe.failed_imports) ? safe.failed_imports : [];
    const html = [];

    overLimit.forEach((row) => {
        html.push(acdAlertItem(
            `Over limit: ${row.name || "Employee"}`,
            [
                { icon: "bi-person-vcard", text: row.employee_id || "-" },
                { icon: "bi-wallet2", text: `Debt ${acdMoney(row.current_debt || 0)} / Limit ${acdMoney(row.credit_limit || 0)}` },
                { icon: "bi-exclamation-triangle", text: `Over ${acdMoney(row.over_amount || 0)}` },
            ],
            "danger"
        ));
    });

    staleDebts.forEach((row) => {
        const lastActivity = row.last_cashbook_at ? String(row.last_cashbook_at) : "No cashbook activity";
        html.push(acdAlertItem(
            `Stale debt: ${row.name || "Employee"}`,
            [
                { icon: "bi-person-vcard", text: row.employee_id || "-" },
                { icon: "bi-wallet2", text: `Debt ${acdMoney(row.current_debt || 0)}` },
                { icon: "bi-clock-history", text: `Last activity: ${lastActivity}` },
            ],
            "warning"
        ));
    });

    failedImports.forEach((row) => {
        html.push(acdAlertItem(
            `Import needs review: ${row.filename || "CSV import"}`,
            [
                { icon: "bi-table", text: `${row.invalid_rows || 0} invalid of ${row.total_rows || 0} rows` },
                { icon: "bi-person", text: `Imported by ${row.imported_by_name || "Unknown"}` },
                { icon: "bi-calendar3", text: row.imported_at || "-" },
            ],
            "info"
        ));
    });

    if (html.length === 0) {
        container.innerHTML = acdDataState("No accounting alerts", "No over-limit, stale-debt, or failed-import alerts were detected.", "bi-shield-check");
        return;
    }

    container.innerHTML = html.join("");
}

async function acdLoad() {
    const totalAccountsEl = document.getElementById("acd-total-accounts");
    const withDebtEl = document.getElementById("acd-with-debt");
    const totalDebtEl = document.getElementById("acd-total-debt");
    const todayDeductedEl = document.getElementById("acd-today-deducted");
    const overLimitEl = document.getElementById("acd-over-limit");
    const lastSettlementEl = document.getElementById("acd-last-settlement");

    try {
        const period = window.IbemsDashboardPeriod?.get() || "day";
        const response = await fetch(`/accounting/dashboard/data?period=${encodeURIComponent(period)}`);
        const data = await response.json();
        if ((window.IbemsDashboardPeriod?.get() || "day") !== period) return;
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Failed to load accounting dashboard.");
        }

        const summary = data.summary || {};
        window.IbemsDashboardPeriod?.setMeta(data.period);
        if (totalAccountsEl) totalAccountsEl.textContent = String(Number(summary.total_accounts || 0));
        if (withDebtEl) withDebtEl.textContent = String(Number(summary.with_debt || 0));
        if (totalDebtEl) totalDebtEl.textContent = acdMoney(summary.total_debt || 0);
        if (todayDeductedEl) todayDeductedEl.textContent = acdMoney(summary.period_deduction_amount || summary.today_deduction_amount || 0);
        if (overLimitEl) overLimitEl.textContent = String(Number(summary.over_limit_count || 0));

        if (lastSettlementEl) {
            if (summary.last_deduction_period) {
                const status = String(summary.last_deduction_status || "").replace(/_/g, " ");
                lastSettlementEl.textContent = `Latest deduction period: ${summary.last_deduction_period} · ${status || "draft"}`;
            } else {
                lastSettlementEl.textContent = "Latest deduction period: None created yet";
            }
        }

        acdRenderTrend(data.trend || []);
        acdRenderActivity(data.activity || []);
        acdRenderTopDebt(data.top_debt_accounts || []);
        acdRenderAlerts(data.alerts || {});
    } catch (error) {
        if (totalAccountsEl) totalAccountsEl.textContent = "-";
        if (withDebtEl) withDebtEl.textContent = "-";
        if (totalDebtEl) totalDebtEl.textContent = "PHP 0.00";
        if (todayDeductedEl) todayDeductedEl.textContent = "PHP 0.00";
        if (overLimitEl) overLimitEl.textContent = "0";
        if (lastSettlementEl) lastSettlementEl.textContent = "Latest deduction period: unavailable";
        acdRenderTrend([]);
        acdRenderActivity([]);
        acdRenderTopDebt([]);
        acdRenderAlerts({});
    }
}

acdLoad();
window.addEventListener("ibems:dashboard-period-change", acdLoad);
