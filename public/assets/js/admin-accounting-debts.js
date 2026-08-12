function aMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function aEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function adDebtDataState(type, message) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    return `<tr class="data-state-row"><td colspan="5"><div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${aEscape(message)}</strong></div></div></td></tr>`;
}

function debtPercent(currentDebt, creditLimit) {
    const current = Number(currentDebt || 0);
    const limit = Number(creditLimit || 0);
    if (limit <= 0) return 0;
    return Math.max(0, Math.min(100, (current / limit) * 100));
}

async function loadAdminDebtOverview() {
    const response = await fetch("/admin/accounting-debts/data");
    const data = await response.json();
    if (!data || data.status !== "success") {
        document.getElementById("ad-top-body").innerHTML = adDebtDataState("error", data?.message || "Unable to load debt records.");
        return;
    }

    const summary = data.summary || {};
    document.getElementById("ad-account-count").textContent = String(summary.account_count || 0);
    document.getElementById("ad-debt-accounts").textContent = String(summary.debt_accounts || 0);
    document.getElementById("ad-total-debt").textContent = aMoney(summary.total_debt || 0);
    document.getElementById("ad-today-deducted").textContent = aMoney(summary.today_deduction_amount || 0);

    const rows = Array.isArray(data.top_debts) ? data.top_debts : [];
    const body = document.getElementById("ad-top-body");
    if (rows.length === 0) {
        body.innerHTML = adDebtDataState("empty", "No debt records.");
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${aEscape(row.employee_id || "-")}</td>
            <td>${aEscape(row.name)}</td>
            <td>${aEscape(row.email)}</td>
            <td>
                <div class="table-debt-wrap">
                    <span>${aEscape(aMoney(row.current_debt))}</span>
                    <div class="table-debt-bar"><i style="width:${debtPercent(row.current_debt, row.credit_limit)}%"></i></div>
                </div>
            </td>
            <td>${aEscape(aMoney(row.credit_limit))}</td>
        </tr>
    `).join("");
}

loadAdminDebtOverview();
