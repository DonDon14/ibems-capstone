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
let departmentDebtAccounts = [];
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
const selectedCatalogVariants = new Map();
let activeVariantFamilyKey = null;
let splitTenderEnabled = false;
let splitTenderSupported = false;
const splitTenderAmounts = new Map();
const selectedPaymentAccounts = new Map();
let debtPinLockoutTimer = null;
const debtPinLockouts = new Map();

function getDebtAccountType() {
    return document.getElementById("debt-account-type")?.value === "department" ? "department" : "employee";
}

function getSelectedDepartmentDebtAccount() {
    const id = Number(document.getElementById("department-debt-account")?.value || 0);
    return departmentDebtAccounts.find((department) => Number(department.id) === id) || null;
}

function getSelectedDepartmentApprover() {
    const department = getSelectedDepartmentDebtAccount();
    const id = Number(document.getElementById("department-approver")?.value || 0);
    return department?.approvers?.find((approver) => Number(approver.id) === id) || null;
}

async function loadDepartmentDebtAccounts() {
    const data = await requestJson("/store/department-debt-accounts", {}, "Unable to load department debt accounts.");
    departmentDebtAccounts = Array.isArray(data.departments) ? data.departments : [];
    const select = document.getElementById("department-debt-account");
    if (!select) return;
    const selected = Number(select.value || 0);
    select.innerHTML = '<option value="">Select a department</option>' + departmentDebtAccounts.map((department) =>
        `<option value="${Number(department.id)}">${escapeHtml(department.code)} · ${escapeHtml(department.name)} — ${escapeHtml(formatMoney(department.remaining_allocation))} remaining</option>`
    ).join("");
    if (departmentDebtAccounts.some((department) => Number(department.id) === selected)) select.value = String(selected);
    renderDepartmentApprovers();
}

function renderDepartmentApprovers() {
    const select = document.getElementById("department-approver");
    const help = document.getElementById("department-approver-help");
    const department = getSelectedDepartmentDebtAccount();
    if (!select) return;
    const approvers = Array.isArray(department?.approvers) ? department.approvers : [];
    select.innerHTML = '<option value="">Select an approver</option>' + approvers.map((approver) =>
        `<option value="${Number(approver.id)}" ${approver.pin_set ? "" : "disabled"}>${escapeHtml(approver.name)}${approver.employee_id ? ` · ${escapeHtml(approver.employee_id)}` : ""}${approver.pin_set ? "" : " · PIN not set"}</option>`
    ).join("");
    if (help) {
        help.textContent = !department
            ? "Select a department first."
            : approvers.some((approver) => approver.pin_set)
                ? "The department head must enter their separate department approval PIN."
                : "The active department head has not set a department approval PIN yet.";
        help.classList.toggle("is-error", !!department && !approvers.some((approver) => approver.pin_set));
    }
}

function updateDepartmentDebtFields() {
    const isDepartment = getDebtAccountType() === "department";
    const employeeFields = document.getElementById("employee-debt-customer-fields");
    const departmentFields = document.getElementById("department-debt-fields");
    employeeFields?.classList.toggle("is-hidden", isDepartment);
    departmentFields?.classList.toggle("is-hidden", !isDepartment);
    if (employeeFields) employeeFields.hidden = isDepartment;
    if (departmentFields) departmentFields.hidden = !isDepartment;
    if (employeeFields) employeeFields.style.display = isDepartment ? "none" : "block";
    if (departmentFields) departmentFields.style.display = isDepartment ? "grid" : "none";
    document.getElementById("open-debt-scanner-btn")?.classList.toggle("is-hidden", isDepartment);
    const pinLabel = document.getElementById("debt-pin-label");
    if (pinLabel) pinLabel.innerHTML = `<i class="bi bi-shield-lock"></i> ${isDepartment ? "Department Approval PIN" : "Debt Authorization PIN"}`;
    if (isDepartment && departmentDebtAccounts.length === 0) {
        loadDepartmentDebtAccounts().catch((error) => setResult(error.message || "Unable to load department debt accounts.", "error"));
    }
    updateDebtCreditMeter();
    updateDebtPinUi();
}

function getProductFamilyKey(product) {
    const familyId = Number(product?.family_id || 0);
    if (familyId > 0) return `family-${familyId}`;
    const name = String(product?.name || "").trim().toLowerCase();
    const category = String(product?.category || "General").trim().toLowerCase();
    return name ? `legacy-${name}-${category}` : `product-${Number(product?.id || 0)}`;
}

function groupCatalogProducts(products) {
    const groups = new Map();
    products.forEach((product) => {
        const key = getProductFamilyKey(product);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(product);
    });
    return Array.from(groups, ([key, variants]) => ({key, variants}));
}

