let historyStores = [];
let historyActiveStoreId = null;
let selectedReceipt = null;
let historyReceiptSequence = 0;
let historyReceiptTrigger = null;
let historyPage = 1;
let historyRequestSequence = 0;
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
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function hDataState(type, message) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    return `<tr class="data-state-row"><td colspan="6"><div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${hEscape(message)}</strong></div></div></td></tr>`;
}

function hDateTime(value) {
    return window.IbemsFormat?.dateTime(value) || new Date(value).toLocaleString();
}

function hPaymentLabel(method) {
    const key = String(method || "").toLowerCase();
    if (key === "gcash") return "GCash";
    return key.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function setHistoryResult(message, type) {
    const el = document.getElementById("history-result");
    el.setAttribute("role", type === "error" ? "alert" : "status");
    el.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
    el.setAttribute("aria-atomic", "true");
    el.textContent = message || "";
    el.style.color = type === "error" ? "var(--feedback-error, #b91c1c)" : "var(--feedback-success, #166534)";
}

function syncHistoryFilters() {
    historyFilters.dateFrom = document.getElementById("history-date-from").value || "";
    historyFilters.dateTo = document.getElementById("history-date-to").value || "";
    historyFilters.paymentMethod = document.getElementById("history-payment-filter").value || "";

    const hasActiveFilters = Boolean(
        historyFilters.dateFrom ||
        historyFilters.dateTo ||
        historyFilters.paymentMethod ||
        document.getElementById("history-sort").value !== "date:desc" ||
        document.getElementById("history-page-size").value !== "25"
    );
    document.getElementById("history-clear-filters").classList.toggle("is-hidden", !hasActiveFilters);
}

async function applyHistoryFiltersAutomatically() {
    syncHistoryFilters();
    if (historyFilters.dateFrom && historyFilters.dateTo && historyFilters.dateFrom > historyFilters.dateTo) {
        ++historyRequestSequence;
        clearHistorySummary("Select valid dates");
        document.getElementById("history-pager").innerHTML = "";
        document.getElementById("history-body").innerHTML = hDataState("error", "From date cannot be later than To date.");
        setHistoryResult("From date cannot be later than To date.", "error");
        return;
    }
    historyPage = 1;
    await loadTransactions();
}

function clearHistorySummary(label) {
    document.getElementById("history-summary-count").textContent = label;
    document.getElementById("history-summary-total").textContent = label;
}

function renderSummary(transactions, summary = null) {
    const countEl = document.getElementById("history-summary-count");
    const totalEl = document.getElementById("history-summary-total");
    const list = Array.isArray(transactions) ? transactions : [];
    const total = summary ? Number(summary.total_amount || 0) : list.reduce((sum, txn) => sum + Number(txn.amount || 0), 0);
    const count = summary ? Number(summary.transaction_count || 0) : list.length;

    countEl.textContent = String(count);
    totalEl.textContent = hMoney(total);
}

function renderTransactions(transactions, summary = null) {
    const body = document.getElementById("history-body");

    if (!Array.isArray(transactions) || transactions.length === 0) {
        body.innerHTML = hDataState("empty", "No transactions found.");
        renderSummary([], summary);
        return;
    }

    body.innerHTML = transactions
        .map(
            (txn) => `
            <tr class="history-row-clickable table-row-clickable" data-txn-id="${txn.id}">
                <td>${txn.id}</td>
                <td><span class="history-date">${hEscape(hDateTime(txn.created_at))}</span><small>${hEscape(txn.client_txn_id || "")}</small></td>
                <td><span class="table-person-cell">${window.IbemsAvatar.html(txn.customer_name, txn.customer_profile_image_url, "table-person-avatar")}<span><strong>${hEscape(txn.customer_name)}</strong><small>${hEscape(hPaymentLabel(txn.customer_type || ""))}</small></span></span></td>
                <td><span class="history-payment-pill payment-${hEscape(String(txn.payment_method || "").toLowerCase())}">${hEscape(hPaymentLabel(txn.payment_method))}</span></td>
                <td><strong class="history-amount">${hEscape(hMoney(txn.amount))}</strong></td>
                <td>
                    <div class="history-row-actions">
                        <button type="button" class="secondary-btn btn-sm" data-history-action="view" data-txn-id="${txn.id}"><i class="bi bi-eye"></i> View</button>
                        <button type="button" class="primary-btn btn-sm" data-history-action="print" data-txn-id="${txn.id}"><i class="bi bi-printer"></i> Print</button>
                    </div>
                </td>
            </tr>
        `
        )
        .join("");

    renderSummary(transactions, summary);
}

async function loadStores() {
    const context = window.IBEMS_PORTAL_CONTEXT || {};
    if (Array.isArray(context.stores) && context.stores.length > 0) {
        historyStores = context.stores;
        historyActiveStoreId = window.IbemsStoreScope.resolve(historyStores, context.default_store_id);
        return;
    }

    const response = await fetch("/store/my-stores");
    const data = await response.json();

    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }

    historyStores = data.stores;
    historyActiveStoreId = window.IbemsStoreScope.resolve(historyStores, data.default_store_id);
}

