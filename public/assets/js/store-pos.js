let cart = [];
let productsCache = [];
let productsLoadCompleted = false;
let filteredProducts = [];
let activeStoreId = null;
let myStores = [];
let searchQuery = "";
let categories = ["All"];
let activeCategory = "All";
let debtCustomers = [];
let selectedDebtCustomerId = null;
let selectedDebtCustomer = null;
let selectedDebtPin = "";
let repaymentCustomers = [];
let selectedRepaymentCustomer = null;
let isSubmitting = false;
let lastReceipt = null;
let lastCardAddAt = 0;
let scannerEngine = null;
let scannerRunning = false;
let scannerLastDetected = "";
let scannerLastDetectedAt = 0;
let scannerMode = "product";
let pendingTransaction = null;
let paymentMethodsCache = [];
let openingBalanceReady = false;
const currentUserRole = String(document.querySelector(".pos-shell")?.dataset.userRole || "").toUpperCase();
const isAdminUser = currentUserRole === "ADMIN";
let openingBalanceMode = "create";
let currentDaySession = null;

function setOpeningReadinessState(state, message = "") {
    const shell = document.querySelector(".pos-shell");
    const banner = document.getElementById("opening-balance-banner");
    const statusEl = document.getElementById("opening-balance-status");
    const guidanceEl = document.getElementById("opening-balance-guidance");
    const normalized = ["ready", "blocked", "checking", "error"].includes(state) ? state : "checking";

    if (shell) {
        shell.classList.toggle("is-opening-ready", normalized === "ready");
        shell.classList.toggle("is-opening-blocked", normalized !== "ready");
    }

    if (banner) {
        banner.classList.remove("is-ready", "is-blocked", "is-checking", "is-error");
        banner.classList.add(`is-${normalized}`);
    }

    if (statusEl) {
        const labels = {
            ready: "Ready",
            blocked: "Setup Required",
            checking: "Checking",
            error: "Needs Review",
        };
        statusEl.textContent = labels[normalized] || "Checking";
    }

    if (guidanceEl) {
        guidanceEl.textContent = message || (
            normalized === "ready"
                ? "POS is ready for transactions."
                : "Transactions stay locked until today's store day is open."
        );
    }
}

function ensureToastHost() {
    let host = document.getElementById("toast-stack");
    if (host) return host;

    host = document.createElement("div");
    host.id = "toast-stack";
    host.className = "toast-stack";
    document.body.appendChild(host);
    return host;
}

function showToast(message, type = "success", timeoutMs = 3000) {
    const text = String(message || "").trim();
    if (text === "") return;

    const host = ensureToastHost();
    const toast = document.createElement("div");
    const cssType = type === "error" ? "is-error" : "is-success";
    toast.className = `toast-item ${cssType}`;
    toast.textContent = text;
    host.appendChild(toast);

    window.setTimeout(() => {
        toast.remove();
    }, timeoutMs);
}

async function requestJson(url, options = {}, fallbackMessage = "Request failed.") {
    let response;
    try {
        response = await fetch(url, options);
    } catch (error) {
        throw new Error("Network error. Please try again.");
    }

    let data = null;
    try {
        data = await response.json();
    } catch (error) {
        if (!response.ok) {
            throw new Error(fallbackMessage);
        }
        return {};
    }

    if (!response.ok) {
        throw new Error(data?.message || fallbackMessage);
    }

    return data || {};
}

function updateOpeningBalanceDisplay(opening = null, businessDate = null) {
    const displayEl = document.getElementById("opening-balance-display");
    const dateEl = document.getElementById("opening-balance-date");
    const openBtn = document.getElementById("opening-balance-open-btn");
    const closeBtn = document.getElementById("store-day-close-btn");
    if (!displayEl || !dateEl) return;

    if (!opening) {
        openingBalanceMode = "create";
        displayEl.textContent = "Store day not open";
        dateEl.textContent = `Business date: ${businessDate || new Date().toISOString().slice(0, 10)}`;
        setOpeningReadinessState("blocked", "Open today's store day before accepting POS transactions.");
        if (openBtn) {
            openBtn.disabled = false;
            openBtn.innerHTML = '<i class="bi bi-pencil-square"></i> Open Store Day';
        }
        if (closeBtn) {
            closeBtn.classList.add("is-hidden");
            closeBtn.disabled = true;
        }
        return;
    }

    const status = String(opening.status || "").toLowerCase();
    const cash = Number(opening.opening_cash || 0);
    const ecash = Number(opening.opening_ecash || 0);
    displayEl.textContent = `Cash ${formatMoney(cash)} | E-Cash ${formatMoney(ecash)}`;
    dateEl.textContent = `Business date: ${businessDate || opening.business_date || "-"}${opening.opened_at ? ` | Opened ${formatDateTime(opening.opened_at)}` : ""}`;

    if (opening.is_stale_open) {
        openingBalanceMode = "locked";
        setOpeningReadinessState("blocked", "A previous store day is still open. Ask another assigned supervisor or an Administrator to resolve it.");
        if (openBtn) {
            openBtn.disabled = true;
            openBtn.innerHTML = '<i class="bi bi-lock"></i> Close Previous Day First';
        }
        if (closeBtn) {
            closeBtn.classList.add("is-hidden");
            closeBtn.disabled = true;
        }
        return;
    }

    if (status === "closed") {
        openingBalanceMode = isAdminUser ? "reopen" : "locked";
        setOpeningReadinessState("blocked", "Today is already closed. POS transactions are locked.");
        if (openBtn) {
            openBtn.disabled = !isAdminUser;
            openBtn.innerHTML = isAdminUser
                ? '<i class="bi bi-arrow-clockwise"></i> Admin Reopen Day'
                : '<i class="bi bi-lock"></i> Store Day Closed';
        }
        if (closeBtn) {
            closeBtn.classList.add("is-hidden");
            closeBtn.disabled = true;
        }
        return;
    }

    openingBalanceMode = "locked";
    setOpeningReadinessState("ready", "Store day is open. Cash and e-cash totals will flow into close-day variance.");
    if (openBtn) {
        openBtn.disabled = true;
        openBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Store Day Open';
    }
    if (closeBtn) {
        closeBtn.classList.remove("is-hidden");
        closeBtn.disabled = false;
    }
}

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function formatMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function renderProductCatalogState(type, message, detail = "") {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    const detailHtml = detail ? `<small>${escapeHtml(detail)}</small>` : "";
    document.getElementById("product-grid").innerHTML = `<div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${escapeHtml(message)}</strong>${detailHtml}</div></div>`;
}

function formatDateTime(value) {
    return window.IbemsFormat?.dateTime(value) || new Date(value || Date.now()).toLocaleString();
}

function getInitials(text) {
    return String(text || "")
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0] || "")
        .join("")
        .toUpperCase() || "PR";
}

function getProductById(productId) {
    return productsCache.find((p) => Number(p.id) === Number(productId));
}

function sanitizeIconClass(iconClass) {
    const value = String(iconClass || "").trim();
    if (!value) return "";
    if (!/^bi\s+bi\-[a-z0-9\-]+$/i.test(value)) return "";
    return value;
}

function getProductDisplayName(product) {
    const name = String(product?.name || "").trim();
    const variant = String(product?.variant_label || "").trim();
    return variant ? `${name} (${variant})` : name;
}

function getProductByScanCode(rawCode) {
    const code = String(rawCode || "").trim();
    if (code === "") return null;
    const normalized = code.toLowerCase();

    return (
        productsCache.find((p) => String(p.sku || "").trim().toLowerCase() === normalized) ||
        productsCache.find((p) => String(p.barcode || "").trim().toLowerCase() === normalized) ||
        productsCache.find((p) => String(p.id || "").trim() === code) ||
        null
    );
}

function renderScanProductSuggestions() {
    const box = document.getElementById("scan-product-suggestions");
    const query = String(document.getElementById("scan-code-input").value || "").trim().toLowerCase();

    if (!query) {
        box.innerHTML = "";
        box.style.display = "none";
        return;
    }

    const matches = productsCache.filter((product) => {
        const sku = String(product.sku || "").toLowerCase();
        const name = String(product.name || "").toLowerCase();
        const barcode = String(product.barcode || "").toLowerCase();
        const category = String(product.category || "").toLowerCase();
        return sku.includes(query) || name.includes(query) || barcode.includes(query) || category.includes(query);
    });

    if (matches.length === 0) {
        box.innerHTML = '<div class="scan-suggestion-empty">No matching products.</div>';
        box.style.display = "block";
        return;
    }

    const top = matches.slice(0, 3);
    box.innerHTML = top
        .map((product) => {
            const category = String(product.category || "General");
            const displayName = getProductDisplayName(product);
            return `
                <button type="button" class="scan-suggestion-item" data-scan-product-id="${product.id}">
                    <span class="name">${escapeHtml(displayName)}</span>
                    <span class="meta">SKU: ${escapeHtml(product.sku || "-")} • ${escapeHtml(category)} • ${escapeHtml(formatMoney(product.price))}</span>
                </button>
            `;
        })
        .join("");
    box.style.display = "block";
}

function getCartQty(productId) {
    const item = cart.find((entry) => Number(entry.product_id) === Number(productId));
    return item ? Number(item.qty) : 0;
}

function setResult(message, type) {
    const resultEl = document.getElementById("result");
    resultEl.textContent = message || "";
    resultEl.style.color = type === "error" ? "#b91c1c" : "#166534";
    if (message) {
        showToast(message, type === "error" ? "error" : "success");
    }
}

function setOpeningBalanceResult(message, type) {
    const resultEl = document.getElementById("opening-balance-result");
    if (!resultEl) return;
    resultEl.textContent = message || "";
    resultEl.style.color = type === "error" ? "#b91c1c" : "#166534";
    if (message) {
        showToast(message, type === "error" ? "error" : "success");
    }
}

function setPosTransactionEnabled(enabled) {
    openingBalanceReady = !!enabled;
    updateCheckoutState();
}

