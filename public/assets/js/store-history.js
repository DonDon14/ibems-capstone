let historyStores = [];
let historyActiveStoreId = null;
let selectedReceipt = null;
let historyFilters = {
    dateFrom: "",
    dateTo: "",
    paymentMethod: "",
};

function hEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function hMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function hDateTime(value) {
    return new Date(value).toLocaleString();
}

function hPaymentLabel(method) {
    const key = String(method || "").toLowerCase();
    if (key === "gcash") return "GCash";
    return key.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function setHistoryResult(message, type) {
    const el = document.getElementById("history-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function renderSummary(transactions) {
    const countEl = document.getElementById("history-summary-count");
    const totalEl = document.getElementById("history-summary-total");
    const list = Array.isArray(transactions) ? transactions : [];
    const total = list.reduce((sum, txn) => sum + Number(txn.amount || 0), 0);

    countEl.textContent = String(list.length);
    totalEl.textContent = hMoney(total);
}

function renderTransactions(transactions) {
    const body = document.getElementById("history-body");

    if (!Array.isArray(transactions) || transactions.length === 0) {
        body.innerHTML = '<tr><td colspan="6">No transactions found.</td></tr>';
        renderSummary([]);
        return;
    }

    body.innerHTML = transactions
        .map(
            (txn) => `
            <tr class="history-row-clickable table-row-clickable" data-txn-id="${txn.id}">
                <td>${txn.id}</td>
                <td><span class="history-date">${hEscape(hDateTime(txn.created_at))}</span><small>${hEscape(txn.client_txn_id || "")}</small></td>
                <td><strong>${hEscape(txn.customer_name)}</strong><small>${hEscape(hPaymentLabel(txn.customer_type || ""))}</small></td>
                <td><span class="history-payment-pill payment-${hEscape(String(txn.payment_method || "").toLowerCase())}">${hEscape(hPaymentLabel(txn.payment_method))}</span></td>
                <td><strong class="history-amount">${hEscape(hMoney(txn.amount))}</strong></td>
                <td>
                    <div class="history-row-actions">
                        <button type="button" data-history-action="view" data-txn-id="${txn.id}"><i class="bi bi-eye"></i> View</button>
                        <button type="button" data-history-action="print" data-txn-id="${txn.id}"><i class="bi bi-printer"></i> Print</button>
                    </div>
                </td>
            </tr>
        `
        )
        .join("");

    renderSummary(transactions);
}

async function loadStores() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();

    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }

    historyStores = data.stores;
    historyActiveStoreId = Number(data.default_store_id || historyStores[0].id);
}

async function loadTransactions() {
    const body = document.getElementById("history-body");
    body.innerHTML = '<tr><td colspan="6">Loading transactions...</td></tr>';

    const params = new URLSearchParams({
        store_id: String(historyActiveStoreId),
        limit: "100",
    });

    if (historyFilters.dateFrom) {
        params.set("date_from", historyFilters.dateFrom);
    }
    if (historyFilters.dateTo) {
        params.set("date_to", historyFilters.dateTo);
    }
    if (historyFilters.paymentMethod) {
        params.set("payment_method", historyFilters.paymentMethod);
    }

    const response = await fetch(`/store/transactions?${params.toString()}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        renderTransactions([]);
        setHistoryResult(data?.message || "Unable to load transactions.", "error");
        return;
    }

    renderTransactions(data.transactions);
    setHistoryResult("", "ok");
}

async function openReceipt(transactionId) {
    const response = await fetch(`/store/transactions/${transactionId}`);
    const data = await response.json();

    if (!data || data.status !== "success" || !data.transaction) {
        setHistoryResult(data?.message || "Unable to load receipt.", "error");
        return;
    }

    const tx = data.transaction;
    selectedReceipt = {
        transactionId: tx.id,
        clientTxnId: tx.client_txn_id,
        createdAt: tx.created_at,
        storeName: tx.store_name,
        customerName: tx.customer_name,
        paymentMethod: tx.payment_method,
        totalAmount: tx.amount,
        items: tx.items,
        lookupUrl: `${window.location.origin}/store/receipt/${encodeURIComponent(String(tx.id))}`,
    };
    const modal = document.getElementById("history-receipt-modal");
    if (window.IbemsReceipt) {
        window.IbemsReceipt.renderReceipt("history-receipt-content", selectedReceipt);
    }
    modal.style.display = "grid";
}

function closeReceipt() {
    document.getElementById("history-receipt-modal").style.display = "none";
}

function printReceipt() {
    if (!selectedReceipt) return;
    if (!window.IbemsReceipt || !window.IbemsReceipt.printReceipt(selectedReceipt)) {
        setHistoryResult("Popup blocked. Please allow popups.", "error");
    }
}

async function printReceiptById(transactionId) {
    await openReceipt(transactionId);
    printReceipt();
}

document.getElementById("history-apply-filters").addEventListener("click", async () => {
    historyFilters.dateFrom = document.getElementById("history-date-from").value || "";
    historyFilters.dateTo = document.getElementById("history-date-to").value || "";
    historyFilters.paymentMethod = document.getElementById("history-payment-filter").value || "";
    await loadTransactions();
});

document.getElementById("history-clear-filters").addEventListener("click", async () => {
    historyFilters = {
        dateFrom: "",
        dateTo: "",
        paymentMethod: "",
    };

    document.getElementById("history-date-from").value = "";
    document.getElementById("history-date-to").value = "";
    document.getElementById("history-payment-filter").value = "";
    await loadTransactions();
});

document.getElementById("history-body").addEventListener("click", async (event) => {
    const actionBtn = event.target.closest("[data-history-action]");
    if (actionBtn) {
        event.stopPropagation();
        const transactionId = Number(actionBtn.dataset.txnId || 0);
        if (!transactionId) return;
        if (actionBtn.dataset.historyAction === "print") {
            await printReceiptById(transactionId);
            return;
        }
        await openReceipt(transactionId);
        return;
    }

    const row = event.target.closest("[data-txn-id]");
    if (!row) return;
    await openReceipt(Number(row.dataset.txnId));
});

document.getElementById("history-receipt-close").addEventListener("click", closeReceipt);
document.getElementById("history-receipt-print").addEventListener("click", printReceipt);
document.getElementById("history-receipt-view").addEventListener("click", () => {
    if (!selectedReceipt?.lookupUrl) return;
    window.open(selectedReceipt.lookupUrl, "_blank", "noopener");
});
document.getElementById("history-receipt-modal").addEventListener("click", (event) => {
    if (event.target.id === "history-receipt-modal") {
        closeReceipt();
    }
});

(async () => {
    try {
        await loadStores();
        await loadTransactions();
    } catch (error) {
        setHistoryResult(error.message || "Unable to load transaction history.", "error");
    }
})();
