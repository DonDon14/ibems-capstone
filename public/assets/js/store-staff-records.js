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
    el.classList.toggle("is-error", Boolean(message) && type === "error");
}

function srSetScannerStatus(message, isError = false) {
    const el = document.getElementById("staff-scanner-status");
    if (!el) return;
    el.textContent = message || "";
    el.classList.toggle("is-error", Boolean(message) && isError);
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
    document.getElementById("staff-scanner-modal").classList.remove("is-hidden");
    document.body.classList.add("app-modal-open");
    srSetScannerUiState(srScannerRunning);
    srSetScannerStatus("Starting scanner...");
    await srStartScanner();
}

async function srCloseScannerModal() {
    await srStopScanner();
    document.getElementById("staff-scanner-modal").classList.add("is-hidden");
    document.body.classList.remove("app-modal-open");
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
    document.getElementById("staff-employee-title").textContent = "Transaction history";
    const scope = document.querySelector("[data-staff-transaction-scope]");
    if (scope) scope.innerHTML = `<i class="bi bi-shop" aria-hidden="true"></i><span>Showing purchases and receipts from <strong>${srEscape(storeName)}</strong>. Account balances remain totals across all stores.</span>`;
}

function srRenderDebts(rows) {
    const body = document.getElementById("debt-body");
    const countText = document.getElementById("staff-count-text");
    const countBadge = document.getElementById("staff-count-badge");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = srDebtDataState("empty", "No debt records found.");
        if (countText) countText.textContent = "Showing 0 records";
        if (countBadge) countBadge.textContent = "0 employees";
        return;
    }

    if (countText) countText.textContent = `Showing ${rows.length} ${rows.length === 1 ? "record" : "records"}`;
    if (countBadge) countBadge.textContent = `${rows.length} employee${rows.length === 1 ? "" : "s"}`;

    body.innerHTML = rows.map((row) => {
        const currentDebt = Math.max(0, Number(row.current_debt || 0));
        const creditLimit = Math.max(0, Number(row.credit_limit || 0));
        const debtUsage = creditLimit > 0 ? Math.min(100, (currentDebt / creditLimit) * 100) : 0;
        return `
        <article class="staff-record-row sr-row-clickable interactive-record-row flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4"
            data-debt-user-id="${Number(row.id || 0)}"
            data-debt-name="${srEscape(row.name)}"
            data-debt-employee="${srEscape(row.employee_id || "")}"
            data-debt-email="${srEscape(row.email || "")}"
            data-debt-photo="${srEscape(row.profile_image_url || "")}"
            data-debt-category="${srEscape(row.user_type || "")}"
            data-current-debt="${currentDebt}"
            data-credit-limit="${creditLimit}"
            data-available-credit="${Math.max(0, Number(row.available_credit || 0))}">
            <div class="staff-person flex min-w-0 items-center gap-3">
                ${window.IbemsAvatar.html(row.name, row.profile_image_url, "staff-avatar h-11 w-11 flex-none rounded-full border border-slate-200 bg-slate-50")}
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
                <div class="staff-fin-kv staff-fin-debt grid gap-0.5">
                    <span class="text-xs text-slate-500">Debt used</span>
                    <strong class="text-sm font-semibold ${currentDebt > 0 ? "staff-money-debt text-rose-600" : "text-slate-900"}">${srEscape(srMoney(currentDebt))}</strong>
                    <div class="staff-debt-meter"><i style="width:${debtUsage}%"></i></div>
                    <small>${debtUsage.toFixed(0)}% of limit</small>
                </div>
                <div class="staff-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Credit limit</span>
                    <strong class="text-sm font-semibold text-slate-900">${srEscape(srMoney(creditLimit))}</strong>
                </div>
                <div class="staff-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Available credit</span>
                    <strong class="text-sm font-semibold text-slate-900">${srEscape(srMoney(row.available_credit))}</strong>
                </div>
                <button class="secondary-btn btn-sm staff-view-transactions" type="button"><i class="bi bi-clock-history"></i> Transaction history</button>
            </div>
        </article>
    `;
    }).join("");
}
