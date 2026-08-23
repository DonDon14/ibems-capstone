(function () {
"use strict";

const uPageSignal = window.IbemsUserNavigation?.currentSignal || new AbortController().signal;
if (!document.getElementById("u-recent-transactions-body")) return;

function uMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function uEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function uEntryLabel(value) {
    const key = String(value || "").toLowerCase();
    if (key === "debt_purchase") return "Debt Purchase";
    if (key === "manual_deduction") return "Manual Deduction";
    if (key === "full_deduction") return "Full Deduction";
    if (key === "salary_deduction") return "Salary Deduction";
    if (key === "confirmed_salary_deduction") return "Confirmed Payroll Deduction";
    if (key === "investigation_reversal") return "Approved Debt Correction";
    if (key === "store_repayment") return "Store Payment";
    if (key === "operator_shortage") return "Store Shortage";
    return key.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
}

function uDataState(type, message, colspan = 0) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    const content = `<div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${uEscape(message)}</strong></div></div>`;
    return colspan > 0 ? `<tr class="data-state-row"><td colspan="${Number(colspan)}">${content}</td></tr>` : content;
}

let uTrendChart = null;

function uSetText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function uApplyDebtStatus(prefix, summary) {
    const status = String(summary.debt_status || "unpaid");
    const tone = String(summary.debt_status_tone || "info");
    const label = String(summary.debt_status_label || "Unpaid");
    const message = String(summary.debt_status_message || "You have an unpaid balance within your available credit limit.");
    const card = document.getElementById(`${prefix}-debt-status-card`);

    if (card) {
        card.classList.remove(
            "user-status-card--success",
            "user-status-card--info",
            "user-status-card--warning",
            "user-status-card--danger"
        );
        card.classList.add(`user-status-card--${tone}`);
        card.dataset.status = status;
    }

    uSetText(`${prefix}-debt-status-label`, label);
    uSetText(`${prefix}-debt-status-message`, message);
}

function uRenderCreditMeter(summary) {
    const creditLimit = Number(summary.credit_limit || 0);
    const currentDebt = Number(summary.current_debt || 0);
    const availableCredit = Number(summary.available_credit || 0);
    const safeLimit = creditLimit > 0 ? creditLimit : 1;
    const usage = Math.max(0, Math.min((currentDebt / safeLimit) * 100, 100));

    const percentEl = document.getElementById("u-credit-used-percent");
    const amountEl = document.getElementById("u-credit-used-amount");
    const statusEl = document.getElementById("u-credit-meter-status");
    const fillEl = document.getElementById("u-credit-meter-fill");

    if (percentEl) percentEl.textContent = `${usage.toFixed(1)}%`;
    if (amountEl) amountEl.textContent = `Used: ${uMoney(currentDebt)} of ${uMoney(creditLimit)}`;
    if (statusEl) statusEl.textContent = `Remaining: ${uMoney(availableCredit)}`;
    if (fillEl) {
        fillEl.style.width = `${usage}%`;
        fillEl.classList.remove("is-safe", "is-mid", "is-high");
        if (usage >= 80) {
            fillEl.classList.add("is-high");
        } else if (usage >= 50) {
            fillEl.classList.add("is-mid");
        } else {
            fillEl.classList.add("is-safe");
        }
    }
}

function uRenderTrend(rows) {
    const canvas = document.getElementById("u-trend-chart");
    if (!canvas || typeof window.Chart === "undefined") return;

    if (uTrendChart) {
        uTrendChart.destroy();
        uTrendChart = null;
    }

    if (!Array.isArray(rows) || rows.length === 0) return;

    const labels = rows.map((row) => String(row.date || "").slice(5));
    const amounts = rows.map((row) => Number(row.amount || 0));

    uTrendChart = new window.Chart(canvas, {
        type: "line",
        data: {
            labels,
            datasets: [{
                label: "Spending",
                data: amounts,
                tension: 0.35,
                fill: true,
                borderWidth: 2,
                borderColor: "rgba(29, 78, 216, 1)",
                backgroundColor: "rgba(59, 130, 246, 0.18)",
                pointRadius: 3,
                pointHoverRadius: 4,
                pointBackgroundColor: "rgba(29, 78, 216, 1)",
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
                            return uMoney(context.parsed.y || 0);
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
                            return window.IbemsFormat?.money(value, { decimals: 0 }) || `PHP ${Number(value).toFixed(0)}`;
                        },
                    },
                },
            },
        },
    });
}