function getCatalogImageUrl(variants) {
    return String((variants || []).find((variant) => String(variant?.image_url || "").trim() !== "")?.image_url || "").trim();
}

function replaceBrokenCatalogImage(image) {
    if (!(image instanceof HTMLImageElement) || !image.matches("[data-product-image]")) return;

    const placeholder = document.createElement(image.classList.contains("product-variant-option-image") ? "span" : "div");
    placeholder.className = `${image.className} placeholder`;
    placeholder.textContent = String(image.dataset.productInitials || "PR").trim() || "PR";
    placeholder.setAttribute("aria-hidden", "true");
    image.replaceWith(placeholder);
}

document.addEventListener("error", (event) => replaceBrokenCatalogImage(event.target), true);

function closeProductVariantPicker() {
    activeVariantFamilyKey = null;
    document.getElementById("product-variant-modal").style.display = "none";
}

function openProductVariantPicker(familyKey) {
    if (!openingBalanceReady) {
        setResult("Open today's store day before adding items.", "error");
        return;
    }

    const group = groupCatalogProducts(productsCache).find((item) => item.key === familyKey);
    if (!group || group.variants.length < 2) return;
    activeVariantFamilyKey = familyKey;
    const productName = String(group.variants[0]?.name || "Product");
    const familyImage = getCatalogImageUrl(group.variants);
    document.getElementById("product-variant-title").textContent = productName;
    document.getElementById("product-variant-guidance").textContent = `Choose one of ${group.variants.length} available variants. Selection adds it directly to the order.`;
    document.getElementById("product-variant-options").innerHTML = group.variants.map((variant) => {
        const stock = Number(variant.stock_qty || 0);
        const inCart = getCartQty(variant.id);
        const tracked = productTracksStock(variant);
        const canAdd = !tracked || inCart < stock;
        const stockState = tracked ? getStockState(stock, inCart, variant.low_stock_threshold ?? variant.reorder_level ?? 10) : {key:"in",label:"Available",detail:`Sold per ${variant.unit_code || "unit"}`};
        const variantImage = String(variant.image_url || familyImage).trim();
        const variantLabel = String(variant.variant_label || "Default");
        const imageHtml = variantImage
            ? `<img class="product-variant-option-image" src="${escapeHtml(variantImage)}" alt="${escapeHtml(`${productName} ${variantLabel}`)}" data-product-image data-product-initials="${escapeHtml(getInitials(variantLabel))}">`
            : `<span class="product-variant-option-image placeholder" aria-hidden="true">${escapeHtml(getInitials(variantLabel))}</span>`;
        return `<button class="product-variant-option stock-${stockState.key}" type="button" data-pick-variant="${Number(variant.id)}" ${canAdd ? "" : "disabled"}>
            ${imageHtml}
            <span class="product-variant-option-main"><strong>${escapeHtml(variantLabel)}</strong><small>SKU: ${escapeHtml(variant.sku || "-")}</small></span>
            <span class="product-variant-option-side"><strong>${formatMoney(variant.price)}</strong><small>${tracked ? `${stock} available` : `Per ${escapeHtml(variant.unit_code || "unit")}`}</small></span>
        </button>`;
    }).join("");
    document.getElementById("product-variant-modal").style.display = "grid";
    document.querySelector("[data-pick-variant]:not(:disabled)")?.focus();
}

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
        const requestError = new Error(data?.message || fallbackMessage);
        requestError.data = data || {};
        requestError.status = response.status;
        throw requestError;
    }

    return data || {};
}

