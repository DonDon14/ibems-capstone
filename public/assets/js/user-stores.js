(function () {
"use strict";

const usPageSignal = window.IbemsUserNavigation?.currentSignal || new AbortController().signal;
if (!document.getElementById("us-products")) return;

/* Keep the dialog outside the scrolling portal content so it owns the viewport. */
const usProductModalPortal = document.getElementById("us-product-modal");
if (usProductModalPortal) document.body.appendChild(usProductModalPortal);

let usPage = 1;
let usSearchTimer = null;
let usRequestSequence = 0;
let usOptionsLoaded = false;
let usRows = [];
let usActiveProduct = null;
let usActiveVariants = [];
let usActiveVariantIndex = 0;
let usProductModalTrigger = null;
let usProductModalTimer = null;
let usSwipeStartX = null;
let usSwipeLastX = null;
let usSwipeStartTime = 0;
let usSwipeMotionTimer = null;

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
            <article class="user-product-card" role="button" tabindex="0" aria-haspopup="dialog" aria-label="View details for ${usEscape(row.name || "product")}" data-product-id="${Number(row.id || 0)}">
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

function usVariantRows(row) {
    const variants = Array.isArray(row?.variants) && row.variants.length ? row.variants : [row];
    return variants.map((variant) => ({
        ...row,
        ...variant,
        variants,
        store_name: row.store_name,
        store_logo_url: row.store_logo_url,
        category: row.category,
        name: row.name,
    }));
}

function usFormatUpdated(value) {
    if (!value) return "Not available";
    const parsed = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(parsed.getTime())) return String(value);
    return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(parsed);
}

function usDetailImage(variant) {
    if (!variant.image_url) {
        return '<span class="user-product-detail-image-fallback"><i class="bi bi-box-seam" aria-hidden="true"></i><small>No product image</small></span>';
    }
    return `<span class="user-product-detail-image-wrap"><img src="${usEscape(variant.image_url)}" alt="${usEscape(`${variant.name || "Product"}${variant.variant_label ? ` - ${variant.variant_label}` : ""}`)}" draggable="false" data-user-detail-image></span>`;
}

function usRenderVariantDetail(direction = 0) {
    const variant = usActiveVariants[usActiveVariantIndex];
    if (!variant || !usActiveProduct) return;

    const available = variant.availability === "available";
    const multiple = usActiveVariants.length > 1;
    const image = document.getElementById("us-product-detail-image");
    const info = document.getElementById("us-product-detail-info");
    const rail = document.getElementById("us-product-variant-rail");
    const position = document.getElementById("us-product-variant-position");
    const previous = document.getElementById("us-product-previous");
    const next = document.getElementById("us-product-next");

    document.getElementById("us-product-modal-title").textContent = usActiveProduct.name || "Product";
    window.clearTimeout(usSwipeMotionTimer);
    document.getElementById("us-product-detail-media").classList.remove("is-dragging");
    image.classList.remove("is-snap-back", "is-swipe-commit");
    image.style.removeProperty("--user-product-drag-x");
    image.dataset.direction = direction < 0 ? "previous" : "next";
    image.innerHTML = usDetailImage(variant);
    image.classList.remove("is-changing");
    window.requestAnimationFrame(() => image.classList.add("is-changing"));

    info.innerHTML = `
        <div class="user-product-detail-store"><i class="bi bi-shop" aria-hidden="true"></i><span>${usEscape(variant.store_name || "Store")}</span></div>
        <div class="user-product-detail-heading">
            <div><span class="user-product-detail-kicker">${usEscape(variant.category || "Uncategorized")}</span><h3>${usEscape(variant.name || "Unnamed product")}</h3></div>
            <span class="user-availability ${available ? "is-available" : "is-out"}">${available ? "Available" : "Out of stock"}</span>
        </div>
        ${variant.variant_label ? `<span class="user-product-detail-variant">${usEscape(variant.variant_label)}</span>` : ""}
        <strong class="user-product-detail-price">${usEscape(usMoney(variant.price))}</strong>
        <dl class="user-product-detail-grid">
            <div><dt>SKU</dt><dd>${usEscape(variant.sku || "Not assigned")}</dd></div>
            <div><dt>Stock</dt><dd>${available ? `${Number(variant.stock_qty || 0)} available` : "Out of stock"}</dd></div>
            <div><dt>Barcode</dt><dd>${usEscape(variant.barcode || "Not assigned")}</dd></div>
            <div><dt>Supplier</dt><dd>${usEscape(variant.supplier || "Not specified")}</dd></div>
            <div><dt>Location</dt><dd>${usEscape(variant.location_bin || "Not specified")}</dd></div>
            <div><dt>Last updated</dt><dd>${usEscape(usFormatUpdated(variant.updated_at))}</dd></div>
        </dl>`;

    rail.innerHTML = usActiveVariants.map((item, index) => {
        const label = item.variant_label || item.sku || `Option ${index + 1}`;
        const isActive = index === usActiveVariantIndex;
        return `<button class="user-product-variant-tab${isActive ? " is-active" : ""}" type="button" role="tab" aria-selected="${isActive ? "true" : "false"}" tabindex="${isActive ? "0" : "-1"}" data-variant-index="${index}"><span>${usEscape(label)}</span><strong>${usEscape(usMoney(item.price))}</strong></button>`;
    }).join("");
    rail.classList.toggle("is-single", !multiple);
    rail.querySelector(".is-active")?.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "center" });
    position.textContent = multiple ? `${usActiveVariantIndex + 1} / ${usActiveVariants.length}` : "";
    previous.classList.toggle("is-hidden", !multiple);
    next.classList.toggle("is-hidden", !multiple);
    document.getElementById("us-product-swipe-hint").classList.toggle("is-hidden", !multiple);
}

