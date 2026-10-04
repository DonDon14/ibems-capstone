function getSplitPaymentLines() {
    return Array.from(splitTenderAmounts, ([payment_method, amount]) => ({
        payment_method,
        amount: Math.round(Number(amount || 0) * 100) / 100,
        destination_account_id: selectedPaymentAccounts.get(payment_method) || null,
    }));
}

function getPaymentMethod(methodCode) { return paymentMethodsCache.find((row) => String(row.code) === String(methodCode)); }

function ensurePaymentAccountSelection(methodCode) {
    const method = getPaymentMethod(methodCode);
    const accounts = Array.isArray(method?.destination_accounts) ? method.destination_accounts : [];
    if (accounts.length && !accounts.some((row) => Number(row.id) === Number(selectedPaymentAccounts.get(methodCode)))) {
        selectedPaymentAccounts.set(String(methodCode), Number(accounts[0].id));
    }
}

function openCustomerQrModal(methodCode, accountId, trigger) {
    const method = getPaymentMethod(methodCode);
    const account = method?.destination_accounts?.find((row) => Number(row.id) === Number(accountId));
    if (!method || !account?.image_url) return;

    document.getElementById("customer-qr-title").textContent = `Scan to pay with ${method.label}`;
    document.getElementById("customer-qr-image").src = String(account.image_url);
    document.getElementById("customer-qr-image").alt = `Payment QR for ${account.account_name}`;
    document.getElementById("customer-qr-method").textContent = String(method.label || "Payment");
    document.getElementById("customer-qr-account").textContent = String(account.account_name || "Receiving account");
    document.getElementById("customer-qr-number").textContent = String(account.masked_number || "");
    const modal = document.getElementById("customer-qr-modal");
    modal.classList.remove("is-hidden");
    modal.style.display = "grid";
    document.body.classList.add("modal-open");
    focusPosCheckoutDialog(modal, document.getElementById("customer-qr-close"), trigger || document.activeElement);
}

function closeCustomerQrModal() {
    const modal = document.getElementById("customer-qr-modal");
    if (!modal || modal.classList.contains("is-hidden")) return;
    modal.classList.add("is-hidden");
    modal.style.display = "none";
    document.body.classList.remove("modal-open");
    document.getElementById("customer-qr-image").removeAttribute("src");
    releasePosCheckoutDialogFocus(modal);
}

function renderPaymentAccountPicker() {
    const picker = document.getElementById("payment-account-picker"); if (!picker) return;
    const codes = splitTenderEnabled ? Array.from(splitTenderAmounts.keys()) : [document.getElementById("payment-method")?.value || ""];
    const methods = codes.map(getPaymentMethod).filter((method) => Array.isArray(method?.destination_accounts) && method.destination_accounts.length);
    picker.classList.toggle("is-hidden", methods.length === 0);
    picker.innerHTML = methods.map((method) => {
        ensurePaymentAccountSelection(String(method.code));
        const selectedId = Number(selectedPaymentAccounts.get(String(method.code)) || 0);
        const selected = method.destination_accounts.find((row) => Number(row.id) === selectedId) || method.destination_accounts[0];
        const qr = selected?.image_url
            ? `<div class="pos-payment-qr-preview"><img src="${escapeHtml(selected.image_url)}" alt="QR code for ${escapeHtml(selected.account_name)}"><button type="button" class="secondary-btn pos-show-qr-btn" data-show-payment-qr="${Number(selected.id)}" data-payment-method-code="${escapeHtml(method.code)}"><i class="bi bi-arrows-fullscreen" aria-hidden="true"></i> Show QR</button></div>`
            : method.image_url
                ? `<img src="${escapeHtml(method.image_url)}" alt="${escapeHtml(method.label)} payment image">`
                : '<span class="payment-account-standard"><i class="bi bi-wallet2"></i></span>';
        return `<section class="pos-payment-account"><div class="pos-payment-account-head"><strong>Pay ${escapeHtml(method.label)} to</strong><small>Select the exact receiving account</small></div><div class="pos-payment-account-body">${qr}<label><span>Receiving account</span><select data-payment-account-method="${escapeHtml(method.code)}">${method.destination_accounts.map((account) => `<option value="${Number(account.id)}" ${Number(account.id) === selectedId ? "selected" : ""}>${escapeHtml(account.account_name)} · ${escapeHtml(account.masked_number)}</option>`).join("")}</select></label></div>${selected?.image_url ? '<small class="pos-qr-guidance">Open the large QR and turn the screen toward the customer.</small>' : '<small class="pos-qr-guidance">No QR uploaded. Account details remain available for manual payment.</small>'}</section>`;
    }).join("");
}

