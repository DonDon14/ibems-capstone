let settingsStoreId = null;
let settingsCategories = [];
let settingsPaymentMethods = [];
let settingsPaymentAccounts = [];
let editingPaymentMethodId = null;
let editingPaymentAccountId = null;
let editingPaymentMethodImageUrl = "";
let paymentMethodSavePending = false;
let categoryMutationPending = false;
let categoryQuery = "";
let categorySort = "name-asc";
let categoryPage = 1;
const categoryPageSize = 10;
let categoryPendingButton = null;
let categoryPendingButtonHtml = "";
let editingCategoryId = null;
let categoryEditTrigger = null;
let settingsCapabilities = [];

function settingsEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function settingsDataState(type, message, colspan) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    return `<tr class="data-state-row"><td colspan="${Number(colspan)}"><div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${settingsEscape(message)}</strong></div></div></td></tr>`;
}

function settingsSetResult(message, isError = false) {
    const el = document.getElementById("settings-result");
    el.textContent = message || "";
    el.classList.toggle("is-error", Boolean(message) && isError);
}

function settingsSetMethodResult(message, isError = false) {
    const el = document.getElementById("settings-method-result");
    el.textContent = message || "";
    el.classList.toggle("is-error", Boolean(message) && isError);
}

function setCategoryEditResult(message, isError = false) {
    const result = document.getElementById("category-edit-result");
    if (!result) return;
    result.textContent = message || "";
    result.classList.toggle("is-error", Boolean(message) && isError);
}

function syncCategoryEditSaveState() {
    const current = settingsCategories.find((row) => Number(row.id) === Number(editingCategoryId));
    const input = document.getElementById("category-edit-name");
    const save = document.getElementById("category-edit-save");
    if (!input || !save) return;
    const nextName = String(input.value || "").trim();
    const unchanged = nextName === String(current?.name || "").trim();
    save.disabled = categoryMutationPending || nextName === "" || unchanged;
}

function openCategoryCreateModal(trigger = null) {
    const modal = document.getElementById("category-edit-modal");
    const input = document.getElementById("category-edit-name");
    if (!modal || !input) return;

    editingCategoryId = null;
    categoryEditTrigger = trigger;
    document.getElementById("category-edit-title").textContent = "Add category";
    document.getElementById("category-edit-subtitle").textContent = "Create a category for products shown in Inventory and POS.";
    document.getElementById("category-edit-current").textContent = "New category";
    document.getElementById("category-edit-usage").textContent = "Products can be assigned after the category is created.";
    document.getElementById("category-edit-guidance-text").textContent = "Use a short, specific name that Store Cashiers can recognize quickly.";
    document.getElementById("category-edit-save").innerHTML = '<i class="bi bi-plus-circle" aria-hidden="true"></i> Create category';
    input.value = "";
    setCategoryEditResult("");
    modal.classList.remove("is-hidden");
    document.body.classList.add("settings-modal-open");
    syncCategoryEditSaveState();
    window.requestAnimationFrame(() => input.focus());
}

function openCategoryEditModal(categoryId, trigger = null) {
    const current = settingsCategories.find((row) => Number(row.id) === Number(categoryId));
    const modal = document.getElementById("category-edit-modal");
    const input = document.getElementById("category-edit-name");
    if (!current || !modal || !input) return;

    editingCategoryId = Number(current.id);
    categoryEditTrigger = trigger;
    document.getElementById("category-edit-title").textContent = "Edit category";
    document.getElementById("category-edit-subtitle").textContent = "Rename this category across Inventory and POS.";
    document.getElementById("category-edit-guidance-text").textContent = "Products are not deleted. Their category label updates automatically.";
    document.getElementById("category-edit-save").innerHTML = '<i class="bi bi-check2-circle" aria-hidden="true"></i> Save changes';
    input.value = String(current.name || "");
    document.getElementById("category-edit-current").textContent = String(current.name || "Selected category");
    const productCount = Number(current.product_count || 0);
    document.getElementById("category-edit-usage").textContent = productCount === 0
        ? "No products currently use this category."
        : `${productCount} ${productCount === 1 ? "product uses" : "products use"} this category.`;
    setCategoryEditResult("");
    modal.classList.remove("is-hidden");
    document.body.classList.add("settings-modal-open");
    syncCategoryEditSaveState();
    window.requestAnimationFrame(() => {
        input.focus();
        input.select();
    });
}

