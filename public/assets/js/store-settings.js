let settingsStoreId = null;
let settingsCategories = [];
let settingsPaymentMethods = [];

function settingsEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
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

function renderCategories() {
    const body = document.getElementById("category-body");

    if (!Array.isArray(settingsCategories) || settingsCategories.length === 0) {
        body.innerHTML = '<tr><td colspan="2">No categories found.</td></tr>';
        return;
    }

    body.innerHTML = settingsCategories.map((category) => {
        const isGeneral = String(category.name || "").toLowerCase() === "general";
        return `
            <tr>
                <td>${settingsEscape(category.name)}</td>
                <td>
                    <div class="category-action-cell">
                        <button type="button" class="secondary-btn btn-sm" data-action="edit" data-id="${category.id}">Edit</button>
                        <button type="button" class="danger-btn btn-sm" data-action="delete" data-id="${category.id}" ${isGeneral ? "disabled" : ""}>Delete</button>
                    </div>
                </td>
            </tr>
        `;
    }).join("");
}

function renderPaymentMethods() {
    const body = document.getElementById("payment-method-body");

    if (!Array.isArray(settingsPaymentMethods) || settingsPaymentMethods.length === 0) {
        body.innerHTML = '<tr><td colspan="4">No payment methods found.</td></tr>';
        return;
    }

    body.innerHTML = settingsPaymentMethods.map((method) => {
        const isDebt = String(method.code || "").toLowerCase() === "debt";
        const icon = String(method.icon_class || "").trim();
        const iconHtml = icon
            ? `<i class="${settingsEscape(icon)}" aria-hidden="true"></i> ${settingsEscape(icon)}`
            : "-";

        return `
            <tr>
                <td>${settingsEscape(method.code)}</td>
                <td>${settingsEscape(method.label)}</td>
                <td>${iconHtml}</td>
                <td>
                    <div class="category-action-cell">
                        <button type="button" class="secondary-btn btn-sm" data-method-action="edit" data-id="${method.id}" ${isDebt ? "disabled" : ""}>Edit</button>
                        <button type="button" class="danger-btn btn-sm" data-method-action="delete" data-id="${method.id}" ${isDebt ? "disabled" : ""}>Delete</button>
                    </div>
                </td>
            </tr>
        `;
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
        throw new Error(data?.message || "Unable to load categories.");
    }
    settingsCategories = Array.isArray(data.categories) ? data.categories : [];
    renderCategories();
}

async function loadPaymentMethods() {
    const response = await fetch(`/store/payment-methods?store_id=${settingsStoreId}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Unable to load payment methods.");
    }
    settingsPaymentMethods = Array.isArray(data.methods) ? data.methods : [];
    renderPaymentMethods();
}

async function createCategory() {
    const input = document.getElementById("new-category-name");
    const name = String(input.value || "").trim();
    if (!name) {
        settingsSetResult("Category name is required.", true);
        return;
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

    input.value = "";
    settingsSetResult(`Category saved: ${name}`);
    await loadCategories();
}

async function updateCategory(categoryId) {
    const current = settingsCategories.find((row) => Number(row.id) === Number(categoryId));
    if (!current) return;

    const next = window.prompt("Update category name:", current.name || "");
    if (next === null) return;
    const name = String(next || "").trim();
    if (!name) {
        settingsSetResult("Category name is required.", true);
        return;
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

    settingsSetResult("Category updated.");
    await loadCategories();
}

async function deleteCategory(categoryId) {
    const current = settingsCategories.find((row) => Number(row.id) === Number(categoryId));
    if (!current) return;

    const confirmed = window.confirm(`Delete category "${current.name}"? Products in this category will move to General.`);
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

    const nextLabel = window.prompt("Update payment method label:", current.label || "");
    if (nextLabel === null) return;
    const label = String(nextLabel || "").trim();
    if (!label) {
        settingsSetMethodResult("Payment method label is required.", true);
        return;
    }

    const nextIcon = window.prompt("Update Bootstrap icon class (optional):", current.icon_class || "");
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

    const confirmed = window.confirm(`Delete payment method "${current.label}"?`);
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

document.getElementById("add-category-btn").addEventListener("click", () => {
    createCategory().catch((error) => settingsSetResult(error.message || "Create failed.", true));
});

document.getElementById("new-category-name").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    createCategory().catch((error) => settingsSetResult(error.message || "Create failed.", true));
});

document.getElementById("category-body").addEventListener("click", (event) => {
    const button = event.target.closest("button[data-action]");
    if (!button) return;

    const action = button.getAttribute("data-action");
    const categoryId = Number(button.getAttribute("data-id") || 0);
    if (categoryId <= 0) return;

    if (action === "edit") {
        updateCategory(categoryId).catch((error) => settingsSetResult(error.message || "Update failed.", true));
        return;
    }

    if (action === "delete") {
        deleteCategory(categoryId).catch((error) => settingsSetResult(error.message || "Delete failed.", true));
    }
});

document.getElementById("add-method-btn").addEventListener("click", () => {
    createPaymentMethod().catch((error) => settingsSetMethodResult(error.message || "Create failed.", true));
});

document.getElementById("new-method-label").addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    createPaymentMethod().catch((error) => settingsSetMethodResult(error.message || "Create failed.", true));
});

document.getElementById("payment-method-body").addEventListener("click", (event) => {
    const button = event.target.closest("button[data-method-action]");
    if (!button) return;

    const action = button.getAttribute("data-method-action");
    const methodId = Number(button.getAttribute("data-id") || 0);
    if (methodId <= 0) return;

    if (action === "edit") {
        updatePaymentMethod(methodId).catch((error) => settingsSetMethodResult(error.message || "Update failed.", true));
        return;
    }

    if (action === "delete") {
        deletePaymentMethod(methodId).catch((error) => settingsSetMethodResult(error.message || "Delete failed.", true));
    }
});

(async () => {
    try {
        await loadStoreContext();
        await loadCategories();
        await loadPaymentMethods();
    } catch (error) {
        settingsSetResult(error.message || "Unable to initialize settings.", true);
        settingsSetMethodResult(error.message || "Unable to initialize payment methods.", true);
    }
})();