function openOpeningBalanceModal(prefill = null) {
    const modal = document.getElementById("opening-balance-modal");
    const titleEl = document.getElementById("opening-balance-title");
    const descEl = document.getElementById("opening-balance-description");
    const labelEl = document.getElementById("opening-balance-label");
    const saveBtn = document.getElementById("opening-balance-save");
    if (!modal) return;
    if (prefill && typeof prefill.opening_cash !== "undefined") {
        document.getElementById("opening-balance-input").value = Number(prefill.opening_cash || 0).toFixed(2);
        document.getElementById("opening-ecash-input").value = Number(prefill.opening_ecash || 0).toFixed(2);
        document.getElementById("opening-balance-note").value = String(prefill.opening_note || "");
    }
    if (openingBalanceMode === "reopen") {
        if (titleEl) titleEl.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Admin Reopen Store Day';
        if (descEl) descEl.textContent = "Admin action: reopen today's closed store day and replace the opening cash values.";
        if (labelEl) labelEl.textContent = "Opening Cash";
        if (saveBtn) saveBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Reopen Store Day';
    } else {
        if (titleEl) titleEl.innerHTML = '<i class="bi bi-safe2"></i> Open Store Day';
        if (descEl) descEl.textContent = "Enter today's starting cash and e-cash before accepting POS transactions.";
        if (labelEl) labelEl.textContent = "Opening Cash";
        if (saveBtn) saveBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Open Store Day';
    }
    setOpeningBalanceResult("", "ok");
    modal.style.display = "grid";
}

function closeOpeningBalanceModal() {
    const modal = document.getElementById("opening-balance-modal");
    if (!modal) return;
    modal.style.display = "none";
}

async function loadOpeningBalanceStatus() {
    if (!activeStoreId) return;
    try {
        const data = await requestJson(
            `/store/day-session/status?store_id=${activeStoreId}`,
            {},
            "Unable to load store day status."
        );
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Unable to load store day status.");
        }

        openingBalanceReady = !!data.is_opened;
        currentDaySession = data.session || null;
        updateOpeningBalanceDisplay(data.session, data.business_date);
        setPosTransactionEnabled(openingBalanceReady);
        if (openingBalanceReady) {
            closeOpeningBalanceModal();
        }
    } catch (error) {
        openingBalanceReady = false;
        currentDaySession = null;
        updateOpeningBalanceDisplay(null, null);
        setOpeningReadinessState("error", "Unable to verify store day status. Check connection or try again.");
        setPosTransactionEnabled(false);
        setOpeningBalanceResult("Unable to verify store day status.", "error");
    }
}

async function saveOpeningBalance() {
    if (!activeStoreId) return;

    const amount = Number(document.getElementById("opening-balance-input").value || 0);
    const ecashAmount = Number(document.getElementById("opening-ecash-input").value || 0);
    const note = String(document.getElementById("opening-balance-note").value || "").trim();
    const saveBtn = document.getElementById("opening-balance-save");

    if (amount < 0 || ecashAmount < 0) {
        setOpeningBalanceResult("Opening cash and e-cash must be 0 or greater.", "error");
        return;
    }

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    try {
        const data = await requestJson(
            "/store/day-session/open",
            {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    store_id: activeStoreId,
                    opening_cash: amount,
                    opening_ecash: ecashAmount,
                    note,
                }),
            },
            "Failed to open store day."
        );
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Failed to open store day.");
        }

        openingBalanceReady = true;
        currentDaySession = data.session || null;
        setPosTransactionEnabled(true);
        updateOpeningBalanceDisplay(data.session, data.session?.business_date || null);
        closeOpeningBalanceModal();
        setResult(
            openingBalanceMode === "reopen"
                ? `Store day reopened: cash ${formatMoney(amount)}, e-cash ${formatMoney(ecashAmount)}`
                : `Store day opened: cash ${formatMoney(amount)}, e-cash ${formatMoney(ecashAmount)}`,
            "ok"
        );
    } catch (error) {
        setOpeningBalanceResult(error.message || "Failed to open store day.", "error");
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML =
            openingBalanceMode === "reopen"
                ? '<i class="bi bi-check2-circle"></i> Reopen Store Day'
                : '<i class="bi bi-check2-circle"></i> Open Store Day';
    }
}

function setStoreDayCloseResult(message, type) {
    const resultEl = document.getElementById("store-day-close-result");
    if (!resultEl) return;
    resultEl.textContent = message || "";
    resultEl.style.color = type === "error" ? "#b91c1c" : "#166534";
    if (message) {
        showToast(message, type === "error" ? "error" : "success");
    }
}

function closeDayValue(key) {
    return Number(currentDaySession?.[key] || 0);
}

function closeDayOtherCashIn() {
    return Math.max(0, closeDayValue("cash_in") - closeDayValue("cash_debt_payments"));
}

function closeDayOtherEcashIn() {
    return Math.max(0, closeDayValue("ecash_in") - closeDayValue("ecash_debt_payments"));
}

function closeDayReconcileRow(label, value, className = "") {
    const safeClass = className ? ` ${className}` : "";
    return `<div class="close-reconcile-row${safeClass}"><span>${escapeHtml(label)}</span><strong>${escapeHtml(formatMoney(value))}</strong></div>`;
}

function renderStoreDayCloseReconciliation() {
    const wrap = document.getElementById("store-day-close-reconcile");
    if (!wrap || !currentDaySession) return;

    const expectedCash = closeDayValue("expected_cash_on_hand") || closeDayValue("expected_cash");
    const expectedEcash = closeDayValue("expected_ecash_on_hand") || closeDayValue("expected_ecash");
    const expectedTotal = expectedCash + expectedEcash;
    const collectedCash = closeDayValue("cash_sales") + closeDayValue("cash_debt_payments");
    const collectedEcash = closeDayValue("ecash_sales") + closeDayValue("ecash_debt_payments");

    wrap.innerHTML = `
        <section class="close-reconcile-section">
            <h4>Sales and Collections</h4>
            ${closeDayReconcileRow("Cash product sales", closeDayValue("cash_sales"))}
            ${closeDayReconcileRow("E-cash product sales", closeDayValue("ecash_sales"))}
            ${closeDayReconcileRow("Debt sales (not collected)", closeDayValue("debt_sales"), "is-muted")}
            ${closeDayReconcileRow("Cash debt payments", closeDayValue("cash_debt_payments"))}
            ${closeDayReconcileRow("E-cash debt payments", closeDayValue("ecash_debt_payments"))}
        </section>
        <section class="close-reconcile-section">
            <h4>Expected Money</h4>
            ${closeDayReconcileRow("Opening cash", closeDayValue("opening_cash"))}
            ${closeDayReconcileRow("Cash collected", collectedCash)}
            ${closeDayReconcileRow("Other cash in", closeDayOtherCashIn())}
            ${closeDayReconcileRow("Cash out", -closeDayValue("cash_out"), "is-muted")}
            ${closeDayReconcileRow("Expected cash", expectedCash, "is-total")}
            ${closeDayReconcileRow("Opening e-cash", closeDayValue("opening_ecash"))}
            ${closeDayReconcileRow("E-cash collected", collectedEcash)}
            ${closeDayReconcileRow("Other e-cash in", closeDayOtherEcashIn())}
            ${closeDayReconcileRow("E-cash out", -closeDayValue("ecash_out"), "is-muted")}
            ${closeDayReconcileRow("Expected e-cash", expectedEcash, "is-total")}
            ${closeDayReconcileRow("Expected total", expectedTotal, "is-grand")}
        </section>
    `;
}

function updateStoreDayCloseVariance() {
    const expectedCash = closeDayValue("expected_cash_on_hand") || closeDayValue("expected_cash");
    const expectedEcash = closeDayValue("expected_ecash_on_hand") || closeDayValue("expected_ecash");
    const countedCash = Number(document.getElementById("closing-cash-input")?.value || 0);
    const countedEcash = Number(document.getElementById("closing-ecash-input")?.value || 0);
    const cashVariance = countedCash - expectedCash;
    const ecashVariance = countedEcash - expectedEcash;
    const cashEl = document.getElementById("closing-cash-variance");
    const ecashEl = document.getElementById("closing-ecash-variance");

    [
        [cashEl, cashVariance],
        [ecashEl, ecashVariance],
    ].forEach(([el, variance]) => {
        if (!el) return;
        const label = variance < -0.005 ? "Shortage" : variance > 0.005 ? "Overage" : "Balanced";
        el.textContent = `${label} ${formatMoney(Math.abs(variance))}`;
        el.classList.toggle("is-balanced", Math.abs(variance) < 0.005);
        el.classList.toggle("is-over", variance > 0.005);
        el.classList.toggle("is-short", variance < -0.005);
    });

    const noteLabel = document.getElementById("closing-note-label");
    const noteInput = document.getElementById("closing-note-input");
    const hasVariance = Math.abs(cashVariance) >= 0.005 || Math.abs(ecashVariance) >= 0.005;
    if (noteLabel) {
        noteLabel.textContent = hasVariance ? "Closing Note (required for variance)" : "Closing Note (optional)";
    }
    if (noteInput) {
        noteInput.required = hasVariance;
        noteInput.placeholder = hasVariance ? "Explain shortage/overage before closing" : "e.g. Cash count verified";
    }
}

async function openStoreDayCloseModal() {
    const modal = document.getElementById("store-day-close-modal");
    if (!modal) return;

    await loadOpeningBalanceStatus();
    const summary = document.getElementById("store-day-close-summary");
    if (!currentDaySession || String(currentDaySession.status || "") !== "open") {
        setResult("The store day is no longer open. Reload its current status before closing.", "error");
        return;
    }

    const expectedCash = Number(currentDaySession.expected_cash_on_hand ?? currentDaySession.expected_cash ?? 0);
    const expectedEcash = Number(currentDaySession.expected_ecash_on_hand ?? currentDaySession.expected_ecash ?? 0);
    document.getElementById("closing-cash-input").value = expectedCash.toFixed(2);
    document.getElementById("closing-ecash-input").value = expectedEcash.toFixed(2);
    document.getElementById("closing-note-input").value = "";
    if (summary) {
        summary.textContent = "Expected totals are calculated from opening balance, sales, direct debt payments, and cash movements. Enter the actual counted cash/e-cash to record any variance.";
    }
    renderStoreDayCloseReconciliation();
    updateStoreDayCloseVariance();
    setStoreDayCloseResult("", "ok");
    modal.classList.remove("is-hidden");
    modal.style.display = "grid";
}

function closeStoreDayCloseModal() {
    const modal = document.getElementById("store-day-close-modal");
    if (!modal) return;
    modal.style.display = "none";
    modal.classList.add("is-hidden");
}

