let apRows = [];
let apStores = [];
let apPage = 1;

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

function apDateTime(value) {
    if (!value) return "-";
    const options = {timeZone: "Asia/Manila", dateStyle: "medium", timeStyle: "short"};
    if (window.IbemsFormat?.dateTime) return window.IbemsFormat.dateTime(value, options);
    let normalized = String(value).trim().replace(" ", "T");
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(normalized)) normalized += "Z";
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString("en-PH", options);
}

function apSetResult(message, type) {
    const el = document.getElementById("ap-result");
    if (!el) return;
    el.setAttribute("role", type === "error" ? "alert" : "status");
    el.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
    el.setAttribute("aria-atomic", "true");
    el.textContent = message || "";
    el.style.color = type === "error" ? "var(--feedback-error, #b91c1c)" : "var(--feedback-success, #166534)";
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

function apProductFallback() {
    return '<span class="ap-product-fallback"><i class="bi bi-image" aria-hidden="true"></i></span>';
}

function apProductImage(url, name, imageClass = "ap-product-img") {
    const safeName = apEscape(name || "Product");
    const safeUrl = apEscape(url || "");
    if (!safeUrl) {
        return apProductFallback();
    }

    return `<span class="ap-product-thumb-wrap"><img class="${imageClass}" src="${safeUrl}" alt="${safeName}"></span>`;
}

document.addEventListener("error", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLImageElement) || !target.closest(".ap-product-thumb-wrap")) {
        return;
    }

    target.closest(".ap-product-thumb-wrap").outerHTML = apProductFallback();
}, true);

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

function apSetListState(type, message = '', detail = '') {
    const region = document.getElementById('ap-table-region');
    const isState = type !== 'rows';
    if (region) {
        region.classList.toggle('is-ap-list-state', isState);
        if (isState) region.removeAttribute('tabindex');
        else region.setAttribute('tabindex', '0');
    }
    if (!isState) return;
    const error = type === 'error';
    const icon = error ? 'bi-exclamation-circle' : type === 'loading' ? 'bi-arrow-repeat' : 'bi-inbox';
    document.getElementById('ap-body').innerHTML = `<tr><td colspan="8"><div class="data-state data-state--${type}" role="${error ? 'alert' : 'status'}" aria-live="${error ? 'assertive' : 'polite'}" aria-atomic="true" tabindex="-1"><i class="bi ${icon}" aria-hidden="true"></i><div><strong>${apEscape(message)}</strong>${detail ? `<small>${apEscape(detail)}</small>` : ''}</div>${error ? '<button type="button" class="secondary-btn" data-ap-retry aria-label="Retry product list">Retry</button>' : ''}</div></td></tr>`;
}

