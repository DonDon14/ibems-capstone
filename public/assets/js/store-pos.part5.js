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

    const renderGroup = (title, description, groupMethods) => {
        if (groupMethods.length === 0) return "";
        const options = groupMethods.map((method) => {
            const selectedMethod = document.getElementById("payment-method")?.value || "";
            const active = splitTenderEnabled
                ? splitTenderAmounts.has(String(method.code))
                : (selectedMethod ? selectedMethod === String(method.code) : methods.indexOf(method) === 0);
            const isActive = active ? " is-active" : "";
            const missingAccount = method.requires_destination_account === true && (!Array.isArray(method.destination_accounts) || method.destination_accounts.length === 0);
            const disabled = missingAccount;
            const iconHtml = method.image_url
                ? `<img src="${escapeHtml(method.image_url)}" alt="">`
                : '<i class="bi bi-wallet2"></i>';
            return `<button type="button" class="secondary-btn payment-method-option${isActive}" data-method="${escapeHtml(method.code)}" aria-pressed="${active ? "true" : "false"}" ${disabled ? "disabled" : ""} title="${missingAccount ? "Configure a receiving account in Settings first" : ""}"><span class="payment-method-option-icon">${iconHtml}</span><span>${escapeHtml(getPaymentOptionLabel(method))}${missingAccount ? '<small class="payment-method-needs-account">Setup required</small>' : ""}</span><i class="bi bi-check-circle-fill payment-method-check" aria-hidden="true"></i></button>`;
        }).join("");
        return `<section class="payment-method-group"><div class="payment-method-group-head"><strong>${escapeHtml(title)}</strong><small>${escapeHtml(description)}</small></div><div class="payment-method-grid">${options}</div></section>`;
    };

    const immediateMethods = methods.filter((method) => !isAccountPaymentMethod(method.code));
    const accountMethods = methods.filter((method) => isAccountPaymentMethod(method.code));
    chipsEl.innerHTML = renderGroup("Pay now", "Immediate sale tender", immediateMethods)
        + renderGroup("Account transaction", "Requires an employee or department account", accountMethods);

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
        splitTenderSupported = data.supports_split_payment === true;
        const splitToggle = document.getElementById("split-payment-toggle");
        if (splitToggle) splitToggle.classList.toggle("is-hidden", !splitTenderSupported);
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
    const chips = document.querySelectorAll("#payment-quick .payment-method-option");
    const guidance = document.getElementById("payment-method-help");
    paymentMethodEl.value = method;
    chips.forEach((chip) => {
        const isActive = chip.dataset.method === method;
        chip.classList.toggle("is-active", isActive);
        chip.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
    if (guidance) guidance.textContent = getPaymentMethodGuidance(method);
    ensurePaymentAccountSelection(method);
    renderPaymentAccountPicker();

    updateDebtCustomerVisibility();
    updateCheckoutState();
}

function refreshUi() {
    applyProductFilters();
    renderProducts();
    renderCart();
}

function addToCart(productId, name, price, qtyRequested = 1) {
    if (!openingBalanceReady) {
        setResult("Open today's store day before adding items.", "error");
        return false;
    }

    const product = getProductById(productId);
    if (!product) return;

    const stock = Number(product.stock_qty || 0);
    const inCart = getCartQty(productId);
    const qty = Number.isInteger(Number(qtyRequested)) ? Number(qtyRequested) : 1;
    const safeQty = qty > 0 ? qty : 1;
    const tracked = productTracksStock(product);
    const available = tracked ? stock - inCart : Number.MAX_SAFE_INTEGER;

    if (available <= 0) {
        setResult("Cannot add more. Reached available stock.", "error");
        return false;
    }

    const qtyToAdd = tracked ? Math.min(safeQty, available) : safeQty;

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
    updatePosStoreContext();
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
    const selectedPaymentMethod = document.getElementById("payment-method").value;
    const paymentMethod = splitTenderEnabled ? "split" : selectedPaymentMethod;
    if (!activeStoreId) return null;

    if (cart.length === 0) return null;

    const usesDebt = paymentMethod === "debt" || splitIncludesDebt();
    const departmentMode = usesDebt && getDebtAccountType() === "department";
    const department = departmentMode ? getSelectedDepartmentDebtAccount() : null;
    const departmentApprover = departmentMode ? getSelectedDepartmentApprover() : null;
    const departmentRequesterName = String(document.getElementById("department-requester-name")?.value || "").trim();
    if (((!splitTenderEnabled && requiresCheckoutCustomer(paymentMethod)) || splitIncludesDebt()) && !departmentMode && !selectedDebtCustomerId) return null;
    if (departmentMode && (!department || !departmentApprover || !departmentRequesterName)) return null;
    if ((paymentMethod === "debt" || splitIncludesDebt()) && (departmentMode || !employeeUsesPurchaseCard()) && !selectedDebtPin) return null;

    const payload = {
        customer_type: selectedDebtCustomer?.user_type || "walk_in",
        customer_user_id: selectedDebtCustomerId || null,
        store_id: activeStoreId,
        payment_method: paymentMethod,
        debt_account_type: departmentMode ? "department" : "employee",
        debt_pin: usesDebt && !departmentMode && !employeeUsesPurchaseCard() ? selectedDebtPin : "",
        department_id: departmentMode ? Number(department.id) : null,
        department_requester_name: departmentMode ? departmentRequesterName : "",
        department_approver_user_id: departmentMode ? Number(departmentApprover.id) : null,
        department_pin: departmentMode ? selectedDebtPin : "",
        items: cart.map((item) => ({
            product_id: Number(item.product_id),
            qty: Number(item.qty),
        })),
    };
    if (departmentMode) {
        payload.customer_type = "walk_in";
        payload.customer_user_id = null;
    }

    const cartSnapshot = cart.map((item) => ({
        name: item.name,
        qty: Number(item.qty),
        price: Number(item.price),
    }));
    const totalAmount = cartSnapshot.reduce((sum, item) => sum + item.qty * item.price, 0);
    const payments = splitTenderEnabled
        ? getSplitPaymentLines()
        : [{payment_method: paymentMethod, amount: totalAmount, destination_account_id: selectedPaymentAccounts.get(paymentMethod) || null}];
    payload.payments = payments.map((line) => ({...line}));
    const debtCustomer = departmentMode
        ? {
            name: department.name,
            employee_id: department.code,
            user_type: "department",
            available_credit: department.remaining_allocation,
            current_debt: department.used_amount,
            credit_limit: department.allocation_amount,
        }
        : (usesDebt && selectedDebtCustomerId ? getDebtCustomerById(selectedDebtCustomerId) : null);
    const checkoutCustomer = departmentMode ? debtCustomer : (selectedDebtCustomerId ? getDebtCustomerById(selectedDebtCustomerId) : null);

    return {
        payload,
        paymentMethod,
        cartSnapshot,
        totalAmount,
        payments,
        storeName: getStoreNameById(activeStoreId),
        debtCustomerLabel:
            departmentMode
                ? `${department.name} (Department)`
                : usesDebt && selectedDebtCustomerId
                    ? getDebtCustomerLabelById(selectedDebtCustomerId)
                : "N/A",
        debtCustomer,
        checkoutCustomer,
        customerLabel: departmentMode ? `${department.name} — requested by ${departmentRequesterName}` : (checkoutCustomer?.name || "Walk-in"),
        departmentMode,
        department,
        departmentApprover,
        departmentRequesterName,
    };
}

async function processConfirmedTransaction(dataToProcess) {
    if (isSubmitting || !dataToProcess) return;
    if (dataToProcess.payments?.some((line) => String(line.payment_method) === "cash") && !updateConfirmCashTender()) {
        document.getElementById("confirm-cash-received")?.focus();
        return;
    }
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
                payments: Array.isArray(data.payments) ? data.payments : dataToProcess.payload.payments,
                customerName: dataToProcess.customerLabel || "Walk-in",
                debtCustomerLabel: dataToProcess.debtCustomerLabel,
                totalAmount: Number(data.total_amount ?? dataToProcess.totalAmount),
                cashReceived: data.cash_received !== null && data.cash_received !== undefined ? Number(data.cash_received) : null,
                changeDue: data.change_due !== null && data.change_due !== undefined ? Number(data.change_due) : null,
                items: dataToProcess.cartSnapshot,
                lookupUrl: `${window.location.origin}/store/receipt/${encodeURIComponent(String(data.transaction_id))}`,
            };

            closeConfirmTransactionModal(true);
            cart = [];
            splitTenderEnabled = false;
            splitTenderAmounts.clear();
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
        if (error?.data && (Number.isFinite(Number(error.data.attempts_remaining)) || error.data.locked_until || error.data.locked_until_epoch)) {
            showDebtPinAuthorizationFailure(error.data);
            setResult(error.message || "Debt PIN authorization failed.", "error");
        } else {
            setResult(error.message || "Transaction failed, please try again.", "error");
        }
    } finally {
        isSubmitting = false;
        updateCheckoutState();
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Proceed';
    }
}
