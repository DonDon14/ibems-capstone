async function saveOpeningBalance() {
    if (!activeStoreId) return;

    const amount = Number(document.getElementById("opening-balance-input").value || 0);
    const accountOpenings = Array.from(document.querySelectorAll("[data-opening-account-id]")).map((input) => ({destination_account_id: Number(input.dataset.openingAccountId), opening_balance: Number(input.value || 0)}));
    const ecashAmount = accountOpenings.reduce((sum, row) => sum + row.opening_balance, 0);
    const note = String(document.getElementById("opening-balance-note").value || "").trim();
    const saveBtn = document.getElementById("opening-balance-save");

    if (openingBalanceMode === "reopen" && note === "") {
        setOpeningBalanceResult("Enter a reason before reopening the store day.", "error");
        document.getElementById("opening-balance-note").focus();
        return;
    }

    if (amount < 0 || accountOpenings.some((row) => row.opening_balance < 0)) {
        setOpeningBalanceResult("Every opening balance must be 0 or greater.", "error");
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
                    payment_account_openings: accountOpenings,
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
                ? `Store day reopened: cash ${formatMoney(amount)} and ${accountOpenings.length} receiving account${accountOpenings.length === 1 ? "" : "s"} counted.`
                : `Store day opened: cash ${formatMoney(amount)} and ${accountOpenings.length} receiving account${accountOpenings.length === 1 ? "" : "s"} counted.`,
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

const CASH_DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];

function denominationCounterMarkup(target) {
    return `<div class="cash-denomination-grid">${CASH_DENOMINATIONS.map((denomination) => `<label class="cash-denomination-item"><strong>PHP ${denomination.toLocaleString()}</strong><input type="number" min="0" step="1" inputmode="numeric" value="" data-denomination="${denomination}" data-denomination-scope="${target}" aria-label="PHP ${denomination.toLocaleString()} quantity"><small data-denomination-subtotal="${denomination}">PHP 0.00</small></label>`).join("")}</div><div class="cash-denomination-total"><span>Counted amount</span><strong data-denomination-total="${target}">PHP 0.00</strong></div><button type="button" class="primary-btn cash-denomination-apply" data-denomination-apply="${target}"><i class="bi bi-check2"></i> Use counted amount</button>`;
}

function updateDenominationCounter(target) {
    const counter = document.getElementById(`${target}-denomination-counter`);
    if (!counter) return 0;
    let total = 0;
    counter.querySelectorAll(`[data-denomination-scope="${target}"]`).forEach((input) => {
        const denomination = Number(input.dataset.denomination || 0);
        const quantity = Math.max(0, Math.floor(Number(input.value) || 0));
        if (Number(input.value) !== quantity && input.value !== "") input.value = String(quantity);
        const subtotal = denomination * quantity;
        total += subtotal;
        const subtotalEl = counter.querySelector(`[data-denomination-subtotal="${denomination}"]`);
        if (subtotalEl) subtotalEl.textContent = formatMoney(subtotal);
    });
    const totalEl = counter.querySelector(`[data-denomination-total="${target}"]`);
    if (totalEl) totalEl.textContent = formatMoney(total);
    return total;
}

function toggleDenominationCounter(button) {
    const target = String(button.dataset.denominationTarget || "");
    const counter = document.getElementById(`${target}-denomination-counter`);
    if (!counter) return;
    if (!counter.dataset.ready) {
        counter.innerHTML = denominationCounterMarkup(target);
        counter.dataset.ready = "true";
    }
    const willOpen = counter.classList.contains("is-hidden");
    counter.classList.toggle("is-hidden", !willOpen);
    button.setAttribute("aria-expanded", String(willOpen));
}

function renderStoreDayCloseReconciliation() {
    const wrap = document.getElementById("store-day-close-reconcile");
    if (!wrap || !currentDaySession) return;

    const expectedCash = closeDayValue("expected_cash_on_hand") || closeDayValue("expected_cash");
    const expectedEcash = closeDayValue("expected_ecash_on_hand") || closeDayValue("expected_ecash");
    const expectedTotal = expectedCash + expectedEcash;
    const salesRows = Array.isArray(currentDaySession.payment_method_sales) ? currentDaySession.payment_method_sales : [];
    const collectionRows = Array.isArray(currentDaySession.payment_method_collections) ? currentDaySession.payment_method_collections : [];
    const receivedSalesRows = salesRows.filter((row) => row.is_collected);
    const creditSalesRows = salesRows.filter((row) => !row.is_collected);
    const receivedSalesMarkup = receivedSalesRows.length
        ? receivedSalesRows.map((row) => closeDayReconcileRow(`${row.payment_method_label} sales`, Number(row.amount || 0))).join("")
        : closeDayReconcileRow("No paid sales", 0, "is-muted");
    const creditSalesMarkup = creditSalesRows.length
        ? creditSalesRows.map((row) => closeDayReconcileRow(`${row.payment_method_label} sales`, Number(row.amount || 0), "is-muted")).join("")
        : closeDayReconcileRow("No employee-credit sales", 0, "is-muted");
    const collectionsMarkup = collectionRows.length
        ? collectionRows.map((row) => closeDayReconcileRow(`${row.payment_method_label} debt collections`, Number(row.amount || 0))).join("")
        : closeDayReconcileRow("No debt collections", 0, "is-muted");
    const collectedCash = closeDayValue("cash_sales") + closeDayValue("cash_debt_payments");
    const collectedEcash = closeDayValue("ecash_sales") + closeDayValue("ecash_debt_payments");

    wrap.innerHTML = `
        <section class="close-reconcile-section">
            <h4>Today&apos;s Activity</h4>
            <div class="close-reconcile-group">
                <h5>Money received today</h5>
                ${receivedSalesMarkup}
                ${collectionsMarkup}
            </div>
            <div class="close-reconcile-group is-credit-group">
                <h5>Sold on account credit</h5>
                ${creditSalesMarkup}
                <small>Recorded as employee or department debt; no cash or wallet balance was received today.</small>
            </div>
        </section>
        <section class="close-reconcile-section">
            <h4>Expected Ending Balances</h4>
            <div class="close-reconcile-group">
                <h5>Physical cash</h5>
            ${closeDayReconcileRow("Opening cash", closeDayValue("opening_cash"))}
            ${closeDayReconcileRow("Cash sales + debt collections", collectedCash)}
            ${closeDayReconcileRow("Other cash added", closeDayOtherCashIn())}
            ${closeDayReconcileRow("Cash out", -closeDayValue("cash_out"), "is-muted")}
            ${closeDayReconcileRow("Expected cash", expectedCash, "is-total")}
            </div>
            <div class="close-reconcile-group">
                <h5>Electronic accounts</h5>
                ${closeDayReconcileRow("Opening account balances", closeDayValue("opening_ecash"))}
                ${closeDayReconcileRow("Wallet sales + debt collections", collectedEcash)}
                ${closeDayReconcileRow("Other electronic money added", closeDayOtherEcashIn())}
                ${closeDayReconcileRow("Electronic money out", -closeDayValue("ecash_out"), "is-muted")}
                ${closeDayReconcileRow("Expected electronic total", expectedEcash, "is-total")}
            </div>
            ${closeDayReconcileRow("Cash + electronic accounts", expectedTotal, "is-grand")}
        </section>
    `;
    const accountWrap = document.getElementById("store-day-account-counts");
    const accounts = Array.isArray(currentDaySession.payment_account_balances) ? currentDaySession.payment_account_balances : [];
    if (accountWrap) accountWrap.innerHTML = accounts.length ? `<h4>Receiving Account Ending Balances</h4>${accounts.map((account) => `<label class="store-day-account-count"><span><strong>${escapeHtml(account.payment_method_label)} · ${escapeHtml(account.account_name)}</strong><small>${escapeHtml(String(account.account_number || "").slice(-4).padStart(String(account.account_number || "").length, "•"))} · Opening ${escapeHtml(formatMoney(account.opening_balance))} · Sales ${escapeHtml(formatMoney(account.sales))} · Collections ${escapeHtml(formatMoney(account.collections || 0))} · Expected ending ${escapeHtml(formatMoney(account.expected_balance))}</small></span><input type="number" min="0" step="0.01" value="${Number(account.expected_balance || 0).toFixed(2)}" data-closing-account-id="${Number(account.id)}"></label>`).join("")}` : "";
    const unassignedWrap = document.getElementById("store-day-unassigned-counts");
    const unassigned = Array.isArray(currentDaySession.unassigned_payment_balances) ? currentDaySession.unassigned_payment_balances : [];
    if (unassignedWrap) unassignedWrap.innerHTML = unassigned.length ? `<h4>Legacy Payments Without a Destination</h4>${unassigned.map((row) => `<label class="store-day-account-count"><span><strong>${escapeHtml(row.payment_method_label)}</strong><small>No receiving account was recorded. Expected ${escapeHtml(formatMoney(row.expected_balance))}</small></span><input type="number" min="0" step="0.01" value="${Number(row.expected_balance || 0).toFixed(2)}" data-closing-unassigned-key="${escapeHtml(row.key)}"></label>`).join("")}` : "";
}

function updateStoreDayCloseVariance() {
    const expectedCash = closeDayValue("expected_cash_on_hand") || closeDayValue("expected_cash");
    const expectedEcash = closeDayValue("expected_ecash_on_hand") || closeDayValue("expected_ecash");
    const countedCash = Number(document.getElementById("closing-cash-input")?.value || 0);
    const closingAccountInputs = Array.from(document.querySelectorAll("[data-closing-account-id], [data-closing-unassigned-key]"));
    const countedEcash = closingAccountInputs.length
        ? closingAccountInputs.reduce((sum, input) => sum + Number(input.value || 0), 0)
        : Number(document.getElementById("closing-ecash-input")?.value || 0);
    document.getElementById("closing-ecash-input").value = countedEcash.toFixed(2);
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
        summary.textContent = "Expected totals are calculated from opening balances and routed transactions. Count cash and every receiving account independently.";
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
    const accountCounts = Array.from(document.querySelectorAll("[data-closing-account-id]")).map((input) => ({destination_account_id: Number(input.dataset.closingAccountId), counted_balance: Number(input.value || 0)}));
    const unassignedPaymentCounts = Array.from(document.querySelectorAll("[data-closing-unassigned-key]")).map((input) => ({key: String(input.dataset.closingUnassignedKey), counted_balance: Number(input.value || 0)}));
    const countedEcash = accountCounts.reduce((sum, row) => sum + row.counted_balance, 0) + unassignedPaymentCounts.reduce((sum, row) => sum + row.counted_balance, 0);
    const note = String(document.getElementById("closing-note-input").value || "").trim();
    const saveBtn = document.getElementById("store-day-close-save");

    if (countedCash < 0 || accountCounts.some((row) => row.counted_balance < 0) || unassignedPaymentCounts.some((row) => row.counted_balance < 0)) {
        setStoreDayCloseResult("Every counted ending balance must be 0 or greater.", "error");
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
                    payment_account_counts: accountCounts,
                    unassigned_payment_counts: unassignedPaymentCounts,
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
        return customer.qr_match === true || employeeId === code || email === code || id === code || name === code;
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
    const selectionKind = document.getElementById("payment-method")?.value === "debt" ? "Debt customer" : "Employee customer";
    setResult(`${selectionKind} selected: ${exact.name}`, "ok");
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

function getCurrentUserRoleLabel() {
    const normalized = currentUserRole.replace(/[_-]+/g, " ").trim().toLowerCase();
    if (!normalized) return "Store operations";
    return normalized.replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function updatePosStoreContext() {
    const nameEl = document.getElementById("pos-store-name");
    const roleEl = document.getElementById("pos-store-role");
    if (nameEl) nameEl.textContent = getStoreNameById(activeStoreId);
    if (roleEl) roleEl.textContent = getCurrentUserRoleLabel();
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

function isAccountPaymentMethod(method) {
    return String(method || "").toLowerCase() === "debt";
}

function requiresCheckoutCustomer(method) {
    return isAccountPaymentMethod(method);
}

function getPaymentOptionLabel(method) {
    const code = String(method?.code || "").toLowerCase();
    if (code === "debt") return "Charge to employee or department account";
    return String(method?.label || formatPaymentLabel(code));
}

function getPaymentMethodGuidance(method) {
    const code = String(method || "").toLowerCase();
    if (code === "debt") return "Choose an employee or department account. The matching balance is charged only after secure PIN authorization.";
    return "Customer selection is optional. Leave it blank for a walk-in sale.";
}

function getCartTotal() {
    return cart.reduce((sum, item) => sum + Number(item.qty || 0) * Number(item.price || 0), 0);
}

function getImmediatePaymentMethods() {
    return paymentMethodsCache.filter((method) => !isAccountPaymentMethod(method.code));
}

function splitIncludesMethod(method) {
    return splitTenderEnabled && splitTenderAmounts.has(String(method || "").toLowerCase());
}

function splitIncludesDebt() {
    return splitIncludesMethod("debt");
}