function renderSplitPaymentEditor() {
    const editor = document.getElementById("split-payment-editor");
    const toggle = document.getElementById("split-payment-toggle");
    if (!editor || !toggle) return;
    toggle.classList.toggle("is-active", splitTenderEnabled);
    toggle.setAttribute("aria-pressed", splitTenderEnabled ? "true" : "false");
    editor.classList.toggle("is-hidden", !splitTenderEnabled);
    if (!splitTenderEnabled) {
        editor.innerHTML = "";
        return;
    }

    const total = getCartTotal();
    const lines = getSplitPaymentLines();
    const allocated = lines.reduce((sum, line) => sum + Number(line.amount || 0), 0);
    const remaining = Math.round((total - allocated) * 100) / 100;
    editor.innerHTML = `
        <div class="split-payment-head"><strong>Split allocation</strong><small>Select any active tender. Debt posts only its allocated remainder to the employee account.</small></div>
        <div class="split-payment-lines">
            ${lines.map((line) => {
                const method = paymentMethodsCache.find((item) => String(item.code) === line.payment_method);
                return `<label class="split-payment-line"><span>${escapeHtml(getPaymentOptionLabel(method || {code: line.payment_method}))}</span><input type="number" min="0.01" step="0.01" inputmode="decimal" value="${Number(line.amount || 0).toFixed(2)}" data-split-amount="${escapeHtml(line.payment_method)}"></label>`;
            }).join("")}
        </div>
        <div class="split-payment-summary ${Math.abs(remaining) < 0.005 ? "is-balanced" : ""}">
            <span>Allocated <strong>${formatMoney(allocated)}</strong></span>
            <span>${remaining >= 0 ? "Remaining" : "Over"} <strong>${formatMoney(Math.abs(remaining))}</strong></span>
        </div>
    `;
}

function refreshSplitPaymentSummary() {
    const summary = document.querySelector("#split-payment-editor .split-payment-summary");
    if (!summary) return;
    const total = getCartTotal();
    const allocated = getSplitPaymentLines().reduce((sum, line) => sum + Number(line.amount || 0), 0);
    const remaining = Math.round((total - allocated) * 100) / 100;
    summary.classList.toggle("is-balanced", Math.abs(remaining) < 0.005);
    summary.innerHTML = `
        <span>Allocated <strong>${formatMoney(allocated)}</strong></span>
        <span>${remaining >= 0 ? "Remaining" : "Over"} <strong>${formatMoney(Math.abs(remaining))}</strong></span>
    `;
}

function setSplitTenderEnabled(enabled) {
    splitTenderEnabled = !!enabled;
    splitTenderAmounts.clear();
    if (splitTenderEnabled) {
        const immediate = getImmediatePaymentMethods();
        const selected = document.getElementById("payment-method")?.value || "cash";
        const first = immediate.find((method) => method.code === selected) || immediate[0];
        const second = immediate.find((method) => method.code !== first?.code);
        if (first) splitTenderAmounts.set(String(first.code), getCartTotal());
        if (second) splitTenderAmounts.set(String(second.code), 0);
    }
    renderPaymentMethods();
    renderSplitPaymentEditor();
    renderPaymentAccountPicker();
    updateDebtCustomerVisibility();
    updateCheckoutState();
}

const posCheckoutDialogStates = new Map();
const posStoreDayControlStates = new Map();

function setPosStoreDayDialogBusy(modal, busy) {
    modal.setAttribute('aria-busy', String(busy));
    if (busy) {
        const controls = Array.from(modal.querySelectorAll('input, select, textarea, button'))
            .filter(control => !control.classList.contains('receipt-close'));
        posStoreDayControlStates.set(modal, controls.map(control => ({ control, disabled: control.disabled })));
        controls.forEach(control => { control.disabled = true; });
        modal.querySelector('.receipt-close')?.focus();
    } else {
        (posStoreDayControlStates.get(modal) || []).forEach(({ control, disabled }) => { control.disabled = disabled; });
        posStoreDayControlStates.delete(modal);
    }
}

function focusPosCheckoutDialog(modal, preferredControl, returnFocus = document.activeElement) {
    const state = { returnFocus: posCheckoutDialogStates.get(modal)?.returnFocus || returnFocus };
    posCheckoutDialogStates.set(modal, state);
    const card = modal.querySelector('.receipt-card');
    if (card) card.scrollTop = 0;
    const content = modal.querySelector('#confirm-transaction-content, #receipt-content, #product-variant-options, .customer-qr-content, .opening-balance-body, .store-day-close-body, .debt-payment-body');
    if (content) content.scrollTop = 0;
    window.requestAnimationFrame(() => {
        if (posCheckoutDialogStates.get(modal) !== state || modal.style.display === 'none' || !modal.isConnected) return;
        const control = preferredControl?.isConnected && !preferredControl.disabled
            ? preferredControl : modal.querySelector('.receipt-close');
        control?.focus();
    });
}