async function saveStoreDayClose() {
    if (!activeStoreId) return;

    const countedCash = Number(document.getElementById("closing-cash-input").value || 0);
    const countedEcash = Number(document.getElementById("closing-ecash-input").value || 0);
    const note = String(document.getElementById("closing-note-input").value || "").trim();
    const saveBtn = document.getElementById("store-day-close-save");

    if (countedCash < 0 || countedEcash < 0) {
        setStoreDayCloseResult("Counted cash and e-cash must be 0 or greater.", "error");
        return;
    }

    const expectedCash = closeDayValue("expected_cash_on_hand") || closeDayValue("expected_cash");
    const expectedEcash = closeDayValue("expected_ecash_on_hand") || closeDayValue("expected_ecash");
    const hasVariance = Math.abs(countedCash - expectedCash) >= 0.005 || Math.abs(countedEcash - expectedEcash) >= 0.005;
    if (hasVariance && note === "") {
        setStoreDayCloseResult("Enter a closing note explaining the shortage or overage before closing.", "error");
        document.getElementById("closing-note-input").focus();
        return;
    }

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Closing...';
    try {
        const data = await requestJson(
            "/store/day-session/close",
            {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    store_id: activeStoreId,
                    counted_cash: countedCash,
                    counted_ecash: countedEcash,
                    note,
                }),
            },
            "Failed to close store day."
        );
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Failed to close store day.");
        }

        openingBalanceReady = false;
        setPosTransactionEnabled(false);
        closeStoreDayCloseModal();
        await loadOpeningBalanceStatus();

        const varianceCash = Number(data.session?.variance_cash || 0);
        const varianceEcash = Number(data.session?.variance_ecash || 0);
        const reviewStatus = String(data.session?.review_status || "not_required");
        const reviewText = reviewStatus === "pending" ? " Flagged for review." : "";
        setResult(`Store day closed. Variance: cash ${formatMoney(varianceCash)}, e-cash ${formatMoney(varianceEcash)}.${reviewText}`, "ok");
    } catch (error) {
        setStoreDayCloseResult(error.message || "Failed to close store day.", "error");
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Close Store Day';
    }
}

function setScannerStatus(message, isError = false) {
    const el = document.getElementById("scanner-status");
    el.textContent = message || "";
    el.style.color = isError ? "#b91c1c" : "#475569";
}

function setScannerUiState(running) {
    const startBtn = document.getElementById("scanner-start");
    const stopBtn = document.getElementById("scanner-stop");
    const openBtn = document.getElementById("open-scanner-btn");
    const openDebtBtn = document.getElementById("open-debt-scanner-btn");

    if (running) {
        if (startBtn) {
            startBtn.style.display = "none";
            startBtn.disabled = true;
        }
        stopBtn.disabled = false;
        openBtn.disabled = true;
        if (openDebtBtn) openDebtBtn.disabled = true;
        return;
    }

    if (startBtn) {
        startBtn.style.display = "inline-flex";
        startBtn.disabled = false;
    }
    stopBtn.disabled = true;
    openBtn.disabled = false;
    if (openDebtBtn) openDebtBtn.disabled = false;
}

async function openScannerModal(mode = "product") {
    scannerMode = mode === "debt" ? "debt" : "product";
    const titleEl = document.getElementById("scanner-title");
    if (titleEl) {
        titleEl.innerHTML =
            scannerMode === "debt"
                ? '<i class="bi bi-person-badge"></i> Employee QR Scanner'
                : '<i class="bi bi-upc-scan"></i> Barcode Scanner';
    }

    document.getElementById("barcode-scanner-modal").style.display = "grid";
    setScannerUiState(scannerRunning);
    setScannerStatus("Starting scanner...");
    await startScanner();
}

async function closeScannerModal() {
    await stopScanner();
    document.getElementById("barcode-scanner-modal").style.display = "none";
}

async function startScanner() {
    if (scannerRunning) return;

    if (typeof window.Html5Qrcode === "undefined") {
        setScannerStatus("Scanner library not loaded. Check internet/CDN access.", true);
        setScannerUiState(false);
        return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setScannerStatus("Camera API not available in this browser.", true);
        setScannerUiState(false);
        return;
    }

    const readerId = "scanner-reader";
    const readerEl = document.getElementById(readerId);
    readerEl.innerHTML = "";
    scannerEngine = new window.Html5Qrcode(readerId);

    try {
        await scannerEngine.start(
            { facingMode: "environment" },
            {
                fps: 10,
                qrbox: { width: 260, height: 180 },
                aspectRatio: 1.7777778,
            },
            async (decodedText) => {
                const rawValue = String(decodedText || "").trim();
                if (rawValue === "") return;

                const now = Date.now();
                if (rawValue === scannerLastDetected && now - scannerLastDetectedAt <= 1200) {
                    return;
                }

                scannerLastDetected = rawValue;
                scannerLastDetectedAt = now;
                setScannerStatus(`Detected: ${rawValue}`);
                await handleDetectedScannerCode(rawValue);
            },
            () => {}
        );
    } catch (error) {
        scannerEngine = null;
        scannerRunning = false;
        setScannerStatus("Failed to start camera scanner.", true);
        setScannerUiState(false);
        return;
    }

    scannerRunning = true;
    setScannerUiState(true);
    setScannerStatus("Scanning... Point camera to barcode/QR.");
}

async function stopScanner() {
    if (!scannerRunning || !scannerEngine) {
        scannerRunning = false;
        setScannerUiState(false);
        return;
    }

    try {
        await scannerEngine.stop();
    } catch (error) {
        // Ignore stop errors on already-stopped sessions.
    }

    try {
        await scannerEngine.clear();
    } catch (error) {
        // Ignore clear errors.
    }

    scannerEngine = null;
    scannerRunning = false;
    setScannerUiState(false);
}

function autoSelectDebtCustomerByCode(rawCode) {
    const code = String(rawCode || "").trim().toLowerCase();
    if (!code || !Array.isArray(debtCustomers) || debtCustomers.length === 0) return false;

    const exact = debtCustomers.find((customer) => {
        const employeeId = String(customer.employee_id || "").trim().toLowerCase();
        const email = String(customer.email || "").trim().toLowerCase();
        const name = String(customer.name || "").trim().toLowerCase();
        const id = String(customer.id || "").trim().toLowerCase();
        return employeeId === code || email === code || id === code || name === code;
    });

    if (!exact) return false;

    selectedDebtCustomerId = Number(exact.id);
    selectedDebtCustomer = exact;
    selectedDebtPin = "";
    const debtPinInput = document.getElementById("debt-pin-input");
    if (debtPinInput) debtPinInput.value = "";
    document.getElementById("debt-customer-search").value = exact.name;
    document.getElementById("debt-customer-suggestions").style.display = "none";
    updateDebtPinUi();
    updateCheckoutState();
    setResult(`Debt customer selected: ${exact.name}`, "ok");
    return true;
}

async function handleDetectedScannerCode(rawValue) {
    if (scannerMode === "debt") {
        const searchInput = document.getElementById("debt-customer-search");
        searchInput.value = rawValue;
        selectedDebtCustomerId = null;
        selectedDebtCustomer = null;
        selectedDebtPin = "";
        await loadDebtCustomers(rawValue);
        autoSelectDebtCustomerByCode(rawValue);
        await closeScannerModal();
        searchInput.focus();
        return;
    }

    if (/\/store\/receipt\/\d+/i.test(rawValue)) {
        await closeScannerModal();
        window.open(rawValue, "_blank", "noopener");
        return;
    }

    document.getElementById("scan-code-input").value = rawValue;
    await closeScannerModal();
    document.getElementById("scan-qty-input").focus();
}

function getStoreNameById(storeId) {
    const store = myStores.find((s) => Number(s.id) === Number(storeId));
    return store ? store.store_name : `Store #${storeId}`;
}

function getDebtCustomerLabelById(customerId) {
    const customer =
        selectedDebtCustomer && Number(selectedDebtCustomer.id) === Number(customerId)
            ? selectedDebtCustomer
            : debtCustomers.find((c) => Number(c.id) === Number(customerId));
    if (!customer) return "N/A";
    const category = String(customer.user_type || "");
    const categoryLabel = category ? category.charAt(0).toUpperCase() + category.slice(1).toLowerCase() : "N/A";
    return `${customer.name} (${categoryLabel})`;
}

function getDebtCustomerById(customerId) {
    return (
        selectedDebtCustomer && Number(selectedDebtCustomer.id) === Number(customerId)
            ? selectedDebtCustomer
            : debtCustomers.find((c) => Number(c.id) === Number(customerId))
    ) || null;
}