function closeCategoryEditModal(restoreFocus = true) {
    if (categoryMutationPending) return;
    const modal = document.getElementById("category-edit-modal");
    modal?.classList.add("is-hidden");
    editingCategoryId = null;
    setCategoryEditResult("");
    if (!document.querySelector(".settings-modal:not(.is-hidden)")) document.body.classList.remove("settings-modal-open");
    if (restoreFocus && categoryEditTrigger?.isConnected) categoryEditTrigger.focus();
    categoryEditTrigger = null;
}

function renderCategories() {
    const body = document.getElementById("category-body");
    const count = document.getElementById("category-count");
    const tools = document.getElementById("category-tools");
    const pagination = document.getElementById("category-pagination");

    if (!Array.isArray(settingsCategories) || settingsCategories.length === 0) {
        body.innerHTML = settingsDataState("empty", "No categories found.", 3);
        if (count) count.textContent = "0 categories";
        tools?.classList.add("is-hidden");
        pagination?.classList.add("is-hidden");
        return;
    }

    const normalizedQuery = categoryQuery.trim().toLowerCase();
    const filtered = settingsCategories.filter((category) => !normalizedQuery || String(category.name || "").toLowerCase().includes(normalizedQuery));
    filtered.sort((left, right) => {
        if (categorySort === "name-desc") return String(right.name || "").localeCompare(String(left.name || ""));
        if (categorySort === "products-desc") return Number(right.product_count || 0) - Number(left.product_count || 0) || String(left.name || "").localeCompare(String(right.name || ""));
        return String(left.name || "").localeCompare(String(right.name || ""));
    });

    const pageCount = Math.max(1, Math.ceil(filtered.length / categoryPageSize));
    categoryPage = Math.min(categoryPage, pageCount);
    const start = (categoryPage - 1) * categoryPageSize;
    const visible = filtered.slice(start, start + categoryPageSize);
    const hasLargeList = settingsCategories.length > categoryPageSize;
    tools?.classList.toggle("is-hidden", !hasLargeList);
    pagination?.classList.toggle("is-hidden", !hasLargeList || filtered.length <= categoryPageSize);
    if (count) count.textContent = `${filtered.length}${normalizedQuery ? ` of ${settingsCategories.length}` : ""} ${filtered.length === 1 ? "category" : "categories"}`;

    if (!visible.length) {
        body.innerHTML = settingsDataState("empty", "No categories match your search.", 3);
    } else {
        body.innerHTML = visible.map((category) => {
        const isGeneral = String(category.name || "").toLowerCase() === "general";
        const productCount = Number(category.product_count || 0);
        return `
            <tr>
                <td><div class="category-name-cell"><strong>${settingsEscape(category.name)}</strong>${isGeneral ? '<span class="category-default-badge"><i class="bi bi-lock-fill" aria-hidden="true"></i> Default</span>' : ""}</div></td>
                <td><span class="category-product-count" aria-label="${productCount} ${productCount === 1 ? "product" : "products"}">${productCount}</span></td>
                <td>
                    <div class="category-action-cell">
                        <button type="button" class="secondary-btn btn-sm" data-action="edit" data-id="${category.id}" aria-label="Edit ${settingsEscape(category.name)}"><i class="bi bi-pencil-square" aria-hidden="true"></i> Edit</button>
                        ${isGeneral ? "" : `<button type="button" class="category-delete-btn btn-sm" data-action="delete" data-id="${category.id}" aria-label="Delete ${settingsEscape(category.name)}"><i class="bi bi-trash3" aria-hidden="true"></i> Delete</button>`}
                    </div>
                </td>
            </tr>
        `;
        }).join("");
    }

    const summary = document.getElementById("category-page-summary");
    if (summary) summary.textContent = `Page ${categoryPage} of ${pageCount}`;
    const previous = document.getElementById("category-prev");
    const next = document.getElementById("category-next");
    if (previous) previous.disabled = categoryPage <= 1;
    if (next) next.disabled = categoryPage >= pageCount;
}