function releasePosCheckoutDialogFocus(modal, restoreFocus = true) {
    if (!posCheckoutDialogStates.has(modal)) return;
    const returnFocus = posCheckoutDialogStates.get(modal)?.returnFocus;
    posCheckoutDialogStates.delete(modal);
    if (restoreFocus) {
        const target = returnFocus?.isConnected && !returnFocus.disabled && returnFocus.getClientRects().length
            ? returnFocus : document.getElementById('product-search');
        if (target?.isConnected && !target.disabled) target.focus({ preventScroll: true });
    }
    return returnFocus;
}

function handlePosCheckoutDialogKeydown(event, closeDialog) {
    if (event.defaultPrevented) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        closeDialog();
        return;
    }
    if (event.key !== 'Tab') return;
    const modal = event.currentTarget;
    const controls = Array.from(modal.querySelectorAll('button, a[href], input, select, textarea, [tabindex]'))
        .filter(control => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length);
    const first = controls[0];
    const last = controls[controls.length - 1];
    const outsideControls = !controls.includes(document.activeElement);
    if (outsideControls || (event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
        event.preventDefault();
        (event.shiftKey ? last : first)?.focus();
    }
}

function bindPosCheckoutDialogKeys() {
    document.getElementById('confirm-transaction-modal').addEventListener('keydown', event => handlePosCheckoutDialogKeydown(event, closeConfirmTransactionModal));
    document.getElementById('receipt-modal').addEventListener('keydown', event => handlePosCheckoutDialogKeydown(event, closeReceiptModal));
    document.getElementById('barcode-scanner-modal').addEventListener('keydown', event => handlePosCheckoutDialogKeydown(event, closeScannerModal));
    document.getElementById('customer-qr-modal')?.addEventListener('keydown', event => handlePosCheckoutDialogKeydown(event, closeCustomerQrModal));
    document.getElementById('product-variant-modal')?.addEventListener('keydown', event => handlePosCheckoutDialogKeydown(event, closeProductVariantPicker));
    document.getElementById('debt-pin-modal')?.addEventListener('keydown', event => handlePosCheckoutDialogKeydown(event, () => closeDebtPinModal(true)));
    document.getElementById('opening-balance-modal')?.addEventListener('keydown', event => handlePosCheckoutDialogKeydown(event, closeOpeningBalanceModal));
    document.getElementById('store-day-close-modal')?.addEventListener('keydown', event => handlePosCheckoutDialogKeydown(event, closeStoreDayCloseModal));
    document.getElementById('debt-payment-modal')?.addEventListener('keydown', event => handlePosCheckoutDialogKeydown(event, closeDebtPaymentModal));
}

function openReceiptModal(receipt, returnFocus = document.activeElement) {
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
    focusPosCheckoutDialog(modal, document.getElementById('receipt-close'), returnFocus);
}

function closeReceiptModal() {
    const modal = document.getElementById("receipt-modal");
    modal.style.display = "none";
    releasePosCheckoutDialogFocus(modal);
}

