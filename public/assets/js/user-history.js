(function () {
"use strict";

const uhPageSignal = window.IbemsUserNavigation?.currentSignal || new AbortController().signal;
if (!document.getElementById("uh-body")) return;

let uhSelectedReceipt = null;
let uhReceiptTrigger = null;
let uhReceiptRequestId = 0;
let uhTransactionPage = 1;
let uhCashbookPage = 1;
let uhTransactionRequestId = 0;
let uhCashbookRequestId = 0;
let uhSummaryRequestId = 0;

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
    if (key === "confirmed_salary_deduction") return "Confirmed Payroll Deduction";
    if (key === "investigation_reversal") return "Approved Debt Correction";
    if (key === "store_repayment") return "Store Payment";
    if (key === "operator_shortage") return "Store Shortage";
    return key.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
}

function uhDataState(type, message, colspan) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    return `<tr class="data-state-row"><td colspan="${Number(colspan)}"><div class="data-state data-state--${safeType}" role="${role}" aria-live="${safeType === "error" ? "assertive" : "polite"}" aria-atomic="true"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${uhEscape(message)}</strong></div></div></td></tr>`;
}

function uhStoreTotalsState(type, message = "") {
    const loading = type === "loading";
    document.getElementById("uh-grand-total").textContent = loading ? "Loading…" : "Unavailable";
    document.getElementById("uh-store-totals").innerHTML = `<div class="data-state data-state--${type}" role="${loading ? "status" : "alert"}" aria-live="${loading ? "polite" : "assertive"}" aria-atomic="true"><div><strong>${uhEscape(message || (loading ? "Loading store spending…" : "Unable to load store spending. Refresh the page to try again."))}</strong></div></div>`;
}

function uhSetStoreOptions(rows) {
    const select = document.getElementById("uh-store");
    const current = select.value;
    select.innerHTML = '<option value="">All stores</option>' + (Array.isArray(rows) ? rows : []).map((row) =>
        `<option value="${Number(row.id)}">${uhEscape(row.store_name || `Store #${row.id}`)}</option>`
    ).join("");
    if (current && select.querySelector(`option[value="${CSS.escape(current)}"]`)) select.value = current;
}

function uhRenderPager(id, meta, pageType) {
    const pager = document.getElementById(id);
    const page = Number(meta?.page || 1);
    const totalPages = Number(meta?.total_pages || 1);
    const total = Number(meta?.total || 0);
    if (total === 0) {
        pager.innerHTML = "";
        return;
    }
    pager.innerHTML = `
        <button class="secondary-btn btn-sm" type="button" aria-label="Previous ${pageType} page" data-${pageType}-page="${page - 1}" ${page <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left" aria-hidden="true"></i> Previous</button>
        <span>Page ${page} of ${totalPages} · ${total} record${total === 1 ? "" : "s"}</span>
        <button class="secondary-btn btn-sm" type="button" aria-label="Next ${pageType} page" data-${pageType}-page="${page + 1}" ${page >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right" aria-hidden="true"></i></button>`;
}