function formatPaymentLabel(method) {
    const key = String(method || "").toLowerCase();
    if (key === "gcash") return "GCash";
    if (key === "debt") return "Debt";
    if (key === "cash") return "Cash";
    if (key === "card") return "Card";
    return key.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function openReceiptModal(receipt) {
    lastReceipt = receipt;
    const modal = document.getElementById("receipt-modal");
    const viewBtn = document.getElementById("receipt-view");
    if (window.IbemsReceipt) {
        window.IbemsReceipt.renderReceipt("receipt-content", receipt);
    }
    if (viewBtn) {
        viewBtn.disabled = !receipt.lookupUrl;
    }
    modal.style.display = "grid";
}

function closeReceiptModal() {
    const modal = document.getElementById("receipt-modal");
    modal.style.display = "none";
}

function renderSuccessStrip(receipt) {
    const strip = document.getElementById("pos-success-strip");
    if (!strip || !receipt) return;

    strip.classList.remove("is-hidden");
    strip.innerHTML = `
        <div>
            <span>Last transaction completed</span>
            <strong>${formatMoney(receipt.totalAmount)}</strong>
            <small>${escapeHtml(formatPaymentLabel(receipt.paymentMethod))} | ${escapeHtml(receipt.customerName || "Walk-in")}</small>
        </div>
        <div class="pos-success-actions">
            <button type="button" class="secondary-btn" data-pos-success-action="view"><i class="bi bi-box-arrow-up-right"></i> View</button>
            <button type="button" class="primary-btn" data-pos-success-action="print"><i class="bi bi-printer"></i> Print</button>
            <a href="/store/history"><i class="bi bi-clock-history"></i> History</a>
        </div>
    `;
}

function buildConfirmTransactionHtml(data) {
    const rows = data.cartSnapshot
        .map((item) => `
            <tr>
                <td>${escapeHtml(item.name)}</td>
                <td>${item.qty}</td>
                <td>${formatMoney(item.price)}</td>
                <td>${formatMoney(item.qty * item.price)}</td>
            </tr>
        `)
        .join("");

    const paymentLabel = formatPaymentLabel(data.paymentMethod);
    const totalItems = data.cartSnapshot.reduce((sum, item) => sum + Number(item.qty || 0), 0);
    const debt = data.debtCustomer || null;
    const currentDebt = Number(debt?.current_debt || 0);
    const availableCredit = Number(debt?.available_credit || 0);
    const creditLimit = currentDebt + availableCredit;
    const projectedDebt = currentDebt + Number(data.totalAmount || 0);
    const projectedRemainingCredit = Math.max(0, creditLimit - projectedDebt);
    const debtWarning = data.paymentMethod === "debt"
        ? `
            <div class="confirm-warning">
                <i class="bi bi-exclamation-triangle"></i>
                <div>
                    <strong>Debt transaction</strong>
                    <span>This sale will be charged to ${escapeHtml(data.debtCustomerLabel)}. Projected remaining credit: ${formatMoney(projectedRemainingCredit)}.</span>
                </div>
            </div>
        `
        : "";
    const customerBlock = data.paymentMethod === "debt"
        ? `
            <section class="confirm-section">
                <h4><i class="bi bi-person-vcard"></i> Debt Customer</h4>
                <div class="confirm-meta-grid">
                    <div class="confirm-meta-item"><span>Name</span><strong>${escapeHtml(debt?.name || data.debtCustomerLabel)}</strong></div>
                    <div class="confirm-meta-item"><span>Type</span><strong>${escapeHtml(String(debt?.user_type || "N/A").replace(/\b\w/g, (letter) => letter.toUpperCase()))}</strong></div>
                    <div class="confirm-meta-item"><span>Current Debt</span><strong>${formatMoney(currentDebt)}</strong></div>
                    <div class="confirm-meta-item"><span>Transaction Amount</span><strong>${formatMoney(data.totalAmount)}</strong></div>
                    <div class="confirm-meta-item"><span>Projected Debt</span><strong>${formatMoney(projectedDebt)}</strong></div>
                    <div class="confirm-meta-item"><span>Remaining Credit</span><strong>${formatMoney(projectedRemainingCredit)}</strong></div>
                </div>
            </section>
        `
        : `
            <section class="confirm-section">
                <h4><i class="bi bi-person"></i> Customer</h4>
                <div class="confirm-meta-grid">
                    <div class="confirm-meta-item"><span>Customer Type</span><strong>Walk-in</strong></div>
                    <div class="confirm-meta-item"><span>Debt Impact</span><strong>None</strong></div>
                </div>
            </section>
        `;

    return `
        <div class="confirm-summary-hero">
            <div>
                <span>Transaction Total</span>
                <strong>${formatMoney(data.totalAmount)}</strong>
                <small>${totalItems} item${totalItems === 1 ? "" : "s"} in this order</small>
            </div>
            <div class="confirm-payment-chip"><i class="bi bi-credit-card-2-front"></i>${escapeHtml(paymentLabel)}</div>
        </div>
        ${debtWarning}
        <section class="confirm-section">
            <h4><i class="bi bi-shop-window"></i> Store & Payment</h4>
            <div class="confirm-meta-grid">
                <div class="confirm-meta-item"><span>Store</span><strong>${escapeHtml(data.storeName)}</strong></div>
                <div class="confirm-meta-item"><span>Payment Method</span><strong>${escapeHtml(paymentLabel)}</strong></div>
            </div>
        </section>
        ${customerBlock}
        <section class="confirm-section">
            <h4><i class="bi bi-basket"></i> Items</h4>
            <table class="receipt-table confirm-table">
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
        </section>
    `;
}

function openConfirmTransactionModal(data) {
    pendingTransaction = data;
    const content = document.getElementById("confirm-transaction-content");
    content.innerHTML = buildConfirmTransactionHtml(data);
    document.getElementById("confirm-transaction-modal").style.display = "grid";
}

function closeConfirmTransactionModal(force = false) {
    if (isSubmitting && !force) return;
    pendingTransaction = null;
    document.getElementById("confirm-transaction-modal").style.display = "none";
}

function printReceipt() {
    if (!lastReceipt) return;
    if (!window.IbemsReceipt || !window.IbemsReceipt.printReceipt(lastReceipt)) {
        setResult("Popup blocked. Please allow popups to print receipt.", "error");
    }
}

function formatCredit(customer) {
    const available = Number(customer.available_credit || 0);
    const debt = Number(customer.current_debt || 0);
    return `Avail ${formatMoney(available)} | Debt ${formatMoney(debt)}`;
}

function updateDebtPinUi() {
    const wrap = document.getElementById("debt-pin-wrap");
    const help = document.getElementById("debt-pin-help");
    const input = document.getElementById("debt-pin-input");
    const openButton = document.getElementById("open-debt-pin-modal");
    if (!wrap || !help || !input || !openButton) return;

    const paymentMethod = document.getElementById("payment-method")?.value || "";
    const shouldShow = paymentMethod === "debt" && !!selectedDebtCustomerId;
    wrap.style.display = shouldShow ? "flex" : "none";

    if (!shouldShow) {
        selectedDebtPin = "";
        input.value = "";
        input.disabled = false;
        openButton.disabled = false;
        openButton.classList.remove("is-ready");
        openButton.innerHTML = '<i class="bi bi-key"></i> Enter PIN';
        help.textContent = "PIN is verified securely when the transaction is submitted.";
        help.classList.remove("is-error", "is-ready");
        return;
    }

    if (selectedDebtCustomer && selectedDebtCustomer.has_debt_pin === false) {
        input.disabled = true;
        input.value = "";
        selectedDebtPin = "";
        openButton.disabled = true;
        openButton.classList.remove("is-ready");
        openButton.innerHTML = '<i class="bi bi-shield-exclamation"></i> PIN Not Set';
        help.textContent = "This customer must set a debt PIN in the User Portal before using debt payment.";
        help.classList.add("is-error");
        help.classList.remove("is-ready");
        return;
    }

    input.disabled = false;
    openButton.disabled = false;
    openButton.classList.toggle("is-ready", !!selectedDebtPin);
    openButton.innerHTML = selectedDebtPin
        ? '<i class="bi bi-check2-circle"></i> PIN Entered'
        : '<i class="bi bi-key"></i> Enter PIN';
    help.textContent = selectedDebtPin ? "PIN ready for secure verification." : "Use the PIN modal before checkout.";
    help.classList.toggle("is-ready", !!selectedDebtPin);
    help.classList.remove("is-error");
}

function openDebtPinModal() {
    const modal = document.getElementById("debt-pin-modal");
    const input = document.getElementById("debt-pin-input");
    const summary = document.getElementById("debt-pin-modal-summary");
    const result = document.getElementById("debt-pin-modal-result");
    if (!modal || !input) return;

    if (!selectedDebtCustomerId) {
        setResult("Select a debt customer before entering a PIN.", "error");
        return;
    }

    if (selectedDebtCustomer && selectedDebtCustomer.has_debt_pin === false) {
        setResult("This customer must set a debt PIN in the User Portal before using debt payment.", "error");
        return;
    }

    if (summary) {
        summary.textContent = `Ask ${selectedDebtCustomer?.name || "the debtor"} to enter their debt authorization PIN.`;
    }
    if (result) {
        result.textContent = "";
        result.className = "result-msg";
    }

    input.value = selectedDebtPin;
    modal.style.display = "grid";
    setTimeout(() => input.focus(), 30);
}

function closeDebtPinModal(clearDraft = false) {
    const modal = document.getElementById("debt-pin-modal");
    const input = document.getElementById("debt-pin-input");
    const result = document.getElementById("debt-pin-modal-result");
    if (clearDraft && input) {
        input.value = selectedDebtPin;
    }
    if (result) {
        result.textContent = "";
        result.className = "result-msg";
    }
    if (modal) modal.style.display = "none";
}

function saveDebtPinFromModal() {
    const input = document.getElementById("debt-pin-input");
    const result = document.getElementById("debt-pin-modal-result");
    const pin = String(input?.value || "").replace(/\D/g, "").slice(0, 6);

    if (!/^[0-9]{4,6}$/.test(pin)) {
        if (result) {
            result.textContent = "Enter a 4 to 6 digit PIN.";
            result.className = "result-msg error";
        }
        input?.focus();
        return false;
    }

    selectedDebtPin = pin;
    if (input) input.value = pin;
    updateDebtPinUi();
    updateCheckoutState();
    closeDebtPinModal();
    setResult("Debt PIN captured for secure verification.", "ok");
    return true;
}

function getStockState(stock, inCart = 0, threshold = 10) {
    const safeStock = Number(stock || 0);
    const safeInCart = Number(inCart || 0);
    const parsedThreshold = Number(threshold);
    const lowStockThreshold = Number.isFinite(parsedThreshold) ? Math.max(0, parsedThreshold) : 10;
    if (safeStock <= 0) {
        return { key: "out", label: "Out of stock", detail: "Unavailable" };
    }
    if (safeInCart >= safeStock) {
        return { key: "maxed", label: "Max in cart", detail: `${safeStock} available` };
    }
    if (safeStock <= lowStockThreshold) {
        return { key: "low", label: "Low stock", detail: `${safeStock - safeInCart} available` };
    }
    return { key: "in", label: "In stock", detail: `${safeStock - safeInCart} available` };
}

function updateCheckoutState() {
    const submitBtn = document.getElementById("submit-transaction");
    const debtPaymentBtn = document.getElementById("open-debt-payment-modal");
    if (!submitBtn) return;

    if (debtPaymentBtn) {
        debtPaymentBtn.disabled = !openingBalanceReady;
        debtPaymentBtn.title = openingBalanceReady
            ? "Record a direct collection against existing employee debt"
            : "Open today's store day before recording a debt collection";
        debtPaymentBtn.innerHTML = openingBalanceReady
            ? '<i class="bi bi-plus-circle"></i> Record Payment'
            : '<i class="bi bi-lock"></i> Open Day First';
    }

    if (!openingBalanceReady) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-lock"></i> Open Store Day First';
        return;
    }

    if (cart.length === 0) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-cart-plus"></i> Add Items to Continue';
        return;
    }

    const paymentMethod = document.getElementById("payment-method")?.value || "";
    if (!paymentMethod) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-credit-card"></i> Select Payment Method';
        return;
    }

    if (paymentMethod === "debt" && !selectedDebtCustomerId) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-person-check"></i> Select Debt Customer';
        return;
    }

    if (paymentMethod === "debt") {
        if (selectedDebtCustomer && selectedDebtCustomer.has_debt_pin === false) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-shield-exclamation"></i> Customer PIN Not Set';
            return;
        }

        if (!selectedDebtPin) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-shield-lock"></i> Enter Debt PIN';
            return;
        }
    }

    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Complete Transaction';
}