function uRenderRecentCashbook(rows) {
    const container = document.getElementById("u-recent-cashbook");
    if (!container) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        container.innerHTML = uDataState("empty", "No debt cashbook entries yet.");
        return;
    }

    container.innerHTML = rows.map((row) => {
        const direction = String(row.direction || "").toLowerCase();
        const sign = direction === "credit" ? "-" : "+";
        return `
            <div class="stack-item">
                <div class="stack-item-head">
                    <strong>${uEscape(uEntryLabel(row.entry_type))}</strong>
                    <span>${uEscape(sign + " " + uMoney(row.amount || 0))}</span>
                </div>
                <div class="stack-meta">
                    ${uEscape(window.IbemsFormat?.dateTime(row.created_at) || String(row.created_at || "").replace("T", " "))} - Debt After: ${uEscape(uMoney(row.debt_after || 0))}
                </div>
            </div>
        `;
    }).join("");
}

function uRenderRecentTransactions(rows) {
    const body = document.getElementById("u-recent-transactions-body");
    if (!body) return;

    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = uDataState("empty", "No transactions yet.", 5);
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td data-label="Date">${uEscape(window.IbemsFormat?.dateTime(row.created_at) || new Date(row.created_at).toLocaleString())}</td>
            <td data-label="Store">${uEscape(row.store_name || "-")}</td>
            <td data-label="Payment">${uEscape(String(row.payment_method || "").toUpperCase())}</td>
            <td data-label="Amount">${uEscape(uMoney(row.amount || 0))}</td>
            <td data-label="Reference">${uEscape(row.client_txn_id || `TXN-${row.id}`)}</td>
        </tr>
    `).join("");
}

async function loadUserDashboard() {
    try {
        const response = await fetch("/user/dashboard/data", { signal: uPageSignal });
        const data = await response.json();
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Failed to load user dashboard.");
        }

        const s = data.summary || {};
        const byId = (id) => document.getElementById(id);
        if (byId("u-credit-limit")) byId("u-credit-limit").textContent = uMoney(s.credit_limit);
        if (byId("u-current-debt")) byId("u-current-debt").textContent = uMoney(s.current_debt);
        if (byId("u-available-credit")) byId("u-available-credit").textContent = uMoney(s.available_credit);
        if (byId("u-debt-added-total")) byId("u-debt-added-total").textContent = uMoney(s.debt_added_total);
        if (byId("u-debt-deducted-total")) byId("u-debt-deducted-total").textContent = uMoney(s.debt_deducted_total);
        if (byId("u-total-spent")) byId("u-total-spent").textContent = uMoney(s.total_spent);
        if (byId("u-txn-count")) byId("u-txn-count").textContent = String(s.txn_count || 0);
        uApplyDebtStatus("u", s);
        uRenderCreditMeter(s);

        uRenderTrend(data.trend || []);
        uRenderRecentCashbook(data.recent_cashbook || []);
        uRenderRecentTransactions(data.recent_transactions || []);
    } catch (error) {
        if (uPageSignal.aborted) return;
        uApplyDebtStatus("u", {
            debt_status_tone: "danger",
            debt_status_label: "Unavailable",
            debt_status_message: "Unable to load your debt status right now.",
        });
        uRenderCreditMeter({});
        uRenderTrend([]);
        document.getElementById("u-recent-cashbook").innerHTML = uDataState("error", error.message || "Unable to load recent cashbook.");
        document.getElementById("u-recent-transactions-body").innerHTML = uDataState("error", error.message || "Unable to load recent transactions.", 5);
    }
}

uPageSignal.addEventListener("abort", () => {
    uTrendChart?.destroy();
    uTrendChart = null;
}, { once: true });
loadUserDashboard();
}());
