let apRows = [];
let apEditing = null;
let apStores = [];

function apEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function apMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function apSetResult(message, type) {
    const el = document.getElementById("ap-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function apOpenModal(id) {
    document.getElementById(id).style.display = "grid";
}

function apCloseModal(id) {
    document.getElementById(id).style.display = "none";
}

function apToggleCreateImageInput() {
    const source = (document.getElementById("ap-c-image-source").value || "upload").trim();
    document.getElementById("ap-c-image-upload-wrap").style.display = source === "upload" ? "flex" : "none";
    document.getElementById("ap-c-image-url-wrap").style.display = source === "url" ? "flex" : "none";
}

function apToggleEditImageInput() {
    const source = (document.getElementById("ap-e-image-source").value || "upload").trim();
    document.getElementById("ap-e-image-upload-wrap").style.display = source === "upload" ? "flex" : "none";
    document.getElementById("ap-e-image-url-wrap").style.display = source === "url" ? "flex" : "none";
}

function apRenderStores(stores) {
    apStores = Array.isArray(stores) ? stores : [];
    const select = document.getElementById("ap-store-filter");
    const current = select.value;
    const options = ['<option value="">All Stores</option>']
        .concat(apStores.map((store) =>
            `<option value="${Number(store.id)}">${apEscape(store.store_name)}</option>`
        ));
    select.innerHTML = options.join("");
    if (current && select.querySelector(`option[value="${current}"]`)) {
        select.value = current;
    }

    const createStore = document.getElementById("ap-c-store-id");
    if (!createStore) return;
    const selectedFilterStore = (document.getElementById("ap-store-filter").value || "").trim();
    createStore.innerHTML = apStores
        .map((store) => `<option value="${Number(store.id)}">${apEscape(store.store_name)}</option>`)
        .join("");
    if (selectedFilterStore && createStore.querySelector(`option[value="${selectedFilterStore}"]`)) {
        createStore.value = selectedFilterStore;
    }
}

function apRenderTable(rows) {
    const body = document.getElementById("ap-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="9">No products found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr class="ap-row" data-product-id="${row.id}" style="cursor:pointer;">
            <td>${apEscape(row.store_name || "-")}</td>
            <td>${apEscape(row.sku || "-")}</td>
            <td>${apEscape(row.name || "-")}${row.variant_label ? ` <small style="color:#64748b;">(${apEscape(row.variant_label)})</small>` : ""}</td>
            <td>${apEscape(row.category || "-")}</td>
            <td>${apEscape(apMoney(row.price))}</td>
            <td>${Number(row.stock_qty || 0)}</td>
            <td><span class="uv-status ${row.is_active ? "is-active" : "is-inactive"}">${row.is_active ? "Active" : "Inactive"}</span></td>
            <td>${apEscape(row.updated_at || "-")}</td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button class="history-action ap-edit-btn" type="button" data-product-id="${row.id}">Edit</button>
                    <button class="history-action alt ap-toggle-btn" type="button" data-product-id="${row.id}" data-next-status="${row.is_active ? 0 : 1}">
                        ${row.is_active ? "Deactivate" : "Activate"}
                    </button>
                </div>
            </td>
        </tr>
    `).join("");
}

async function apLoad() {
    const q = (document.getElementById("ap-search").value || "").trim();
    const storeId = (document.getElementById("ap-store-filter").value || "").trim();
    const includeInactive = document.getElementById("ap-include-inactive").checked ? "1" : "0";

    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (storeId) params.set("store_id", storeId);
    params.set("include_inactive", includeInactive);

    const response = await fetch(`/admin/products/data?${params.toString()}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        apRenderTable([]);
        apSetResult(data?.message || "Unable to load products.", "error");
        return;
    }

    apRows = Array.isArray(data.data) ? data.data : [];
    apRenderStores(data.stores || []);
    apRenderTable(apRows);
}

function apResetCreateForm() {
    document.getElementById("ap-c-sku").value = "";
    document.getElementById("ap-c-name").value = "";
    document.getElementById("ap-c-variant").value = "";
    document.getElementById("ap-c-category").value = "General";
    document.getElementById("ap-c-barcode").value = "";
    document.getElementById("ap-c-image-source").value = "upload";
    document.getElementById("ap-c-image-file").value = "";
    document.getElementById("ap-c-image-url").value = "";
    document.getElementById("ap-c-price").value = "0";
    document.getElementById("ap-c-stock").value = "0";
    document.getElementById("ap-c-active").value = "1";
    apToggleCreateImageInput();
    apRenderStores(apStores);
}

function apOpenEdit(productId) {
    const row = apRows.find((item) => Number(item.id) === Number(productId));
    if (!row) {
        apSetResult("Product not found.", "error");
        return;
    }

    apEditing = row;
    document.getElementById("ap-e-store-name").value = row.store_name || "";
    document.getElementById("ap-e-sku").value = row.sku || "";
    document.getElementById("ap-e-name").value = row.name || "";
    document.getElementById("ap-e-variant").value = row.variant_label || "";
    document.getElementById("ap-e-category").value = row.category || "";
    document.getElementById("ap-e-barcode").value = row.barcode || "";
    document.getElementById("ap-e-image-source").value = row.image_url ? "url" : "upload";
    document.getElementById("ap-e-image-file").value = "";
    document.getElementById("ap-e-image-url").value = row.image_url || "";
    document.getElementById("ap-e-price").value = Number(row.price || 0);
    document.getElementById("ap-e-active").value = row.is_active ? "1" : "0";
    apToggleEditImageInput();
    apOpenModal("ap-edit-modal");
}

function apOpenCreate() {
    apResetCreateForm();
    apOpenModal("ap-create-modal");
}

async function apCreateProduct() {
    const storeId = Number(document.getElementById("ap-c-store-id").value || 0);
    const imageSource = (document.getElementById("ap-c-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("ap-c-image-url").value || "").trim();
    const imageFile = document.getElementById("ap-c-image-file").files[0] || null;
    const formData = new FormData();
    formData.append("store_id", String(storeId));
    formData.append("sku", (document.getElementById("ap-c-sku").value || "").trim());
    formData.append("name", (document.getElementById("ap-c-name").value || "").trim());
    formData.append("variant_label", (document.getElementById("ap-c-variant").value || "").trim());
    formData.append("category", (document.getElementById("ap-c-category").value || "").trim());
    formData.append("barcode", (document.getElementById("ap-c-barcode").value || "").trim());
    formData.append("price", String(Number(document.getElementById("ap-c-price").value || 0)));
    formData.append("stock_qty", String(Number(document.getElementById("ap-c-stock").value || 0)));
    formData.append("is_active", String(Number(document.getElementById("ap-c-active").value || 1)));
    if (imageSource === "url" && imageUrl !== "") {
        formData.append("image_url", imageUrl);
    } else if (imageSource === "upload" && imageFile) {
        formData.append("image_file", imageFile);
    }

    const response = await fetch("/admin/products/create", {
        method: "POST",
        body: formData,
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        apSetResult(data?.message || "Failed to create product.", "error");
        return;
    }

    apCloseModal("ap-create-modal");
    apSetResult("Product created.", "ok");
    await apLoad();
}

async function apSaveEdit() {
    if (!apEditing) return;
    const imageSource = (document.getElementById("ap-e-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("ap-e-image-url").value || "").trim();
    const imageFile = document.getElementById("ap-e-image-file").files[0] || null;

    const formData = new FormData();
    formData.append("product_id", String(Number(apEditing.id)));
    formData.append("store_id", String(Number(apEditing.store_id)));
    formData.append("sku", (document.getElementById("ap-e-sku").value || "").trim());
    formData.append("name", (document.getElementById("ap-e-name").value || "").trim());
    formData.append("variant_label", (document.getElementById("ap-e-variant").value || "").trim());
    formData.append("category", (document.getElementById("ap-e-category").value || "").trim());
    formData.append("barcode", (document.getElementById("ap-e-barcode").value || "").trim());
    formData.append("price", String(Number(document.getElementById("ap-e-price").value || 0)));
    formData.append("is_active", String(Number(document.getElementById("ap-e-active").value || 1)));
    if (imageSource === "url" && imageUrl !== "") {
        formData.append("image_url", imageUrl);
    } else if (imageSource === "upload" && imageFile) {
        formData.append("image_file", imageFile);
    }

    const response = await fetch("/admin/products/update", {
        method: "POST",
        body: formData,
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        apSetResult(data?.message || "Failed to update product.", "error");
        return;
    }

    apCloseModal("ap-edit-modal");
    apSetResult("Product updated.", "ok");
    await apLoad();
}

async function apToggleStatus(productId, nextStatus) {
    const response = await fetch("/admin/products/toggle-status", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            product_id: Number(productId || 0),
            is_active: Number(nextStatus || 0),
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        apSetResult(data?.message || "Failed to update product status.", "error");
        return;
    }

    apSetResult(nextStatus === 1 ? "Product activated." : "Product deactivated.", "ok");
    await apLoad();
}

document.getElementById("ap-add-btn").addEventListener("click", apOpenCreate);
document.getElementById("ap-search-btn").addEventListener("click", apLoad);
document.getElementById("ap-refresh-btn").addEventListener("click", () => {
    document.getElementById("ap-search").value = "";
    document.getElementById("ap-store-filter").value = "";
    document.getElementById("ap-include-inactive").checked = false;
    apLoad();
});
document.getElementById("ap-store-filter").addEventListener("change", apLoad);
document.getElementById("ap-include-inactive").addEventListener("change", apLoad);
document.getElementById("ap-search").addEventListener("input", () => {
    clearTimeout(window.__apSearchTimer);
    window.__apSearchTimer = setTimeout(apLoad, 220);
});

document.getElementById("ap-body").addEventListener("click", (event) => {
    const editBtn = event.target.closest(".ap-edit-btn");
    if (editBtn) {
        event.preventDefault();
        event.stopPropagation();
        apOpenEdit(Number(editBtn.getAttribute("data-product-id") || 0));
        return;
    }

    const toggleBtn = event.target.closest(".ap-toggle-btn");
    if (toggleBtn) {
        event.preventDefault();
        event.stopPropagation();
        const productId = Number(toggleBtn.getAttribute("data-product-id") || 0);
        const nextStatus = Number(toggleBtn.getAttribute("data-next-status") || 0);
        apToggleStatus(productId, nextStatus);
        return;
    }

    const row = event.target.closest(".ap-row");
    if (row) {
        apOpenEdit(Number(row.getAttribute("data-product-id") || 0));
    }
});

document.getElementById("ap-create-close").addEventListener("click", () => apCloseModal("ap-create-modal"));
document.getElementById("ap-create-save").addEventListener("click", apCreateProduct);
document.getElementById("ap-c-image-source").addEventListener("change", apToggleCreateImageInput);
document.getElementById("ap-create-modal").addEventListener("click", (event) => {
    if (event.target.id === "ap-create-modal") apCloseModal("ap-create-modal");
});

document.getElementById("ap-edit-close").addEventListener("click", () => apCloseModal("ap-edit-modal"));
document.getElementById("ap-edit-save").addEventListener("click", apSaveEdit);
document.getElementById("ap-e-image-source").addEventListener("change", apToggleEditImageInput);
document.getElementById("ap-edit-modal").addEventListener("click", (event) => {
    if (event.target.id === "ap-edit-modal") apCloseModal("ap-edit-modal");
});

apLoad();