function applyProductFilters() {
    const query = searchQuery.trim().toLowerCase();

    filteredProducts = productsCache.filter((product) => {
        const name = String(product.name || "").toLowerCase();
        const variant = String(product.variant_label || "").toLowerCase();
        const sku = String(product.sku || "").toLowerCase();
        const barcode = String(product.barcode || "").toLowerCase();
        const supplier = String(product.supplier || "").toLowerCase();
        const locationBin = String(product.location_bin || "").toLowerCase();
        const category = String(product.category || "General");

        const matchesQuery = !query
            || name.includes(query)
            || variant.includes(query)
            || sku.includes(query)
            || barcode.includes(query)
            || supplier.includes(query)
            || locationBin.includes(query);
        const matchesCategory = activeCategory === "All" || category === activeCategory;
        return matchesQuery && matchesCategory;
    });
}

function buildCategories() {
    const derived = new Set(["All"]);
    productsCache.forEach((product) => {
        const category = String(product.category || "General").trim() || "General";
        derived.add(category);
    });

    categories = Array.from(derived);
    if (!categories.includes(activeCategory)) {
        activeCategory = "All";
    }
}

function renderCategoryTabs() {
    const tabsEl = document.getElementById("category-tabs");

    tabsEl.innerHTML = categories
        .map(
            (category) =>
                `<button class="category-tab ${category === activeCategory ? "active" : ""}" data-category="${escapeHtml(category)}" type="button">${escapeHtml(category)}</button>`
        )
        .join("");
}

function renderProducts() {
    const grid = document.getElementById("product-grid");

    if (productsCache.length === 0) {
        renderProductCatalogState("empty", "No active products in this store yet.", "Add products in Inventory before using POS.");
        return;
    }

    if (filteredProducts.length === 0) {
        renderProductCatalogState("empty", "No products match your search or category.", "Try another SKU, barcode, product name, or category.");
        return;
    }

    grid.innerHTML = filteredProducts
        .map((product) => {
            const stock = Number(product.stock_qty || 0);
            const inCart = getCartQty(product.id);
            const canAdd = inCart < stock;
            const stockState = getStockState(stock, inCart, product.low_stock_threshold ?? product.reorder_level ?? 10);
            const category = String(product.category || "General");
            const displayName = getProductDisplayName(product);
            const imageUrl = String(product.image_url || "").trim();
            const supplier = String(product.supplier || "").trim();
            const locationBin = String(product.location_bin || "").trim();
            const useImage = imageUrl !== "";
            const visualHtml = useImage
                ? `<img class="product-visual" src="${escapeHtml(imageUrl)}" alt="${escapeHtml(displayName)}">`
                : `<div class="product-visual placeholder">${escapeHtml(getInitials(displayName))}</div>`;
            const inCartHtml = inCart > 0 ? `<span class="product-cart-chip"><i class="bi bi-cart-check"></i>${inCart} in cart</span>` : "";
            const operationsMeta = supplier || locationBin
                ? `
                    <div class="product-ops-meta">
                        ${supplier ? `<span><i class="bi bi-truck"></i>${escapeHtml(supplier)}</span>` : ""}
                        ${locationBin ? `<span><i class="bi bi-geo-alt"></i>${escapeHtml(locationBin)}</span>` : ""}
                    </div>
                `
                : "";

            return `
                <article class="product-card stock-${stockState.key} ${canAdd ? "" : "out-of-stock"}" data-product-card="${product.id}" aria-disabled="${canAdd ? "false" : "true"}">
                    <div class="product-card-top">
                        ${visualHtml}
                        <span class="stock-badge stock-${stockState.key}">${escapeHtml(stockState.label)}</span>
                    </div>
                    <h5 class="product-name">${escapeHtml(displayName)}</h5>
                    <p class="product-meta">${escapeHtml(category)} | SKU: ${escapeHtml(product.sku)}</p>
                    ${operationsMeta}
                    <div class="product-bottom">
                        <div>
                            <div class="product-price">${formatMoney(product.price)}</div>
                            <div class="product-stock">${escapeHtml(stockState.detail)} | Stock: ${stock}</div>
                        </div>
                        ${inCartHtml}
                    </div>
                </article>
            `;
        })
        .join("");
}

function renderCart() {
    const cartBody = document.getElementById("cart-body");
    const subtotalEl = document.getElementById("subtotal-amount");
    const totalEl = document.getElementById("grand-total");
    const itemCountEl = document.getElementById("item-count");

    if (cart.length === 0) {
        cartBody.innerHTML = '<div class="empty-state cart-empty-state"><i class="bi bi-cart-plus"></i><span>No items in cart.</span><small>Search or scan products to start the order.</small></div>';
        subtotalEl.textContent = formatMoney(0);
        totalEl.textContent = formatMoney(0);
        itemCountEl.textContent = "0";
        updateCheckoutState();
        return;
    }

    let subtotal = 0;
    let itemCount = 0;

    cartBody.innerHTML = cart
        .map((item) => {
            const lineTotal = Number(item.qty) * Number(item.price);
            subtotal += lineTotal;
            itemCount += Number(item.qty);

            const product = getProductById(item.product_id);
            const maxedOut = product ? Number(item.qty) >= Number(product.stock_qty || 0) : false;
            const stockText = product ? `${Number(product.stock_qty || 0)} available` : "Stock unavailable";

            return `
                <div class="cart-item ${maxedOut ? "is-maxed" : ""}">
                    <div class="cart-top">
                        <div>
                            <p class="cart-name">${escapeHtml(item.name)}</p>
                            <span class="cart-stock-note">${escapeHtml(stockText)}${maxedOut ? " | limit reached" : ""}</span>
                        </div>
                        <span class="cart-line-total">${formatMoney(lineTotal)}</span>
                    </div>
                    <div class="cart-controls">
                        <button class="cart-btn cart-dec" data-product-id="${item.product_id}" type="button" aria-label="Decrease ${escapeHtml(item.name)}">-</button>
                        <span class="cart-qty">${item.qty}</span>
                        <button class="cart-btn cart-inc" data-product-id="${item.product_id}" type="button" ${maxedOut ? "disabled" : ""} aria-label="Increase ${escapeHtml(item.name)}">+</button>
                        <button class="cart-btn remove cart-remove" data-product-id="${item.product_id}" type="button"><i class="bi bi-trash"></i> Remove</button>
                    </div>
                </div>
            `;
        })
        .join("");

    subtotalEl.textContent = formatMoney(subtotal);
    totalEl.textContent = formatMoney(subtotal);
    itemCountEl.textContent = String(itemCount);
    updateCheckoutState();
}

function renderDebtSuggestions() {
    const box = document.getElementById("debt-customer-suggestions");
    const query = String(document.getElementById("debt-customer-search").value || "").trim().toLowerCase();

    if (!query || debtCustomers.length === 0) {
        box.innerHTML = "";
        box.style.display = "none";
        return;
    }

    const items = debtCustomers.slice(0, 8).map((customer) => {
        const category = String(customer.user_type || "");
        const categoryLabel = category ? category.charAt(0).toUpperCase() + category.slice(1).toLowerCase() : "N/A";
        return `
            <button type="button" class="debt-suggestion-item" data-customer-id="${customer.id}">
                <span class="name">${escapeHtml(customer.name)}</span>
                <span class="meta">${escapeHtml(customer.employee_id || customer.email)} • ${escapeHtml(categoryLabel)} • ${escapeHtml(formatCredit(customer))}</span>
            </button>
        `;
    });

    box.innerHTML = items.join("");
    box.style.display = "block";
}

async function loadDebtCustomers(query = "") {
    try {
        const data = await requestJson(
            `/store/debt-customers?q=${encodeURIComponent(query)}`,
            {},
            "Unable to load debt customers."
        );
        if (!data || data.status !== "success") {
            debtCustomers = [];
            renderDebtSuggestions();
            return;
        }

        debtCustomers = Array.isArray(data.customers) ? data.customers : [];
        renderDebtSuggestions();
    } catch (error) {
        debtCustomers = [];
        renderDebtSuggestions();
        setResult(error.message || "Unable to load debt customers.", "error");
    }
}

function setDebtPaymentResult(message = "", type = "") {
    const result = document.getElementById("debt-payment-result");
    if (!result) return;
    result.textContent = message;
    result.className = `result-msg ${type === "error" ? "error" : type === "ok" ? "ok" : ""}`.trim();
}

function renderDebtPaymentSuggestions() {
    const box = document.getElementById("debt-payment-suggestions");
    const input = document.getElementById("debt-payment-search");
    if (!box || !input) return;

    const query = String(input.value || "").trim().toLowerCase();
    const list = repaymentCustomers.filter((customer) => Number(customer.current_debt || 0) > 0);
    if (!query || list.length === 0) {
        box.innerHTML = "";
        box.style.display = "none";
        return;
    }

    box.innerHTML = list.slice(0, 8).map((customer) => {
        const category = String(customer.user_type || "");
        const categoryLabel = category ? category.charAt(0).toUpperCase() + category.slice(1).toLowerCase() : "N/A";
        return `
            <button type="button" class="debt-suggestion-item" data-repayment-user-id="${customer.id}">
                <span class="name">${escapeHtml(customer.name)}</span>
                <span class="meta">${escapeHtml(customer.employee_id || customer.email)} | ${escapeHtml(categoryLabel)} | Debt ${escapeHtml(formatMoney(customer.current_debt || 0))}</span>
            </button>
        `;
    }).join("");
    box.style.display = "block";
}

async function loadDebtPaymentCustomers(query = "") {
    try {
        const data = await requestJson(
            `/store/debt-customers?q=${encodeURIComponent(query)}`,
            {},
            "Unable to load debt customers."
        );
        repaymentCustomers = Array.isArray(data.customers) ? data.customers : [];
        renderDebtPaymentSuggestions();
    } catch (error) {
        repaymentCustomers = [];
        renderDebtPaymentSuggestions();
        setDebtPaymentResult(error.message || "Unable to load debt customers.", "error");
    }
}