function usShowVariant(index, direction = 0) {
    if (!usActiveVariants.length) return;
    const length = usActiveVariants.length;
    usActiveVariantIndex = ((Number(index) % length) + length) % length;
    usRenderVariantDetail(direction);
}

function usFinishSwipe(event, cancelled = false) {
    const media = event.currentTarget;
    const image = document.getElementById("us-product-detail-image");
    if (usSwipeStartX === null) return;

    media.releasePointerCapture?.(event.pointerId);
    media.classList.remove("is-dragging");
    const distance = (usSwipeLastX ?? event.clientX) - usSwipeStartX;
    const elapsed = Math.max(1, performance.now() - usSwipeStartTime);
    const threshold = Math.max(52, media.clientWidth * 0.12);
    const velocity = Math.abs(distance) / elapsed;
    const shouldChange = !cancelled && usActiveVariants.length > 1
        && (Math.abs(distance) >= threshold || (Math.abs(distance) >= 28 && velocity >= 0.45));

    usSwipeStartX = null;
    usSwipeLastX = null;
    usSwipeStartTime = 0;
    window.clearTimeout(usSwipeMotionTimer);

    if (!shouldChange) {
        image.classList.add("is-snap-back");
        image.style.setProperty("--user-product-drag-x", "0px");
        usSwipeMotionTimer = window.setTimeout(() => image.classList.remove("is-snap-back"), 210);
        return;
    }

    const direction = distance < 0 ? 1 : -1;
    const complete = () => {
        image.classList.remove("is-swipe-commit");
        image.style.removeProperty("--user-product-drag-x");
        usShowVariant(usActiveVariantIndex + direction, direction);
    };
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        complete();
        return;
    }

    image.classList.add("is-swipe-commit");
    image.style.setProperty("--user-product-drag-x", `${direction > 0 ? -media.clientWidth * 0.58 : media.clientWidth * 0.58}px`);
    usSwipeMotionTimer = window.setTimeout(complete, 150);
}

function usOpenProduct(row, trigger) {
    const modal = document.getElementById("us-product-modal");
    if (!modal || !row) return;
    window.clearTimeout(usProductModalTimer);
    usProductModalTrigger = trigger || document.activeElement;
    usActiveProduct = row;
    usActiveVariants = usVariantRows(row);
    usActiveVariantIndex = Math.max(0, usActiveVariants.findIndex((variant) => Number(variant.id) === Number(row.id)));
    usRenderVariantDetail(0);
    modal.classList.remove("is-hidden", "is-closing");
    document.body.classList.add("user-product-modal-open");
    window.requestAnimationFrame(() => modal.classList.add("is-open"));
    document.getElementById("us-product-modal-close")?.focus();
}

