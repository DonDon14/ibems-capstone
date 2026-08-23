(function () {
"use strict";

const usPageSignal = window.IbemsUserNavigation?.currentSignal || new AbortController().signal;
if (!document.getElementById("us-products")) return;

let usPage = 1;
let usSearchTimer = null;
let usRequestSequence = 0;
let usOptionsLoaded = false;

function usHasActiveFilters() {
    return document.getElementById("us-search").value.trim() !== ""
        || document.getElementById("us-store").value !== ""
        || document.getElementById("us-category").value !== ""
        || document.getElementById("us-availability").value !== ""
        || document.getElementById("us-sort").value !== "store:asc";
}

function usUpdateResetFilters() {
    document.getElementById("us-reset-filters").classList.toggle("is-hidden", !usHasActiveFilters());
}

function usEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function usMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function usState(type, message) {
    const icon = type === "error" ? "bi-exclamation-circle" : type === "empty" ? "bi-inbox" : "bi-arrow-repeat";
    return `<div class="data-state data-state--${usEscape(type)}" role="status"><i class="bi ${icon}" aria-hidden="true"></i><div><strong>${usEscape(message)}</strong></div></div>`;
}

function usSetOptions(id, placeholder, rows, valueKey, labelKey) {
    const select = document.getElementById(id);
    const current = select.value;
    select.innerHTML = `<option value="">${usEscape(placeholder)}</option>${rows.map((row) => {
        const value = typeof row === "string" ? row : row[valueKey];
        const label = typeof row === "string" ? row : row[labelKey];
        return `<option value="${usEscape(value)}">${usEscape(label)}</option>`;
    }).join("")}`;
    if (current && select.querySelector(`option[value="${CSS.escape(current)}"]`)) select.value = current;
}

function usProductImage(row) {
    if (!row.image_url) return '<span class="user-product-image-fallback"><i class="bi bi-box-seam"></i></span>';
    return `<span class="user-product-image-wrap"><img src="${usEscape(row.image_url)}" alt="${usEscape(row.name || "Product")}"></span>`;
}

function usRenderProducts(rows) {
    const grid = document.getElementById("us-products");
    if (!rows.length) {
        grid.innerHTML = usState("empty", "No products match these filters.");
        return;
    }
    grid.innerHTML = rows.map((row) => {
        const available = row.availability === "available";
        const variant = row.variant_label ? `<span class="user-product-variant">${usEscape(row.variant_label)}</span>` : "";
        return `
            <article class="user-product-card">
                ${usProductImage(row)}
                <div class="user-product-card-body">
                    <div class="user-product-store"><i class="bi bi-shop"></i> ${usEscape(row.store_name || "Store")}</div>
                    <h3>${usEscape(row.name || "Unnamed product")}</h3>
                    ${variant}
                    <p>${usEscape(row.category || "Uncategorized")} · ${usEscape(row.sku || "No SKU")}</p>
                    <div class="user-product-card-footer">
                        <strong>${usEscape(usMoney(row.price))}</strong>
                        <span class="user-availability ${available ? "is-available" : "is-out"}">${available ? "Available" : "Out of stock"}</span>
                    </div>
                </div>
            </article>`;
    }).join("");
}

function usRenderPager(meta) {
    const pager = document.getElementById("us-pager");
    const page = Number(meta.page || 1);
    const totalPages = Number(meta.total_pages || 1);
    pager.innerHTML = `
        <button class="secondary-btn btn-sm" type="button" data-page="${page - 1}" ${page <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left"></i> Previous</button>
        <span>Page ${page} of ${totalPages}</span>
        <button class="secondary-btn btn-sm" type="button" data-page="${page + 1}" ${page >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right"></i></button>`;
}

async function usLoad() {
    const requestId = ++usRequestSequence;
    usUpdateResetFilters();
    const params = new URLSearchParams({ page: String(usPage), page_size: "24" });
    params.set("include_options", usOptionsLoaded ? "0" : "1");
    const values = {
        q: document.getElementById("us-search").value.trim(),
        store_id: document.getElementById("us-store").value,
        category: document.getElementById("us-category").value,
        availability: document.getElementById("us-availability").value,
    };
    Object.entries(values).forEach(([key, value]) => { if (value) params.set(key, value); });
    const [sortBy, sortDir] = document.getElementById("us-sort").value.split(":");
    params.set("sort_by", sortBy);
    params.set("sort_dir", sortDir);

    const grid = document.getElementById("us-products");
    grid.innerHTML = usState("loading", "Loading store products...");
    try {
        const response = await fetch(`/user/stores/data?${params.toString()}`, { signal: usPageSignal });
        const data = await response.json();
        if (requestId !== usRequestSequence) return;
        if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Unable to load store products.");

        if (data.options_included) {
            usSetOptions("us-store", "All stores", data.stores || [], "id", "store_name");
            usSetOptions("us-category", "All categories", data.categories || [], "", "");
            usOptionsLoaded = true;
        }
        const rows = Array.isArray(data.data) ? data.data : [];
        const meta = data.pagination || {};
        if (data.options_included) document.getElementById("us-store-count").textContent = String((data.stores || []).length);
        document.getElementById("us-product-count").textContent = String(Number(meta.total || 0));
        document.getElementById("us-available-count").textContent = String(rows.filter((row) => row.availability === "available").length);
        document.getElementById("us-context").textContent = `Showing ${rows.length} of ${Number(meta.total || 0)} matching products`;
        usRenderProducts(rows);
        usRenderPager(meta);
    } catch (error) {
        if (usPageSignal.aborted) return;
        if (requestId !== usRequestSequence) return;
        grid.innerHTML = usState("error", error.message || "Unable to load store products.");
        document.getElementById("us-context").textContent = "Products unavailable";
        document.getElementById("us-pager").innerHTML = "";
    }
}

document.querySelectorAll("#us-store, #us-category, #us-availability, #us-sort").forEach((control) => {
    control.addEventListener("change", () => { usPage = 1; usLoad(); });
});
document.getElementById("us-search").addEventListener("input", () => {
    clearTimeout(usSearchTimer);
    usUpdateResetFilters();
    usSearchTimer = setTimeout(() => { usPage = 1; usLoad(); }, 250);
});
document.getElementById("us-search").addEventListener("search", () => {
    clearTimeout(usSearchTimer);
    usPage = 1;
    usLoad();
});
document.getElementById("us-reset-filters").addEventListener("click", () => {
    ["us-search", "us-store", "us-category", "us-availability"].forEach((id) => { document.getElementById(id).value = ""; });
    document.getElementById("us-sort").value = "store:asc";
    usPage = 1;
    usUpdateResetFilters();
    usLoad();
    document.getElementById("us-search").focus();
});
document.getElementById("us-pager").addEventListener("click", (event) => {
    const button = event.target.closest("[data-page]");
    if (!button || button.disabled) return;
    usPage = Math.max(1, Number(button.dataset.page || 1));
    usLoad();
    document.querySelector(".page-header")?.scrollIntoView({ behavior: "smooth", block: "start" });
});
document.addEventListener("error", (event) => {
    const image = event.target;
    if (!(image instanceof HTMLImageElement) || !image.closest(".user-product-image-wrap")) return;
    image.closest(".user-product-image-wrap").outerHTML = '<span class="user-product-image-fallback"><i class="bi bi-box-seam"></i></span>';
}, { capture: true, signal: usPageSignal });

usLoad();
}());