function renderDebtPaymentProfile() {
    const profile = document.getElementById("debt-payment-profile");
    if (!profile) return;

    if (!selectedRepaymentCustomer) {
        profile.classList.add("is-hidden");
        profile.innerHTML = "";
        return;
    }

    const debt = Number(selectedRepaymentCustomer.current_debt || 0);
    const credit = Number(selectedRepaymentCustomer.credit_limit || 0);
    profile.classList.remove("is-hidden");
    profile.innerHTML = `
        <div>
            <strong>${escapeHtml(selectedRepaymentCustomer.name || "Debtor")}</strong>
            <small>${escapeHtml(selectedRepaymentCustomer.employee_id || selectedRepaymentCustomer.email || "-")}</small>
        </div>
        <div>
            <span>Current Debt</span>
            <strong>${escapeHtml(formatMoney(debt))}</strong>
        </div>
        <div>
            <span>Credit Limit</span>
            <strong>${escapeHtml(formatMoney(credit))}</strong>
        </div>
    `;
}

function resetDebtPaymentModal() {
    selectedRepaymentCustomer = null;
    repaymentCustomers = [];
    ["debt-payment-search", "debt-payment-amount", "debt-payment-reference", "debt-payment-remarks"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.value = "";
    });
    const channel = document.getElementById("debt-payment-channel");
    if (channel) channel.value = "cash";
    const suggestions = document.getElementById("debt-payment-suggestions");
    if (suggestions) {
        suggestions.innerHTML = "";
        suggestions.style.display = "none";
    }
    renderDebtPaymentProfile();
    setDebtPaymentResult("");
}

function openDebtPaymentModal() {
    if (!activeStoreId) {
        setResult("No active store selected.", "error");
        return;
    }

    if (!openingBalanceReady) {
        setResult("Open today's store day before recording debt payments.", "error");
        return;
    }

    resetDebtPaymentModal();
    const modal = document.getElementById("debt-payment-modal");
    const input = document.getElementById("debt-payment-search");
    if (modal) modal.style.display = "grid";
    loadDebtPaymentCustomers("").catch(() => setDebtPaymentResult("Unable to load debt customers.", "error"));
    setTimeout(() => input?.focus(), 30);
}

function closeDebtPaymentModal() {
    const modal = document.getElementById("debt-payment-modal");
    if (modal) modal.style.display = "none";
}

async function submitDebtPayment() {
    if (!selectedRepaymentCustomer) {
        setDebtPaymentResult("Select a debtor before recording payment.", "error");
        return;
    }

    const amount = Number(document.getElementById("debt-payment-amount")?.value || 0);
    const currentDebt = Number(selectedRepaymentCustomer.current_debt || 0);
    if (amount <= 0) {
        setDebtPaymentResult("Payment amount must be greater than 0.", "error");
        return;
    }
    if (amount > currentDebt) {
        setDebtPaymentResult("Payment cannot exceed current debt.", "error");
        return;
    }

    const saveBtn = document.getElementById("debt-payment-save");
    const payload = {
        store_id: activeStoreId,
        user_id: Number(selectedRepaymentCustomer.id),
        amount,
        channel: document.getElementById("debt-payment-channel")?.value || "cash",
        reference_no: document.getElementById("debt-payment-reference")?.value || "",
        remarks: document.getElementById("debt-payment-remarks")?.value || "",
    };

    try {
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Recording...';
        }
        const data = await requestJson(
            "/store/debt-repayments/create",
            {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
            },
            "Unable to record debt payment."
        );

        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Unable to record debt payment.");
        }

        const payment = data.payment || {};
        setResult(
            `Debt payment recorded for ${payment.debtor_name || selectedRepaymentCustomer.name}. New debt: ${formatMoney(payment.new_debt || 0)}.`,
            "ok"
        );
        showToast("Debt payment recorded.", "success");
        selectedRepaymentCustomer.current_debt = Number(payment.new_debt || 0);
        renderDebtPaymentProfile();
        await loadOpeningBalanceStatus();
        await loadDebtCustomers();
        closeDebtPaymentModal();
    } catch (error) {
        setDebtPaymentResult(error.message || "Unable to record debt payment.", "error");
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Record Payment';
        }
    }
}

function updateDebtCustomerVisibility() {
    const paymentMethod = document.getElementById("payment-method").value;
    const wrap = document.getElementById("debt-customer-wrap");

    if (paymentMethod === "debt") {
        wrap.style.display = "flex";
        updateDebtPinUi();
        if (debtCustomers.length === 0) {
            loadDebtCustomers().catch(() => setResult("Unable to load debt customers.", "error"));
        }
        return;
    }

    wrap.style.display = "none";
    selectedDebtCustomerId = null;
    selectedDebtCustomer = null;
    selectedDebtPin = "";
    document.getElementById("debt-customer-search").value = "";
    const debtPinInput = document.getElementById("debt-pin-input");
    if (debtPinInput) debtPinInput.value = "";
    document.getElementById("debt-customer-suggestions").style.display = "none";
    updateDebtPinUi();
}

function renderPaymentMethods() {
    const chipsEl = document.getElementById("payment-quick");
    const selectEl = document.getElementById("payment-method");
    const methods = Array.isArray(paymentMethodsCache) ? paymentMethodsCache : [];

    if (methods.length === 0) {
        chipsEl.innerHTML = '<div class="payment-state is-empty"><i class="bi bi-credit-card"></i>No payment methods available.</div>';
        selectEl.innerHTML = "";
        updateCheckoutState();
        return;
    }

    chipsEl.innerHTML = methods
        .map((method, index) => {
            const isActive = index === 0 ? " is-active" : "";
            const iconClass = sanitizeIconClass(method.icon_class);
            const iconHtml = iconClass ? `<i class="${escapeHtml(iconClass)}"></i>` : "";
            return `<button type="button" class="payment-chip${isActive}" data-method="${escapeHtml(method.code)}">${iconHtml}${escapeHtml(method.label)}</button>`;
        })
        .join("");

    selectEl.innerHTML = methods
        .map((method, index) => `<option value="${escapeHtml(method.code)}" ${index === 0 ? "selected" : ""}>${escapeHtml(method.label)}</option>`)
        .join("");
}

function setPaymentMethodState(message, type = "info") {
    const chipsEl = document.getElementById("payment-quick");
    if (!chipsEl) return;

    const stateClass = type === "error" ? "is-error" : type === "empty" ? "is-empty" : "is-loading";
    chipsEl.innerHTML = `
        <div class="payment-state ${stateClass}">
            <i class="bi ${type === "error" ? "bi-exclamation-triangle" : type === "empty" ? "bi-credit-card" : "bi-arrow-repeat"}"></i>
            ${escapeHtml(message)}
        </div>
    `;
}

async function loadPaymentMethods() {
    if (!activeStoreId) return;

    setPaymentMethodState("Loading payment methods...");
    try {
        const data = await requestJson(
            `/store/payment-methods?store_id=${activeStoreId}`,
            {},
            "Unable to load payment methods."
        );
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Unable to load payment methods.");
        }

        paymentMethodsCache = Array.isArray(data.methods) ? data.methods : [];
        if (paymentMethodsCache.length === 0) {
            renderPaymentMethods();
            setResult("No active payment methods configured for this store.", "error");
            return;
        }

        renderPaymentMethods();
        const preferred = paymentMethodsCache.find((m) => String(m.code) === "cash")?.code
            || paymentMethodsCache[0]?.code
            || "cash";
        setPaymentMethod(preferred);
    } catch (error) {
        paymentMethodsCache = [
            { code: "cash", label: "Cash", icon_class: "bi bi-cash" },
            { code: "gcash", label: "GCash", icon_class: "bi bi-wallet2" },
            { code: "debt", label: "Debt", icon_class: "bi bi-credit-card" },
        ];
        renderPaymentMethods();
        const chipsWrap = document.getElementById("payment-quick");
        if (chipsWrap) {
            chipsWrap.insertAdjacentHTML(
                "afterbegin",
                '<div class="payment-state is-error"><i class="bi bi-exclamation-triangle"></i>Using fallback payment methods.</div>'
            );
        }
        setPaymentMethod("cash");
        setResult("Unable to load dynamic payment methods. Using fallback.", "error");
    }
}

function setPaymentMethod(method) {
    const paymentMethodEl = document.getElementById("payment-method");
    const chips = document.querySelectorAll("#payment-quick .payment-chip");
    paymentMethodEl.value = method;
    chips.forEach((chip) => chip.classList.toggle("is-active", chip.dataset.method === method));

    updateDebtCustomerVisibility();
    updateCheckoutState();
}

function refreshUi() {
    applyProductFilters();
    renderProducts();
    renderCart();
}

function addToCart(productId, name, price, qtyRequested = 1) {
    const product = getProductById(productId);
    if (!product) return;

    const stock = Number(product.stock_qty || 0);
    const inCart = getCartQty(productId);
    const qty = Number.isInteger(Number(qtyRequested)) ? Number(qtyRequested) : 1;
    const safeQty = qty > 0 ? qty : 1;
    const available = stock - inCart;

    if (available <= 0) {
        setResult("Cannot add more. Reached available stock.", "error");
        return false;
    }

    const qtyToAdd = Math.min(safeQty, available);

    const existing = cart.find((item) => Number(item.product_id) === Number(productId));
    if (existing) {
        existing.qty += qtyToAdd;
    } else {
        cart.push({
            product_id: Number(productId),
            name,
            price: Number(price),
            qty: qtyToAdd,
        });
    }

    if (qtyToAdd < safeQty) {
        setResult(`Only ${qtyToAdd} item(s) added. Reached available stock.`, "error");
    } else {
        setResult("", "ok");
    }
    refreshUi();
    return true;
}

function quickAddFromScan() {
    const codeInput = document.getElementById("scan-code-input");
    const qtyInput = document.getElementById("scan-qty-input");

    const code = String(codeInput.value || "").trim();
    const qty = Number(qtyInput.value || 1);

    if (code === "") {
        setResult("Scan or enter a product code first.", "error");
        return;
    }

    if (!Number.isInteger(qty) || qty <= 0) {
        setResult("Quantity must be a whole number greater than 0.", "error");
        return;
    }

    const product = getProductByScanCode(code);
    if (!product) {
        setResult(`No product found for code: ${code}`, "error");
        return;
    }

    const added = addToCart(Number(product.id), getProductDisplayName(product), Number(product.price || 0), qty);
    if (!added) return;

    document.getElementById("scan-product-suggestions").style.display = "none";
    codeInput.value = "";
    qtyInput.value = "1";
    codeInput.focus();
}

