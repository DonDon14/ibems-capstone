let apRows = [];
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
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function apSetResult(message, type) {
    const el = document.getElementById("ap-result");
    if (!el) return;
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function apStatusLabel(status) {
    if (status === "out") return "Out of Stock";
    if (status === "low") return "Low Stock";
    return "Healthy";
}

function apStatusClass(status) {
    if (status === "out") return "is-danger";
    if (status === "low") return "is-warning";
    return "is-healthy";
}

function apSetOptions(selectId, placeholder, rows, valueKey, labelKey) {
    const select = document.getElementById(selectId);
    if (!select) return;
    const current = select.value;
    const options = [`<option value="">${apEscape(placeholder)}</option>`].concat(
        rows.map((row) => {
            const value = typeof row === "string" ? row : row[valueKey];
            const label = typeof row === "string" ? row : row[labelKey];
            return `<option value="${apEscape(value)}">${apEscape(label)}</option>`;
        })
    );
    select.innerHTML = options.join("");
    if (current && select.querySelector(`option[value="${CSS.escape(current)}"]`)) {
        select.value = current;
    }
}

function apRenderFilters(data) {
    apStores = Array.isArray(data.stores) ? data.stores : [];
    apSetOptions("ap-store-filter", "All Stores", apStores, "id", "store_name");
    apSetOptions("ap-category-filter", "All Categories", Array.isArray(data.categories) ? data.categories : [], "", "");
    apSetOptions("ap-supplier-filter", "All Suppliers", Array.isArray(data.suppliers) ? data.suppliers : [], "", "");
}

function apRenderSummary(summary) {
    const safe = summary || {};
    document.getElementById("ap-visible-products").textContent = String(Number(safe.visible_products || 0));
    document.getElementById("ap-low-stock").textContent = String(Number(safe.low_stock || 0));
    document.getElementById("ap-out-stock").textContent = String(Number(safe.out_of_stock || 0));
    document.getElementById("ap-inactive-products").textContent = String(Number(safe.inactive_products || 0));
}

function apRenderTable(rows) {
    const body = document.getElementById("ap-body");
    const countText = document.getElementById("ap-count-text");
    const list = Array.isArray(rows) ? rows : [];
    if (countText) countText.textContent = `Showing ${list.length} product${list.length === 1 ? "" : "s"}`;

    if (list.length === 0) {
        body.innerHTML = '<tr><td colspan="8">No products found.</td></tr>';
        return;
    }

    body.innerHTML = list.map((row) => {
        const stockStatus = row.stock_status || "healthy";
        const variant = row.variant_label ? `<small>${apEscape(row.variant_label)}</small>` : "";
        const image = row.image_url
            ? `<img class="ap-product-img" src="${apEscape(row.image_url)}" alt="${apEscape(row.name || "Product")}">`
            : `<span class="ap-product-fallback">${apEscape(String(row.name || "P").charAt(0).toUpperCase())}</span>`;
        return `
            <tr class="ap-row" data-product-id="${Number(row.id)}" tabindex="0" title="View product snapshot">
                <td>
                    <div class="ap-product-cell">
                        ${image}
                        <div>
                            <strong>${apEscape(row.name || "-")}</strong>
                            ${variant}
                            <span>SKU ${apEscape(row.sku || "-")} ${row.barcode ? `| Barcode ${apEscape(row.barcode)}` : ""}</span>
                        </div>
                    </div>
                </td>
                <td>${apEscape(row.store_name || "-")}</td>
                <td>${apEscape(row.category || "-")}</td>
                <td>
                    <div class="ap-meta-stack">
                        <span>${apEscape(row.supplier || "No supplier")}</span>
                        <small>${apEscape(row.location_bin || "No bin/location")}</small>
                    </div>
                </td>
                <td>${apEscape(apMoney(row.price))}</td>
                <td>
                    <div class="ap-meta-stack">
                        <strong>${Number(row.stock_qty || 0)}</strong>
                        <small>Threshold ${Number(row.low_stock_threshold || 0)}</small>
                    </div>
                </td>
                <td>
                    <span class="ap-stock-badge ${apStatusClass(stockStatus)}">${apEscape(apStatusLabel(stockStatus))}</span>
                    ${row.is_active ? "" : '<span class="ap-stock-badge is-inactive">Inactive</span>'}
                </td>
                <td>${apEscape(row.updated_at || "-")}</td>
            </tr>
        `;
    }).join("");
}

async function apLoad() {
    const params = new URLSearchParams();
    const q = (document.getElementById("ap-search").value || "").trim();
    const storeId = (document.getElementById("ap-store-filter").value || "").trim();
    const stockStatus = (document.getElementById("ap-stock-filter").value || "").trim();
    const category = (document.getElementById("ap-category-filter").value || "").trim();
    const supplier = (document.getElementById("ap-supplier-filter").value || "").trim();
    const includeInactive = document.getElementById("ap-include-inactive").checked ? "1" : "0";

    if (q) params.set("q", q);
    if (storeId) params.set("store_id", storeId);
    if (stockStatus) params.set("stock_status", stockStatus);
    if (category) params.set("category", category);
    if (supplier) params.set("supplier", supplier);
    params.set("include_inactive", includeInactive);

    const response = await fetch(`/admin/products/data?${params.toString()}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        apRows = [];
        apRenderTable([]);
        apRenderSummary({});
        apSetResult(data?.message || "Unable to load products.", "error");
        return;
    }

    apRows = Array.isArray(data.data) ? data.data : [];
    apRenderFilters(data);
    apRenderSummary(data.summary || {});
    apRenderTable(apRows);
    apSetResult("", "ok");
}

function apOpenModal(id) {
    document.getElementById(id).classList.remove("is-hidden");
}

function apCloseModal(id) {
    document.getElementById(id).classList.add("is-hidden");
}

function apDetailItem(label, value) {
    return `
        <div class="ap-detail-item">
            <span>${apEscape(label)}</span>
            <strong>${apEscape(value || "-")}</strong>
        </div>
    `;
}

function apOpenSnapshot(productId) {
    const row = apRows.find((item) => Number(item.id) === Number(productId));
    if (!row) {
        apSetResult("Product not found.", "error");
        return;
    }

    const status = apStatusLabel(row.stock_status || "healthy");
    const content = document.getElementById("ap-view-content");
    content.innerHTML = `
        <div class="ap-detail-hero">
            ${row.image_url ? `<img src="${apEscape(row.image_url)}" alt="${apEscape(row.name || "Product")}">` : `<span>${apEscape(String(row.name || "P").charAt(0).toUpperCase())}</span>`}
            <div>
                <h5>${apEscape(row.name || "Product")}</h5>
                <p>${apEscape(row.store_name || "Store")} | ${apEscape(row.category || "General")}</p>
                <span class="ap-stock-badge ${apStatusClass(row.stock_status || "healthy")}">${apEscape(status)}</span>
                ${row.is_active ? "" : '<span class="ap-stock-badge is-inactive">Inactive</span>'}
            </div>
        </div>
        <div class="ap-detail-items">
            ${apDetailItem("SKU", row.sku)}
            ${apDetailItem("Variant", row.variant_label)}
            ${apDetailItem("Barcode", row.barcode)}
            ${apDetailItem("Price", apMoney(row.price))}
            ${apDetailItem("Stock Quantity", String(Number(row.stock_qty || 0)))}
            ${apDetailItem("Low-Stock Threshold", String(Number(row.low_stock_threshold || 0)))}
            ${apDetailItem("Supplier", row.supplier)}
            ${apDetailItem("Bin / Location", row.location_bin)}
            ${apDetailItem("Last Updated", row.updated_at)}
        </div>
    `;

    const storeLink = document.getElementById("ap-store-link");
    storeLink.href = `/admin/stores/${Number(row.store_id || 0)}`;
    apOpenModal("ap-view-modal");
}

function apResetFilters() {
    document.getElementById("ap-search").value = "";
    document.getElementById("ap-store-filter").value = "";
    document.getElementById("ap-stock-filter").value = "";
    document.getElementById("ap-category-filter").value = "";
    document.getElementById("ap-supplier-filter").value = "";
    document.getElementById("ap-include-inactive").checked = false;
    apLoad();
}

document.getElementById("ap-search-btn").addEventListener("click", apLoad);
document.getElementById("ap-refresh-btn").addEventListener("click", apResetFilters);
["ap-store-filter", "ap-stock-filter", "ap-category-filter", "ap-supplier-filter", "ap-include-inactive"].forEach((id) => {
    document.getElementById(id).addEventListener("change", apLoad);
});
document.getElementById("ap-search").addEventListener("input", () => {
    clearTimeout(window.__apSearchTimer);
    window.__apSearchTimer = setTimeout(apLoad, 220);
});

document.getElementById("ap-body").addEventListener("click", (event) => {
    const row = event.target.closest(".ap-row");
    if (row) apOpenSnapshot(Number(row.getAttribute("data-product-id") || 0));
});
document.getElementById("ap-body").addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    const row = event.target.closest(".ap-row");
    if (!row) return;
    event.preventDefault();
    apOpenSnapshot(Number(row.getAttribute("data-product-id") || 0));
});
document.getElementById("ap-view-close").addEventListener("click", () => apCloseModal("ap-view-modal"));
document.getElementById("ap-view-modal").addEventListener("click", (event) => {
    if (event.target.id === "ap-view-modal") apCloseModal("ap-view-modal");
});

apLoad();
