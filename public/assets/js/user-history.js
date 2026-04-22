function uhMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function uhDateTime(value) {
    return new Date(value).toLocaleString();
}

function uhEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function uhEntryLabel(value) {
    const key = String(value || "").toLowerCase();
    if (key === "debt_purchase") return "Debt Purchase";
    if (key === "manual_deduction") return "Manual Deduction";
    if (key === "full_deduction") return "Full Deduction";
    if (key === "salary_deduction") return "Salary Deduction";
    return key.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
}

async function loadUserTransactions() {
    const params = new URLSearchParams({ limit: "120" });
    const from = document.getElementById("uh-date-from").value || "";
    const to = document.getElementById("uh-date-to").value || "";
    if (from) params.set("date_from", from);
    if (to) params.set("date_to", to);

    const response = await fetch(`/user/transactions?${params.toString()}`);
    const data = await response.json();
    const body = document.getElementById("uh-body");

    if (!data || data.status !== "success" || !Array.isArray(data.data)) {
        body.innerHTML = '<tr><td colspan="5">Unable to load transactions.</td></tr>';
        return;
    }

    if (data.data.length === 0) {
        body.innerHTML = '<tr><td colspan="5">No transactions found.</td></tr>';
        return;
    }

    body.innerHTML = data.data.map((row) => `
        <tr>
            <td>${uhEscape(uhDateTime(row.created_at))}</td>
            <td>${uhEscape(row.store_name)}</td>
            <td>${uhEscape(String(row.payment_method || "").toUpperCase())}</td>
            <td>${uhEscape(uhMoney(row.amount))}</td>
            <td>${uhEscape(row.client_txn_id || `TXN-${row.id}`)}</td>
        </tr>
    `).join("");
}

async function loadUserCashbook() {
    const params = new URLSearchParams({ limit: "150" });
    const from = document.getElementById("uh-date-from").value || "";
    const to = document.getElementById("uh-date-to").value || "";
    if (from) params.set("date_from", from);
    if (to) params.set("date_to", to);

    const response = await fetch(`/user/cashbook?${params.toString()}`);
    const data = await response.json();
    const body = document.getElementById("uh-cashbook-body");

    if (!data || data.status !== "success" || !Array.isArray(data.data)) {
        body.innerHTML = '<tr><td colspan="8">Unable to load cashbook.</td></tr>';
        return;
    }

    if (data.data.length === 0) {
        body.innerHTML = '<tr><td colspan="8">No debt cashbook entries found.</td></tr>';
        return;
    }

    body.innerHTML = data.data.map((row) => `
        <tr>
            <td>${uhEscape(uhDateTime(row.created_at))}</td>
            <td>${uhEscape(uhEntryLabel(row.entry_type))}</td>
            <td>${uhEscape(String(row.direction || "").toUpperCase())}</td>
            <td>${uhEscape(uhMoney(row.amount))}</td>
            <td>${uhEscape(uhMoney(row.debt_before))}</td>
            <td>${uhEscape(uhMoney(row.debt_after))}</td>
            <td>${uhEscape(uhMoney(row.available_credit_snapshot))}</td>
            <td>${uhEscape(row.remarks || "-")}</td>
        </tr>
    `).join("");
}

async function loadUserHistoryAll() {
    await loadUserTransactions();
    await loadUserCashbook();
}

document.getElementById("uh-apply").addEventListener("click", loadUserHistoryAll);
document.getElementById("uh-clear").addEventListener("click", () => {
    document.getElementById("uh-date-from").value = "";
    document.getElementById("uh-date-to").value = "";
    loadUserHistoryAll();
});

loadUserHistoryAll();