function increaseQty(productId) {
    const item = cart.find((entry) => Number(entry.product_id) === Number(productId));
    if (!item) return;

    const product = getProductById(productId);
    if (!product) return;

    if (Number(item.qty) >= Number(product.stock_qty || 0)) {
        setResult("Cannot increase. Reached available stock.", "error");
        return;
    }

    item.qty += 1;
    setResult("", "ok");
    refreshUi();
}

function decreaseQty(productId) {
    const item = cart.find((entry) => Number(entry.product_id) === Number(productId));
    if (!item) return;

    if (Number(item.qty) <= 1) {
        removeFromCart(productId);
        return;
    }

    item.qty -= 1;
    refreshUi();
}

function removeFromCart(productId) {
    cart = cart.filter((item) => Number(item.product_id) !== Number(productId));
    refreshUi();
}

async function loadMyStores() {
    const data = await requestJson("/store/my-stores", {}, "Unable to load store context.");

    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No assigned store found.");
    }

    myStores = data.stores;
    activeStoreId = Number(data.default_store_id || myStores[0].id);
}

async function loadProducts() {
    if (!activeStoreId) {
        productsLoadCompleted = false;
        renderProductCatalogState("error", "No active store selected.");
        return;
    }

    productsLoadCompleted = false;
    renderProductCatalogState("loading", "Loading products...", "Preparing the active store catalog.");

    try {
        const data = await requestJson(
            `/store/products?store_id=${activeStoreId}`,
            {},
            "Unable to load products."
        );

        if (!data || data.status !== "success") {
            productsCache = [];
            const message = data?.message || "Unable to load products.";
            renderProductCatalogState("error", message);
            renderCart();
            setResult(message, "error");
            return;
        }

        productsCache = Array.isArray(data.products) ? data.products : [];
        productsLoadCompleted = true;
        buildCategories();
        renderCategoryTabs();
        refreshUi();
    } catch (error) {
        productsCache = [];
        const message = error.message || "Unable to load products.";
        renderProductCatalogState("error", message);
        renderCart();
        setResult(message, "error");
    }
}

async function refreshProductsForCheckout() {
    if (!activeStoreId) {
        throw new Error("No active store selected.");
    }

    const data = await requestJson(
        `/store/products?store_id=${activeStoreId}`,
        {},
        "Unable to refresh product stock."
    );

    if (!data || data.status !== "success" || !Array.isArray(data.products)) {
        throw new Error(data?.message || "Unable to refresh product stock.");
    }

    productsCache = data.products;
    buildCategories();
    renderCategoryTabs();
}

async function preflightCartStock() {
    await refreshProductsForCheckout();

    const unavailable = [];
    let adjusted = false;

    cart = cart
        .map((item) => {
            const product = getProductById(item.product_id);
            if (!product) {
                unavailable.push(item.name);
                adjusted = true;
                return null;
            }

            const latestStock = Number(product.stock_qty || 0);
            if (latestStock <= 0) {
                unavailable.push(item.name);
                adjusted = true;
                return null;
            }

            if (Number(item.qty) > latestStock) {
                adjusted = true;
                return {
                    ...item,
                    qty: latestStock,
                    price: Number(product.price || item.price),
                    name: getProductDisplayName(product),
                };
            }

            return {
                ...item,
                price: Number(product.price || item.price),
                name: getProductDisplayName(product),
            };
        })
        .filter(Boolean);

    refreshUi();

    if (unavailable.length > 0) {
        return {
            ok: false,
            message: `Removed unavailable item(s): ${unavailable.join(", ")}. Review the cart before checkout.`,
        };
    }

    if (adjusted) {
        return {
            ok: false,
            message: "Cart quantities were adjusted to match latest stock. Review the cart before checkout.",
        };
    }

    return { ok: true, message: "" };
}

function buildPendingTransaction() {
    const paymentMethod = document.getElementById("payment-method").value;
    if (!activeStoreId) return null;

    if (cart.length === 0) return null;

    if (paymentMethod === "debt" && (!selectedDebtCustomerId || !selectedDebtPin)) return null;

    const payload = {
        customer_type: "walk_in",
        customer_user_id: paymentMethod === "debt" ? selectedDebtCustomerId : null,
        store_id: activeStoreId,
        payment_method: paymentMethod,
        debt_pin: paymentMethod === "debt" ? selectedDebtPin : "",
        items: cart.map((item) => ({
            product_id: Number(item.product_id),
            qty: Number(item.qty),
        })),
    };

    const cartSnapshot = cart.map((item) => ({
        name: item.name,
        qty: Number(item.qty),
        price: Number(item.price),
    }));
    const totalAmount = cartSnapshot.reduce((sum, item) => sum + item.qty * item.price, 0);
    const debtCustomer = paymentMethod === "debt" && selectedDebtCustomerId
        ? getDebtCustomerById(selectedDebtCustomerId)
        : null;

    return {
        payload,
        paymentMethod,
        cartSnapshot,
        totalAmount,
        storeName: getStoreNameById(activeStoreId),
        debtCustomerLabel:
            paymentMethod === "debt" && selectedDebtCustomerId
                ? getDebtCustomerLabelById(selectedDebtCustomerId)
                : "N/A",
        debtCustomer,
    };
}

async function processConfirmedTransaction(dataToProcess) {
    if (isSubmitting || !dataToProcess) return;
    const submitBtn = document.getElementById("submit-transaction");
    const confirmBtn = document.getElementById("confirm-proceed");

    try {
        isSubmitting = true;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';

        const data = await requestJson(
            "/pos/transactions",
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(dataToProcess.payload),
            },
            "Transaction failed, please try again."
        );

        if (data.status === "success") {
            setResult("Transaction successful.", "ok");
            const receipt = {
                transactionId: String(data.transaction_id),
                clientTxnId: String(data.client_txn_id || data.transaction_id),
                createdAt: data.created_at || new Date().toISOString(),
                storeName: dataToProcess.storeName,
                paymentMethod: dataToProcess.paymentMethod,
                customerName: dataToProcess.paymentMethod === "debt" ? dataToProcess.debtCustomerLabel : "Walk-in",
                debtCustomerLabel: dataToProcess.debtCustomerLabel,
                totalAmount: Number(data.total_amount ?? dataToProcess.totalAmount),
                items: dataToProcess.cartSnapshot,
                lookupUrl: `${window.location.origin}/store/receipt/${encodeURIComponent(String(data.transaction_id))}`,
            };

            closeConfirmTransactionModal(true);
            cart = [];
            selectedDebtPin = "";
            const debtPinInput = document.getElementById("debt-pin-input");
            if (debtPinInput) debtPinInput.value = "";
            await loadProducts();
            openReceiptModal(receipt);
            renderSuccessStrip(receipt);
            return;
        }

        const message = data?.message || data?.messages?.error || "Transaction failed.";
        setResult(message, "error");
    } catch (error) {
        setResult(error.message || "Transaction failed, please try again.", "error");
    } finally {
        isSubmitting = false;
        updateCheckoutState();
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Proceed';
    }
}

async function submitTransaction() {
    if (isSubmitting) return;
    if (!openingBalanceReady) {
        if (currentDaySession?.is_stale_open) {
            setResult("Ask another assigned supervisor or an Administrator to resolve the previous store day.", "error");
            return;
        }
        openOpeningBalanceModal();
        setResult("Open today's store day before creating transactions.", "error");
        return;
    }

    if (!activeStoreId) {
        setResult("No active store selected.", "error");
        return;
    }

    if (cart.length === 0) {
        setResult("Add at least one item before submitting.", "error");
        return;
    }

    if (document.getElementById("payment-method").value === "debt" && !selectedDebtCustomerId) {
        setResult("Select an employee (Faculty/Staff) for debt payment.", "error");
        return;
    }

    if (document.getElementById("payment-method").value === "debt") {
        if (selectedDebtCustomer && selectedDebtCustomer.has_debt_pin === false) {
            setResult("This customer must set a debt PIN in the User Portal before using debt payment.", "error");
            updateCheckoutState();
            return;
        }

        if (!selectedDebtPin) {
            openDebtPinModal();
            setResult("Enter the customer's debt PIN in the secure PIN modal.", "error");
            updateCheckoutState();
            return;
        }
    }

    const submitBtn = document.getElementById("submit-transaction");
    try {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Checking Stock...';
        const preflight = await preflightCartStock();
        if (!preflight.ok) {
            setResult(preflight.message, "error");
            updateCheckoutState();
            return;
        }
    } catch (error) {
        setResult(error.message || "Unable to verify stock before checkout.", "error");
        updateCheckoutState();
        return;
    }

    const draft = buildPendingTransaction();
    if (!draft) {
        setResult("Unable to prepare transaction.", "error");
        updateCheckoutState();
        return;
    }

    openConfirmTransactionModal(draft);
    updateCheckoutState();
}

document.getElementById("product-grid").addEventListener("click", (event) => {
    const card = event.target.closest("[data-product-card]");
    if (!card) return;

    const now = Date.now();
    if (now - lastCardAddAt < 220) return;
    lastCardAddAt = now;

    const productId = Number(card.getAttribute("data-product-card") || 0);
    const product = getProductById(productId);
    if (!product) return;

    addToCart(productId, getProductDisplayName(product), Number(product.price || 0));
});

document.getElementById("cart-body").addEventListener("click", (event) => {
    const incBtn = event.target.closest(".cart-inc");
    const decBtn = event.target.closest(".cart-dec");
    const removeBtn = event.target.closest(".cart-remove");

    if (incBtn) {
        increaseQty(Number(incBtn.dataset.productId));
        return;
    }

    if (decBtn) {
        decreaseQty(Number(decBtn.dataset.productId));
        return;
    }

    if (removeBtn) {
        removeFromCart(Number(removeBtn.dataset.productId));
    }
});

document.getElementById("category-tabs").addEventListener("click", (event) => {
    const button = event.target.closest(".category-tab");
    if (!button) return;

    activeCategory = button.dataset.category || "All";
    renderCategoryTabs();
    applyProductFilters();
    renderProducts();
});

