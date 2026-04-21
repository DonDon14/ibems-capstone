function aMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function aEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

async function loadAdminDebtOverview() {
    const response = await fetch("/admin/accounting-debts/data");
    const data = await response.json();
    if (!data || data.status !== "success") return;

    const summary = data.summary || {};
    document.getElementById("ad-account-count").textContent = String(summary.account_count || 0);
    document.getElementById("ad-debt-accounts").textContent = String(summary.debt_accounts || 0);
    document.getElementById("ad-total-debt").textContent = aMoney(summary.total_debt || 0);
    document.getElementById("ad-today-deducted").textContent = aMoney(summary.today_deduction_amount || 0);

    const rows = Array.isArray(data.top_debts) ? data.top_debts : [];
    const body = document.getElementById("ad-top-body");
    if (rows.length === 0) {
        body.innerHTML = '<tr><td colspan="5">No debt records.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${aEscape(row.employee_id || "-")}</td>
            <td>${aEscape(row.name)}</td>
            <td>${aEscape(row.email)}</td>
            <td>${aEscape(aMoney(row.current_debt))}</td>
            <td>${aEscape(aMoney(row.credit_limit))}</td>
        </tr>
    `).join("");
}

loadAdminDebtOverview();
