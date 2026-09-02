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

    const checkoutUsesDebt = document.getElementById("payment-method").value === "debt" || splitIncludesDebt();
    const departmentMode = checkoutUsesDebt && getDebtAccountType() === "department";
    const department = departmentMode ? getSelectedDepartmentDebtAccount() : null;
    const departmentApprover = departmentMode ? getSelectedDepartmentApprover() : null;
    const departmentRequesterName = String(document.getElementById("department-requester-name")?.value || "").trim();
    if (checkoutUsesDebt && !departmentMode && !selectedDebtCustomerId) {
        setResult("Select an employee (Faculty/Staff) for employee debt payment.", "error");
        return;
    }
    if (departmentMode && (!department || !departmentApprover || !departmentRequesterName)) {
        setResult("Select a department and authorized approver, then enter the requester name.", "error");
        return;
    }

    if (checkoutUsesDebt) {
        if (!departmentMode && employeeUsesPurchaseCard()) {
            if (!employeePurchaseCardIsReady()) {
                await refreshSelectedPurchaseCard();
            }
            if (!employeePurchaseCardIsReady()) {
                setResult("This employee's purchase card is locked. Ask them to unlock it in their User Portal, then check again.", "error");
                updateCheckoutState();
                return;
            }
        }

        if ((!departmentMode && !employeeUsesPurchaseCard() && selectedDebtCustomer && selectedDebtCustomer.has_debt_pin === false) || (departmentMode && departmentApprover?.pin_set === false)) {
            setResult(departmentMode ? "This approver must set a department approval PIN in the User Portal first." : "This customer must set a debt PIN in the User Portal before using debt payment.", "error");
            updateCheckoutState();
            return;
        }

        if ((departmentMode || !employeeUsesPurchaseCard()) && !selectedDebtPin) {
            openDebtPinModal();
            setResult(departmentMode ? "Enter the department head's approval PIN in the secure PIN modal." : "Enter the customer's debt PIN in the secure PIN modal.", "error");
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
    const card = event.target.closest("[data-family-card]");
    if (!card || card.getAttribute("aria-disabled") === "true") return;

    const now = Date.now();
    if (now - lastCardAddAt < 220) return;
    lastCardAddAt = now;

    const productId = Number(card.getAttribute("data-direct-product") || 0);
    if (productId <= 0) {
        openProductVariantPicker(card.getAttribute("data-family-card"));
        return;
    }
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
    const chip = event.target.closest(".payment-method-option");
    if (!chip) return;
    const method = chip.dataset.method || "cash";
    if (splitTenderEnabled) {
        if (splitTenderAmounts.has(method)) {
            splitTenderAmounts.delete(method);
        } else {
            splitTenderAmounts.set(method, 0);
        }
        renderPaymentMethods();
        renderSplitPaymentEditor();
        updateDebtCustomerVisibility();
        updateCheckoutState();
        return;
    }
    setPaymentMethod(method);
});
document.getElementById("split-payment-toggle").addEventListener("click", () => {
    if (splitTenderSupported) setSplitTenderEnabled(!splitTenderEnabled);
});
document.getElementById("split-payment-editor").addEventListener("input", (event) => {
    const input = event.target.closest("[data-split-amount]");
    if (!input) return;
    splitTenderAmounts.set(input.dataset.splitAmount, Number(input.value || 0));
    refreshSplitPaymentSummary();
    updateCheckoutState();
});
document.getElementById("payment-account-picker")?.addEventListener("change", (event) => {
    const select = event.target.closest("[data-payment-account-method]"); if (!select) return;
    selectedPaymentAccounts.set(String(select.dataset.paymentAccountMethod), Number(select.value)); renderPaymentAccountPicker(); updateCheckoutState();
});
document.getElementById("payment-account-picker")?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-show-payment-qr]");
    if (!button) return;
    openCustomerQrModal(String(button.dataset.paymentMethodCode || ""), Number(button.dataset.showPaymentQr || 0), button);
});
document.getElementById("customer-qr-close")?.addEventListener("click", closeCustomerQrModal);
document.getElementById("customer-qr-done")?.addEventListener("click", closeCustomerQrModal);
document.getElementById("customer-qr-modal")?.addEventListener("click", (event) => {
    if (event.target.id === "customer-qr-modal") closeCustomerQrModal();
});
document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !document.getElementById("customer-qr-modal")?.classList.contains("is-hidden")) {
        closeCustomerQrModal();
    }
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
document.getElementById("product-grid").addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    const card = event.target.closest("[data-family-card]");
    if (!card) return;
    event.preventDefault();
    card.click();
});
document.getElementById("product-variant-options").addEventListener("click", (event) => {
    const option = event.target.closest("[data-pick-variant]");
    if (!option) return;
    if (!openingBalanceReady) {
        setResult("Open today's store day before adding items.", "error");
        return;
    }
    const product = getProductById(Number(option.dataset.pickVariant || 0));
    if (!product) return;
    addToCart(product.id, getProductDisplayName(product), Number(product.price || 0));
    closeProductVariantPicker();
});
document.getElementById("product-variant-close").addEventListener("click", closeProductVariantPicker);
document.getElementById("product-variant-cancel").addEventListener("click", closeProductVariantPicker);
document.getElementById("product-variant-modal").addEventListener("click", (event) => {
    if (event.target.id === "product-variant-modal") closeProductVariantPicker();
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
    const selectionKind = document.getElementById("payment-method")?.value === "debt" ? "Debt customer" : "Employee customer";
    setResult(`${selectionKind} selected: ${picked.name}`, "ok");
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
document.getElementById("debt-payment-channel").addEventListener("change", renderDebtPaymentDestinations);

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

document.getElementById("open-debt-pin-modal").addEventListener("click", () => {
    if (employeeUsesPurchaseCard()) refreshSelectedPurchaseCard(); else openDebtPinModal();
});
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
document.addEventListener("click", (event) => {
    const toggle = event.target.closest("[data-denomination-target]");
    if (toggle) {
        toggleDenominationCounter(toggle);
        return;
    }
    const apply = event.target.closest("[data-denomination-apply]");
    if (!apply) return;
    const target = String(apply.dataset.denominationApply || "");
    const total = updateDenominationCounter(target);
    const input = document.getElementById(target === "opening" ? "opening-balance-input" : "closing-cash-input");
    if (input) {
        input.value = total.toFixed(2);
        input.dispatchEvent(new Event("input", {bubbles: true}));
        input.focus();
    }
}, true);
document.addEventListener("input", (event) => {
    const input = event.target.closest("[data-denomination-scope]");
    if (input) updateDenominationCounter(String(input.dataset.denominationScope || ""));
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
document.getElementById("store-day-account-counts").addEventListener("input", (event) => {
    if (event.target.matches("[data-closing-account-id]")) updateStoreDayCloseVariance();
});
document.getElementById("store-day-unassigned-counts").addEventListener("input", (event) => {
    if (event.target.matches("[data-closing-unassigned-key]")) updateStoreDayCloseVariance();
});
document.getElementById("debt-account-type")?.addEventListener("change", () => {
    selectedDebtPin = "";
    const input = document.getElementById("debt-pin-input");
    if (input) input.value = "";
    updateDepartmentDebtFields();
    updateCheckoutState();
});
document.getElementById("department-debt-account")?.addEventListener("change", () => {
    selectedDebtPin = "";
    const input = document.getElementById("debt-pin-input");
    if (input) input.value = "";
    renderDepartmentApprovers();
    updateDebtCreditMeter();
    updateDebtPinUi();
    updateCheckoutState();
});
document.getElementById("department-approver")?.addEventListener("change", () => {
    selectedDebtPin = "";
    const input = document.getElementById("debt-pin-input");
    if (input) input.value = "";
    updateDebtPinUi();
    updateCheckoutState();
});
document.getElementById("department-requester-name")?.addEventListener("input", updateCheckoutState);

window.IbemsPortalNavigation?.onCleanup(async () => {
    if (debtPinLockoutTimer) {
        clearInterval(debtPinLockoutTimer);
        debtPinLockoutTimer = null;
    }
    await stopScanner();
});

(async () => {
    try {
        renderCategoryTabs();
        setScannerUiState(false);
        setPosTransactionEnabled(false);
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
