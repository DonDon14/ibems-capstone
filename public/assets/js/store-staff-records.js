let srStores = [];
let srStoreId = null;
let srSelectedReceipt = null;
let srSelectedEmployee = null;
let srScannerEngine = null;
let srScannerRunning = false;
let srScannerLastDetected = "";
let srScannerLastDetectedAt = 0;

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
    document.getElementById("staff-scanner-modal").style.display = "grid";
    srSetScannerUiState(srScannerRunning);
    srSetScannerStatus("Starting scanner...");
    await srStartScanner();
}

async function srCloseScannerModal() {
    await srStopScanner();
    document.getElementById("staff-scanner-modal").style.display = "none";
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

function srActiveStoreName() {
    const store = srStores.find((row) => Number(row.id) === Number(srStoreId));
    return String(store?.store_name || "current store");
}

function srRenderDebts(rows) {
    const body = document.getElementById("debt-body");
    const countText = document.getElementById("staff-count-text");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = srDebtDataState("empty", "No debt records found.");
        if (countText) countText.textContent = "Showing 0 records";
        return;
    }

    if (countText) countText.textContent = `Showing ${rows.length} records`;

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
                        <span class="table-status ${Number(row.is_active || 0) === 1 ? "is-active" : "is-inactive"}">${Number(row.is_active || 0) === 1 ? "Active" : "Inactive"}</span>
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
            </div>
        </article>
    `).join("");
}

function srRenderTransactions(rows) {
    const body = document.getElementById("txn-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td class="px-3 py-4 text-sm text-slate-500" colspan="4">No employee transactions found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr class="sr-row-clickable table-row-clickable" data-txn-id="${row.id}">
            <td class="border border-slate-200 px-3 py-2 text-sm text-slate-700">${srEscape(srDateTime(row.created_at))}</td>
            <td class="border border-slate-200 px-3 py-2 text-sm text-slate-700">${srEscape(String(row.payment_method || "").toUpperCase())}</td>
            <td class="border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900">${srEscape(srMoney(row.amount))}</td>
            <td class="border border-slate-200 px-3 py-2 text-sm text-slate-700">${srEscape(srMoney(row.staff.current_debt))}</td>
        </tr>
    `).join("");
}

async function srLoadDebtRecords() {
    const q = document.getElementById("debt-search").value.trim();
    const params = new URLSearchParams();
    if (q) params.set("q", q);

    const body = document.getElementById("debt-body");
    const countText = document.getElementById("staff-count-text");
    body.innerHTML = srDebtDataState("loading", "Loading debt records...");

    let data;
    try {
        const response = await fetch(`/store/debt-customers?${params.toString()}`);
        data = await response.json();
    } catch (error) {
        const message = error?.message || "Unable to load debt records.";
        body.innerHTML = srDebtDataState("error", message);
        if (countText) countText.textContent = "Showing 0 records";
        srSetResult(message, "error");
        return;
    }
    if (!data || data.status !== "success") {
        const message = data?.message || "Unable to load debt records.";
        body.innerHTML = srDebtDataState("error", message);
        if (countText) countText.textContent = "Showing 0 records";
        srSetResult(message, "error");
        return;
    }
    srRenderDebts(data.customers);
}

async function srLoadStaffTransactions() {
    if (!srSelectedEmployee || !srSelectedEmployee.userId) {
        srRenderTransactions([]);
        return;
    }

    const params = new URLSearchParams({
        store_id: String(srStoreId),
        limit: "120",
        user_id: String(srSelectedEmployee.userId),
    });

    const dateFrom = document.getElementById("txn-date-from").value || "";
    const dateTo = document.getElementById("txn-date-to").value || "";
    const debtOnly = document.getElementById("txn-debt-only").checked;

    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    if (debtOnly) params.set("debt_only", "1");

    const response = await fetch(`/store/staff-transactions?${params.toString()}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        srSetResult(data?.message || "Unable to load employee transactions.", "error");
        srRenderTransactions([]);
        return;
    }
    srRenderTransactions(data.transactions);
}

function srOpenEmployeeModal(employee) {
    srSelectedEmployee = employee;
    const title = document.getElementById("staff-employee-title");
    if (title) title.textContent = `Transactions in ${srActiveStoreName()}`;
    document.getElementById("staff-employee-summary").innerHTML = `
        <div><strong>Employee:</strong> ${srEscape(employee.name)}</div>
        <div><strong>Employee ID:</strong> ${srEscape(employee.employeeId || "-")} | <strong>Email:</strong> ${srEscape(employee.email || "-")} | <strong>Category:</strong> ${srEscape(srCategory(employee.userType))}</div>
        <div><strong>Scope:</strong> Showing transactions recorded in ${srEscape(srActiveStoreName())}. Debt balance above is overall across stores.</div>
    `;
    document.getElementById("staff-employee-modal").style.display = "grid";
}

function srCloseEmployeeModal() {
    document.getElementById("staff-employee-modal").style.display = "none";
    srSelectedEmployee = null;
    document.getElementById("txn-date-from").value = "";
    document.getElementById("txn-date-to").value = "";
    document.getElementById("txn-debt-only").checked = false;
    document.getElementById("txn-body").innerHTML = '<tr><td class="px-3 py-4 text-sm text-slate-500" colspan="4">Select an employee to load transactions.</td></tr>';
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
    const response = await fetch(`/store/transactions/${transactionId}`);
    const data = await response.json();
    if (!data || data.status !== "success" || !data.transaction) {
        srSetResult(data?.message || "Unable to load receipt.", "error");
        return;
    }

    srSelectedReceipt = data.transaction;
    document.getElementById("staff-receipt-content").innerHTML = srBuildReceiptHtml(srSelectedReceipt);
    document.getElementById("staff-receipt-modal").style.display = "grid";
}

function srCloseReceipt() {
    document.getElementById("staff-receipt-modal").style.display = "none";
}

function srPrintReceipt() {
    if (!srSelectedReceipt) return;

    const printWindow = window.open("", "_blank", "width=800,height=900");
    if (!printWindow) {
        srSetResult("Popup blocked. Please allow popups.", "error");
        return;
    }

    const html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt ${srEscape(srSelectedReceipt.client_txn_id)}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; color: #111; }
                h2 { margin-top: 0; color: #003366; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { border: 1px solid #ddd; padding: 8px; font-size: 13px; }
                th { background: #f4f4f4; text-align: left; }
                .receipt-total { margin-top: 12px; text-align: right; font-weight: bold; font-size: 16px; }
            </style>
        </head>
        <body>
            <h2>USTP Store Receipt</h2>
            ${srBuildReceiptHtml(srSelectedReceipt)}
        </body>
        </html>
    `;

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

document.getElementById("debt-search-btn").addEventListener("click", async () => {
    srSetResult("", "ok");
    await srLoadDebtRecords();
});

document.getElementById("debt-search").addEventListener("keydown", async (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    srSetResult("", "ok");
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