function apRenderTable(rows, pagination = {}) {
    const body = document.getElementById("ap-body");
    const countText = document.getElementById("ap-count-text");
    const list = Array.isArray(rows) ? rows : [];
    const total = Number(pagination.total ?? list.length);
    if (countText) countText.textContent = `Showing ${list.length} of ${total} product${total === 1 ? "" : "s"}`;

    if (list.length === 0) {
        apSetListState('empty', 'No products found.');
        return;
    }

    apSetListState('rows');

    body.innerHTML = list.map((row) => {
        const stockStatus = row.stock_status || "healthy";
        const variant = row.variant_label ? `<small>${apEscape(row.variant_label)}</small>` : "";
        const image = apProductImage(row.image_url, row.name);
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
                <td>${apEscape(apDateTime(row.updated_at))}</td>
            </tr>
        `;
    }).join("");
}

function apRenderPager(meta) {
    if (Number(meta?.total ?? apRows.length) === 0) {
        document.getElementById("ap-pager").innerHTML = "";
        return;
    }
    const page = Number(meta?.page || 1);
    const totalPages = Number(meta?.total_pages || 1);
    document.getElementById("ap-pager").innerHTML = `
        <button class="secondary-btn btn-sm" type="button" aria-label="Previous product page" data-page="${page - 1}" ${page <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left"></i> Previous</button>
        <span>Page ${page} of ${totalPages}</span>
        <button class="secondary-btn btn-sm" type="button" aria-label="Next product page" data-page="${page + 1}" ${page >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right"></i></button>`;
}

let apRequestId = 0;
async function apLoad() {
    const requestId = ++apRequestId;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const isCurrent = () => requestId === apRequestId && !signal?.aborted;
    const pager = document.getElementById("ap-pager");
    const body = document.getElementById("ap-body");
    const focusLabel = pager.contains(document.activeElement) ? document.activeElement.getAttribute("aria-label") : null;
    const restoreListFocus = body.contains(document.activeElement) || Boolean(focusLabel);
    const params = new URLSearchParams({
        page: String(apPage),
        page_size: document.getElementById("ap-page-size").value || "25",
    });
    const q = (document.getElementById("ap-search").value || "").trim();
    const storeId = (document.getElementById("ap-store-filter").value || "").trim();
    const stockStatus = (document.getElementById("ap-stock-filter").value || "").trim();
    const category = (document.getElementById("ap-category-filter").value || "").trim();
    const supplier = (document.getElementById("ap-supplier-filter").value || "").trim();
    const includeInactive = document.getElementById("ap-include-inactive").checked ? "1" : "0";
    const [sortBy, sortDir] = (document.getElementById("ap-sort").value || "store:asc").split(":");

    if (q) params.set("q", q);
    if (storeId) params.set("store_id", storeId);
    if (stockStatus) params.set("stock_status", stockStatus);
    if (category) params.set("category", category);
    if (supplier) params.set("supplier", supplier);
    params.set("include_inactive", includeInactive);
    params.set("sort_by", sortBy);
    params.set("sort_dir", sortDir);

    apRows = [];
    body.setAttribute("aria-busy", "true");
    document.getElementById("ap-count-text").textContent = "Loading products…";
    document.getElementById("ap-pager").innerHTML = "";
    ["ap-visible-products", "ap-low-stock", "ap-out-stock", "ap-inactive-products"].forEach(id => {
        document.getElementById(id).textContent = "Loading…";
    });
    apSetListState('loading', 'Loading products…');
    if (restoreListFocus && document.activeElement === document.body) body.querySelector('.data-state')?.focus({preventScroll: true});
    apSetResult("", "ok");
    let failureMessage = 'The product list could not be loaded. Retry to refresh it.';
    try {
        const response = await fetch(`/admin/products/data?${params.toString()}`, { signal });
        const data = await response.json();
        if (!isCurrent()) return;
        if (!response.ok || !data || data.status !== "success") {
            if (typeof data?.message === 'string' && data.message.trim()) failureMessage = data.message;
            throw new Error('Products unavailable');
        }
        apRows = Array.isArray(data.data) ? data.data : [];
        apRenderFilters(data);
        apRenderSummary(data.summary || {});
        apRenderTable(apRows, data.pagination || {});
        apRenderPager(data.pagination || {});
        apSetResult("", "ok");
    } catch (error) {
        if (!isCurrent()) return;
        apRows = [];
        apSetListState('error', 'Products unavailable', failureMessage);
        document.getElementById("ap-count-text").textContent = "Products unavailable";
        ["ap-visible-products", "ap-low-stock", "ap-out-stock", "ap-inactive-products"].forEach(id => {
            document.getElementById(id).textContent = "Unavailable";
        });
        apSetResult("", "ok");
    } finally {
        if (isCurrent()) {
            body.removeAttribute("aria-busy");
            if (restoreListFocus && document.activeElement === document.body) {
                const buttons = Array.from(pager.querySelectorAll("button"));
                const target = buttons.find(button => !button.disabled && button.getAttribute("aria-label") === focusLabel)
                    || (focusLabel ? buttons.find(button => !button.disabled) : null)
                    || body.querySelector('[data-ap-retry]') || body.querySelector('.ap-row')
                    || body.querySelector('.data-state') || document.getElementById("ap-refresh-btn");
                target?.focus({ preventScroll: true });
            }
        }
    }
}

let apModalOpener = null;
function apOpenModal(id) {
    apModalOpener = document.activeElement;
    document.getElementById(id).classList.remove("is-hidden");
    document.body.classList.add("app-modal-open");
    const modal = document.getElementById(id);
    modal.querySelector('.ap-view-modal-scroll').scrollTop = 0;
    modal.querySelector('.admin-modal-close').focus();
}

function apCloseModal(id) {
    document.getElementById(id).classList.add("is-hidden");
    document.body.classList.remove("app-modal-open");
    if (apModalOpener?.isConnected) apModalOpener.focus({ preventScroll: true });
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
            ${apProductImage(row.image_url, row.name, "ap-detail-img")}
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
            ${apDetailItem("Last Updated (Manila)", apDateTime(row.updated_at))}
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
    document.getElementById("ap-sort").value = "store:asc";
    document.getElementById("ap-page-size").value = "25";
    ["ap-store-filter", "ap-stock-filter", "ap-category-filter", "ap-supplier-filter", "ap-include-inactive", "ap-sort", "ap-page-size"].forEach(id => {
        document.getElementById(id).dispatchEvent(new Event("ibems:control-sync"));
    });
    apPage = 1;
    apLoad();
}

document.getElementById("ap-search-btn").addEventListener("click", () => { apPage = 1; apLoad(); });
document.getElementById("ap-refresh-btn").addEventListener("click", apResetFilters);
["ap-store-filter", "ap-stock-filter", "ap-category-filter", "ap-supplier-filter", "ap-include-inactive", "ap-sort", "ap-page-size"].forEach((id) => {
    document.getElementById(id).addEventListener("change", () => { apPage = 1; apLoad(); });
});
document.getElementById("ap-search").addEventListener("input", () => {
    clearTimeout(window.__apSearchTimer);
    window.__apSearchTimer = setTimeout(() => { apPage = 1; apLoad(); }, 220);
});
document.getElementById("ap-pager").addEventListener("click", (event) => {
    const button = event.target.closest("[data-page]");
    if (!button || button.disabled) return;
    apPage = Math.max(1, Number(button.dataset.page || 1));
    apLoad();
});

document.getElementById("ap-body").addEventListener("click", (event) => {
    if (event.target.closest('[data-ap-retry]')) { apLoad(); return; }
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
document.getElementById("ap-view-modal").addEventListener("keydown", event => {
    if (event.defaultPrevented) return;
    if (event.key === "Escape") {
        event.preventDefault(); apCloseModal("ap-view-modal");
    } else if (event.key === "Tab") {
        const controls = Array.from(event.currentTarget.querySelectorAll('button, a[href], [tabindex]'))
            .filter(control => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
});
document.getElementById("ap-view-modal").addEventListener("click", (event) => {
    if (event.target.id === "ap-view-modal") apCloseModal("ap-view-modal");
});

apLoad();
