let srStores = [];
let srStoreId = null;
let srSelectedReceipt = null;
let srSelectedEmployee = null;
let srScannerEngine = null;
let srScannerRunning = false;
let srScannerLastDetected = "";
let srScannerLastDetectedAt = 0;
let srEmployeeTrigger = null;
let srReceiptTrigger = null;
let srScannerTrigger = null;
let srDebtRequestSequence = 0;
let srTransactionRequestSequence = 0;
let srDebtPage = 1;
let srTransactionPage = 1;

function srEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function srMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function srDebtDataState(type, message) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    return `<div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${srEscape(message)}</strong></div></div>`;
}

function srTransactionDataState(type, message) {
    const safeType = ["loading", "empty", "error"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-receipt",
        error: "bi bi-exclamation-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    return `<tr><td colspan="5"><div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${srEscape(message)}</strong></div></div></td></tr>`;
}

function srDateTime(value) {
    return window.IbemsFormat?.dateTime(value) || new Date(value).toLocaleString();
}

function srCategory(value) {
    const text = String(value || "");
    if (!text) return "-";
    return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
}

function srSetResult(message, type) {
    const el = document.getElementById("staff-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function srSetScannerStatus(message, isError = false) {
    const el = document.getElementById("staff-scanner-status");
    if (!el) return;
    el.textContent = message || "";
    el.style.color = isError ? "#b91c1c" : "#475569";
}

function srSetScannerUiState(running) {
    const scanBtn = document.getElementById("debt-search-scan-btn");
    const stopBtn = document.getElementById("staff-scanner-stop");
    if (scanBtn) scanBtn.disabled = !!running;
    if (stopBtn) stopBtn.disabled = !running;
}

function srExtractScanCode(rawValue) {
    const raw = String(rawValue || "").trim();
    if (!raw) return "";

    try {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === "object") {
            const candidate = parsed.employee_id || parsed.employeeId || parsed.email || parsed.id || parsed.value || "";
            if (candidate) return String(candidate).trim();
        }
    } catch (error) {
        // Not JSON, continue with other parse modes.
    }

    if (/^https?:\/\//i.test(raw)) {
        try {
            const url = new URL(raw);
            const fromQuery =
                url.searchParams.get("employee_id") ||
                url.searchParams.get("employeeId") ||
                url.searchParams.get("email") ||
                url.searchParams.get("id") ||
                url.searchParams.get("q") ||
                url.searchParams.get("value") ||
                "";
            if (fromQuery) return String(fromQuery).trim();

            const chunks = url.pathname.split("/").filter(Boolean);
            const last = chunks[chunks.length - 1] || "";
            if (last) return String(last).trim();
        } catch (error) {
            // Invalid URL, fallback to raw.
        }
    }

    return raw;
}

async function srOpenScannerModal() {
    srScannerTrigger = document.activeElement;
    document.getElementById("staff-scanner-modal").style.display = "grid";
    srSetScannerUiState(srScannerRunning);
    srSetScannerStatus("Starting scanner...");
    await srStartScanner();
}

async function srCloseScannerModal() {
    await srStopScanner();
    document.getElementById("staff-scanner-modal").style.display = "none";
    srScannerTrigger?.focus?.();
    srScannerTrigger = null;
}

async function srStartScanner() {
    if (srScannerRunning) return;

    if (typeof window.Html5Qrcode === "undefined") {
        srSetScannerStatus("Scanner library not loaded. Check internet/CDN access.", true);
        srSetScannerUiState(false);
        return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        srSetScannerStatus("Camera API not available in this browser.", true);
        srSetScannerUiState(false);
        return;
    }

    const readerId = "staff-scanner-reader";
    const readerEl = document.getElementById(readerId);
    readerEl.innerHTML = "";
    srScannerEngine = new window.Html5Qrcode(readerId);

    try {
        await srScannerEngine.start(
            { facingMode: "environment" },
            {
                fps: 10,
                qrbox: { width: 260, height: 180 },
                aspectRatio: 1.7777778,
            },
            async (decodedText) => {
                const raw = String(decodedText || "").trim();
                if (!raw) return;

                const now = Date.now();
                if (raw === srScannerLastDetected && now - srScannerLastDetectedAt <= 1200) return;
                srScannerLastDetected = raw;
                srScannerLastDetectedAt = now;

                const resolved = srExtractScanCode(raw);
                if (!resolved) return;

                srSetScannerStatus(`Detected: ${resolved}`);
                document.getElementById("debt-search").value = resolved;
                await srLoadDebtRecords();
                await srCloseScannerModal();
                document.getElementById("debt-search").focus();
                srSetResult(`Scanned and searched: ${resolved}`, "ok");
            },
            () => {}
        );
    } catch (error) {
        srScannerEngine = null;
        srScannerRunning = false;
        srSetScannerStatus("Failed to start camera scanner.", true);
        srSetScannerUiState(false);
        return;
    }

    srScannerRunning = true;
    srSetScannerUiState(true);
    srSetScannerStatus("Scanning... Point camera to employee QR/ID.");
}

async function srStopScanner() {
    if (!srScannerRunning || !srScannerEngine) {
        srScannerRunning = false;
        srSetScannerUiState(false);
        return;
    }

    try {
        await srScannerEngine.stop();
    } catch (error) {
        // Ignore stop errors for already-stopped sessions.
    }

    try {
        await srScannerEngine.clear();
    } catch (error) {
        // Ignore clear errors.
    }

    srScannerEngine = null;
    srScannerRunning = false;
    srSetScannerUiState(false);
}

async function srLoadStores() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();
    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }
    srStores = data.stores;
    srStoreId = Number(data.default_store_id || data.stores[0].id);
}

