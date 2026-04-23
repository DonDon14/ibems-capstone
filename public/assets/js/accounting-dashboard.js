function acdMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function acdEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

let acdTrendChart = null;

function acdRenderTrend(rows) {
    const canvas = document.getElementById("acd-trend-chart");
    if (!canvas || typeof window.Chart === "undefined") return;

    if (acdTrendChart) {
        acdTrendChart.destroy();
        acdTrendChart = null;
    }

    if (!Array.isArray(rows) || rows.length === 0) return;

    const labels = rows.map((row) => String(row.date || "").slice(5));
    const amounts = rows.map((row) => Number(row.amount || 0));

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
                x: { grid: { display: false } },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback(value) {
                            return `PHP ${Number(value).toFixed(0)}`;
                        },
                    },
                },
            },
        },
    });
}

function acdRenderActivity(rows) {
    const container = document.getElementById("acd-activity");
    if (!container) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        container.innerHTML = '<div class="mini-bar-empty">No recent accounting activity.</div>';
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
        tbody.innerHTML = '<tr><td colspan="4">No debt accounts found.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map((row) => `
        <tr>
            <td>${acdEscape(row.name || "-")}<br><small>${acdEscape(row.employee_id || "-")}</small></td>
            <td>${acdEscape(row.email || "-")}</td>
            <td>${acdEscape(acdMoney(row.current_debt || 0))}</td>
            <td>${acdEscape(acdMoney(row.credit_limit || 0))}</td>
        </tr>
    `).join("");
}

async function acdLoad() {
    const totalAccountsEl = document.getElementById("acd-total-accounts");
    const withDebtEl = document.getElementById("acd-with-debt");
    const totalDebtEl = document.getElementById("acd-total-debt");
    const todayDeductedEl = document.getElementById("acd-today-deducted");
    const lastSettlementEl = document.getElementById("acd-last-settlement");

    try {
        const response = await fetch("/accounting/dashboard/data");
        const data = await response.json();
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Failed to load accounting dashboard.");
        }

        const summary = data.summary || {};
        if (totalAccountsEl) totalAccountsEl.textContent = String(Number(summary.total_accounts || 0));
        if (withDebtEl) withDebtEl.textContent = String(Number(summary.with_debt || 0));
        if (totalDebtEl) totalDebtEl.textContent = acdMoney(summary.total_debt || 0);
        if (todayDeductedEl) todayDeductedEl.textContent = acdMoney(summary.today_deduction_amount || 0);

        if (lastSettlementEl) {
            if (summary.last_settlement_month) {
                const runAt = summary.last_settlement_at ? ` (${summary.last_settlement_at})` : "";
                lastSettlementEl.textContent = `Last settlement: ${summary.last_settlement_month}${runAt}`;
            } else {
                lastSettlementEl.textContent = "Last settlement: No settlement run yet";
            }
        }

        acdRenderTrend(data.trend || []);
        acdRenderActivity(data.activity || []);
        acdRenderTopDebt(data.top_debt_accounts || []);
    } catch (error) {
        if (totalAccountsEl) totalAccountsEl.textContent = "-";
        if (withDebtEl) withDebtEl.textContent = "-";
        if (totalDebtEl) totalDebtEl.textContent = "PHP 0.00";
        if (todayDeductedEl) todayDeductedEl.textContent = "PHP 0.00";
        if (lastSettlementEl) lastSettlementEl.textContent = "Last settlement: unavailable";
        acdRenderTrend([]);
        acdRenderActivity([]);
        acdRenderTopDebt([]);
    }
}

acdLoad();
