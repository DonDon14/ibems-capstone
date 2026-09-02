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
    setResult(`${getDebtAccountType() === "department" ? "Department approval" : "Debt"} PIN captured for secure verification.`, "ok");
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
    updateDebtCreditMeter();

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

    if (splitTenderEnabled) {
        const splitLines = getSplitPaymentLines();
        const allocated = splitLines.reduce((sum, line) => sum + Number(line.amount || 0), 0);
        const total = getCartTotal();
        if (splitLines.length < 2 || splitLines.some((line) => line.amount <= 0) || Math.abs(allocated - total) >= 0.005) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-calculator"></i> Balance Split Payment';
            return;
        }
    }

    const usesDebt = paymentMethod === "debt" || splitIncludesDebt();
    const departmentMode = usesDebt && getDebtAccountType() === "department";
    const department = departmentMode ? getSelectedDepartmentDebtAccount() : null;
    const departmentApprover = departmentMode ? getSelectedDepartmentApprover() : null;
    const departmentRequesterName = String(document.getElementById("department-requester-name")?.value || "").trim();
    if (((!splitTenderEnabled && requiresCheckoutCustomer(paymentMethod)) || splitIncludesDebt()) && !departmentMode && !selectedDebtCustomerId) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = `<i class="bi bi-person-check"></i> Select ${paymentMethod === "debt" ? "Debt" : "Employee"} Customer`;
        return;
    }
    if (departmentMode && (!department || !departmentApprover || !departmentRequesterName)) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-buildings"></i> Complete Department Details';
        return;
    }

    if (usesDebt) {
        const availableCredit = Math.max(0, Number(departmentMode ? department?.remaining_allocation : selectedDebtCustomer?.available_credit || 0));
        if ((departmentMode ? department : selectedDebtCustomer) && getCheckoutDebtAmount() - availableCredit > 0.004) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = departmentMode
                ? '<i class="bi bi-exclamation-triangle"></i> Debt Exceeds Allocation'
                : '<i class="bi bi-exclamation-triangle"></i> Debt Exceeds Credit';
            return;
        }

        if (!departmentMode && employeeUsesPurchaseCard() && !employeePurchaseCardIsReady()) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-lock"></i> Purchase Card Locked';
            return;
        }

        if ((!departmentMode && !employeeUsesPurchaseCard() && selectedDebtCustomer && selectedDebtCustomer.has_debt_pin === false) || (departmentMode && departmentApprover?.pin_set === false)) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = `<i class="bi bi-shield-exclamation"></i> ${departmentMode ? "Approver" : "Customer"} PIN Not Set`;
            return;
        }

        if ((departmentMode || !employeeUsesPurchaseCard()) && !selectedDebtPin) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = `<i class="bi bi-shield-lock"></i> Enter ${departmentMode ? "Approval" : "Debt"} PIN`;
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
                `<button class="category-tab ${category === activeCategory ? "active" : ""}" data-category="${escapeHtml(category)}" type="button" role="tab" aria-selected="${category === activeCategory ? "true" : "false"}" aria-controls="product-grid">${escapeHtml(category)}</button>`
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

    grid.innerHTML = groupCatalogProducts(filteredProducts)
        .map(({key, variants}) => {
            const product = variants[0];
            const stock = Number(product.stock_qty || 0);
            const inCart = getCartQty(product.id);
            const tracked = productTracksStock(product);
            const canAdd = !tracked || inCart < stock;
            const stockState = tracked ? getStockState(stock, inCart, product.low_stock_threshold ?? product.reorder_level ?? 10) : {key:"in",label:"Available",detail:`Sold per ${product.unit_code || "unit"}`};
            const availableVariants = variants.filter((variant) => !productTracksStock(variant) || getCartQty(variant.id) < Number(variant.stock_qty || 0));
            const canOpenOrAdd = variants.length > 1 ? availableVariants.length > 0 : canAdd;
            const familyStockLabel = variants.length > 1 ? `${availableVariants.length}/${variants.length} available` : stockState.label;
            const category = String(product.category || "General");
            const displayName = variants.length > 1 ? String(product.name || "Unnamed product") : getProductDisplayName(product);
            const imageUrl = getCatalogImageUrl(variants);
            const supplier = String(product.supplier || "").trim();
            const locationBin = String(product.location_bin || "").trim();
            const useImage = imageUrl !== "";
            const visualHtml = useImage
                ? `<img class="product-visual" src="${escapeHtml(imageUrl)}" alt="${escapeHtml(displayName)}" data-product-image data-product-initials="${escapeHtml(getInitials(displayName))}">`
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
            const priceValues = variants.map((variant) => Number(variant.price || 0));
            const priceLabel = variants.length > 1 ? `From ${formatMoney(Math.min(...priceValues))}` : formatMoney(product.price);
            const isFamily = variants.length > 1;
            const canInteract = openingBalanceReady && canOpenOrAdd;
            const cardInteraction = `tabindex="0" role="button" aria-label="${escapeHtml(!openingBalanceReady ? "Open store day before adding items" : (isFamily ? `Choose ${product.name} variant` : `Add ${displayName} to order`))}" aria-disabled="${canInteract ? "false" : "true"}"`;
            const actionHtml = isFamily
                ? `<span class="product-card-action"><i class="bi bi-hand-index-thumb"></i>Choose a variant</span>`
                : `<span class="product-card-action"><i class="bi bi-cart-plus"></i>Tap to add</span>`;

            return `
                <article class="product-card product-family-card stock-${stockState.key} ${canOpenOrAdd ? "" : "out-of-stock"} ${openingBalanceReady ? "" : "is-pos-locked"}" data-family-card="${escapeHtml(key)}" data-direct-product="${isFamily ? "" : Number(product.id)}" ${cardInteraction}>
                    <div class="product-card-top">
                        ${visualHtml}
                        <div class="product-card-heading">
                            <h5 class="product-name">${escapeHtml(displayName)}</h5>
                            <p class="product-meta product-card-category">${escapeHtml(category)} · ${variants.length} ${variants.length === 1 ? "variant" : "variants"}</p>
                        </div>
                        <span class="stock-badge stock-${stockState.key}">${escapeHtml(familyStockLabel)}</span>
                    </div>
                    <p class="product-meta product-selected-sku">${variants.length > 1 ? "Tap to view sizes" : `SKU: ${escapeHtml(product.sku)}`}</p>
                    ${operationsMeta}
                    <div class="product-bottom">
                        <div>
                            <div class="product-price">${priceLabel}</div>
                            <div class="product-stock">${variants.length > 1 ? `${variants.length} choices available` : escapeHtml(stockState.detail)}</div>
                        </div>
                        ${inCartHtml}
                    </div>
                    ${openingBalanceReady ? actionHtml : ""}
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
        renderSplitPaymentEditor();
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
    renderSplitPaymentEditor();
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
                ${window.IbemsAvatar.html(customer.name, customer.profile_image_url, "debt-suggestion-avatar")}
                <span class="debt-suggestion-copy"><span class="name">${escapeHtml(customer.name)}</span><span class="meta">${escapeHtml(customer.employee_id || customer.email)} • ${escapeHtml(categoryLabel)} • ${escapeHtml(formatCredit(customer))}</span></span>
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
                ${window.IbemsAvatar.html(customer.name, customer.profile_image_url, "debt-suggestion-avatar")}
                <span class="debt-suggestion-copy"><span class="name">${escapeHtml(customer.name)}</span><span class="meta">${escapeHtml(customer.employee_id || customer.email)} • ${escapeHtml(categoryLabel)} • Debt ${escapeHtml(formatMoney(customer.current_debt || 0))}</span></span>
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
        <div class="debt-payment-person">
            ${window.IbemsAvatar.html(selectedRepaymentCustomer.name, selectedRepaymentCustomer.profile_image_url, "debt-payment-avatar")}
            <div>
            <strong>${escapeHtml(selectedRepaymentCustomer.name || "Debtor")}</strong>
            <small>${escapeHtml(selectedRepaymentCustomer.employee_id || selectedRepaymentCustomer.email || "-")}</small>
            </div>
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

function renderDebtPaymentDestinations() {
    const methodCode = String(document.getElementById("debt-payment-channel")?.value || "cash");
    const method = paymentMethodsCache.find((row) => String(row.code) === methodCode);
    const accounts = Array.isArray(method?.destination_accounts) ? method.destination_accounts : [];
    const wrap = document.getElementById("debt-payment-destination-wrap");
    const select = document.getElementById("debt-payment-destination");
    if (!wrap || !select) return;
    wrap.classList.toggle("is-hidden", methodCode === "cash");
    select.innerHTML = accounts.map((account) => `<option value="${Number(account.id)}">${escapeHtml(account.account_name)} · ${escapeHtml(account.masked_number)}</option>`).join("");
    select.disabled = methodCode === "cash" || accounts.length === 0;
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
    const methodSelect = document.getElementById("debt-payment-channel");
    if (methodSelect) {
        const methods = paymentMethodsCache.filter((method) => String(method.code || "").toLowerCase() !== "debt");
        methodSelect.innerHTML = methods.map((method) => `<option value="${escapeHtml(method.code)}">${escapeHtml(method.label)}</option>`).join("");
        methodSelect.value = methods.some((method) => String(method.code) === "cash") ? "cash" : String(methods[0]?.code || "cash");
        renderDebtPaymentDestinations();
    }
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
        payment_method: document.getElementById("debt-payment-channel")?.value || "cash",
        destination_account_id: Number(document.getElementById("debt-payment-destination")?.value || 0) || null,
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
    const label = document.getElementById("checkout-customer-label");
    const help = document.getElementById("checkout-customer-help");

    const customerRequired = requiresCheckoutCustomer(paymentMethod) || splitIncludesDebt();
    const usesDebt = paymentMethod === "debt" || splitIncludesDebt();
    wrap.style.display = "flex";
    const debtAccountTypeWrap = document.getElementById("debt-account-type-wrap");
    debtAccountTypeWrap?.classList.toggle("is-hidden", !usesDebt);
    if (debtAccountTypeWrap) debtAccountTypeWrap.hidden = !usesDebt;
    if (debtAccountTypeWrap) debtAccountTypeWrap.style.display = usesDebt ? "grid" : "none";
    if (label) {
        label.textContent = customerRequired ? "Employee customer (required)" : "Customer (optional)";
    }
    if (help) {
        help.textContent = customerRequired
            ? (paymentMethod === "debt" || splitIncludesDebt()
                ? "Select the faculty or staff member who is authorizing this debt purchase."
                : "Select the employee associated with this advance payment sale.")
            : "Leave blank for a walk-in sale, or select an employee to record this transaction in their history.";
    }
    if (!usesDebt) {
        const accountType = document.getElementById("debt-account-type");
        if (accountType) accountType.value = "employee";
        selectedDebtPin = "";
        const debtPinInput = document.getElementById("debt-pin-input");
        if (debtPinInput) debtPinInput.value = "";
    }
    updateDepartmentDebtFields();
    updateDebtPinUi();
    if (debtCustomers.length === 0) {
        loadDebtCustomers().catch(() => setResult("Unable to load employee customers.", "error"));
    }
}