async function loadTransactions() {
    const requestId = ++historyRequestSequence;
    const body = document.getElementById("history-body");
    body.innerHTML = hDataState("loading", "Loading transactions...");
    clearHistorySummary("Loading…");
    document.getElementById("history-pager").innerHTML = "";
    setHistoryResult("", "ok");

    const params = new URLSearchParams({
        store_id: String(historyActiveStoreId),
        page: String(historyPage),
        page_size: document.getElementById("history-page-size").value || "25",
    });
    const [sortBy, sortDir] = (document.getElementById("history-sort").value || "date:desc").split(":");
    params.set("sort_by", sortBy);
    params.set("sort_dir", sortDir);

    if (historyFilters.dateFrom) {
        params.set("date_from", historyFilters.dateFrom);
    }
    if (historyFilters.dateTo) {
        params.set("date_to", historyFilters.dateTo);
    }
    if (historyFilters.paymentMethod) {
        params.set("payment_method", historyFilters.paymentMethod);
    }

    try {
    const response = await fetch(`/store/transactions?${params.toString()}`);
    const data = await response.json();
    if (requestId !== historyRequestSequence) return;

    if (!response.ok || !data || data.status !== "success") throw new Error(data?.message || "Unable to load transactions.");

    renderTransactions(data.transactions, data.summary || null);
    const meta = data.pagination || {};
    const page = Number(meta.page || 1);
    const totalPages = Number(meta.total_pages || 1);
    document.getElementById("history-pager").innerHTML = `
        <button class="secondary-btn btn-sm" type="button" data-page="${page - 1}" ${page <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left"></i> Previous</button>
        <span>Page ${page} of ${totalPages} · ${Number(meta.total || 0)} records</span>
        <button class="secondary-btn btn-sm" type="button" data-page="${page + 1}" ${page >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right"></i></button>`;
    setHistoryResult("", "ok");
    } catch (error) {
        if (requestId !== historyRequestSequence) return;
        const message = error?.message || "Unable to load transactions.";
        body.innerHTML = hDataState("error", message);
        clearHistorySummary("Unavailable");
        document.getElementById("history-pager").innerHTML = "";
        setHistoryResult(message, "error");
    }
}