function uhRenderStoreTotals(rows, grandTotals) {
    const container = document.getElementById("uh-store-totals");
    const totals = Array.isArray(rows) ? rows : [];
    document.getElementById("uh-grand-total").textContent = uhMoney(grandTotals?.total_spent || 0);
    if (!totals.length) {
        container.innerHTML = '<div class="data-state data-state--empty" role="status"><i class="bi bi-inbox" aria-hidden="true"></i><div><strong>No store spending in this period.</strong></div></div>';
        return;
    }
    container.innerHTML = totals.map((row) => `
        <article class="user-store-total-card">
            <div><span>Store</span><strong>${uhEscape(row.store_name || "Store")}</strong></div>
            <dl>
                <div><dt>Purchases</dt><dd>${Number(row.purchase_count || 0)}</dd></div>
                <div><dt>Total spent</dt><dd>${uhEscape(uhMoney(row.total_spent || 0))}</dd></div>
                <div><dt>Debt charged</dt><dd>${uhEscape(uhMoney(row.debt_charged || 0))}</dd></div>
            </dl>
        </article>`).join("");
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
    const requestId = ++uhSummaryRequestId;
    const metricIds = ["uh-credit-limit", "uh-current-debt", "uh-available-credit", "uh-total-spent"];
    metricIds.forEach((id) => { document.getElementById(id).textContent = "Loading…"; });
    uhApplyDebtStatus({debt_status_tone: "info", debt_status: "loading", debt_status_label: "Loading", debt_status_message: "Checking your current debt balance."});
    let data;
    try {
        const response = await fetch("/user/summary", { signal: uhPageSignal });
        if (!response.ok) throw new Error("Unable to load account summary.");
        data = await response.json();
        if (uhPageSignal.aborted || requestId !== uhSummaryRequestId) return;
    } catch (error) {
        if (uhPageSignal.aborted || requestId !== uhSummaryRequestId) return;
        metricIds.forEach((id) => { document.getElementById(id).textContent = "Unavailable"; });
        uhApplyDebtStatus({
            debt_status_tone: "danger",
            debt_status_label: "Unavailable",
            debt_status: "unavailable",
            debt_status_message: "Unable to load your account summary. Refresh the page to try again.",
        });
        return;
    }
    if (!data || data.status !== "success" || !data.summary) {
        metricIds.forEach((id) => { document.getElementById(id).textContent = "Unavailable"; });
        uhApplyDebtStatus({
            debt_status_tone: "danger",
            debt_status_label: "Unavailable",
            debt_status: "unavailable",
            debt_status_message: "Unable to load your account summary. Refresh the page to try again.",
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

function uhValidateDateRange() {
    const from = document.getElementById("uh-date-from").value;
    const to = document.getElementById("uh-date-to").value;
    if (!from || !to || from <= to) return true;
    ++uhTransactionRequestId;
    ++uhCashbookRequestId;
    const message = "From date must be on or before To date. Adjust the date range to view your records.";
    document.getElementById("uh-body").innerHTML = uhDataState("error", message, 6);
    document.getElementById("uh-cashbook-body").innerHTML = uhDataState("error", message, 8);
    document.getElementById("uh-transactions-pager").innerHTML = "";
    document.getElementById("uh-cashbook-pager").innerHTML = "";
    uhStoreTotalsState("error", message);
    return false;
}

async function loadUserTransactions() {
    if (!uhValidateDateRange()) return;
    const requestId = ++uhTransactionRequestId;
    const params = new URLSearchParams({
        page: String(uhTransactionPage),
        page_size: document.getElementById("uh-page-size").value || "25",
    });
    const from = document.getElementById("uh-date-from").value || "";
    const to = document.getElementById("uh-date-to").value || "";
    const storeId = document.getElementById("uh-store").value || "";
    const [sortBy, sortDir] = (document.getElementById("uh-sort").value || "date:desc").split(":");
    if (from) params.set("date_from", from);
    if (to) params.set("date_to", to);
    if (storeId) params.set("store_id", storeId);
    params.set("sort_by", sortBy);
    params.set("sort_dir", sortDir);

    const body = document.getElementById("uh-body");
    body.innerHTML = uhDataState("loading", "Loading transactions...", 6);
    document.getElementById("uh-transactions-pager").innerHTML = "";
    uhStoreTotalsState("loading");

    let data;
    try {
        const response = await fetch(`/user/transactions?${params.toString()}`, { signal: uhPageSignal });
        if (!response.ok) throw new Error("Unable to load transactions. Refresh the page to try again.");
        data = await response.json();
        if (uhPageSignal.aborted || requestId !== uhTransactionRequestId) return;
    } catch (error) {
        if (uhPageSignal.aborted || requestId !== uhTransactionRequestId) return;
        uhStoreTotalsState("error");
        body.innerHTML = uhDataState("error", error.message || "Unable to load transactions.", 6);
        document.getElementById("uh-transactions-pager").innerHTML = "";
        return;
    }

    if (!data || data.status !== "success" || !Array.isArray(data.data)) {
        uhStoreTotalsState("error");
        body.innerHTML = uhDataState("error", data?.message || "Unable to load transactions.", 6);
        document.getElementById("uh-transactions-pager").innerHTML = "";
        return;
    }

    uhSetStoreOptions(data.stores || []);
    uhRenderStoreTotals(data.store_totals || [], data.grand_totals || {});
    uhRenderPager("uh-transactions-pager", data.pagination || {}, "transactions");

    if (data.data.length === 0) {
        body.innerHTML = uhDataState("empty", "No transactions found.", 6);
        return;
    }

    body.innerHTML = data.data.map((row) => `
        <tr class="uh-row-clickable table-row-clickable" data-txn-id="${Number(row.id)}">
            <td data-label="Date">${uhEscape(uhDateTime(row.created_at))}</td>
            <td data-label="Store">${uhEscape(row.store_name)}</td>
            <td data-label="Payment">${uhEscape(String(row.payment_method || "").toUpperCase())}</td>
            <td data-label="Amount">${uhEscape(uhMoney(row.amount))}</td>
            <td data-label="Reference">${uhEscape(row.client_txn_id || `TXN-${row.id}`)}</td>
            <td data-label="Receipt">
                <button class="secondary-btn btn-sm" type="button" data-receipt-id="${Number(row.id)}">
                    <i class="bi bi-receipt" aria-hidden="true"></i> Receipt
                </button>
            </td>
        </tr>
    `).join("");
}

async function loadUserCashbook() {
    if (!uhValidateDateRange()) return;
    const requestId = ++uhCashbookRequestId;
    const params = new URLSearchParams({
        page: String(uhCashbookPage),
        page_size: document.getElementById("uh-page-size").value || "25",
    });
    const from = document.getElementById("uh-date-from").value || "";
    const to = document.getElementById("uh-date-to").value || "";
    const [sortBy, sortDir] = (document.getElementById("uh-cashbook-sort").value || "date:desc").split(":");
    if (from) params.set("date_from", from);
    if (to) params.set("date_to", to);
    params.set("sort_by", sortBy);
    params.set("sort_dir", sortDir);

    const body = document.getElementById("uh-cashbook-body");
    body.innerHTML = uhDataState("loading", "Loading cashbook...", 8);
    document.getElementById("uh-cashbook-pager").innerHTML = "";

    let data;
    try {
        const response = await fetch(`/user/cashbook?${params.toString()}`, { signal: uhPageSignal });
        if (!response.ok) throw new Error("Unable to load cashbook. Refresh the page to try again.");
        data = await response.json();
        if (uhPageSignal.aborted || requestId !== uhCashbookRequestId) return;
    } catch (error) {
        if (uhPageSignal.aborted || requestId !== uhCashbookRequestId) return;
        body.innerHTML = uhDataState("error", error.message || "Unable to load cashbook.", 8);
        document.getElementById("uh-cashbook-pager").innerHTML = "";
        return;
    }

    if (!data || data.status !== "success" || !Array.isArray(data.data)) {
        body.innerHTML = uhDataState("error", data?.message || "Unable to load cashbook.", 8);
        document.getElementById("uh-cashbook-pager").innerHTML = "";
        return;
    }

    uhRenderPager("uh-cashbook-pager", data.pagination || {}, "cashbook");

    if (data.data.length === 0) {
        body.innerHTML = uhDataState("empty", "No debt cashbook entries found.", 8);
        return;
    }

    body.innerHTML = data.data.map((row) => {
        const referenceId = Number(row.reference_id || 0);
        const canOpenReceipt = String(row.reference_type || "").toLowerCase() === "transaction" && referenceId > 0;
        const remarks = uhEscape(row.remarks || "-");
        const remarksHtml = canOpenReceipt
            ? `<button class="user-inline-link" type="button" data-receipt-id="${referenceId}">${remarks}</button>`
            : remarks;

        return `
        <tr>
            <td data-label="Date">${uhEscape(uhDateTime(row.created_at))}</td>
            <td data-label="Entry">${uhEscape(uhEntryLabel(row.entry_type))}</td>
            <td data-label="Direction">${uhEscape(String(row.direction || "").toUpperCase())}</td>
            <td data-label="Amount">${uhEscape(uhMoney(row.amount))}</td>
            <td data-label="Debt Before">${uhEscape(uhMoney(row.debt_before))}</td>
            <td data-label="Debt After">${uhEscape(uhMoney(row.debt_after))}</td>
            <td data-label="Available Credit">${uhEscape(uhMoney(row.available_credit_snapshot))}</td>
            <td data-label="Remarks">${remarksHtml}</td>
        </tr>
    `;
    }).join("");
}

async function openReceipt(transactionId) {
    if (uhPageSignal.aborted) return;
    const requestId = ++uhReceiptRequestId;
    const modal = document.getElementById("uh-receipt-modal");
    const content = document.getElementById("uh-receipt-content");
    const printButton = document.getElementById("uh-receipt-print");
    uhReceiptTrigger = document.activeElement;
    uhSelectedReceipt = null;
    content.innerHTML = '<div class="receipt-loading" role="status">Loading receipt...</div>';
    printButton.disabled = true;
    modal.classList.remove("is-hidden");
    document.getElementById("uh-receipt-close").focus();

    try {
        const response = await fetch(`/user/transactions/${transactionId}`, { signal: uhPageSignal });
        const data = await response.json();
        if (uhPageSignal.aborted || requestId !== uhReceiptRequestId) return;

        if (!response.ok || !data || data.status !== "success" || !data.transaction) {
            throw new Error(data?.message || "Unable to load this receipt.");
        }

        const tx = data.transaction;
        uhSelectedReceipt = {
            transactionId: tx.id,
            clientTxnId: tx.client_txn_id,
            createdAt: tx.created_at,
            storeName: tx.store_name,
            customerName: tx.customer_name,
            paymentMethod: tx.payment_method,
            payments: tx.payments,
            totalAmount: tx.amount,
            items: tx.items,
            lookupUrl: `${window.location.origin}/user/receipt/${encodeURIComponent(String(tx.id))}`,
        };

        if (!window.IbemsReceipt) {
            throw new Error("Receipt renderer is unavailable.");
        }
        window.IbemsReceipt.renderReceipt("uh-receipt-content", uhSelectedReceipt);
        printButton.disabled = false;
    } catch (error) {
        if (uhPageSignal.aborted || requestId !== uhReceiptRequestId) return;
        content.innerHTML = `<div class="receipt-error" role="alert">${uhEscape(error.message || "Unable to load this receipt.")}</div>`;
    }
}

function closeReceipt() {
    ++uhReceiptRequestId;
    uhSelectedReceipt = null;
    document.getElementById("uh-receipt-modal").classList.add("is-hidden");
    if (uhReceiptTrigger instanceof HTMLElement) {
        uhReceiptTrigger.focus();
    }
    uhReceiptTrigger = null;
}

function printReceipt() {
    if (!uhSelectedReceipt) return;
    if (!window.IbemsReceipt || !window.IbemsReceipt.printReceipt(uhSelectedReceipt)) {
        // silent fallback
    }
}

async function loadUserHistoryAll() {
    uhUpdateResetFilters();
    await Promise.allSettled([
        loadUserSummaryCards(),
        loadUserTransactions(),
        loadUserCashbook(),
    ]);
}

function uhHasActiveFilters() {
    return document.getElementById("uh-store").value !== ""
        || document.getElementById("uh-date-from").value !== ""
        || document.getElementById("uh-date-to").value !== ""
        || document.getElementById("uh-sort").value !== "date:desc"
        || document.getElementById("uh-cashbook-sort").value !== "date:desc"
        || document.getElementById("uh-page-size").value !== "25";
}

function uhUpdateResetFilters() {
    document.getElementById("uh-reset-filters").classList.toggle("is-hidden", !uhHasActiveFilters());
}

document.getElementById("uh-reset-filters").addEventListener("click", () => {
    document.getElementById("uh-store").value = "";
    document.getElementById("uh-date-from").value = "";
    document.getElementById("uh-date-to").value = "";
    document.getElementById("uh-sort").value = "date:desc";
    document.getElementById("uh-cashbook-sort").value = "date:desc";
    document.getElementById("uh-page-size").value = "25";
    ["uh-store", "uh-date-from", "uh-date-to", "uh-sort", "uh-cashbook-sort", "uh-page-size"].forEach((id) => {
        document.getElementById(id).dispatchEvent(new CustomEvent("ibems:control-sync", { bubbles: true }));
    });
    uhTransactionPage = 1;
    uhCashbookPage = 1;
    uhUpdateResetFilters();
    loadUserHistoryAll();
    document.querySelector('[data-compact-filter-toggle="sort"]')?.focus();
});

["uh-store", "uh-sort"].forEach((id) => {
    document.getElementById(id).addEventListener("change", () => {
        uhTransactionPage = 1;
        uhUpdateResetFilters();
        loadUserTransactions();
    });
});
document.getElementById("uh-page-size").addEventListener("change", () => {
    uhTransactionPage = 1;
    uhCashbookPage = 1;
    uhUpdateResetFilters();
    Promise.allSettled([loadUserTransactions(), loadUserCashbook()]);
});
["uh-date-from", "uh-date-to"].forEach((id) => {
    document.getElementById(id).addEventListener("change", () => {
        uhTransactionPage = 1;
        uhCashbookPage = 1;
        loadUserHistoryAll();
    });
});
document.getElementById("uh-cashbook-sort").addEventListener("change", () => {
    uhCashbookPage = 1;
    uhUpdateResetFilters();
    loadUserCashbook();
});
function uhRestorePagerFocus(id, label) {
    if (!label || uhPageSignal.aborted || document.activeElement !== document.body) return;
    const buttons = Array.from(document.getElementById(id).querySelectorAll("button:not(:disabled)"));
    const target = buttons.find((button) => button.getAttribute("aria-label") === label)
        || buttons[0] || document.getElementById("uh-store").parentElement.querySelector("button");
    target?.focus();
}

document.getElementById("uh-transactions-pager").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-transactions-page]");
    if (!button || button.disabled) return;
    uhTransactionPage = Math.max(1, Number(button.dataset.transactionsPage || 1));
    const label = document.activeElement === button ? button.getAttribute("aria-label") : null;
    const pending = loadUserTransactions();
    const requestId = uhTransactionRequestId;
    await pending;
    if (requestId === uhTransactionRequestId) uhRestorePagerFocus("uh-transactions-pager", label);
});
document.getElementById("uh-cashbook-pager").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-cashbook-page]");
    if (!button || button.disabled) return;
    uhCashbookPage = Math.max(1, Number(button.dataset.cashbookPage || 1));
    const label = document.activeElement === button ? button.getAttribute("aria-label") : null;
    const pending = loadUserCashbook();
    const requestId = uhCashbookRequestId;
    await pending;
    if (requestId === uhCashbookRequestId) uhRestorePagerFocus("uh-cashbook-pager", label);
});

