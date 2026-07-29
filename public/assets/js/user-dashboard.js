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
    if (key === "store_repayment") return "Store Payment";
    if (key === "operator_shortage") return "Store Shortage";
    return key.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
}

let uTrendChart = null;
let uHasDebtPin = false;

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

function uSetPinResult(message = "", tone = "") {
    const result = document.getElementById("u-pin-form-result");
    if (!result) return;
    result.textContent = message;
    result.classList.remove("is-error", "is-success");
    if (tone) result.classList.add(tone === "success" ? "is-success" : "is-error");
}

function uRenderPinStatus() {
    uSetText(
        "u-debt-pin-status",
        uHasDebtPin
            ? "PIN is configured. Stores can verify debt purchases using your PIN."
            : "No PIN is configured yet. Set one before using debt payment at POS."
    );

    const button = document.getElementById("u-open-pin-modal");
    if (button) {
        button.innerHTML = uHasDebtPin ? '<i class="bi bi-key"></i> Change PIN' : '<i class="bi bi-key"></i> Set PIN';
    }
}

async function uLoadDebtPinStatus() {
    try {
        const response = await fetch("/user/debt-pin/status");
        const data = await response.json();
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Unable to load debt PIN status.");
        }
        uHasDebtPin = !!data.has_pin;
    } catch (error) {
        uHasDebtPin = false;
        uSetText("u-debt-pin-status", "Unable to check PIN status right now.");
    } finally {
        uRenderPinStatus();
    }
}

function uOpenPinModal() {
    const modal = document.getElementById("u-debt-pin-modal");
    const currentWrap = document.getElementById("u-current-password-wrap");
    const help = document.getElementById("u-pin-form-help");
    const form = document.getElementById("u-debt-pin-form");
    if (form) form.reset();
    uSetPinResult("");

    if (currentWrap) currentWrap.classList.toggle("is-hidden", !uHasDebtPin);
    if (help) {
        help.textContent = uHasDebtPin
            ? "Enter your current password, then choose a new 4 to 6 digit debt PIN."
            : "Use a 4 to 6 digit PIN. Stores will ask for this only when charging purchases to debt.";
    }

    if (modal) modal.classList.remove("is-hidden");
}

function uClosePinModal() {
    const modal = document.getElementById("u-debt-pin-modal");
    if (modal) modal.classList.add("is-hidden");
}

function uNormalizePinInput(event) {
    const input = event.target;
    input.value = String(input.value || "").replace(/\D/g, "").slice(0, 6);
}

async function uSubmitDebtPin(event) {
    event.preventDefault();

    const pin = String(document.getElementById("u-debt-pin")?.value || "");
    const pinConfirm = String(document.getElementById("u-debt-pin-confirm")?.value || "");
    const currentPassword = String(document.getElementById("u-current-password")?.value || "");

    if (!/^[0-9]{4,6}$/.test(pin)) {
        uSetPinResult("Debt PIN must be 4 to 6 digits.", "error");
        return;
    }
    if (pin !== pinConfirm) {
        uSetPinResult("Debt PIN confirmation does not match.", "error");
        return;
    }
    if (uHasDebtPin && !currentPassword) {
        uSetPinResult("Enter your current password to change your debt PIN.", "error");
        return;
    }

    const submitBtn = document.getElementById("u-save-pin");
    try {
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
        }

        const response = await fetch("/user/debt-pin/set", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                current_password: currentPassword,
                pin,
                pin_confirm: pinConfirm,
            }),
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Unable to update debt PIN.");
        }

        uHasDebtPin = true;
        uRenderPinStatus();
        uSetPinResult(data.message || "Debt PIN saved.", "success");
        setTimeout(uClosePinModal, 550);
    } catch (error) {
        uSetPinResult(error.message || "Unable to update debt PIN.", "error");
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Save PIN';
        }
    }
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
        container.innerHTML = '<div class="mini-bar-empty">No debt cashbook entries yet.</div>';
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
        body.innerHTML = '<tr><td colspan="5">No transactions yet.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${uEscape(window.IbemsFormat?.dateTime(row.created_at) || new Date(row.created_at).toLocaleString())}</td>
            <td>${uEscape(row.store_name || "-")}</td>
            <td>${uEscape(String(row.payment_method || "").toUpperCase())}</td>
            <td>${uEscape(uMoney(row.amount || 0))}</td>
            <td>${uEscape(row.client_txn_id || `TXN-${row.id}`)}</td>
        </tr>
    `).join("");
}

async function loadUserDashboard() {
    try {
        const response = await fetch("/user/dashboard/data");
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
        uApplyDebtStatus("u", {
            debt_status_tone: "danger",
            debt_status_label: "Unavailable",
            debt_status_message: "Unable to load your debt status right now.",
        });
        uRenderCreditMeter({});
        uRenderTrend([]);
        uRenderRecentCashbook([]);
        uRenderRecentTransactions([]);
    }
}

document.getElementById("u-open-pin-modal")?.addEventListener("click", uOpenPinModal);
document.getElementById("u-pin-modal-close")?.addEventListener("click", uClosePinModal);
document.getElementById("u-pin-modal-cancel")?.addEventListener("click", uClosePinModal);
document.getElementById("u-debt-pin-modal")?.addEventListener("click", (event) => {
    if (event.target.id === "u-debt-pin-modal") uClosePinModal();
});
document.getElementById("u-debt-pin")?.addEventListener("input", uNormalizePinInput);
document.getElementById("u-debt-pin-confirm")?.addEventListener("input", uNormalizePinInput);
document.getElementById("u-debt-pin-form")?.addEventListener("submit", uSubmitDebtPin);

loadUserDashboard();
uLoadDebtPinStatus();