async function openReceipt(transactionId) {
    const requestId = ++historyReceiptSequence;
    const modal = document.getElementById("history-receipt-modal");
    const content = document.getElementById("history-receipt-content");
    const print = document.getElementById("history-receipt-print");
    const view = document.getElementById("history-receipt-view");
    historyReceiptTrigger = document.activeElement;
    selectedReceipt = null;
    print.disabled = view.disabled = true;
    content.innerHTML = '<div class="receipt-loading" role="status">Loading receipt...</div>';
    modal.style.display = "grid";
    document.getElementById("history-receipt-close").focus();
    try {
    const response = await fetch(`/store/transactions/${encodeURIComponent(String(transactionId))}`);
    const data = await response.json();
    if (requestId !== historyReceiptSequence) return false;
    if (!response.ok || !data || data.status !== "success" || !data.transaction) {
        throw new Error(data?.message || "Unable to load receipt.");
    }

    const tx = data.transaction;
    selectedReceipt = {
        transactionId: tx.id,
        clientTxnId: tx.client_txn_id,
        createdAt: tx.created_at,
        storeName: tx.store_name,
        customerName: tx.customer_name,
        paymentMethod: tx.payment_method,
        payments: tx.payments,
        totalAmount: tx.amount,
        items: tx.items,
        lookupUrl: `${window.location.origin}/store/receipt/${encodeURIComponent(String(tx.id))}?store_id=${encodeURIComponent(String(historyActiveStoreId))}`,
    };
    if (!window.IbemsReceipt) throw new Error("Receipt renderer is unavailable.");
    window.IbemsReceipt.renderReceipt("history-receipt-content", selectedReceipt);
    print.disabled = view.disabled = false;
    return true;
    } catch (error) {
        if (requestId !== historyReceiptSequence) return false;
        selectedReceipt = null;
        content.innerHTML = `<div class="receipt-error" role="alert">${hEscape(error?.message || "Unable to load receipt.")} Close this dialog and try again.</div>`;
        return false;
    }
}

function closeReceipt() {
    ++historyReceiptSequence;
    selectedReceipt = null;
    document.getElementById("history-receipt-modal").style.display = "none";
    historyReceiptTrigger?.focus?.();
    historyReceiptTrigger = null;
}

document.addEventListener("keydown", event => {
    const modal = document.getElementById("history-receipt-modal");
    if (modal.style.display !== "grid") return;
    if (event.key === "Escape") { event.preventDefault(); closeReceipt(); return; }
    if (event.key !== "Tab") return;
    const controls = Array.from(modal.querySelectorAll('a[href],button,input,select,textarea,[tabindex]'))
        .filter(element => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length);
    const first = controls[0], last = controls[controls.length - 1];
    if (!first) { event.preventDefault(); return; }
    if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
});

function printReceipt() {
    if (!selectedReceipt) return;
    if (!window.IbemsReceipt || !window.IbemsReceipt.printReceipt(selectedReceipt)) {
        setHistoryResult("Popup blocked. Please allow popups.", "error");
    }
}

async function printReceiptById(transactionId) {
    if (await openReceipt(transactionId)) printReceipt();
}

document.getElementById("history-clear-filters").addEventListener("click", async () => {
    historyFilters = {
        dateFrom: "",
        dateTo: "",
        paymentMethod: "",
    };

    document.getElementById("history-date-from").value = "";
    document.getElementById("history-date-to").value = "";
    document.getElementById("history-payment-filter").value = "";
    document.getElementById("history-sort").value = "date:desc";
    document.getElementById("history-page-size").value = "25";
    syncHistoryFilters();
    historyPage = 1;
    await loadTransactions();
});

[
    "history-date-from",
    "history-date-to",
    "history-payment-filter",
    "history-sort",
    "history-page-size",
].forEach((id) => {
    document.getElementById(id).addEventListener("change", applyHistoryFiltersAutomatically);
});
document.getElementById("history-pager").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-page]");
    if (!button || button.disabled) return;
    historyPage = Math.max(1, Number(button.dataset.page || 1));
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
        const message = error.message || "Unable to load transaction history.";
        document.getElementById("history-body").innerHTML = hDataState("error", message);
        clearHistorySummary("Unavailable");
        document.getElementById("history-pager").innerHTML = "";
        setHistoryResult(message, "error");
    }
})();