function browserBusinessDate() {
    const parts = new Intl.DateTimeFormat("en-US", {
        timeZone: "Asia/Manila",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).formatToParts(new Date());
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    return `${values.year}-${values.month}-${values.day}`;
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
        dateEl.textContent = `Business date: ${businessDate || browserBusinessDate()}`;
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
    const accountCount = Array.isArray(opening.payment_account_balances) ? opening.payment_account_balances.length : 0;
    displayEl.textContent = `Cash ${formatMoney(cash)} | ${accountCount} receiving account${accountCount === 1 ? "" : "s"} ${formatMoney(ecash)}`;
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
function productTracksStock(product) {
    return String(product?.stock_policy || "tracked") === "tracked";
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

    const shell = document.querySelector(".pos-shell");
    const note = document.getElementById("pos-transaction-note");
    const noteText = note?.querySelector("span");
    const guardedControls = [
        "scan-code-input",
        "scan-qty-input",
        "scan-add-btn",
        "open-scanner-btn",
        "open-debt-payment-modal",
    ];

    shell?.classList.toggle("is-pos-locked", !openingBalanceReady);
    guardedControls.forEach((id) => {
        const control = document.getElementById(id);
        if (control) control.disabled = !openingBalanceReady;
    });

    if (note) note.classList.toggle("is-locked", !openingBalanceReady);
    if (noteText) {
        noteText.textContent = openingBalanceReady
            ? "Tap a product card or scan a code to add items to the order."
            : (document.getElementById("opening-balance-guidance")?.textContent || "Open today's store day before adding items.");
    }

    if (productsLoadCompleted) renderProducts();
    updateCheckoutState();
}

function openOpeningBalanceModal(prefill = null) {
    const modal = document.getElementById("opening-balance-modal");
    const titleEl = document.getElementById("opening-balance-title");
    const descEl = document.getElementById("opening-balance-description");
    const labelEl = document.getElementById("opening-balance-label");
    const saveBtn = document.getElementById("opening-balance-save");
    const previewEl = document.getElementById("store-day-reopen-preview");
    const noteLabel = document.getElementById("opening-balance-note-label");
    if (!modal) return;
    const accountWrap = document.getElementById("opening-payment-account-balances");
    const accounts = paymentMethodsCache.flatMap((method) => (method.destination_accounts || []).map((account) => ({...account, payment_method_label: method.label})));
    if (accountWrap) accountWrap.innerHTML = accounts.length ? `<div class="opening-account-head"><strong>Electronic receiving accounts</strong><small>Enter the verified starting balance of each account.</small></div>${accounts.map((account) => `<label class="store-day-account-count"><span><strong>${escapeHtml(account.payment_method_label)} · ${escapeHtml(account.account_name)}</strong><small>${escapeHtml(account.masked_number)}</small></span><input type="number" min="0" step="0.01" value="0.00" data-opening-account-id="${Number(account.id)}"></label>`).join("")}` : "";
    if (prefill && typeof prefill.opening_cash !== "undefined") {
        document.getElementById("opening-balance-input").value = Number(prefill.opening_cash || 0).toFixed(2);
        document.getElementById("opening-balance-note").value = String(prefill.opening_note || "");
        document.querySelectorAll("[data-opening-account-id]").forEach((input) => {
            const prior = Array.isArray(prefill.payment_account_balances) ? prefill.payment_account_balances.find((row) => Number(row.id) === Number(input.dataset.openingAccountId)) : null;
            input.value = Number(prior?.opening_balance || 0).toFixed(2);
        });
    }
    if (openingBalanceMode === "reopen") {
        if (titleEl) titleEl.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Admin Reopen Store Day';
        if (descEl) descEl.textContent = "Review the previous close and explain why more transactions must be added. Original opening balances will not change.";
        if (labelEl) labelEl.textContent = "Original Opening Cash (preserved)";
        document.getElementById("opening-balance-input").disabled = true;
        document.querySelectorAll("[data-opening-account-id]").forEach((input) => { input.disabled = true; });
        if (noteLabel) noteLabel.textContent = "Reopen Reason (required)";
        document.getElementById("opening-balance-note").value = "";
        document.getElementById("opening-balance-note").required = true;
        document.getElementById("opening-balance-note").placeholder = "Explain why this closed day must be reopened";
        if (previewEl) {
            previewEl.classList.remove("is-hidden");
            previewEl.innerHTML = `<strong>Previous close</strong><div><span>Closed at</span><b>${escapeHtml(prefill?.closed_at ? formatDateTime(prefill.closed_at) : "-")}</b></div><div><span>Expected total</span><b>${escapeHtml(formatMoney(Number(prefill?.expected_cash || 0) + Number(prefill?.expected_ecash || 0)))}</b></div><div><span>Counted total</span><b>${escapeHtml(formatMoney(Number(prefill?.counted_cash || 0) + Number(prefill?.counted_ecash || 0)))}</b></div><div><span>Variance</span><b>${escapeHtml(formatMoney(Number(prefill?.variance_cash || 0) + Number(prefill?.variance_ecash || 0)))}</b></div>${prefill?.closing_note ? `<small>Closing note: ${escapeHtml(prefill.closing_note)}</small>` : ""}`;
        }
        if (saveBtn) saveBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Reopen Store Day';
    } else {
        document.getElementById("opening-balance-input").disabled = false;
        document.querySelectorAll("[data-opening-account-id]").forEach((input) => { input.disabled = false; });
        if (previewEl) { previewEl.classList.add("is-hidden"); previewEl.innerHTML = ""; }
        if (noteLabel) noteLabel.textContent = "Note (optional)";
        document.getElementById("opening-balance-note").required = false;
        document.getElementById("opening-balance-note").placeholder = "e.g. Start of day float";
        if (titleEl) titleEl.innerHTML = '<i class="bi bi-safe2"></i> Open Store Day';
        if (descEl) descEl.textContent = "Count today's starting cash and every electronic receiving account.";
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