function usCloseProduct(immediate = false) {
    const modal = document.getElementById("us-product-modal");
    if (!modal || modal.classList.contains("is-hidden")) return;
    window.clearTimeout(usProductModalTimer);
    modal.classList.remove("is-open");
    modal.classList.add("is-closing");
    const finish = () => {
        modal.classList.add("is-hidden");
        modal.classList.remove("is-closing");
        document.body.classList.remove("user-product-modal-open");
        usProductModalTrigger?.focus?.();
        usProductModalTrigger = null;
    };
    if (immediate) finish();
    else usProductModalTimer = window.setTimeout(finish, 220);
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
        usRows = rows;
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
document.getElementById("us-products").addEventListener("click", (event) => {
    const card = event.target.closest("[data-product-id]");
    if (!card) return;
    const row = usRows.find((item) => Number(item.id) === Number(card.dataset.productId));
    if (row) usOpenProduct(row, card);
});
document.getElementById("us-products").addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    const card = event.target.closest("[data-product-id]");
    if (!card) return;
    event.preventDefault();
    const row = usRows.find((item) => Number(item.id) === Number(card.dataset.productId));
    if (row) usOpenProduct(row, card);
});
document.getElementById("us-product-modal-close").addEventListener("click", () => usCloseProduct());
document.getElementById("us-product-modal").addEventListener("click", (event) => {
    if (event.target.id === "us-product-modal") usCloseProduct();
});
document.getElementById("us-product-previous").addEventListener("click", () => usShowVariant(usActiveVariantIndex - 1, -1));
document.getElementById("us-product-next").addEventListener("click", () => usShowVariant(usActiveVariantIndex + 1, 1));
document.getElementById("us-product-variant-rail").addEventListener("click", (event) => {
    const tab = event.target.closest("[data-variant-index]");
    if (tab) usShowVariant(Number(tab.dataset.variantIndex), Number(tab.dataset.variantIndex) < usActiveVariantIndex ? -1 : 1);
});
document.getElementById("us-product-detail-media").addEventListener("pointerdown", (event) => {
    if (event.target.closest(".user-product-variant-arrow")) return;
    if (event.pointerType === "mouse" && event.button !== 0) return;
    if (usActiveVariants.length < 2) return;
    const image = document.getElementById("us-product-detail-image");
    window.clearTimeout(usSwipeMotionTimer);
    usSwipeStartX = event.clientX;
    usSwipeLastX = event.clientX;
    usSwipeStartTime = performance.now();
    event.currentTarget.classList.add("is-dragging");
    image.classList.remove("is-changing", "is-snap-back", "is-swipe-commit");
    image.style.setProperty("--user-product-drag-x", "0px");
    event.currentTarget.setPointerCapture?.(event.pointerId);
});
document.getElementById("us-product-detail-media").addEventListener("pointermove", (event) => {
    if (usSwipeStartX === null) return;
    const rawDistance = event.clientX - usSwipeStartX;
    const softLimit = event.currentTarget.clientWidth * 0.42;
    const distance = Math.abs(rawDistance) <= softLimit
        ? rawDistance
        : Math.sign(rawDistance) * (softLimit + (Math.abs(rawDistance) - softLimit) * 0.18);
    usSwipeLastX = event.clientX;
    document.getElementById("us-product-detail-image").style.setProperty("--user-product-drag-x", `${distance}px`);
    if (Math.abs(rawDistance) > 4) event.preventDefault();
});
document.getElementById("us-product-detail-media").addEventListener("pointerup", (event) => usFinishSwipe(event));
document.getElementById("us-product-detail-media").addEventListener("pointercancel", (event) => usFinishSwipe(event, true));
document.addEventListener("keydown", (event) => {
    const modal = document.getElementById("us-product-modal");
    if (!modal || modal.classList.contains("is-hidden")) return;
    if (event.key === "Escape") usCloseProduct();
    if (event.key === "ArrowLeft" && usActiveVariants.length > 1) usShowVariant(usActiveVariantIndex - 1, -1);
    if (event.key === "ArrowRight" && usActiveVariants.length > 1) usShowVariant(usActiveVariantIndex + 1, 1);
    if (event.key === "Tab") {
        const focusable = Array.from(modal.querySelectorAll('button:not([disabled]):not(.is-hidden), [href], input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'))
            .filter((element) => element.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (!first || !last) return;
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}, { signal: usPageSignal });
document.addEventListener("error", (event) => {
    const image = event.target;
    if (!(image instanceof HTMLImageElement)) return;
    const detailWrap = image.closest(".user-product-detail-image-wrap");
    if (detailWrap) {
        detailWrap.outerHTML = '<span class="user-product-detail-image-fallback"><i class="bi bi-box-seam" aria-hidden="true"></i><small>Image unavailable</small></span>';
        return;
    }
    const cardWrap = image.closest(".user-product-image-wrap");
    if (cardWrap) cardWrap.outerHTML = '<span class="user-product-image-fallback"><i class="bi bi-box-seam"></i></span>';
}, { capture: true, signal: usPageSignal });
usPageSignal.addEventListener("abort", () => {
    usCloseProduct(true);
    usProductModalPortal?.remove();
}, { once: true });

usLoad();
}());