function renderSuccessStrip(receipt) {
    const strip = document.getElementById("pos-success-strip");
    if (!strip || !receipt) return;
    window.IbemsFeedbackSound?.play('success');

    strip.classList.remove("is-hidden");
    strip.innerHTML = `
        <div>
            <span>Last transaction completed</span>
            <strong>${formatMoney(receipt.totalAmount)}</strong>
            <small>${escapeHtml(formatPaymentLabel(receipt.paymentMethod))} | ${escapeHtml(receipt.customerName || "Walk-in")}</small>
        </div>
        <div class="pos-success-actions">
            <button type="button" class="secondary-btn" data-pos-success-action="view"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> View</button>
            <button type="button" class="primary-btn" data-pos-success-action="print"><i class="bi bi-printer" aria-hidden="true"></i> Print</button>
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

    const paymentLabel = data.paymentMethod === "split" ? "Split payment" : formatPaymentLabel(data.paymentMethod);
    const paymentLines = Array.isArray(data.payments) ? data.payments : [];
    const cashPayment = paymentLines.find((line) => String(line.payment_method) === "cash") || null;
    const paymentBreakdownHtml = paymentLines.length > 1
        ? `<div class="confirm-payment-breakdown">${paymentLines.map((line) => `<div><span>${escapeHtml(formatPaymentLabel(line.payment_method))}</span><strong>${formatMoney(line.amount)}</strong></div>`).join("")}</div>`
        : "";
    const totalItems = data.cartSnapshot.reduce((sum, item) => sum + Number(item.qty || 0), 0);
    const debt = data.debtCustomer || null;
    const debtPayment = paymentLines.find((line) => String(line.payment_method) === "debt") || null;
    const debtAmount = Number(debtPayment?.amount || 0);
    const currentDebt = Number(debt?.current_debt || 0);
    const availableCredit = Number(debt?.available_credit || 0);
    const creditLimit = currentDebt + availableCredit;
    const projectedDebt = currentDebt + debtAmount;
    const projectedRemainingCredit = Math.max(0, creditLimit - projectedDebt);
    const debtWarning = debtPayment
        ? `
            <div class="confirm-warning">
                <i class="bi bi-exclamation-triangle"></i>
                <div>
                    <strong>${paymentLines.length > 1 ? "Partial debt allocation" : "Debt transaction"}</strong>
                    <span>${formatMoney(debtAmount)} will be charged to ${escapeHtml(data.debtCustomerLabel)}. Projected remaining ${data.departmentMode ? "monthly allocation" : "credit"}: ${formatMoney(projectedRemainingCredit)}.</span>
                </div>
            </div>
        `
        : "";
    const cashTenderBlock = cashPayment
        ? `
            <section class="confirm-section confirm-cash-tender">
                <h4><i class="bi bi-cash-stack"></i> Cash Tender</h4>
                <div class="confirm-cash-grid">
                    <label class="payment-wrap" for="confirm-cash-received">
                        <span>Cash Received</span>
                        <input id="confirm-cash-received" type="number" min="${Number(cashPayment.amount || 0).toFixed(2)}" step="0.01" inputmode="decimal" placeholder="0.00" autocomplete="off">
                    </label>
                    <div class="confirm-change-due" aria-live="polite">
                        <span>Change Due</span>
                        <strong id="confirm-change-due">${formatMoney(0)}</strong>
                    </div>
                </div>
                <small id="confirm-cash-help">Enter at least ${formatMoney(cashPayment.amount)} for the cash portion.</small>
            </section>
        `
        : "";
    const checkoutCustomer = data.checkoutCustomer || null;
    const customerBlock = debtPayment
        ? `
            <section class="confirm-section">
                <h4><i class="bi ${data.departmentMode ? "bi-buildings" : "bi-person-vcard"}"></i> ${data.departmentMode ? "Department Account" : "Debt Customer"}</h4>
                <div class="confirm-meta-grid">
                    <div class="confirm-meta-item"><span>Name</span><strong>${escapeHtml(debt?.name || data.debtCustomerLabel)}</strong></div>
                    <div class="confirm-meta-item"><span>Type</span><strong>${escapeHtml(String(debt?.user_type || "N/A").replace(/\b\w/g, (letter) => letter.toUpperCase()))}</strong></div>
                    <div class="confirm-meta-item"><span>${data.departmentMode ? "Used This Month" : "Current Debt"}</span><strong>${formatMoney(currentDebt)}</strong></div>
                    <div class="confirm-meta-item"><span>Debt Portion</span><strong>${formatMoney(debtAmount)}</strong></div>
                    <div class="confirm-meta-item"><span>${data.departmentMode ? "Projected Monthly Use" : "Projected Debt"}</span><strong>${formatMoney(projectedDebt)}</strong></div>
                    <div class="confirm-meta-item"><span>Remaining ${data.departmentMode ? "Allocation" : "Credit"}</span><strong>${formatMoney(projectedRemainingCredit)}</strong></div>
                    ${data.departmentMode ? `<div class="confirm-meta-item"><span>Requested By</span><strong>${escapeHtml(data.departmentRequesterName)}</strong></div><div class="confirm-meta-item"><span>Approved By</span><strong>${escapeHtml(data.departmentApprover?.name || "")}</strong></div>` : ""}
                </div>
            </section>
        `
        : checkoutCustomer
            ? `
            <section class="confirm-section">
                <h4><i class="bi bi-person-check"></i> Customer</h4>
                <div class="confirm-meta-grid">
                    <div class="confirm-meta-item"><span>Name</span><strong>${escapeHtml(checkoutCustomer.name || data.customerLabel)}</strong></div>
                    <div class="confirm-meta-item"><span>Customer Type</span><strong>${escapeHtml(String(checkoutCustomer.user_type || "Employee").replace(/\b\w/g, (letter) => letter.toUpperCase()))}</strong></div>
                    <div class="confirm-meta-item confirm-meta-wide"><span>Transaction Record</span><strong>Saved to this employee&apos;s purchase history</strong></div>
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
            ${paymentBreakdownHtml}
        </section>
        ${cashTenderBlock}
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

function updateConfirmCashTender() {
    const cashPayment = pendingTransaction?.payments?.find((line) => String(line.payment_method) === "cash");
    if (!pendingTransaction || !cashPayment) return true;

    const input = document.getElementById("confirm-cash-received");
    const changeEl = document.getElementById("confirm-change-due");
    const helpEl = document.getElementById("confirm-cash-help");
    const proceedBtn = document.getElementById("confirm-proceed");
    const received = Number(input?.value || 0);
    const cashAmount = Number(cashPayment.amount || 0);
    const valid = Number.isFinite(received) && received >= cashAmount;
    const changeDue = valid ? Math.max(0, received - cashAmount) : 0;

    pendingTransaction.cashReceived = valid ? received : null;
    pendingTransaction.changeDue = changeDue;
    pendingTransaction.payload.cash_received = valid ? received : null;
    pendingTransaction.payload.change_due = changeDue;
    const payloadCashLine = pendingTransaction.payload.payments?.find((line) => String(line.payment_method) === "cash");
    if (payloadCashLine) payloadCashLine.cash_received = valid ? received : null;
    if (changeEl) changeEl.textContent = formatMoney(changeDue);
    if (helpEl) {
        helpEl.textContent = valid
            ? `${formatMoney(received)} received; return ${formatMoney(changeDue)} change.`
            : `Enter at least ${formatMoney(cashAmount)} for the cash portion.`;
        helpEl.classList.toggle("is-error", !valid && String(input?.value || "") !== "");
    }
    if (proceedBtn) proceedBtn.disabled = !valid;
    return valid;
}

function openConfirmTransactionModal(data) {
    pendingTransaction = data;
    const content = document.getElementById("confirm-transaction-content");
    content.innerHTML = buildConfirmTransactionHtml(data);
    document.getElementById("confirm-transaction-modal").style.display = "grid";
    if (data.payments?.some((line) => String(line.payment_method) === "cash")) {
        const input = document.getElementById("confirm-cash-received");
        input?.addEventListener("input", updateConfirmCashTender);
        input?.addEventListener("keydown", (event) => {
            if (event.key !== "Enter" || !updateConfirmCashTender()) return;
            event.preventDefault();
            document.getElementById("confirm-proceed")?.click();
        });
        updateConfirmCashTender();
        focusPosCheckoutDialog(document.getElementById('confirm-transaction-modal'), input);
    } else {
        document.getElementById("confirm-proceed").disabled = false;
        focusPosCheckoutDialog(document.getElementById('confirm-transaction-modal'), document.getElementById('confirm-close'));
    }
}

function closeConfirmTransactionModal(force = false) {
    if (isSubmitting && force !== true) return;
    pendingTransaction = null;
    const modal = document.getElementById('confirm-transaction-modal');
    modal.style.display = "none";
    return releasePosCheckoutDialogFocus(modal, force !== true);
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

function getCheckoutDebtAmount() {
    if (splitTenderEnabled) return Math.max(0, Number(splitTenderAmounts.get("debt") || 0));
    return document.getElementById("payment-method")?.value === "debt" ? getCartTotal() : 0;
}

function updateDebtCreditMeter() {
    const meter = document.getElementById("debt-credit-meter");
    if (!meter) return;
    const paymentMethod = document.getElementById("payment-method")?.value || "";
    const usesDebt = paymentMethod === "debt" || splitIncludesDebt();
    const departmentMode = getDebtAccountType() === "department";
    const department = getSelectedDepartmentDebtAccount();
    const customer = departmentMode ? department : selectedDebtCustomer;
    meter.classList.toggle("is-hidden", !usesDebt || !customer);
    if (!usesDebt || !customer) return;

    const creditLimit = Math.max(0, Number(departmentMode ? customer.allocation_amount : customer.credit_limit || 0));
    const currentDebt = Math.max(0, Number(departmentMode ? customer.used_amount : customer.current_debt || 0));
    const debtAmount = getCheckoutDebtAmount();
    const availableCredit = Math.max(0, Number(departmentMode ? customer.remaining_allocation : (customer.available_credit ?? (creditLimit - currentDebt))));
    const projectedDebt = currentDebt + debtAmount;
    const projectedAvailable = Math.max(0, creditLimit - projectedDebt);
    const overBy = Math.max(0, debtAmount - availableCredit);
    const usedPercent = creditLimit > 0 ? Math.min(100, (projectedDebt / creditLimit) * 100) : 100;
    const isOver = overBy > 0.004;
    const isMaxed = !isOver && debtAmount > 0 && projectedAvailable < 0.005;
    const isNear = !isOver && !isMaxed && usedPercent >= 80;

    meter.classList.toggle("is-over", isOver);
    meter.classList.toggle("is-maxed", isMaxed);
    meter.classList.toggle("is-near", isNear);
    document.getElementById("debt-credit-status").textContent = isOver ? "Over monthly allocation" : isMaxed ? "Allocation will be fully used" : isNear ? "Near monthly allocation" : (departmentMode ? "Allocation available" : "Credit available");
    document.getElementById("debt-credit-available").textContent = `${formatMoney(projectedAvailable)} available after sale`;
    document.getElementById("debt-credit-current").textContent = `${departmentMode ? "Projected monthly use" : "Projected debt"} ${formatMoney(projectedDebt)}`;
    document.getElementById("debt-credit-limit").textContent = `${departmentMode ? "Allocation" : "Limit"} ${formatMoney(creditLimit)}`;
    document.getElementById("debt-credit-fill").style.width = `${usedPercent}%`;
    const track = meter.querySelector(".debt-credit-track");
    track?.setAttribute("aria-valuenow", String(Math.round(usedPercent)));
    track?.setAttribute("aria-valuetext", `${formatMoney(projectedDebt)} projected ${departmentMode ? "monthly use" : "debt"} of ${formatMoney(creditLimit)} ${departmentMode ? "allocation" : "limit"}`);
    document.getElementById("debt-credit-message").textContent = isOver
        ? (departmentMode
            ? `Debt charge exceeds the available department allocation by ${formatMoney(overBy)}. Reduce the Debt portion or use another payment method.`
            : `Debt allocation exceeds available credit by ${formatMoney(overBy)}. Reduce the Debt portion or use another payment method.`)
        : debtAmount > 0
            ? `${formatMoney(debtAmount)} will be charged to Debt in this transaction.`
            : `Allocate an amount to Debt to preview the ${departmentMode ? "department's remaining monthly allocation" : "employee's remaining credit"}.`;
}

function employeeUsesPurchaseCard() {
    return getDebtAccountType() !== "department" && selectedDebtCustomer?.authorization_mode === "card_unlock";
}

function employeePurchaseCardIsReady() {
    return employeeUsesPurchaseCard() && selectedDebtCustomer?.purchase_card_locked === false;
}

async function refreshSelectedPurchaseCard() {
    if (!selectedDebtCustomerId || !employeeUsesPurchaseCard()) return false;
    const lookup = String(selectedDebtCustomer?.employee_id || selectedDebtCustomerId);
    try {
        const data = await requestJson(`/store/debt-customers?q=${encodeURIComponent(lookup)}`, {}, "Unable to check the employee purchase card.");
        const refreshed = (Array.isArray(data.customers) ? data.customers : []).find((customer) => Number(customer.id) === Number(selectedDebtCustomerId));
        if (refreshed) {
            selectedDebtCustomer = refreshed;
            const cachedIndex = debtCustomers.findIndex((customer) => Number(customer.id) === Number(refreshed.id));
            if (cachedIndex >= 0) debtCustomers[cachedIndex] = refreshed;
        }
        updateDebtPinUi();
        updateCheckoutState();
        setResult(refreshed?.purchase_card_locked === false
            ? `${refreshed.name}'s purchase card is unlocked and ready for one transaction.`
            : `${refreshed?.name || "Employee"}'s purchase card is locked. Ask them to unlock it in their User Portal.`, refreshed?.purchase_card_locked === false ? "ok" : "error");
        return refreshed?.purchase_card_locked === false;
    } catch (error) {
        setResult(error.message || "Unable to check the employee purchase card.", "error");
        return false;
    }
}

function updateDebtPinUi() {
    const wrap = document.getElementById("debt-pin-wrap");
    const help = document.getElementById("debt-pin-help");
    const input = document.getElementById("debt-pin-input");
    const openButton = document.getElementById("open-debt-pin-modal");
    if (!wrap || !help || !input || !openButton) return;

    const paymentMethod = document.getElementById("payment-method")?.value || "";
    const departmentMode = getDebtAccountType() === "department";
    const department = getSelectedDepartmentDebtAccount();
    const approver = getSelectedDepartmentApprover();
    const shouldShow = (paymentMethod === "debt" || splitIncludesDebt()) && (departmentMode ? !!department && !!approver : !!selectedDebtCustomerId);
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

    const label = document.getElementById("debt-pin-label");
    if (!departmentMode && employeeUsesPurchaseCard()) {
        selectedDebtPin = "";
        input.value = "";
        input.disabled = true;
        openButton.disabled = false;
        const ready = employeePurchaseCardIsReady();
        openButton.classList.toggle("is-ready", ready);
        openButton.innerHTML = ready
            ? '<i class="bi bi-check2-circle"></i> Check Again'
            : '<i class="bi bi-arrow-clockwise"></i> Check Unlock Status';
        if (label) label.innerHTML = '<i class="bi bi-phone"></i> Employee Purchase Card';
        help.textContent = ready
            ? "Unlocked on the employee's phone. Ready for one successful debt purchase."
            : "Locked. Ask the employee to unlock their purchase card in the User Portal, then check again.";
        help.classList.toggle("is-ready", ready);
        help.classList.toggle("is-error", !ready);
        return;
    }

    if (label) label.innerHTML = `<i class="bi bi-shield-lock"></i> ${departmentMode ? "Department Approval PIN" : "Debt Authorization PIN"}`;

    if ((!departmentMode && selectedDebtCustomer && selectedDebtCustomer.has_debt_pin === false) || (departmentMode && approver && approver.pin_set === false)) {
        input.disabled = true;
        input.value = "";
        selectedDebtPin = "";
        openButton.disabled = true;
        openButton.classList.remove("is-ready");
        openButton.innerHTML = '<i class="bi bi-shield-exclamation"></i> PIN Not Set';
        help.textContent = departmentMode
            ? "This department head must set a department approval PIN in the User Portal before authorizing a charge."
            : "This customer must set a debt PIN in the User Portal before using debt payment.";
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
    help.textContent = selectedDebtPin
        ? `${departmentMode ? "Department approval" : "Debt"} PIN ready for secure verification.`
        : `Use the ${departmentMode ? "department approval" : "debt"} PIN modal before checkout.`;
    help.classList.toggle("is-ready", !!selectedDebtPin);
    help.classList.remove("is-error");
}

function setDebtPinModalResult(message = "", tone = "") {
    const result = document.getElementById("debt-pin-modal-result");
    const input = document.getElementById("debt-pin-input");
    if (!result) return;
    const error = tone === "error";
    result.className = `result-msg${error ? " error" : ""}`;
    result.setAttribute("role", error ? "alert" : "status");
    result.setAttribute("aria-live", error ? "assertive" : "polite");
    result.setAttribute("aria-atomic", "true");
    if (result.textContent !== message) result.textContent = message;
    if (error && !input?.disabled) input?.setAttribute("aria-invalid", "true");
    else input?.removeAttribute("aria-invalid");
}

function openDebtPinModal(returnFocus = document.activeElement) {
    const modal = document.getElementById("debt-pin-modal");
    const input = document.getElementById("debt-pin-input");
    const summary = document.getElementById("debt-pin-modal-summary");
    if (!modal || !input) return;

    const departmentMode = getDebtAccountType() === "department";
    if (!departmentMode && employeeUsesPurchaseCard()) {
        refreshSelectedPurchaseCard();
        return;
    }
    const department = getSelectedDepartmentDebtAccount();
    const approver = getSelectedDepartmentApprover();
    if (departmentMode ? (!department || !approver) : !selectedDebtCustomerId) {
        setResult(departmentMode ? "Select a department and authorized approver before entering a PIN." : "Select a debt customer before entering a PIN.", "error");
        return;
    }

    if ((!departmentMode && selectedDebtCustomer && selectedDebtCustomer.has_debt_pin === false) || (departmentMode && approver?.pin_set === false)) {
        setResult(departmentMode ? "This approver must set a department approval PIN in the User Portal first." : "This customer must set a debt PIN in the User Portal before using debt payment.", "error");
        return;
    }

    if (summary) {
        summary.textContent = departmentMode
            ? `Ask ${approver?.name || "the department head"} to approve the ${department?.name || "department"} charge using their department PIN.`
            : `Ask ${selectedDebtCustomer?.name || "the debtor"} to enter their debt authorization PIN.`;
    }
    const title = document.getElementById("debt-pin-modal-title");
    if (title) title.innerHTML = `<i class="bi bi-shield-lock" aria-hidden="true"></i> ${departmentMode ? "Department Approval PIN" : "Debt Authorization PIN"}`;
    setDebtPinModalResult();

    input.value = selectedDebtPin;
    renderDebtPinAuthorizationState();
    modal.style.display = "grid";
    focusPosCheckoutDialog(modal, input.disabled ? document.getElementById("debt-pin-close") : input, returnFocus);
}

function clearDebtPinLockoutTimer() {
    if (debtPinLockoutTimer !== null) {
        window.clearInterval(debtPinLockoutTimer);
        debtPinLockoutTimer = null;
    }
}

function renderDebtPinAuthorizationState(message = "") {
    const input = document.getElementById("debt-pin-input");
    const saveButton = document.getElementById("debt-pin-save");
    const result = document.getElementById("debt-pin-modal-result");
    const countdown = document.getElementById("debt-pin-lockout-countdown");
    if (!input || !saveButton || !result) return;

    const lockoutKey = getDebtAccountType() === "department"
        ? `department-${Number(getSelectedDepartmentDebtAccount()?.id || 0)}-${Number(getSelectedDepartmentApprover()?.id || 0)}`
        : `employee-${Number(selectedDebtCustomerId || 0)}`;
    const lockout = debtPinLockouts.get(lockoutKey) || null;
    const lockedUntilMs = Number(lockout?.epoch) > 0
        ? Number(lockout.epoch) * 1000
        : (lockout?.until ? new Date(String(lockout.until).replace(" ", "T")).getTime() : 0);
    const remainingSeconds = lockedUntilMs > 0 ? Math.max(0, Math.ceil((lockedUntilMs - Date.now()) / 1000)) : 0;
    if (remainingSeconds > 0) {
        const minutes = Math.floor(remainingSeconds / 60);
        const seconds = remainingSeconds % 60;
        const employeeName = getDebtAccountType() === "department" ? (getSelectedDepartmentApprover()?.name || "This approver") : (selectedDebtCustomer?.name || "This employee");
        input.disabled = true;
        saveButton.disabled = true;
        saveButton.innerHTML = '<i class="bi bi-lock" aria-hidden="true"></i> PIN Locked';
        const alternatives = getDebtAccountType() === "department"
            ? "Other accounts and payment methods remain available."
            : "Other customers and payment methods remain available.";
        setDebtPinModalResult(`${employeeName}'s ${getDebtAccountType() === "department" ? "department approval" : "debt"} PIN is locked after too many incorrect attempts. Try again after the lockout expires. ${alternatives}`, "error");
        if (countdown) {
            countdown.hidden = false;
            countdown.setAttribute("role", "timer");
            countdown.setAttribute("aria-live", "off");
            countdown.textContent = `Try again in ${minutes}:${String(seconds).padStart(2, "0")}.`;
        }
        return;
    }

    if (lockout) {
        debtPinLockouts.delete(lockoutKey);
        if (debtPinLockouts.size === 0) clearDebtPinLockoutTimer();
    }
    input.disabled = false;
    saveButton.disabled = false;
    saveButton.innerHTML = '<i class="bi bi-check2-circle" aria-hidden="true"></i> Use PIN';
    if (countdown) { countdown.hidden = true; countdown.textContent = ""; }
    if (message) setDebtPinModalResult(message, "error");
    else if (lockout) setDebtPinModalResult("Lockout ended. Enter your PIN to try again.");
}

function showDebtPinAuthorizationFailure(data = {}) {
    selectedDebtPin = "";
    const input = document.getElementById("debt-pin-input");
    if (input) input.value = "";
    updateDebtPinUi();
    const returnFocus = closeConfirmTransactionModal(true);
    openDebtPinModal(returnFocus || document.activeElement);

    if (data.locked_until || Number(data.locked_until_epoch) > 0) {
        const lockoutKey = getDebtAccountType() === "department"
            ? `department-${Number(getSelectedDepartmentDebtAccount()?.id || 0)}-${Number(getSelectedDepartmentApprover()?.id || 0)}`
            : `employee-${Number(selectedDebtCustomerId || 0)}`;
        debtPinLockouts.set(lockoutKey, {
            until: String(data.locked_until || ""),
            epoch: Number(data.locked_until_epoch || 0) || null,
        });
        clearDebtPinLockoutTimer();
        renderDebtPinAuthorizationState();
        debtPinLockoutTimer = window.setInterval(renderDebtPinAuthorizationState, 1000);
        return;
    }

    const remaining = Number(data.attempts_remaining);
    const message = Number.isFinite(remaining)
        ? `Incorrect PIN. ${remaining} attempt${remaining === 1 ? "" : "s"} remaining before a 15-minute lockout.`
        : (data.message || "Invalid debt PIN.");
    renderDebtPinAuthorizationState(message);
}

function closeDebtPinModal(clearDraft = false) {
    const modal = document.getElementById("debt-pin-modal");
    const input = document.getElementById("debt-pin-input");
    if (clearDraft && input) {
        input.value = selectedDebtPin;
    }
    setDebtPinModalResult();
    if (modal) {
        modal.style.display = "none";
        releasePosCheckoutDialogFocus(modal);
    }
}
