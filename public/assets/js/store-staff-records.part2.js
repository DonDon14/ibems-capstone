function srRenderTransactions(rows) {
    const body = document.getElementById("txn-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = srTransactionDataState("empty", "No employee transactions match these filters.");
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr class="sr-row-clickable table-row-clickable" data-txn-id="${row.id}">
            <td class="border border-slate-200 px-3 py-2 text-sm text-slate-700">${srEscape(srDateTime(row.created_at))}</td>
            <td class="border border-slate-200 px-3 py-2 text-sm text-slate-700">${srEscape(String(row.payment_method || "").toUpperCase())}</td>
            <td class="border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900">${srEscape(srMoney(row.amount))}</td>
            <td class="border border-slate-200 px-3 py-2 text-sm text-slate-700">${srEscape(srMoney(row.staff?.current_debt))}</td>
            <td class="border border-slate-200 px-3 py-2 text-sm"><button class="secondary-btn btn-sm" type="button"><i class="bi bi-receipt"></i> View receipt</button></td>
        </tr>
    `).join("");
}

async function srLoadDebtRecords() {
    const requestId = ++srDebtRequestSequence;
    const q = document.getElementById("debt-search").value.trim();
    const params = new URLSearchParams();
    if (q) params.set("q", q);
    const [sortBy, sortDir] = document.getElementById("debt-sort").value.split(":");
    params.set("page", String(srDebtPage));
    params.set("page_size", document.getElementById("debt-page-size").value);
    params.set("sort_by", sortBy);
    params.set("sort_dir", sortDir);

    const body = document.getElementById("debt-body");
    const countText = document.getElementById("staff-count-text");
    const searchButton = document.getElementById("debt-search-btn");
    body.innerHTML = srDebtDataState("loading", "Loading debt records...");
    if (countText) countText.textContent = q ? "Searching employee records..." : "Loading employee records...";
    searchButton.disabled = true;

    let data;
    try {
        const response = await fetch(`/store/debt-customers?${params.toString()}`);
        data = await response.json();
    } catch (error) {
        if (requestId !== srDebtRequestSequence) return;
        const message = error?.message || "Unable to load debt records.";
        body.innerHTML = srDebtDataState("error", message);
        document.getElementById("debt-pager").innerHTML = "";
        if (countText) countText.textContent = "Showing 0 records";
        srSetResult(message, "error");
        searchButton.disabled = false;
        return;
    }
    if (requestId !== srDebtRequestSequence) return;
    if (!data || data.status !== "success") {
        const message = data?.message || "Unable to load debt records.";
        body.innerHTML = srDebtDataState("error", message);
        document.getElementById("debt-pager").innerHTML = "";
        if (countText) countText.textContent = "Showing 0 records";
        srSetResult(message, "error");
        searchButton.disabled = false;
        return;
    }
    srRenderDebts(data.customers);
    srDebtPage = Number(data.pagination?.page || 1);
    srRenderPager("debt-pager", data.pagination, "debt-page");
    const total = Number(data.pagination?.total || 0);
    if (countText) countText.textContent = `${total} matching ${total === 1 ? "record" : "records"}`;
    const countBadge = document.getElementById("staff-count-badge");
    if (countBadge) countBadge.textContent = `${total} employee${total === 1 ? "" : "s"}`;
    searchButton.disabled = false;
}

async function srLoadStaffTransactions() {
    srUpdateTransactionResetVisibility();
    if (!srSelectedEmployee || !srSelectedEmployee.userId) {
        srRenderTransactions([]);
        return;
    }

    const params = new URLSearchParams({
        store_id: String(srStoreId),
        user_id: String(srSelectedEmployee.userId),
    });
    const [sortBy, sortDir] = document.getElementById("txn-sort").value.split(":");
    params.set("page", String(srTransactionPage));
    params.set("page_size", document.getElementById("txn-page-size").value);
    params.set("sort_by", sortBy);
    params.set("sort_dir", sortDir);

    const dateFrom = document.getElementById("txn-date-from").value || "";
    const dateTo = document.getElementById("txn-date-to").value || "";
    const debtOnly = document.getElementById("txn-debt-only").checked;
    const dateFromInput = document.getElementById("txn-date-from");
    const dateToInput = document.getElementById("txn-date-to");
    dateFromInput.removeAttribute("aria-invalid");
    dateToInput.removeAttribute("aria-invalid");

    if (dateFrom && dateTo && dateFrom > dateTo) {
        srSetTransactionResult("From date cannot be later than To date.", "error");
        dateFromInput.setAttribute("aria-invalid", "true");
        dateToInput.setAttribute("aria-invalid", "true");
        dateFromInput.focus();
        return;
    }
    const requestId = ++srTransactionRequestSequence;

    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    if (debtOnly) params.set("debt_only", "1");

    const body = document.getElementById("txn-body");
    body.innerHTML = srTransactionDataState("loading", "Loading employee transactions...");
    srSetTransactionResult("");

    let data;
    try {
        const response = await fetch(`/store/staff-transactions?${params.toString()}`);
        data = await response.json();
    } catch (error) {
        if (requestId !== srTransactionRequestSequence) return;
        const message = error?.message || "Unable to load employee transactions.";
        body.innerHTML = srTransactionDataState("error", message);
        document.getElementById("txn-pager").innerHTML = "";
        srSetTransactionResult(message, "error");
        return;
    }
    if (requestId !== srTransactionRequestSequence) return;
    if (!data || data.status !== "success") {
        const message = data?.message || "Unable to load employee transactions.";
        body.innerHTML = srTransactionDataState("error", message);
        document.getElementById("txn-pager").innerHTML = "";
        srSetTransactionResult(message, "error");
        return;
    }
    srRenderTransactions(data.transactions);
    srTransactionPage = Number(data.pagination?.page || 1);
    srRenderPager("txn-pager", data.pagination, "txn-page");
    const total = Number(data.pagination?.total || 0);
    srSetTransactionResult(`${total} matching transaction${total === 1 ? "" : "s"} for ${srActiveStoreName()}.`);
}

function srUpdateTransactionResetVisibility() {
    const hasActiveFilters = document.getElementById("txn-date-from").value !== ""
        || document.getElementById("txn-date-to").value !== ""
        || document.getElementById("txn-debt-only").checked
        || document.getElementById("txn-sort").value !== "date:desc"
        || document.getElementById("txn-page-size").value !== "25";
    document.getElementById("txn-clear-btn").classList.toggle("is-hidden", !hasActiveFilters);
}

function srOpenEmployeeModal(employee) {
    srEmployeeTrigger = document.activeElement;
    srSelectedEmployee = employee;
    srTransactionPage = 1;
    srRenderTransactionStores();
    document.getElementById("staff-employee-summary").innerHTML = `
        <div class="staff-summary-person">
            ${window.IbemsAvatar.html(employee.name, employee.profileImageUrl, "staff-avatar")}
            <div><strong>${srEscape(employee.name)}</strong><span>${srEscape(employee.employeeId || "-")} &middot; ${srEscape(employee.email || "-")}</span></div>
            <span class="table-chip">${srEscape(srCategory(employee.userType))}</span>
        </div>
        <div class="staff-summary-finance">
            <div><span>Overall debt</span><strong class="${Number(employee.currentDebt || 0) > 0 ? "staff-money-debt" : ""}">${srEscape(srMoney(employee.currentDebt))}</strong></div>
            <div><span>Credit limit</span><strong>${srEscape(srMoney(employee.creditLimit))}</strong></div>
            <div><span>Available credit</span><strong>${srEscape(srMoney(employee.availableCredit))}</strong></div>
        </div>
        <div class="staff-transaction-scope" data-staff-transaction-scope></div>
    `;
    srUpdateTransactionScope();
    srUpdateTransactionResetVisibility();
    document.getElementById("staff-employee-modal").classList.remove("is-hidden");
    document.body.classList.add("app-modal-open");
    window.requestAnimationFrame(() => document.getElementById("staff-employee-close").focus());
}

function srCloseEmployeeModal() {
    document.getElementById("staff-employee-modal").classList.add("is-hidden");
    document.body.classList.remove("app-modal-open");
    srSelectedEmployee = null;
    document.getElementById("txn-date-from").value = "";
    document.getElementById("txn-date-to").value = "";
    document.getElementById("txn-debt-only").checked = false;
    srUpdateTransactionResetVisibility();
    srSetTransactionResult("");
    document.getElementById("txn-body").innerHTML = '<tr><td class="px-3 py-4 text-sm text-slate-500" colspan="5">Select an employee to load transactions.</td></tr>';
    document.getElementById("txn-pager").innerHTML = "";
    srEmployeeTrigger?.focus?.();
    srEmployeeTrigger = null;
}

function srBuildReceiptHtml(receipt) {
    const rows = receipt.items
        .map((item) => `
            <tr>
                <td class="border border-slate-200 px-3 py-2 text-sm text-slate-700">${srEscape(item.name)}</td>
                <td class="border border-slate-200 px-3 py-2 text-sm text-slate-700">${item.qty}</td>
                <td class="border border-slate-200 px-3 py-2 text-sm text-slate-700">${srMoney(item.unit_price)}</td>
                <td class="border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900">${srMoney(item.line_total)}</td>
            </tr>
        `)
        .join("");

    return `
        <div class="receipt-content-head mb-3 space-y-1 text-sm text-slate-700">
            <div><strong>Transaction #:</strong> ${srEscape(receipt.client_txn_id)}</div>
            <div><strong>Date:</strong> ${srEscape(srDateTime(receipt.created_at))}</div>
            <div><strong>Store:</strong> ${srEscape(receipt.store_name)}</div>
            <div><strong>Customer:</strong> ${srEscape(receipt.customer_name)}</div>
            <div><strong>Payment:</strong> ${srEscape(String(receipt.payment_method).toUpperCase())}</div>
        </div>
        <table class="receipt-table mb-3 w-full border-collapse overflow-hidden rounded-xl border border-slate-200">
            <thead>
                <tr>
                    <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Item</th>
                    <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Qty</th>
                    <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Price</th>
                    <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Line Total</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
        <div class="receipt-total text-right text-lg font-bold text-slate-900">Total: ${srMoney(receipt.amount)}</div>
    `;
}

async function srOpenReceipt(transactionId) {
    srReceiptTrigger = document.activeElement;
    let data;
    try {
        const response = await fetch(`/store/transactions/${transactionId}`);
        data = await response.json();
    } catch (error) {
        srSetTransactionResult(error?.message || "Unable to load receipt.", "error");
        return;
    }
    if (!data || data.status !== "success" || !data.transaction) {
        srSetTransactionResult(data?.message || "Unable to load receipt.", "error");
        return;
    }

    const tx = data.transaction;
    srSelectedReceipt = {transactionId:tx.id,clientTxnId:tx.client_txn_id,createdAt:tx.created_at,storeName:tx.store_name,customerName:tx.customer_name,paymentMethod:tx.payment_method,payments:tx.payments,totalAmount:tx.amount,items:tx.items,lookupUrl:`${window.location.origin}/store/receipt/${encodeURIComponent(String(tx.id))}`};
    if (!window.IbemsReceipt) throw new Error("Standard receipt renderer is unavailable.");
    window.IbemsReceipt.renderReceipt("staff-receipt-content", srSelectedReceipt);
    document.getElementById("staff-receipt-view").href = srSelectedReceipt.lookupUrl;
    document.getElementById("staff-receipt-modal").classList.remove("is-hidden");
    document.body.classList.add("app-modal-open");
    window.requestAnimationFrame(() => document.getElementById("staff-receipt-close").focus());
}

function srCloseReceipt() {
    document.getElementById("staff-receipt-modal").classList.add("is-hidden");
    document.body.classList.remove("app-modal-open");
    srReceiptTrigger?.focus?.();
    srReceiptTrigger = null;
}

function srPrintReceipt() {
    if (!srSelectedReceipt) return;
    if (!window.IbemsReceipt || !window.IbemsReceipt.printReceipt(srSelectedReceipt)) {
        srSetResult("Popup blocked. Please allow popups.", "error");
    }
}

document.getElementById("debt-search-btn").addEventListener("click", async () => {
    srSetResult("", "ok");
    srDebtPage = 1;
    await srLoadDebtRecords();
});

document.getElementById("debt-search").addEventListener("keydown", async (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    srSetResult("", "ok");
    srDebtPage = 1;
    await srLoadDebtRecords();
});

document.getElementById("debt-search-scan-btn").addEventListener("click", () => {
    srOpenScannerModal().catch(() => srSetScannerStatus("Unable to open scanner.", true));
});

document.getElementById("staff-scanner-close").addEventListener("click", async () => {
    await srCloseScannerModal();
});

document.getElementById("staff-scanner-stop").addEventListener("click", async () => {
    await srStopScanner();
    srSetScannerStatus("Scanner stopped.");
});

document.getElementById("staff-scanner-modal").addEventListener("click", async (event) => {
    if (event.target.id === "staff-scanner-modal") {
        await srCloseScannerModal();
    }
});

document.getElementById("txn-store-select").addEventListener("change", async (event) => {
    const requestedStoreId = Number(event.target.value || 0);
    if (!srStores.some((store) => Number(store.id) === requestedStoreId)) {
        event.target.value = String(srStoreId);
        srSetTransactionResult("That store is not available to this account.", "error");
        return;
    }
    srStoreId = requestedStoreId;
    srTransactionPage = 1;
    srUpdateTransactionScope();
    await srLoadStaffTransactions();
});

document.getElementById("debt-search").addEventListener("input", (event) => {
    document.getElementById("debt-clear-btn").classList.toggle("is-hidden", String(event.target.value || "").trim() === "");
});

document.getElementById("debt-clear-btn").addEventListener("click", async () => {
    const search = document.getElementById("debt-search");
    search.value = "";
    document.getElementById("debt-clear-btn").classList.add("is-hidden");
    srSetResult("", "ok");
    srDebtPage = 1;
    await srLoadDebtRecords();
    search.focus();
});

document.getElementById("debt-search").addEventListener("search", async (event) => {
    if (String(event.target.value || "").trim() !== "") return;
    srSetResult("", "ok");
    srDebtPage = 1;
    await srLoadDebtRecords();
});

document.getElementById("txn-clear-btn").addEventListener("click", async () => {
    document.getElementById("txn-date-from").value = "";
    document.getElementById("txn-date-to").value = "";
    document.getElementById("txn-debt-only").checked = false;
    document.getElementById("txn-sort").value = "date:desc";
    document.getElementById("txn-page-size").value = "25";
    document.getElementById("txn-date-from").removeAttribute("aria-invalid");
    document.getElementById("txn-date-to").removeAttribute("aria-invalid");
    srTransactionPage = 1;
    srUpdateTransactionResetVisibility();
    await srLoadStaffTransactions();
});

document.getElementById("debt-sort").addEventListener("change", async () => {
    srDebtPage = 1;
    await srLoadDebtRecords();
});

document.getElementById("debt-page-size").addEventListener("change", async () => {
    srDebtPage = 1;
    await srLoadDebtRecords();
});

document.getElementById("txn-sort").addEventListener("change", async () => {
    srTransactionPage = 1;
    await srLoadStaffTransactions();
});

["txn-date-from", "txn-date-to", "txn-debt-only"].forEach((controlId) => {
    document.getElementById(controlId).addEventListener("change", async () => {
        srTransactionPage = 1;
        await srLoadStaffTransactions();
    });
});

document.getElementById("txn-page-size").addEventListener("change", async () => {
    srTransactionPage = 1;
    await srLoadStaffTransactions();
});

document.getElementById("debt-pager").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-debt-page]");
    if (!button || button.disabled) return;
    srDebtPage = Number(button.getAttribute("data-debt-page") || 1);
    await srLoadDebtRecords();
});

document.getElementById("txn-pager").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-txn-page]");
    if (!button || button.disabled) return;
    srTransactionPage = Number(button.getAttribute("data-txn-page") || 1);
    await srLoadStaffTransactions();
});

document.getElementById("debt-body").addEventListener("click", async (event) => {
    const row = event.target.closest("[data-debt-user-id]");
    if (!row) return;

    const userId = Number(row.getAttribute("data-debt-user-id") || 0);
    if (!userId) return;

    srOpenEmployeeModal({
        userId,
        name: row.getAttribute("data-debt-name") || "",
        employeeId: row.getAttribute("data-debt-employee") || "",
        email: row.getAttribute("data-debt-email") || "",
        profileImageUrl: row.getAttribute("data-debt-photo") || "",
        userType: row.getAttribute("data-debt-category") || "",
        currentDebt: Number(row.getAttribute("data-current-debt") || 0),
        creditLimit: Number(row.getAttribute("data-credit-limit") || 0),
        availableCredit: Number(row.getAttribute("data-available-credit") || 0),
    });
    await srLoadStaffTransactions();
});

document.getElementById("txn-body").addEventListener("click", async (event) => {
    const row = event.target.closest("[data-txn-id]");
    if (!row) return;
    await srOpenReceipt(Number(row.getAttribute("data-txn-id")));
});

document.getElementById("staff-receipt-close").addEventListener("click", srCloseReceipt);
document.getElementById("staff-receipt-print").addEventListener("click", srPrintReceipt);
document.getElementById("staff-employee-close").addEventListener("click", srCloseEmployeeModal);
document.getElementById("staff-employee-modal").addEventListener("click", (event) => {
    if (event.target.id === "staff-employee-modal") srCloseEmployeeModal();
});
document.getElementById("staff-receipt-modal").addEventListener("click", (event) => {
    if (event.target.id === "staff-receipt-modal") srCloseReceipt();
});

const srCleanupScanner = () => {
    document.body.classList.remove("app-modal-open");
    return srStopScanner();
};
window.addEventListener("beforeunload", srCleanupScanner);
window.IbemsPortalNavigation?.onCleanup(srCleanupScanner);

document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    if (!document.getElementById("staff-receipt-modal").classList.contains("is-hidden")) {
        event.preventDefault();
        srCloseReceipt();
        return;
    }
    if (!document.getElementById("staff-scanner-modal").classList.contains("is-hidden")) {
        event.preventDefault();
        srCloseScannerModal();
        return;
    }
    if (!document.getElementById("staff-employee-modal").classList.contains("is-hidden")) {
        event.preventDefault();
        srCloseEmployeeModal();
    }
});

(async () => {
    try {
        await srLoadStores();
        await srLoadDebtRecords();
    } catch (error) {
        const message = error.message || "Unable to load staff records.";
        document.getElementById("debt-body").innerHTML = srDebtDataState("error", message);
        document.getElementById("staff-count-text").textContent = "Showing 0 records";
        srSetResult(message, "error");
    }
})();