function srRenderPager(targetId, pagination, pageAttribute) {
    const target = document.getElementById(targetId);
    if (!target) return;
    const page = Number(pagination?.page || 1);
    const totalPages = Number(pagination?.total_pages || 1);
    const total = Number(pagination?.total || 0);
    const pageSize = Number(pagination?.page_size || 20);
    if (total <= 0) {
        target.innerHTML = "";
        return;
    }
    const from = (page - 1) * pageSize + 1;
    const to = Math.min(total, page * pageSize);
    target.innerHTML = `
        <span class="pagination-summary">${from}-${to} of ${total}</span>
        <div class="pagination-actions">
            <button class="secondary-btn btn-sm" type="button" data-${pageAttribute}="${page - 1}" ${page <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left"></i> Previous</button>
            <span>Page ${page} of ${totalPages}</span>
            <button class="secondary-btn btn-sm" type="button" data-${pageAttribute}="${page + 1}" ${page >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right"></i></button>
        </div>`;
}

function srIsActive(value) {
    return value === true || value === 1 || value === "1" || value === "t" || value === "true";
}

function srSetTransactionResult(message, type = "ok") {
    const el = document.getElementById("txn-filter-result");
    if (!el) return;
    el.textContent = message || "";
    el.classList.toggle("is-error", type === "error");
    el.setAttribute("role", type === "error" ? "alert" : "status");
}

function srActiveStoreName() {
    const store = srStores.find((row) => Number(row.id) === Number(srStoreId));
    return String(store?.store_name || "current store");
}

function srRenderTransactionStores() {
    const select = document.getElementById("txn-store-select");
    select.innerHTML = srStores.map((store) => `<option value="${Number(store.id)}">${srEscape(store.store_name || "Store")}</option>`).join("");
    select.value = String(srStoreId);
}

function srUpdateTransactionScope() {
    const storeName = srActiveStoreName();
    document.getElementById("staff-employee-title").textContent = `Transactions at ${storeName}`;
    const scope = document.querySelector("[data-staff-transaction-scope]");
    if (scope) scope.innerHTML = `<strong>Scope:</strong> Purchases and receipts recorded at ${srEscape(storeName)}. Credit and debt above remain totals across all stores.`;
}

function srRenderDebts(rows) {
    const body = document.getElementById("debt-body");
    const countText = document.getElementById("staff-count-text");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = srDebtDataState("empty", "No debt records found.");
        if (countText) countText.textContent = "Showing 0 records";
        return;
    }

    if (countText) countText.textContent = `Showing ${rows.length} ${rows.length === 1 ? "record" : "records"}`;

    body.innerHTML = rows.map((row) => `
        <article class="staff-record-row sr-row-clickable flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:-translate-y-0.5 hover:shadow-sm"
            data-debt-user-id="${Number(row.id || 0)}"
            data-debt-name="${srEscape(row.name)}"
            data-debt-employee="${srEscape(row.employee_id || "")}"
            data-debt-email="${srEscape(row.email || "")}"
            data-debt-category="${srEscape(row.user_type || "")}">
            <div class="staff-person flex min-w-0 items-center gap-3">
                <div class="staff-avatar inline-flex h-11 w-11 flex-none items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-sm font-bold text-blue-700">${srEscape(String(row.name || "U").split(" ").filter(Boolean).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "U")}</div>
                <div class="staff-person-meta min-w-0">
                    <div class="staff-name-line flex flex-wrap items-center gap-2">
                        <strong class="text-base font-bold text-slate-900">${srEscape(row.name)}</strong>
                        <span class="table-chip">${srEscape(srCategory(row.user_type))}</span>
                        <span class="table-status ${srIsActive(row.is_active) ? "is-active" : "is-inactive"}">${srIsActive(row.is_active) ? "Active" : "Inactive"}</span>
                    </div>
                    <div class="staff-subline truncate text-sm text-slate-500">${srEscape(row.employee_id || "-")} | ${srEscape(row.email)}</div>
                </div>
            </div>
            <div class="staff-finance flex flex-wrap items-center justify-end gap-4">
                <div class="staff-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Overall Debt</span>
                    <strong class="text-sm font-semibold ${Number(row.current_debt || 0) > 0 ? "staff-money-debt text-rose-600" : "text-slate-900"}">${srEscape(srMoney(row.current_debt))}</strong>
                    <div class="table-debt-bar"><i style="width:${Math.min(100, (Number(row.current_debt || 0) / Math.max(1, Number(row.credit_limit || 0))) * 100)}%"></i></div>
                </div>
                <div class="staff-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Credit Limit</span>
                    <strong class="text-sm font-semibold text-slate-900">${srEscape(srMoney(row.credit_limit))}</strong>
                </div>
                <div class="staff-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Overall Available Credit</span>
                    <strong class="text-sm font-semibold text-slate-900">${srEscape(srMoney(row.available_credit))}</strong>
                </div>
                <button class="secondary-btn btn-sm staff-view-transactions" type="button"><i class="bi bi-receipt"></i> View transactions</button>
            </div>
        </article>
    `).join("");
}

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
    if (countText) countText.textContent = `${Number(data.pagination?.total || 0)} matching ${Number(data.pagination?.total || 0) === 1 ? "record" : "records"}`;
    searchButton.disabled = false;
}