document.getElementById("product-search").addEventListener("input", (event) => {
    searchQuery = event.target.value || "";
    applyProductFilters();
    renderProducts();
});

document.getElementById("scan-code-input").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    quickAddFromScan();
});

document.getElementById("scan-code-input").addEventListener("input", () => {
    renderScanProductSuggestions();
});

document.getElementById("scan-qty-input").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    quickAddFromScan();
});

document.getElementById("scan-add-btn").addEventListener("click", () => {
    quickAddFromScan();
});

document.getElementById("scan-product-suggestions").addEventListener("click", (event) => {
    const btn = event.target.closest("[data-scan-product-id]");
    if (!btn) return;

    const productId = Number(btn.getAttribute("data-scan-product-id") || 0);
    const product = getProductById(productId);
    if (!product) return;

    document.getElementById("scan-code-input").value = product.sku || String(product.id);
    document.getElementById("scan-product-suggestions").style.display = "none";
    document.getElementById("scan-qty-input").focus();
});

document.getElementById("open-scanner-btn").addEventListener("click", () => {
    openScannerModal("product").catch(() => setScannerStatus("Unable to open scanner.", true));
});

document.getElementById("open-debt-scanner-btn").addEventListener("click", () => {
    openScannerModal("debt").catch(() => setScannerStatus("Unable to open scanner.", true));
});

document.getElementById("scanner-close").addEventListener("click", async () => {
    await closeScannerModal();
});

document.getElementById("scanner-stop").addEventListener("click", async () => {
    await stopScanner();
    setScannerStatus("Scanner stopped.");
});

document.getElementById("barcode-scanner-modal").addEventListener("click", async (event) => {
    if (event.target.id === "barcode-scanner-modal") {
        await closeScannerModal();
    }
});

document.getElementById("payment-quick").addEventListener("click", (event) => {
    const chip = event.target.closest(".payment-chip");
    if (!chip) return;
    setPaymentMethod(chip.dataset.method || "cash");
});

document.getElementById("debt-customer-search").addEventListener("input", async (event) => {
    const query = event.target.value || "";
    selectedDebtCustomerId = null;
    selectedDebtCustomer = null;
    selectedDebtPin = "";
    const debtPinInput = document.getElementById("debt-pin-input");
    if (debtPinInput) debtPinInput.value = "";
    updateDebtPinUi();
    updateCheckoutState();
    await loadDebtCustomers(query);
});

document.getElementById("debt-customer-suggestions").addEventListener("click", (event) => {
    const btn = event.target.closest("[data-customer-id]");
    if (!btn) return;

    const customerId = Number(btn.getAttribute("data-customer-id") || 0);
    const picked = debtCustomers.find((c) => Number(c.id) === customerId);
    if (!picked) return;

    selectedDebtCustomerId = customerId;
    selectedDebtCustomer = picked;
    selectedDebtPin = "";
    const debtPinInput = document.getElementById("debt-pin-input");
    if (debtPinInput) debtPinInput.value = "";
    document.getElementById("debt-customer-search").value = picked.name;
    document.getElementById("debt-customer-suggestions").style.display = "none";
    updateDebtPinUi();
    updateCheckoutState();
    setResult(`Debt customer selected: ${picked.name}`, "ok");
});

document.getElementById("open-debt-payment-modal").addEventListener("click", openDebtPaymentModal);
document.getElementById("debt-payment-close").addEventListener("click", closeDebtPaymentModal);
document.getElementById("debt-payment-cancel").addEventListener("click", closeDebtPaymentModal);
document.getElementById("debt-payment-modal").addEventListener("click", (event) => {
    if (event.target.id === "debt-payment-modal") {
        closeDebtPaymentModal();
    }
});
document.getElementById("debt-payment-search").addEventListener("input", async (event) => {
    selectedRepaymentCustomer = null;
    renderDebtPaymentProfile();
    await loadDebtPaymentCustomers(event.target.value || "");
});
document.getElementById("debt-payment-suggestions").addEventListener("click", (event) => {
    const btn = event.target.closest("[data-repayment-user-id]");
    if (!btn) return;

    const userId = Number(btn.getAttribute("data-repayment-user-id") || 0);
    const picked = repaymentCustomers.find((customer) => Number(customer.id) === userId);
    if (!picked) return;

    selectedRepaymentCustomer = picked;
    document.getElementById("debt-payment-search").value = picked.name;
    document.getElementById("debt-payment-suggestions").style.display = "none";
    document.getElementById("debt-payment-amount").value = Number(picked.current_debt || 0).toFixed(2);
    renderDebtPaymentProfile();
    setDebtPaymentResult("");
});
document.getElementById("debt-payment-save").addEventListener("click", () => {
    submitDebtPayment().catch((error) => setDebtPaymentResult(error.message || "Unable to record debt payment.", "error"));
});

document.getElementById("debt-pin-input").addEventListener("input", (event) => {
    event.target.value = String(event.target.value || "").replace(/\D/g, "").slice(0, 6);
    const result = document.getElementById("debt-pin-modal-result");
    if (result) {
        result.textContent = "";
        result.className = "result-msg";
    }
});

document.getElementById("debt-pin-input").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    saveDebtPinFromModal();
});

document.getElementById("open-debt-pin-modal").addEventListener("click", openDebtPinModal);
document.getElementById("debt-pin-save").addEventListener("click", saveDebtPinFromModal);
document.getElementById("debt-pin-cancel").addEventListener("click", () => closeDebtPinModal(true));
document.getElementById("debt-pin-close").addEventListener("click", () => closeDebtPinModal(true));
document.getElementById("debt-pin-modal").addEventListener("click", (event) => {
    if (event.target.id === "debt-pin-modal") {
        closeDebtPinModal(true);
    }
});

document.addEventListener("click", (event) => {
    if (event.target.closest(".debt-search-wrap")) return;
    const box = document.getElementById("debt-customer-suggestions");
    if (box) box.style.display = "none";
    const paymentBox = document.getElementById("debt-payment-suggestions");
    if (paymentBox) paymentBox.style.display = "none";
});

document.addEventListener("click", (event) => {
    if (event.target.closest(".scan-code-wrap")) return;
    const box = document.getElementById("scan-product-suggestions");
    if (box) box.style.display = "none";
});

document.getElementById("submit-transaction").addEventListener("click", () => {
    submitTransaction().catch((error) => setResult(error.message || "Unable to submit transaction.", "error"));
});
document.getElementById("confirm-proceed").addEventListener("click", async () => {
    if (!pendingTransaction) return;
    await processConfirmedTransaction(pendingTransaction);
});
document.getElementById("confirm-cancel").addEventListener("click", closeConfirmTransactionModal);
document.getElementById("confirm-close").addEventListener("click", closeConfirmTransactionModal);
document.getElementById("confirm-transaction-modal").addEventListener("click", (event) => {
    if (event.target.id === "confirm-transaction-modal") {
        closeConfirmTransactionModal();
    }
});
document.getElementById("receipt-close").addEventListener("click", closeReceiptModal);
document.getElementById("receipt-print").addEventListener("click", printReceipt);
document.getElementById("receipt-view").addEventListener("click", () => {
    if (!lastReceipt?.lookupUrl) return;
    window.open(lastReceipt.lookupUrl, "_blank", "noopener");
});
document.getElementById("receipt-new").addEventListener("click", () => {
    closeReceiptModal();
    document.getElementById("product-search").focus();
});
document.getElementById("pos-success-strip").addEventListener("click", (event) => {
    const actionBtn = event.target.closest("[data-pos-success-action]");
    if (!actionBtn || !lastReceipt) return;

    const action = actionBtn.getAttribute("data-pos-success-action");
    if (action === "view" && lastReceipt.lookupUrl) {
        window.open(lastReceipt.lookupUrl, "_blank", "noopener");
        return;
    }

    if (action === "print") {
        printReceipt();
    }
});
document.getElementById("receipt-modal").addEventListener("click", (event) => {
    if (event.target.id === "receipt-modal") {
        closeReceiptModal();
    }
});
document.getElementById("opening-balance-save").addEventListener("click", saveOpeningBalance);
document.getElementById("opening-balance-close").addEventListener("click", closeOpeningBalanceModal);
document.getElementById("opening-balance-cancel").addEventListener("click", closeOpeningBalanceModal);
document.getElementById("opening-balance-modal").addEventListener("click", (event) => {
    if (event.target.id === "opening-balance-modal") {
        closeOpeningBalanceModal();
    }
});
document.getElementById("opening-balance-open-btn").addEventListener("click", () => {
    if (openingBalanceMode === "locked") return;
    openOpeningBalanceModal(currentDaySession);
});
document.getElementById("opening-balance-input").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    saveOpeningBalance();
});
document.getElementById("opening-ecash-input").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    saveOpeningBalance();
});
document.getElementById("store-day-close-btn").addEventListener("click", () => {
    openStoreDayCloseModal().catch((error) => setResult(error.message || "Unable to load current close-day totals.", "error"));
});
document.getElementById("store-day-close-x").addEventListener("click", closeStoreDayCloseModal);
document.getElementById("store-day-close-cancel").addEventListener("click", closeStoreDayCloseModal);
document.getElementById("store-day-close-save").addEventListener("click", saveStoreDayClose);
document.getElementById("store-day-close-modal").addEventListener("click", (event) => {
    if (event.target === event.currentTarget) {
        event.preventDefault();
    }
});
document.getElementById("store-day-close-modal").addEventListener("pointerdown", (event) => {
    if (event.target === event.currentTarget) {
        event.preventDefault();
    }
});
document.querySelector("#store-day-close-modal .store-day-close-card")?.addEventListener("click", (event) => {
    event.stopPropagation();
});
document.getElementById("closing-cash-input").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
});
document.getElementById("closing-cash-input").addEventListener("input", updateStoreDayCloseVariance);
document.getElementById("closing-ecash-input").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
});
document.getElementById("closing-ecash-input").addEventListener("input", updateStoreDayCloseVariance);

(async () => {
    try {
        renderCategoryTabs();
        setScannerUiState(false);
        await loadMyStores();
        await loadProducts();
        await loadPaymentMethods();
        await loadOpeningBalanceStatus();
    } catch (error) {
        if (!productsLoadCompleted) {
            renderProductCatalogState("error", error.message || "Unable to load store catalog.");
        }
        setResult(error.message || "Unable to load store context.", "error");
    }
})();