document.getElementById("uh-body").addEventListener("click", async (event) => {
    const receiptButton = event.target.closest("[data-receipt-id]");
    if (receiptButton) {
        await openReceipt(Number(receiptButton.dataset.receiptId));
        return;
    }
    const row = event.target.closest("[data-txn-id]");
    if (!row) return;
    await openReceipt(Number(row.dataset.txnId));
});

document.getElementById("uh-cashbook-body").addEventListener("click", async (event) => {
    const receiptButton = event.target.closest("[data-receipt-id]");
    if (!receiptButton) return;
    await openReceipt(Number(receiptButton.dataset.receiptId));
});

document.getElementById("uh-receipt-close").addEventListener("click", closeReceipt);
document.getElementById("uh-receipt-print").addEventListener("click", printReceipt);
document.getElementById("uh-receipt-modal").addEventListener("click", (event) => {
    if (event.target.id === "uh-receipt-modal") {
        closeReceipt();
    }
});
document.getElementById("uh-receipt-modal").addEventListener("keydown", (event) => {
    if (event.defaultPrevented) return;
    if (event.key === "Escape") {
        event.preventDefault();
        closeReceipt();
        return;
    }
    if (event.key !== "Tab") return;
    const controls = Array.from(event.currentTarget.querySelectorAll('button, a[href], input, select, textarea, [tabindex]'))
        .filter((control) => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length);
    const first = controls[0];
    const last = controls[controls.length - 1];
    if ((event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
        event.preventDefault();
        (event.shiftKey ? last : first)?.focus();
    }
}, { signal: uhPageSignal });
loadUserHistoryAll();
}());
