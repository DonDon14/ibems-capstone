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

function renderStoreSelect() {
    const select = document.getElementById("history-store-select");
    const wrap = document.querySelector(".history-store-switch");
    select.innerHTML = historyStores
        .map((store) => `<option value="${store.id}">${hEscape(store.store_name)}</option>`)
        .join("");
    select.value = String(historyActiveStoreId);
    select.disabled = historyStores.length <= 1;
    wrap.style.display = historyStores.length <= 1 ? "none" : "flex";
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
            <tr>
                <td>${txn.id}</td>
                <td>${hEscape(hDateTime(txn.created_at))}</td>
                <td>${hEscape(txn.customer_name)}</td>
                <td>${hEscape(String(txn.payment_method).toUpperCase())}</td>
                <td>${hEscape(hMoney(txn.amount))}</td>
                <td><button class="history-action" data-txn-id="${txn.id}" type="button">View Receipt</button></td>
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
    renderStoreSelect();
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

function buildReceiptHtml(receipt) {
    const rows = receipt.items
        .map(
            (item) => `
            <tr>
                <td>${hEscape(item.name)}</td>
                <td>${item.qty}</td>
                <td>${hMoney(item.unit_price)}</td>
                <td>${hMoney(item.line_total)}</td>
            </tr>
        `
        )
        .join("");

    return `
        <div class="receipt-content-head">
            <div><strong>Transaction #:</strong> ${hEscape(receipt.client_txn_id)}</div>
            <div><strong>Date:</strong> ${hEscape(hDateTime(receipt.created_at))}</div>
            <div><strong>Store:</strong> ${hEscape(receipt.store_name)}</div>
            <div><strong>Customer:</strong> ${hEscape(receipt.customer_name)}</div>
            <div><strong>Payment:</strong> ${hEscape(String(receipt.payment_method).toUpperCase())}</div>
        </div>
        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Line Total</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
        <div class="receipt-total">Total: ${hMoney(receipt.amount)}</div>
    `;
}

async function openReceipt(transactionId) {
    const response = await fetch(`/store/transactions/${transactionId}`);
    const data = await response.json();

    if (!data || data.status !== "success" || !data.transaction) {
        setHistoryResult(data?.message || "Unable to load receipt.", "error");
        return;
    }

    selectedReceipt = data.transaction;
    const modal = document.getElementById("history-receipt-modal");
    const content = document.getElementById("history-receipt-content");
    content.innerHTML = buildReceiptHtml(selectedReceipt);
    modal.style.display = "grid";
}

function closeReceipt() {
    document.getElementById("history-receipt-modal").style.display = "none";
}

function printReceipt() {
    if (!selectedReceipt) {
        return;
    }

    const printWindow = window.open("", "_blank", "width=800,height=900");
    if (!printWindow) {
        setHistoryResult("Popup blocked. Please allow popups.", "error");
        return;
    }

    const html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt ${hEscape(selectedReceipt.client_txn_id)}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; color: #111; }
                h2 { margin-top: 0; color: #003366; }
                .meta { margin-bottom: 12px; font-size: 14px; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { border: 1px solid #ddd; padding: 8px; font-size: 13px; }
                th { background: #f4f4f4; text-align: left; }
                .total { margin-top: 12px; text-align: right; font-weight: bold; font-size: 16px; }
            </style>
        </head>
        <body>
            <h2>USTP Store Receipt</h2>
            ${buildReceiptHtml(selectedReceipt)}
        </body>
        </html>
    `;

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

document.getElementById("history-store-select").addEventListener("change", async (event) => {
    historyActiveStoreId = Number(event.target.value);
    await loadTransactions();
});

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
    const button = event.target.closest(".history-action");
    if (!button) return;

    await openReceipt(Number(button.dataset.txnId));
});

document.getElementById("history-receipt-close").addEventListener("click", closeReceipt);
document.getElementById("history-receipt-print").addEventListener("click", printReceipt);
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