async function srLoadStaffTransactions() {
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
    const applyButton = document.getElementById("txn-search-btn");
    body.innerHTML = srTransactionDataState("loading", "Loading employee transactions...");
    applyButton.disabled = true;
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
        applyButton.disabled = false;
        return;
    }
    if (requestId !== srTransactionRequestSequence) return;
    if (!data || data.status !== "success") {
        const message = data?.message || "Unable to load employee transactions.";
        body.innerHTML = srTransactionDataState("error", message);
        document.getElementById("txn-pager").innerHTML = "";
        srSetTransactionResult(message, "error");
        applyButton.disabled = false;
        return;
    }
    srRenderTransactions(data.transactions);
    srTransactionPage = Number(data.pagination?.page || 1);
    srRenderPager("txn-pager", data.pagination, "txn-page");
    const total = Number(data.pagination?.total || 0);
    srSetTransactionResult(`${total} matching transaction${total === 1 ? "" : "s"} for ${srActiveStoreName()}.`);
    applyButton.disabled = false;
}

function srOpenEmployeeModal(employee) {
    srEmployeeTrigger = document.activeElement;
    srSelectedEmployee = employee;
    srTransactionPage = 1;
    srRenderTransactionStores();
    document.getElementById("staff-employee-summary").innerHTML = `
        <div><strong>Employee:</strong> ${srEscape(employee.name)}</div>
        <div><strong>Employee ID:</strong> ${srEscape(employee.employeeId || "-")} | <strong>Email:</strong> ${srEscape(employee.email || "-")} | <strong>Category:</strong> ${srEscape(srCategory(employee.userType))}</div>
        <div data-staff-transaction-scope></div>
    `;
    srUpdateTransactionScope();
    document.getElementById("staff-employee-modal").style.display = "grid";
    window.requestAnimationFrame(() => document.getElementById("staff-employee-close").focus());
}

function srCloseEmployeeModal() {
    document.getElementById("staff-employee-modal").style.display = "none";
    srSelectedEmployee = null;
    document.getElementById("txn-date-from").value = "";
    document.getElementById("txn-date-to").value = "";
    document.getElementById("txn-debt-only").checked = false;
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
    document.getElementById("staff-receipt-modal").style.display = "grid";
    window.requestAnimationFrame(() => document.getElementById("staff-receipt-close").focus());
}

function srCloseReceipt() {
    document.getElementById("staff-receipt-modal").style.display = "none";
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

document.getElementById("txn-search-btn").addEventListener("click", async () => {
    srSetResult("", "ok");
    srTransactionPage = 1;
    await srLoadStaffTransactions();
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
    document.getElementById("txn-date-from").removeAttribute("aria-invalid");
    document.getElementById("txn-date-to").removeAttribute("aria-invalid");
    srTransactionPage = 1;
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
        userType: row.getAttribute("data-debt-category") || "",
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

window.addEventListener("beforeunload", () => {
    srStopScanner();
});

document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    if (document.getElementById("staff-receipt-modal").style.display === "grid") {
        event.preventDefault();
        srCloseReceipt();
        return;
    }
    if (document.getElementById("staff-scanner-modal").style.display === "grid") {
        event.preventDefault();
        srCloseScannerModal();
        return;
    }
    if (document.getElementById("staff-employee-modal").style.display === "grid") {
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