function setCategoryMutationPending(isPending, action = "create", activeButton = null) {
    categoryMutationPending = Boolean(isPending);
    const card = document.getElementById("category-settings-card");
    const editModal = document.getElementById("category-edit-modal");
    const addButton = document.getElementById("add-category-btn");
    card?.setAttribute("aria-busy", categoryMutationPending ? "true" : "false");
    editModal?.querySelector(".category-edit-card")?.setAttribute("aria-busy", categoryMutationPending ? "true" : "false");
    document.querySelectorAll("#category-settings-card button, #category-settings-card select, #category-settings-card input, #category-edit-modal button, #category-edit-modal input").forEach((control) => {
        control.disabled = categoryMutationPending;
    });

    if (categoryMutationPending) {
        categoryPendingButton = activeButton || addButton;
        categoryPendingButtonHtml = categoryPendingButton?.innerHTML || "";
        const labels = {create: "Creating category...", update: "Saving...", delete: "Deleting..."};
        if (categoryPendingButton) categoryPendingButton.innerHTML = `<i class="bi bi-arrow-repeat settings-submit-spinner" aria-hidden="true"></i> ${labels[action] || "Working..."}`;
        return;
    }

    if (categoryPendingButton?.isConnected && categoryPendingButtonHtml) categoryPendingButton.innerHTML = categoryPendingButtonHtml;
    if (addButton) addButton.innerHTML = '<i class="bi bi-plus-circle" aria-hidden="true"></i> Add category';
    categoryPendingButton = null;
    categoryPendingButtonHtml = "";
    renderCategories();
    syncCategoryEditSaveState();
}

async function runCategoryMutation(action, activeButton, task) {
    if (categoryMutationPending) return;
    settingsSetResult("");
    setCategoryMutationPending(true, action, activeButton);
    try {
        await task();
    } catch (error) {
        settingsSetResult(error.message || "Category action failed.", true);
    } finally {
        setCategoryMutationPending(false);
    }
}

function renderPaymentMethods() {
    const body = document.getElementById("payment-method-list");

    if (!Array.isArray(settingsPaymentMethods) || settingsPaymentMethods.length === 0) {
        body.innerHTML = '<div class="payment-method-empty"><i class="bi bi-credit-card"></i><strong>No payment methods available.</strong></div>';
        return;
    }

    body.innerHTML = settingsPaymentMethods.filter((method) => String(method.code) !== "debt").map((method) => {
        const icon = String(method.icon_class || "bi bi-wallet2").trim();
        const accounts = settingsPaymentAccounts.filter((account) => Number(account.payment_method_id) === Number(method.id));
        const requiresAccount = method.requires_destination_account === true;
        const accountSummary = requiresAccount
            ? (accounts.length ? `${accounts.length} receiving account${accounts.length === 1 ? "" : "s"}` : "Setup required")
            : (String(method.code) === "cash" ? "Cash drawer" : String(method.code) === "debt" ? "Employee account" : "No receiving account required");
        const isBuiltIn = String(method.code) === "cash";
        const needsSetup = requiresAccount && !accounts.length;
        const statusLabel = isBuiltIn ? "Built in" : needsSetup ? "Setup required" : "Ready";
        const statusClass = isBuiltIn ? "is-built-in" : needsSetup ? "is-setup" : "is-ready";
        const primaryAccount = accounts[0] || null;
        const destination = primaryAccount
            ? `<small class="payment-method-destination"><i class="bi bi-send" aria-hidden="true"></i> ${settingsEscape(primaryAccount.account_name)}${primaryAccount.masked_number ? ` · ${settingsEscape(primaryAccount.masked_number)}` : ""}${accounts.length > 1 ? ` · +${accounts.length - 1} more` : ""}</small>`
            : "";
        const action = isBuiltIn ? "" : `<button type="button" class="secondary-btn btn-sm" data-method-edit="${Number(method.id)}"><i class="bi bi-pencil-square"></i> Edit</button>`;
        const visual = method.image_url ? `<img src="${settingsEscape(method.image_url)}" alt="${settingsEscape(method.label)}">` : `<i class="${settingsEscape(icon)}"></i>`;
        return `<article class="payment-method-card${needsSetup ? " needs-setup" : ""}"><span class="payment-method-icon">${visual}</span><div class="payment-method-copy"><div class="payment-method-title-row"><strong>${settingsEscape(method.label)}</strong><span class="payment-status-badge ${statusClass}">${statusLabel}</span></div><span class="payment-method-summary">${settingsEscape(accountSummary)}</span>${destination}<code>${settingsEscape(method.code)}</code></div>${action}</article>`;
    }).join("");
}

