let uhSelectedReceipt = null;

function uhMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function uhDateTime(value) {
    return window.IbemsFormat?.dateTime(value) || new Date(value).toLocaleString();
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
    if (key === "store_repayment") return "Store Payment";
    if (key === "operator_shortage") return "Store Shortage";
    return key.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
}

function uhApplyDebtStatus(summary) {
    const tone = String(summary.debt_status_tone || "info");
    const card = document.getElementById("uh-debt-status-card");
    if (card) {
        card.classList.remove(
            "user-status-card--success",
            "user-status-card--info",
            "user-status-card--warning",
            "user-status-card--danger"
        );
        card.classList.add(`user-status-card--${tone}`);
        card.dataset.status = String(summary.debt_status || "unpaid");
    }

    const label = document.getElementById("uh-debt-status-label");
    const message = document.getElementById("uh-debt-status-message");
    if (label) label.textContent = String(summary.debt_status_label || "Unpaid");
    if (message) {
        message.textContent = String(summary.debt_status_message || "You have an unpaid balance within your available credit limit.");
    }
}

async function loadUserSummaryCards() {
    const response = await fetch("/user/summary");
    const data = await response.json();
    if (!data || data.status !== "success" || !data.summary) {
        uhApplyDebtStatus({
            debt_status_tone: "danger",
            debt_status_label: "Unavailable",
            debt_status_message: "Unable to load your debt status right now.",
        });
        return;
    }
    const s = data.summary;
    const byId = (id) => document.getElementById(id);
    if (byId("uh-credit-limit")) byId("uh-credit-limit").textContent = uhMoney(s.credit_limit);
    if (byId("uh-current-debt")) byId("uh-current-debt").textContent = uhMoney(s.current_debt);
    if (byId("uh-available-credit")) byId("uh-available-credit").textContent = uhMoney(s.available_credit);
    if (byId("uh-total-spent")) byId("uh-total-spent").textContent = uhMoney(s.total_spent);
    uhApplyDebtStatus(s);
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
        body.innerHTML = '<tr><td colspan="6">Unable to load transactions.</td></tr>';
        return;
    }

    if (data.data.length === 0) {
        body.innerHTML = '<tr><td colspan="6">No transactions found.</td></tr>';
        return;
    }

    body.innerHTML = data.data.map((row) => `
        <tr class="uh-row-clickable table-row-clickable" data-txn-id="${Number(row.id)}">
            <td>${uhEscape(uhDateTime(row.created_at))}</td>
            <td>${uhEscape(row.store_name)}</td>
            <td>${uhEscape(String(row.payment_method || "").toUpperCase())}</td>
            <td>${uhEscape(uhMoney(row.amount))}</td>
            <td>${uhEscape(row.client_txn_id || `TXN-${row.id}`)}</td>
            <td>
                <a class="history-action" href="/user/receipt/${encodeURIComponent(String(row.id))}">
                    Receipt
                </a>
            </td>
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

    body.innerHTML = data.data.map((row) => {
        const referenceId = Number(row.reference_id || 0);
        const canOpenReceipt = String(row.reference_type || "").toLowerCase() === "transaction" && referenceId > 0;
        const remarks = uhEscape(row.remarks || "-");
        const remarksHtml = canOpenReceipt
            ? `<a class="user-inline-link" href="/user/receipt/${encodeURIComponent(String(referenceId))}">${remarks}</a>`
            : remarks;

        return `
        <tr>
            <td>${uhEscape(uhDateTime(row.created_at))}</td>
            <td>${uhEscape(uhEntryLabel(row.entry_type))}</td>
            <td>${uhEscape(String(row.direction || "").toUpperCase())}</td>
            <td>${uhEscape(uhMoney(row.amount))}</td>
            <td>${uhEscape(uhMoney(row.debt_before))}</td>
            <td>${uhEscape(uhMoney(row.debt_after))}</td>
            <td>${uhEscape(uhMoney(row.available_credit_snapshot))}</td>
            <td>${remarksHtml}</td>
        </tr>
    `;
    }).join("");
}

async function openReceipt(transactionId) {
    const response = await fetch(`/user/transactions/${transactionId}`);
    const data = await response.json();

    if (!data || data.status !== "success" || !data.transaction) {
        return;
    }

    const tx = data.transaction;
    uhSelectedReceipt = {
        transactionId: tx.id,
        clientTxnId: tx.client_txn_id,
        createdAt: tx.created_at,
        storeName: tx.store_name,
        customerName: tx.customer_name,
        paymentMethod: tx.payment_method,
        totalAmount: tx.amount,
        items: tx.items,
        lookupUrl: `${window.location.origin}/user/receipt/${encodeURIComponent(String(tx.id))}`,
    };

    if (window.IbemsReceipt) {
        window.IbemsReceipt.renderReceipt("uh-receipt-content", uhSelectedReceipt);
    }
    document.getElementById("uh-receipt-modal").classList.remove("is-hidden");
}

function closeReceipt() {
    document.getElementById("uh-receipt-modal").classList.add("is-hidden");
}

function printReceipt() {
    if (!uhSelectedReceipt) return;
    if (!window.IbemsReceipt || !window.IbemsReceipt.printReceipt(uhSelectedReceipt)) {
        // silent fallback
    }
}

async function loadUserHistoryAll() {
    await loadUserSummaryCards();
    await loadUserTransactions();
    await loadUserCashbook();
}

document.getElementById("uh-apply").addEventListener("click", loadUserHistoryAll);
document.getElementById("uh-clear").addEventListener("click", () => {
    document.getElementById("uh-date-from").value = "";
    document.getElementById("uh-date-to").value = "";
    loadUserHistoryAll();
});

document.getElementById("uh-body").addEventListener("click", async (event) => {
    if (event.target.closest("a")) return;
    const row = event.target.closest("[data-txn-id]");
    if (!row) return;
    await openReceipt(Number(row.dataset.txnId));
});

document.getElementById("uh-receipt-close").addEventListener("click", closeReceipt);
document.getElementById("uh-receipt-print").addEventListener("click", printReceipt);
document.getElementById("uh-receipt-modal").addEventListener("click", (event) => {
    if (event.target.id === "uh-receipt-modal") {
        closeReceipt();
    }
});

loadUserHistoryAll();
