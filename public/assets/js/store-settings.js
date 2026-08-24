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
    el.style.color = isError ? "#b91c1c" : "#166534";
}

function settingsSetMethodResult(message, isError = false) {
    const el = document.getElementById("settings-method-result");
    el.textContent = message || "";
    el.style.color = isError ? "#b91c1c" : "#166534";
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
    document.getElementById("category-edit-guidance-text").textContent = "Use a short, specific name that store officers can recognize quickly.";
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
    el.style.color = isError ? "#b91c1c" : "#166534";
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

function closePaymentMethodModal() {
    const modal = document.getElementById("payment-method-modal");
    modal.classList.add("is-hidden");
    document.body.classList.remove("settings-modal-open");
    editingPaymentMethodId = null;
    editingPaymentAccountId = null;
    editingPaymentMethodImageUrl = "";
    document.getElementById("modal-account-form").classList.add("is-hidden");
    setAccountResult("");
}

function setPaymentMethodSavePending(isPending) {
    paymentMethodSavePending = Boolean(isPending);
    const modal = document.getElementById("payment-method-modal");
    const card = modal?.querySelector(".settings-modal-card");
    const saveButton = document.getElementById("save-method-btn");
    const isCreating = !editingPaymentMethodId;

    card?.setAttribute("aria-busy", paymentMethodSavePending ? "true" : "false");
    modal?.querySelectorAll("button, input, select, textarea").forEach((control) => {
        control.disabled = paymentMethodSavePending;
    });

    if (saveButton) {
        saveButton.innerHTML = paymentMethodSavePending
            ? `<i class="bi bi-arrow-repeat settings-submit-spinner" aria-hidden="true"></i> ${isCreating ? "Creating payment method..." : "Saving changes..."}`
            : isCreating
                ? '<i class="bi bi-plus-circle"></i> Create payment method'
                : '<i class="bi bi-check2-circle"></i> Save changes';
    }
}

function renderMethodImagePreview(previewUrl = editingPaymentMethodImageUrl) {
    const preview = document.getElementById("method-image-preview");
    if (!preview) return;
    preview.innerHTML = previewUrl
        ? `<img src="${settingsEscape(previewUrl)}" alt="Payment method display"><small>Uploaded display image</small>`
        : '<span><i class="bi bi-wallet2"></i></span><small>Standard payment image</small>';
}

async function uploadSelectedMethodImage() {
    const file = document.getElementById("edit-method-image")?.files?.[0] || null;
    if (!file) return editingPaymentMethodImageUrl;
    const form = new FormData();
    form.append("store_id", String(settingsStoreId));
    form.append("method_image", file);
    const response = await fetch("/store/payment-methods/upload-image", {method: "POST", body: form});
    const data = await response.json();
    if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Payment image upload failed.");
    editingPaymentMethodImageUrl = String(data.image_url || "");
    return editingPaymentMethodImageUrl;
}

function openPaymentMethodModal(methodId) {
    const method = settingsPaymentMethods.find((row) => Number(row.id) === Number(methodId));
    if (!method) return;
    editingPaymentMethodId = Number(method.id);
    setPaymentMethodSavePending(false);
    document.getElementById("payment-method-modal-title").textContent = `Edit ${method.label}`;
    document.getElementById("edit-method-label").value = String(method.label || "");
    editingPaymentMethodImageUrl = String(method.image_url || "");
    document.getElementById("edit-method-image").value = "";
    renderMethodImagePreview();
    document.getElementById("method-code-field").classList.add("is-hidden");
    const protectedMethod = String(method.code) === "debt";
    document.getElementById("edit-method-label").disabled = protectedMethod;
    document.getElementById("edit-method-image").disabled = protectedMethod;
    document.getElementById("save-method-btn").classList.toggle("is-hidden", protectedMethod);
    document.getElementById("delete-method-btn").classList.toggle("is-hidden", protectedMethod || String(method.code) === "cash");
    document.getElementById("save-method-btn").innerHTML = '<i class="bi bi-check2-circle"></i> Save changes';
    document.getElementById("show-account-form").classList.remove("is-hidden");
    document.getElementById("add-account-btn").classList.remove("is-hidden");
    document.getElementById("cancel-account-form").classList.remove("is-hidden");
    document.getElementById("method-display-guidance").textContent = protectedMethod
        ? "Debt is a protected system method. Its label and accounting behavior cannot be changed here."
        : "This label and image are used consistently in POS and reports. The standard payment image is used when no image is uploaded.";
    const accountsSection = document.getElementById("method-accounts-section");
    const allowsAccounts = method.requires_destination_account === true;
    accountsSection.classList.toggle("is-hidden", !allowsAccounts);
    renderPaymentAccounts();
    document.getElementById("payment-method-modal").classList.remove("is-hidden");
    document.body.classList.add("settings-modal-open");
}

function openCreatePaymentMethodModal() {
    editingPaymentMethodId = null;
    editingPaymentAccountId = null;
    setPaymentMethodSavePending(false);
    document.getElementById("payment-method-modal-title").textContent = "Add payment method";
    document.getElementById("edit-method-label").value = "";
    editingPaymentMethodImageUrl = "";
    document.getElementById("edit-method-image").value = "";
    renderMethodImagePreview();
    document.getElementById("edit-method-code").value = "";
    document.getElementById("edit-method-label").disabled = false;
    document.getElementById("edit-method-image").disabled = false;
    document.getElementById("method-code-field").classList.remove("is-hidden");
    document.getElementById("method-display-guidance").textContent = "Choose the name and optional image that will appear in POS. The standard payment image is used when none is uploaded. The code may be left blank to generate it from the label.";
    document.getElementById("method-accounts-section").classList.remove("is-hidden");
    document.getElementById("show-account-form").classList.add("is-hidden");
    document.getElementById("add-account-btn").classList.add("is-hidden");
    document.getElementById("cancel-account-form").classList.add("is-hidden");
    document.getElementById("modal-account-form").classList.remove("is-hidden");
    document.getElementById("account-name").value = "";
    document.getElementById("account-number").value = "";
    document.getElementById("account-qr").value = "";
    document.getElementById("payment-account-list").innerHTML = '<div class="payment-account-empty"><i class="bi bi-qr-code"></i><strong>Add the first receiving destination.</strong><span>This account will be selectable in POS.</span></div>';
    document.getElementById("save-method-btn").classList.remove("is-hidden");
    document.getElementById("delete-method-btn").classList.add("is-hidden");
    document.getElementById("save-method-btn").innerHTML = '<i class="bi bi-plus-circle"></i> Create payment method';
    document.getElementById("payment-method-modal").classList.remove("is-hidden");
    document.body.classList.add("settings-modal-open");
}

async function savePaymentMethodChanges() {
    const label = String(document.getElementById("edit-method-label").value || "").trim();
    if (!label) throw new Error("Payment method label is required.");
    const imageUrl = await uploadSelectedMethodImage();
    if (!editingPaymentMethodId) {
        const accountName = String(document.getElementById("account-name").value || "").trim();
        const accountNumber = String(document.getElementById("account-number").value || "").trim();
        if (!accountName || !accountNumber) throw new Error("Add the first receiving account name and number.");
        const response = await fetch("/store/payment-methods/create", {method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify({store_id: settingsStoreId, label, code: String(document.getElementById("edit-method-code").value || "").trim(), icon_class: "bi bi-wallet2", image_url: imageUrl})});
        const data = await response.json();
        if (!response.ok || data?.status !== "success" || !data.method?.id) throw new Error(data?.message || "Failed to create payment method.");
        editingPaymentMethodId = Number(data.method.id);
        try {
            await createPaymentAccount();
        } catch (error) {
            await fetch("/store/payment-methods/delete", {method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify({store_id: settingsStoreId, method_id: Number(data.method.id)})}).catch(() => null);
            editingPaymentMethodId = null;
            throw error;
        }
        settingsSetMethodResult(`${label} is now available in POS.`);
        closePaymentMethodModal();
        return;
    }
    const method = settingsPaymentMethods.find((row) => Number(row.id) === Number(editingPaymentMethodId));
    if (!method || String(method.code) === "debt" || String(method.code) === "cash") return;
    const response = await fetch("/store/payment-methods/update", {method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify({store_id: settingsStoreId, method_id: Number(method.id), label, icon_class: "bi bi-wallet2", image_url: imageUrl})});
    const data = await response.json();
    if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Failed to update payment method.");
    await loadPaymentMethods();
    settingsSetMethodResult(`${label} updated.`);
    closePaymentMethodModal();
}

async function createPaymentAccount() {
    const methodId = Number(editingPaymentMethodId || 0);
    const name = String(document.getElementById("account-name").value || "").trim();
    const number = String(document.getElementById("account-number").value || "").trim();
    const file = document.getElementById("account-qr").files?.[0] || null;
    if (!methodId || !name || !number) throw new Error("Payment method, account name, and account number are required.");
    const existing = editingPaymentAccountId ? settingsPaymentAccounts.find((account) => Number(account.id) === Number(editingPaymentAccountId)) : null;
    let imageUrl = String(existing?.image_url || "");
    if (file) {
        const form = new FormData(); form.append("store_id", String(settingsStoreId)); form.append("qr_image", file);
        const uploadResponse = await fetch("/store/payment-accounts/upload-qr", {method: "POST", body: form});
        const upload = await uploadResponse.json();
        if (!uploadResponse.ok || upload?.status !== "success") throw new Error(upload?.message || "QR upload failed.");
        imageUrl = String(upload.image_url || "");
    }
    const response = await fetch("/store/payment-accounts/save", {method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify({store_id: settingsStoreId, payment_method_id: methodId, account_id: editingPaymentAccountId, account_name: name, account_number: number, image_url: imageUrl})});
    const data = await response.json();
    if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Unable to save receiving account.");
    document.getElementById("account-name").value = ""; document.getElementById("account-number").value = ""; document.getElementById("account-qr").value = "";
    setAccountResult(editingPaymentAccountId ? "Receiving account updated." : "Receiving account added. It is now available in POS.");
    editingPaymentAccountId = null;
    document.getElementById("modal-account-form").classList.add("is-hidden");
    await loadPaymentAccounts(); await loadPaymentMethods();
    renderPaymentAccounts();
}

async function createCategory(nextName) {
    const name = String(nextName || "").trim();
    if (!name) {
        throw new Error("Category name is required.");
    }

    const response = await fetch("/store/categories/create", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            store_id: settingsStoreId,
            name,
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Failed to create category.");
    }

    await loadCategories();
    settingsSetResult(`Category created: ${name}.`);
}

async function updateCategory(categoryId, nextName) {
    const current = settingsCategories.find((row) => Number(row.id) === Number(categoryId));
    if (!current) return;

    const name = String(nextName || "").trim();
    if (!name) {
        throw new Error("Category name is required.");
    }
    if (name === String(current.name || "").trim()) {
        throw new Error("Enter a different category name before saving.");
    }

    const response = await fetch("/store/categories/update", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            store_id: settingsStoreId,
            category_id: Number(categoryId),
            name,
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Failed to update category.");
    }

    await loadCategories();
    settingsSetResult(`Category updated to ${name}.`);
}

async function deleteCategory(categoryId) {
    const current = settingsCategories.find((row) => Number(row.id) === Number(categoryId));
    if (!current) return;

    const confirmed = await window.IbemsDialog.confirm(`Products in “${current.name}” will move to General.`, {
        title: "Delete category?",
        confirmLabel: "Delete category",
        tone: "danger",
    });
    if (!confirmed) return;

    const response = await fetch("/store/categories/delete", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            store_id: settingsStoreId,
            category_id: Number(categoryId),
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Failed to delete category.");
    }

    settingsSetResult("Category deleted.");
    await loadCategories();
}

async function createPaymentMethod() {
    const labelInput = document.getElementById("new-method-label");
    const codeInput = document.getElementById("new-method-code");
    const iconInput = document.getElementById("new-method-icon");

    const label = String(labelInput.value || "").trim();
    const code = String(codeInput.value || "").trim();
    const iconClass = String(iconInput.value || "").trim();

    if (!label) {
        settingsSetMethodResult("Payment method label is required.", true);
        return;
    }

    const response = await fetch("/store/payment-methods/create", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            store_id: settingsStoreId,
            label,
            code,
            icon_class: iconClass,
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Failed to create payment method.");
    }

    labelInput.value = "";
    codeInput.value = "";
    iconInput.value = "";
    settingsSetMethodResult(`Payment method saved: ${label}`);
    await loadPaymentMethods();
}

async function updatePaymentMethod(methodId) {
    const current = settingsPaymentMethods.find((row) => Number(row.id) === Number(methodId));
    if (!current) return;

    const nextLabel = await window.IbemsDialog.prompt("Change the label shown to store officers and customers.", {
        title: "Update payment method",
        inputLabel: "Payment method label",
        defaultValue: current.label || "",
        required: true,
        confirmLabel: "Continue",
    });
    if (nextLabel === null) return;
    const label = String(nextLabel || "").trim();
    if (!label) {
        settingsSetMethodResult("Payment method label is required.", true);
        return;
    }

    const nextIcon = await window.IbemsDialog.prompt("Optionally provide a Bootstrap Icons class.", {
        title: "Payment method icon",
        inputLabel: "Icon class",
        defaultValue: current.icon_class || "",
        placeholder: "Example: bi bi-wallet2",
        confirmLabel: "Update payment method",
    });
    if (nextIcon === null) return;
    const iconClass = String(nextIcon || "").trim();

    const response = await fetch("/store/payment-methods/update", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            store_id: settingsStoreId,
            method_id: Number(methodId),
            label,
            icon_class: iconClass,
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Failed to update payment method.");
    }

    settingsSetMethodResult("Payment method updated.");
    await loadPaymentMethods();
}

async function deletePaymentMethod(methodId) {
    const current = settingsPaymentMethods.find((row) => Number(row.id) === Number(methodId));
    if (!current) return;

    const confirmed = await window.IbemsDialog.confirm(`The “${current.label}” payment option will no longer be available.`, {
        title: "Delete payment method?",
        confirmLabel: "Delete payment method",
        tone: "danger",
    });
    if (!confirmed) return;

    const response = await fetch("/store/payment-methods/delete", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            store_id: settingsStoreId,
            method_id: Number(methodId),
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Failed to delete payment method.");
    }

    settingsSetMethodResult("Payment method deleted.");
    await loadPaymentMethods();
}

document.getElementById("add-category-btn").addEventListener("click", (event) => {
    openCategoryCreateModal(event.currentTarget);
});

document.getElementById("category-body").addEventListener("click", (event) => {
    const button = event.target.closest("button[data-action]");
    if (!button) return;

    const action = button.getAttribute("data-action");
    const categoryId = Number(button.getAttribute("data-id") || 0);
    if (categoryId <= 0) return;

    if (action === "edit") {
        openCategoryEditModal(categoryId, button);
        return;
    }

    if (action === "delete") {
        runCategoryMutation("delete", button, () => deleteCategory(categoryId));
    }
});

document.getElementById("category-search")?.addEventListener("input", (event) => {
    categoryQuery = String(event.target.value || "");
    categoryPage = 1;
    renderCategories();
});
document.getElementById("category-sort")?.addEventListener("change", (event) => {
    categorySort = String(event.target.value || "name-asc");
    categoryPage = 1;
    renderCategories();
});
document.getElementById("category-prev")?.addEventListener("click", () => {
    if (categoryPage <= 1) return;
    categoryPage -= 1;
    renderCategories();
});
document.getElementById("category-next")?.addEventListener("click", () => {
    categoryPage += 1;
    renderCategories();
});

document.getElementById("category-edit-name")?.addEventListener("input", () => {
    setCategoryEditResult("");
    syncCategoryEditSaveState();
});
document.getElementById("category-edit-name")?.addEventListener("keydown", (event) => {
    if (event.key === "Enter" && !document.getElementById("category-edit-save")?.disabled) {
        event.preventDefault();
        document.getElementById("category-edit-save")?.click();
    }
});
document.getElementById("category-edit-save")?.addEventListener("click", async (event) => {
    if (categoryMutationPending) return;
    const current = settingsCategories.find((row) => Number(row.id) === Number(editingCategoryId));
    const isCreating = !editingCategoryId;
    const nextName = String(document.getElementById("category-edit-name")?.value || "").trim();
    if (!nextName) {
        setCategoryEditResult("Enter a category name.", true);
        syncCategoryEditSaveState();
        return;
    }
    if (!isCreating && !current) {
        setCategoryEditResult("This category is no longer available. Refresh and try again.", true);
        return;
    }

    let saved = false;
    setCategoryEditResult("");
    setCategoryMutationPending(true, isCreating ? "create" : "update", event.currentTarget);
    try {
        if (isCreating) {
            await createCategory(nextName);
        } else {
            await updateCategory(Number(current.id), nextName);
        }
        saved = true;
    } catch (error) {
        setCategoryEditResult(error.message || `Unable to ${isCreating ? "create" : "update"} this category.`, true);
    } finally {
        setCategoryMutationPending(false);
    }
    if (saved) closeCategoryEditModal(false);
});
document.getElementById("category-edit-cancel")?.addEventListener("click", () => closeCategoryEditModal());
document.getElementById("category-edit-close")?.addEventListener("click", () => closeCategoryEditModal());
document.getElementById("category-edit-modal")?.addEventListener("click", (event) => {
    if (event.target.id === "category-edit-modal") closeCategoryEditModal();
});
document.getElementById("category-edit-modal")?.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    event.preventDefault();
    closeCategoryEditModal();
});