async function loadStoreContext() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();
    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }
    settingsStoreId = Number(data.default_store_id || data.stores[0].id);
}
function setCapabilityResult(message, isError = false) {
    const el = document.getElementById("capability-result");
    if (!el) return;
    el.textContent = message || "";
    el.classList.toggle("is-error", Boolean(message) && isError);
}

async function loadCapabilities() {
    const response = await fetch(`/store/capabilities?store_id=${settingsStoreId}`);
    const data = await response.json();
    if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Unable to load store capabilities.");
    settingsCapabilities = Array.isArray(data.capabilities) ? data.capabilities : ["retail"];
    document.querySelectorAll('#store-capability-grid input[type="checkbox"]').forEach((input) => {
        input.checked = settingsCapabilities.includes(input.value);
    });
}

async function saveCapabilities() {
    const button = document.getElementById("save-capabilities-btn");
    const capabilities = Array.from(document.querySelectorAll('#store-capability-grid input[type="checkbox"]:checked')).map((input) => input.value);
    button.disabled = true;
    setCapabilityResult("");
    try {
        const response = await fetch("/store/capabilities/update", {method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({store_id:settingsStoreId,capabilities})});
        const data = await response.json();
        if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Unable to save store capabilities.");
        settingsCapabilities = data.capabilities;
        setCapabilityResult("Business capabilities saved. Product and POS behavior remain unified for this store.");
    } catch (error) {
        setCapabilityResult(error.message || "Unable to save store capabilities.", true);
    } finally {
        button.disabled = false;
    }
}

async function loadCategories() {
    const response = await fetch(`/store/categories?store_id=${settingsStoreId}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        const message = data?.message || "Unable to load categories.";
        document.getElementById("category-body").innerHTML = settingsDataState("error", message, 3);
        document.getElementById("category-count").textContent = "Unavailable";
        throw new Error(message);
    }
    settingsCategories = Array.isArray(data.categories) ? data.categories : [];
    renderCategories();
}

async function loadPaymentMethods() {
    const response = await fetch(`/store/payment-methods?store_id=${settingsStoreId}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        const message = data?.message || "Unable to load payment methods.";
        document.getElementById("payment-method-list").innerHTML = `<div class="payment-method-empty is-error"><i class="bi bi-exclamation-circle"></i><strong>${settingsEscape(message)}</strong></div>`;
        throw new Error(message);
    }
    settingsPaymentMethods = Array.isArray(data.methods) ? data.methods : [];
    renderPaymentMethods();
}

function setAccountResult(message, isError = false) {
    const el = document.getElementById("settings-account-result");
    if (!el) return;
    el.textContent = message || "";
    el.classList.toggle("is-error", Boolean(message) && isError);
}

function renderPaymentAccounts() {
    const list = document.getElementById("payment-account-list");
    const accounts = settingsPaymentAccounts.filter((account) => Number(account.payment_method_id) === Number(editingPaymentMethodId));
    if (!accounts.length) {
        list.innerHTML = '<div class="payment-account-empty"><i class="bi bi-wallet2"></i><strong>No receiving accounts configured.</strong><span>Add one for each GCash, bank, card terminal, or online destination.</span></div>';
        return;
    }
    list.innerHTML = accounts.map((account) => {
        const visual = account.image_url
            ? `<img src="${settingsEscape(account.image_url)}" alt="QR for ${settingsEscape(account.account_name)}">`
            : `<span class="payment-account-fallback"><i class="${settingsEscape(account.icon_class || "bi bi-wallet2")}"></i></span>`;
        return `<article class="payment-account-card">${visual}<div><span class="payment-account-method">${settingsEscape(account.payment_method_label)}</span><strong>${settingsEscape(account.account_name)}</strong><small>${settingsEscape(account.masked_number)}</small></div><div class="payment-account-actions"><button class="secondary-btn btn-sm" type="button" data-account-edit="${Number(account.id)}"><i class="bi bi-pencil-square"></i> Edit</button><button class="danger-btn btn-sm" type="button" data-account-remove="${Number(account.id)}">Deactivate</button></div></article>`;
    }).join("");
}

async function loadPaymentAccounts() {
    const response = await fetch(`/store/payment-accounts?store_id=${settingsStoreId}`);
    const data = await response.json();
    if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Unable to load receiving accounts.");
    settingsPaymentAccounts = Array.isArray(data.accounts) ? data.accounts : [];
    renderPaymentAccounts();
    renderPaymentMethods();
    if (data.migration_required) setAccountResult("Apply the payment destination migration before adding accounts.", true);
}
