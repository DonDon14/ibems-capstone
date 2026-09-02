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

    const nextLabel = await window.IbemsDialog.prompt("Change the label shown to Store Cashiers and customers.", {
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
document.getElementById("save-capabilities-btn")?.addEventListener("click", saveCapabilities);

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
        await loadCapabilities();
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