const settingsModalRoot = document.getElementById("payment-method-modal");
if (settingsModalRoot && settingsModalRoot.parentElement !== document.body) document.body.appendChild(settingsModalRoot);
const categoryEditModalRoot = document.getElementById("category-edit-modal");
if (categoryEditModalRoot && categoryEditModalRoot.parentElement !== document.body) document.body.appendChild(categoryEditModalRoot);

document.getElementById("payment-method-list").addEventListener("click", (event) => {
    const button = event.target.closest("button[data-method-edit]");
    if (!button) return;
    openPaymentMethodModal(Number(button.dataset.methodEdit));
});
document.getElementById("add-payment-method-btn")?.addEventListener("click", openCreatePaymentMethodModal);

document.getElementById("add-account-btn")?.addEventListener("click", () => createPaymentAccount().catch((error) => setAccountResult(error.message, true)));
document.getElementById("show-account-form")?.addEventListener("click", () => { editingPaymentAccountId = null; document.getElementById("account-name").value = ""; document.getElementById("account-number").value = ""; document.getElementById("account-qr").value = ""; document.getElementById("modal-account-form").classList.remove("is-hidden"); });
document.getElementById("cancel-account-form")?.addEventListener("click", () => { editingPaymentAccountId = null; document.getElementById("modal-account-form").classList.add("is-hidden"); });
document.getElementById("payment-method-modal-close")?.addEventListener("click", closePaymentMethodModal);
document.getElementById("payment-method-modal-cancel")?.addEventListener("click", closePaymentMethodModal);
document.getElementById("save-method-btn")?.addEventListener("click", async () => {
    if (paymentMethodSavePending) return;
    setAccountResult("");
    setPaymentMethodSavePending(true);
    try {
        await savePaymentMethodChanges();
    } catch (error) {
        setAccountResult(error.message, true);
    } finally {
        setPaymentMethodSavePending(false);
    }
});
document.getElementById("edit-method-image")?.addEventListener("change", (event) => {
    const file = event.target.files?.[0] || null;
    renderMethodImagePreview(file ? URL.createObjectURL(file) : editingPaymentMethodImageUrl);
});
document.getElementById("delete-method-btn")?.addEventListener("click", async () => {
    const method = settingsPaymentMethods.find((row) => Number(row.id) === Number(editingPaymentMethodId));
    if (!method || ["cash", "debt"].includes(String(method.code))) return;
    const confirmed = await window.IbemsDialog.confirm("This method will disappear from new POS sales. Existing transactions, receipts, and reports remain unchanged.", {title: `Delete ${method.label}?`, confirmLabel: "Delete method", tone: "danger"});
    if (!confirmed) return;
    try {
        const response = await fetch("/store/payment-methods/delete", {method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify({store_id: settingsStoreId, method_id: Number(method.id)})});
        const data = await response.json();
        if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Unable to delete payment method.");
        closePaymentMethodModal();
        await loadPaymentAccounts(); await loadPaymentMethods();
        settingsSetMethodResult(`${method.label} deleted. Historical records were preserved.`);
    } catch (error) { setAccountResult(error.message, true); }
});
document.getElementById("payment-method-modal")?.addEventListener("click", (event) => { if (!paymentMethodSavePending && event.target.id === "payment-method-modal") closePaymentMethodModal(); });
window.IbemsPortalNavigation?.onCleanup(() => {
    document.body.classList.remove("settings-modal-open");
});
document.getElementById("payment-account-list")?.addEventListener("click", async (event) => {
    const editButton = event.target.closest("[data-account-edit]");
    if (editButton) {
        const account = settingsPaymentAccounts.find((row) => Number(row.id) === Number(editButton.dataset.accountEdit));
        if (!account) return;
        editingPaymentAccountId = Number(account.id);
        document.getElementById("account-name").value = String(account.account_name || "");
        document.getElementById("account-number").value = String(account.account_number || "");
        document.getElementById("account-qr").value = "";
        document.getElementById("modal-account-form").classList.remove("is-hidden");
        return;
    }
    const button = event.target.closest("[data-account-remove]"); if (!button) return;
    const confirmed = await window.IbemsDialog.confirm("This destination will disappear from new POS sales. Historical payments remain unchanged.", {title: "Deactivate receiving account?", confirmLabel: "Deactivate", tone: "danger"});
    if (!confirmed) return;
    try {
        const response = await fetch("/store/payment-accounts/deactivate", {method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify({store_id: settingsStoreId, account_id: Number(button.dataset.accountRemove)})});
        const data = await response.json(); if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Deactivate failed.");
        await loadPaymentAccounts(); await loadPaymentMethods();
    } catch (error) { setAccountResult(error.message, true); }
});

(async () => {
    try {
        await loadStoreContext();
        await loadCategories();
        await loadPaymentMethods();
        await loadPaymentAccounts();
    } catch (error) {
        const message = error.message || "Unable to initialize store settings.";
        if (document.querySelector("#category-body .data-state--loading")) {
            document.getElementById("category-body").innerHTML = settingsDataState("error", message, 3);
        }
        const methodList = document.getElementById("payment-method-list");
        if (methodList) methodList.innerHTML = `<div class="payment-method-empty is-error"><strong>${settingsEscape(message)}</strong></div>`;
        settingsSetResult(message, true);
        settingsSetMethodResult(message, true);
    }
})();
