let invStores = [];
let invActiveStoreId = null;
let invProducts = [];
let invIsRestocking = false;
let invIsCreatingProduct = false;
let invIsUpdatingProduct = false;
let invModalProductId = null;
let invProductEditMode = false;
let invModalPanel = "adjust";
let invCreatePreviewObjectUrl = null;
let invModalPreviewObjectUrl = null;
let invCategories = [];
let invCreateSnapshot = null;
let invMovements = [];
let invVariantSequence = 0;
let invPage = 1;
let invBarcodeCamera = null;
let invBarcodeCameraTarget = null;
const invSelectedFamilyVariants = new Map();
const invExpandedFamilies = new Set();

function invProductFamilyKey(product) {
    const familyId = Number(product?.family_id || 0);
    if (familyId > 0) return `family-${familyId}`;
    const name = String(product?.name || "").trim().toLowerCase();
    const category = String(product?.category || "General").trim().toLowerCase();
    return name ? `legacy-${name}-${category}` : `product-${Number(product?.id || 0)}`;
}

function invGroupProducts(products) {
    const groups = new Map();
    products.forEach((product) => {
        const key = invProductFamilyKey(product);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(product);
    });
    return Array.from(groups, ([key, variants]) => ({key, variants}));
}

function invSkuToken(value, fallback = "ITEM") {
    return String(value || "").toUpperCase().replace(/[^A-Z0-9]+/g, "-").replace(/^-+|-+$/g, "") || fallback;
}
function invGeneratedSku(name, variant, position = 1) {
    const base = invSkuToken(name).split("-").slice(0, 2).join("-");
    return `${base}-${invSkuToken(variant, `V${position}`)}`.slice(0, 50);
}
function invSyncFirstVariantSku() {
    const sku = document.getElementById("new-product-sku");
    if (!sku || sku.dataset.manual === "true") return;
    sku.value = invGeneratedSku(invGetElementValue("new-product-name"), invGetElementValue("new-product-variant-label"), 1);
}
function invAddVariantRow() {
    const position = ++invVariantSequence + 1;
    const row = document.createElement("div");
    row.className = "variant-builder-row"; row.dataset.variantRow = String(position);
    row.innerHTML = `<label>Variant/Size<input data-v="label" placeholder="e.g. 1L"></label><label>SKU<input data-v="sku" placeholder="Generated"></label><label>Barcode<div class="barcode-entry-row"><input data-v="barcode" inputmode="numeric" placeholder="Optional"><button class="secondary-btn barcode-camera-btn" type="button" data-variant-camera title="Scan barcode with camera"><i class="bi bi-camera"></i></button></div></label><label>Unit Cost<input data-v="cost" type="number" min="0" step=".01" value="0"></label><label>Sell Price<input data-v="price" type="number" min="0" step=".01" value="0"></label><label>Stock<input data-v="stock" type="number" min="0" step="1" value="0"></label><label>Low Stock<input data-v="low" type="number" min="0" step="1" value="0"></label><button class="secondary-btn variant-remove" type="button" aria-label="Remove variant"><i class="bi bi-trash"></i></button><label class="variant-image-override">Variant Image Override<input data-v="image" type="file" accept="image/png,image/jpeg,image/webp,image/gif"></label>`;
    document.getElementById("new-product-variants").appendChild(row);
    const label = row.querySelector('[data-v="label"]'), sku = row.querySelector('[data-v="sku"]');
    label.addEventListener("input", () => { if (sku.dataset.manual !== "true") sku.value = invGeneratedSku(invGetElementValue("new-product-name"), label.value, position); invUpdateCreateProductProjection(); });
    sku.addEventListener("input", () => { sku.dataset.manual = "true"; invUpdateCreateProductProjection(); });
    row.querySelectorAll("input").forEach(input => input.addEventListener("input", invUpdateCreateProductProjection));
    row.querySelector(".variant-remove").addEventListener("click", () => { row.remove(); invUpdateVariantCount(); invUpdateCreateProductProjection(); });
    row.querySelector("[data-variant-camera]").addEventListener("click", () => invOpenBarcodeCamera(row.querySelector('[data-v="barcode"]')));
    invUpdateVariantCount();
    row.scrollIntoView({block:"nearest",behavior:"smooth"});
}
function invUpdateVariantCount() { const count=document.querySelectorAll("[data-variant-row]").length; const el=document.getElementById("product-variant-count"); if(el) el.textContent=`(${count})`; }

async function invCloseBarcodeCamera() {
    if (invBarcodeCamera) { try { await invBarcodeCamera.stop(); } catch (error) {} try { await invBarcodeCamera.clear(); } catch (error) {} }
    invBarcodeCamera = null; invBarcodeCameraTarget = null; document.getElementById("inventory-barcode-camera-modal").style.display = "none";
}
async function invOpenBarcodeCamera(target) {
    if (!window.Html5Qrcode) { invSetResult("Camera scanner could not load. Type the barcode manually or use a USB scanner.", "error"); return; }
    invBarcodeCameraTarget = target; document.getElementById("inventory-barcode-camera-modal").style.display = "grid";
    document.getElementById("inventory-barcode-camera-status").textContent = "Starting camera...";
    invBarcodeCamera = new Html5Qrcode("inventory-barcode-camera-reader");
    try { await invBarcodeCamera.start({facingMode:"environment"},{fps:10,qrbox:{width:280,height:140}},async code => { if(invBarcodeCameraTarget){invBarcodeCameraTarget.value=code;invBarcodeCameraTarget.dispatchEvent(new Event("input",{bubbles:true}));} await invCloseBarcodeCamera(); invSetResult(`Barcode captured: ${code}`,"ok"); },()=>{}); document.getElementById("inventory-barcode-camera-status").textContent="Camera ready. Center the barcode inside the frame."; }
    catch(error){ document.getElementById("inventory-barcode-camera-status").textContent="Camera unavailable. Allow camera access, type the barcode, or connect a USB scanner."; }
}
function invAdditionalVariants() {
    return Array.from(document.querySelectorAll("[data-variant-row]")).map(row => ({row,label:row.querySelector('[data-v="label"]').value.trim(),sku:row.querySelector('[data-v="sku"]').value.trim(),barcode:row.querySelector('[data-v="barcode"]').value.trim(),cost:Number(row.querySelector('[data-v="cost"]').value||0),price:Number(row.querySelector('[data-v="price"]').value||0),stock:Number(row.querySelector('[data-v="stock"]').value||0),low:Number(row.querySelector('[data-v="low"]').value||0),image:row.querySelector('[data-v="image"]').files[0]||null}));
}

function invGetElementValue(id, fallback = "") {
    const el = document.getElementById(id);
    return el ? String(el.value ?? "") : fallback;
}

function invEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function invMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function invInitials(value) {
    return String(value || "Product")
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join("")
        .toUpperCase() || "PR";
}

function invDataState(type, message, colspan = 0) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    const content = `<div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${invEscape(message)}</strong></div></div>`;
    return colspan > 0 ? `<tr class="data-state-row"><td colspan="${Number(colspan)}">${content}</td></tr>` : content;
}

function invSkuExists(sku, excludeProductId = 0) {
    const normalizedSku = String(sku || "").trim().toLowerCase();
    const excludedId = Number(excludeProductId || 0);
    if (normalizedSku === "") return false;

    return invProducts.some((product) => (
        Number(product.id || 0) !== excludedId
        && String(product.sku || "").trim().toLowerCase() === normalizedSku
    ));
}

function invBarcodeExists(barcode, excludeProductId = 0) {
    const normalizedBarcode = String(barcode || "").trim().toLowerCase();
    const excludedId = Number(excludeProductId || 0);
    if (normalizedBarcode === "") return false;

    return invProducts.some((product) => (
        Number(product.id || 0) !== excludedId
        && String(product.barcode || "").trim().toLowerCase() === normalizedBarcode
    ));
}

function invFormatDateTime(value) {
    return window.IbemsFormat?.shortDateTime(value) || value || "-";
}

function invStockState(product) {
    const qty = Number(product?.stock_qty || 0);
    const rawLowStock = product?.low_stock_threshold ?? product?.reorder_level ?? 10;
    const parsedLowStock = Number(rawLowStock);
    const lowStock = Number.isFinite(parsedLowStock) ? Math.max(0, parsedLowStock) : 10;

    if (qty <= 0) {
        return {
            key: "out",
            label: "Out of stock",
            detail: "Needs restock",
            qty,
        };
    }

    if (qty <= lowStock) {
        return {
            key: "low",
            label: "Low stock",
            detail: `At or below ${lowStock}`,
            qty,
        };
    }

    return {
        key: "in",
        label: "In stock",
        detail: "Ready for POS",
        qty,
    };
}

function invSetResult(message, type) {
    const el = document.getElementById("inventory-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function invEnsureCategoryOption(selectEl, categoryName) {
    if (!selectEl) return;
    const name = String(categoryName || "").trim();
    if (!name) return;
    const exists = Array.from(selectEl.options).some((opt) => String(opt.value) === name);
    if (!exists) {
        const option = document.createElement("option");
        option.value = name;
        option.textContent = name;
        selectEl.appendChild(option);
    }
}

function invRenderCategorySelects() {
    const createSelect = document.getElementById("new-product-category");
    const editSelect = document.getElementById("modal-product-category");
    const filterSelect = document.getElementById("inventory-category-filter");
    const names = invCategories.length > 0
        ? invCategories.map((row) => String(row.name || "").trim()).filter((name) => name !== "")
        : ["General"];

    [createSelect, editSelect].forEach((selectEl) => {
        if (!selectEl) return;
        const currentValue = String(selectEl.value || "");
        selectEl.innerHTML = names.map((name) => `<option value="${invEscape(name)}">${invEscape(name)}</option>`).join("");
        if (currentValue !== "") {
            invEnsureCategoryOption(selectEl, currentValue);
            selectEl.value = currentValue;
        } else {
            selectEl.value = "General";
        }
    });

    if (filterSelect) {
        const currentValue = String(filterSelect.value || "");
        filterSelect.innerHTML = `<option value="">All Categories</option>${names
            .map((name) => `<option value="${invEscape(name)}">${invEscape(name)}</option>`)
            .join("")}`;
        if (currentValue !== "" && names.includes(currentValue)) {
            filterSelect.value = currentValue;
        }
    }
}

function invRenderProductTable() {
    const body = document.getElementById("inventory-product-body");
    const search = (document.getElementById("inventory-search").value || "").trim().toLowerCase();
    const categoryFilter = (document.getElementById("inventory-category-filter").value || "").trim().toLowerCase();
    const stockFilter = (document.getElementById("inventory-stock-filter").value || "").trim();

    const contextualRows = invProducts.filter((product) => {
        const haystack = `${product.name || ""} ${product.variant_label || ""} ${product.sku || ""} ${product.barcode || ""} ${product.supplier || ""} ${product.location_bin || ""}`.toLowerCase();
        const category = String(product.category || "").trim().toLowerCase();

        if (search && !haystack.includes(search)) return false;
        if (categoryFilter && category !== categoryFilter) return false;
        return true;
    });

    const contextualGroups = invGroupProducts(contextualRows);
    const groups = stockFilter
        ? contextualGroups.filter((group) => {
            const states = group.variants.map((product) => invStockState(product).key);
            const familyState = states.includes("out") ? "out" : states.includes("low") ? "low" : "in";
            return familyState === stockFilter;
        })
        : contextualGroups;
    const [sortBy, sortDir] = (document.getElementById("inventory-sort").value || "name:asc").split(":");
    const direction = sortDir === "desc" ? -1 : 1;
    groups.sort((left, right) => {
        const firstLeft = left.variants[0] || {};
        const firstRight = right.variants[0] || {};
        let a;
        let b;
        if (sortBy === "stock") {
            a = left.variants.reduce((sum, row) => sum + Number(row.stock_qty || 0), 0);
            b = right.variants.reduce((sum, row) => sum + Number(row.stock_qty || 0), 0);
        } else if (sortBy === "price") {
            a = Math.min(...left.variants.map((row) => Number(row.price || 0)));
            b = Math.min(...right.variants.map((row) => Number(row.price || 0)));
        } else {
            a = String(firstLeft.name || "").toLowerCase();
            b = String(firstRight.name || "").toLowerCase();
        }
        return (typeof a === "number" ? a - b : a.localeCompare(b)) * direction;
    });
    const pageSize = Math.max(10, Number(document.getElementById("inventory-page-size").value || 25));
    const totalPages = Math.max(1, Math.ceil(groups.length / pageSize));
    invPage = Math.min(invPage, totalPages);
    const pageGroups = groups.slice((invPage - 1) * pageSize, invPage * pageSize);
    const allRows = groups.flatMap((group) => group.variants);
    const rows = pageGroups.flatMap((group) => group.variants);
    invRenderStockSummary(contextualGroups, stockFilter);
    invRenderResultsContext(groups, contextualGroups, search, categoryFilter, stockFilter);
    document.getElementById("inventory-pager").innerHTML = `
        <button class="secondary-btn btn-sm" type="button" data-page="${invPage - 1}" ${invPage <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left"></i> Previous</button>
        <span>Page ${invPage} of ${totalPages}</span>
        <button class="secondary-btn btn-sm" type="button" data-page="${invPage + 1}" ${invPage >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right"></i></button>`;

    if (allRows.length === 0) {
        body.innerHTML = invDataState("empty", "No products match these filters. Clear a filter or try another search.", 5);
        return;
    }

    body.innerHTML = pageGroups.map(({key, variants}) => {
        const product = variants[0];
        const stock = invStockState(product);
        const variant = product.variant_label ? `<span>${invEscape(product.variant_label)}</span>` : "";
        const sku = product.sku ? `<span>SKU ${invEscape(product.sku)}</span>` : "";
        const supplier = product.supplier ? `<span><i class="bi bi-truck"></i>${invEscape(product.supplier)}</span>` : "";
        const location = product.location_bin ? `<span><i class="bi bi-geo-alt"></i>${invEscape(product.location_bin)}</span>` : "";
        const operationalMeta = supplier || location
            ? `<div class="inventory-product-meta inventory-product-meta-secondary">${supplier}${location}</div>`
            : "";
        const thumb = product.image_url
            ? `<img src="${invEscape(product.image_url)}" alt="${invEscape(product.name)}" class="prod-thumb">`
            : `<div class="prod-thumb-fallback" aria-label="No product image">${invEscape(invInitials(product.name))}</div>`;
        const isFamily = variants.length > 1;
        const expanded = invExpandedFamilies.has(key);
        const totalStock = variants.reduce((sum, item) => sum + Number(item.stock_qty || 0), 0);
        const prices = variants.map((item) => Number(item.price || 0));
        const familyStockStates = variants.map((item) => invStockState(item));
        const familyState = familyStockStates.some((item) => item.key === "out") ? "out" : familyStockStates.some((item) => item.key === "low") ? "low" : "in";
        const familyStatus = familyState === "out" ? "Needs attention" : familyState === "low" ? "Some low stock" : "All in stock";
        const familyTitle = isFamily
            ? `<button class="inventory-family-name-toggle" type="button" data-family-toggle="${invEscape(key)}" aria-expanded="${expanded}" aria-label="${expanded ? "Hide" : "View"} ${invEscape(product.name || "product")} variants"><strong>${invEscape(product.name || "Unnamed product")}</strong><span class="inventory-family-count">${variants.length} variants</span><i class="bi bi-chevron-${expanded ? "up" : "down"}" aria-hidden="true"></i></button>`
            : `<strong>${invEscape(product.name || "Unnamed product")}</strong>`;
        const childRows = expanded ? variants.map((item) => {
            const itemStock = invStockState(item);
            return `<tr class="inventory-variant-row inventory-row-${itemStock.key}">
                <td><div class="inventory-variant-indent"><span class="inventory-variant-line"></span><div><strong>${invEscape(item.variant_label || "Default")}</strong><small>SKU ${invEscape(item.sku || "-")}${item.barcode ? ` · Barcode ${invEscape(item.barcode)}` : ""}</small></div></div></td>
                <td>${invEscape(item.category || "-")}</td>
                <td>${invEscape(invMoney(item.price))}</td>
                <td><div class="inv-stock-block"><span class="inv-stock-badge inv-stock-${itemStock.key}">${itemStock.label}</span><span class="inv-stock-detail">${itemStock.qty} item${itemStock.qty === 1 ? "" : "s"} - ${invEscape(itemStock.detail)}</span></div></td>
                <td><button class="secondary-btn btn-sm" type="button" data-product-action="${item.id}"><i class="bi bi-sliders"></i> Manage</button></td>
            </tr>`;
        }).join("") : "";

        return `${`<tr class="product-row inventory-family-row inventory-row-${isFamily ? familyState : stock.key}">
                <td>
                    <div class="inventory-product-cell">
                        ${thumb}
                        <div class="inventory-product-copy">
                            ${familyTitle}
                            <div class="inventory-product-meta">${isFamily ? `<span>${totalStock} total units</span><span>${invMoney(Math.min(...prices))} - ${invMoney(Math.max(...prices))}</span>` : `${sku}${variant}`}</div>
                            ${operationalMeta}
                        </div>
                    </div>
                </td>
                <td>${invEscape(product.category || "-")}</td>
                <td>${isFamily ? `${invEscape(invMoney(Math.min(...prices)))} - ${invEscape(invMoney(Math.max(...prices)))}` : invEscape(invMoney(product.price))}</td>
                <td>
                    <div class="inv-stock-block">
                        <span class="inv-stock-badge inv-stock-${isFamily ? familyState : stock.key}">${isFamily ? familyStatus : stock.label}</span>
                        <span class="inv-stock-detail">${isFamily ? `${totalStock} units across ${variants.length} variants` : `${stock.qty} item${stock.qty === 1 ? "" : "s"} - ${invEscape(stock.detail)}`}</span>
                    </div>
                </td>
                <td>
                    ${isFamily ? `<button class="secondary-btn btn-sm inventory-family-toggle" type="button" data-family-toggle="${invEscape(key)}" aria-expanded="${expanded}"><i class="bi bi-chevron-${expanded ? "up" : "down"}"></i> ${expanded ? "Hide" : "View"} variants</button>` : `<button class="secondary-btn btn-sm" type="button" data-product-action="${product.id}"><i class="bi bi-sliders"></i> Manage</button>`}
                </td>
            </tr>`}${childRows}`;
    }).join("");
}

function invRenderStockSummary(groups, selectedStock = "") {
    const summary = document.getElementById("inventory-stock-summary");
    if (!summary) return;

    const counts = groups.reduce((acc, group) => {
        const states = group.variants.map((product) => invStockState(product).key);
        acc.total += 1;
        acc.variants += group.variants.length;
        if (states.includes("out")) acc.out += 1;
        else if (states.includes("low")) acc.low += 1;
        else acc.in += 1;
        return acc;
    }, { total: 0, variants: 0, in: 0, low: 0, out: 0 });
    const plural = (count, singular, pluralWord = `${singular}s`) => count === 1 ? singular : pluralWord;

    summary.innerHTML = `
        <button class="inventory-summary-pill ${selectedStock === "" ? "is-active" : ""}" type="button" data-stock-summary="" aria-pressed="${selectedStock === ""}">
            <span>All Families</span>
            <strong>${counts.total}</strong>
            <small>${counts.variants} ${plural(counts.variants, "variant")}</small>
        </button>
        <button class="inventory-summary-pill inventory-summary-in ${selectedStock === "in" ? "is-active" : ""}" type="button" data-stock-summary="in" aria-pressed="${selectedStock === "in"}">
            <span>In Stock</span>
            <strong>${counts.in}</strong>
            <small>${plural(counts.in, "family", "families")}</small>
        </button>
        <button class="inventory-summary-pill inventory-summary-low ${selectedStock === "low" ? "is-active" : ""}" type="button" data-stock-summary="low" aria-pressed="${selectedStock === "low"}">
            <span>Low Stock</span>
            <strong>${counts.low}</strong>
            <small>${plural(counts.low, "family", "families")}</small>
        </button>
        <button class="inventory-summary-pill inventory-summary-out ${selectedStock === "out" ? "is-active" : ""}" type="button" data-stock-summary="out" aria-pressed="${selectedStock === "out"}">
            <span>Out of Stock</span>
            <strong>${counts.out}</strong>
            <small>${plural(counts.out, "family", "families")}</small>
        </button>
    `;
}

function invRenderResultsContext(groups, contextualGroups, search, categoryFilter, stockFilter) {
    const count = document.getElementById("inventory-results-count");
    const toggle = document.getElementById("inventory-toggle-families");
    const clear = document.getElementById("inventory-clear-filters");
    const activeFilters = [
        search ? `Search: “${search}”` : "",
        categoryFilter ? `Category: ${categoryFilter}` : "",
        stockFilter ? `Stock: ${stockFilter === "in" ? "In Stock" : stockFilter === "low" ? "Low Stock" : "Out of Stock"}` : "",
    ].filter(Boolean);
    const totalFamilies = invGroupProducts(invProducts).length;
    const familyWord = totalFamilies === 1 ? "family" : "families";
    count.textContent = `Showing ${groups.length} of ${totalFamilies} ${familyWord}${activeFilters.length ? ` · ${activeFilters.join(" · ")}` : ""}`;
    const hasNonDefaultControls = activeFilters.length > 0
        || document.getElementById("inventory-sort").value !== "name:asc"
        || document.getElementById("inventory-page-size").value !== "25";
    clear.classList.toggle("is-hidden", !hasNonDefaultControls);

    const multiVariantKeys = groups.filter((group) => group.variants.length > 1).map((group) => group.key);
    const allExpanded = multiVariantKeys.length > 0 && multiVariantKeys.every((key) => invExpandedFamilies.has(key));
    toggle.classList.toggle("is-hidden", multiVariantKeys.length === 0);
    toggle.dataset.familyKeys = JSON.stringify(multiVariantKeys);
    toggle.dataset.expand = allExpanded ? "false" : "true";
    toggle.innerHTML = allExpanded
        ? '<i class="bi bi-arrows-collapse"></i> Collapse all variants'
        : '<i class="bi bi-arrows-expand"></i> Expand all variants';
}

function invMovementMeta(type) {
    if (type === "restock") {
        return { label: "Stock In", className: "movement-restock", sign: "+" };
    }
    if (type === "sale") {
        return { label: "Sale", className: "movement-sale", sign: "" };
    }
    return { label: "Adjustment", className: "movement-adjustment", sign: "" };
}

function invRenderMovements() {
    const list = document.getElementById("inventory-movement-list");
    if (!list) return;

    if (invMovements.length === 0) {
        list.innerHTML = invDataState("empty", "No stock activity recorded yet.");
        return;
    }

    list.innerHTML = invMovements.map((movement) => {
        const meta = invMovementMeta(String(movement.type || ""));
        const qty = Number(movement.qty || 0);
        const qtyText = qty > 0 && meta.sign ? `${meta.sign}${qty}` : String(qty);
        const product = movement.product || {};
        const cost = movement.total_cost !== null && movement.total_cost !== undefined
            ? `<span>Total Cost ${invEscape(invMoney(movement.total_cost))}</span>`
            : "";
        const reason = movement.reason ? `<span>${invEscape(movement.reason)}</span>` : "";

        return `
            <div class="inventory-movement-item">
                <div class="inventory-movement-type ${meta.className}">
                    <strong>${qtyText}</strong>
                    <span>${meta.label}</span>
                </div>
                <div class="inventory-movement-copy">
                    <strong>${invEscape(product.name || "Unknown product")}</strong>
                    <div class="inventory-movement-meta">
                        <span>${invEscape(product.sku || "No SKU")}</span>
                        <span>${invEscape(invFormatDateTime(movement.created_at))}</span>
                        ${reason}
                        ${cost}
                    </div>
                </div>
            </div>
        `;
    }).join("");
}

function invGetProductById(productId) {
    return invProducts.find((product) => Number(product.id) === Number(productId)) || null;
}

function invOpenProductActionModal(productId) {
    const product = invGetProductById(productId);
    if (!product) return;

    invModalProductId = Number(product.id);
    const displayName = product.variant_label ? `${product.name} (${product.variant_label})` : product.name;
    const stock = invStockState(product);
    const productThumb = product.image_url
        ? `<img src="${invEscape(product.image_url)}" alt="${invEscape(displayName)}" class="product-action-thumb">`
        : `<div class="product-action-thumb product-action-thumb-fallback" role="img" aria-label="No product image">${invEscape(invInitials(product.name))}</div>`;
    document.getElementById("product-action-info").innerHTML = `
        <div class="product-action-main">
            <div class="product-action-identity">
                ${productThumb}
                <div>
                    <span class="product-action-label">Selected Product</span>
                    <strong>${invEscape(displayName)}</strong>
                    <div class="product-action-meta">
                        <span>SKU ${invEscape(product.sku || "-")}</span>
                        <span>${invEscape(product.category || "Uncategorized")}</span>
                    </div>
                </div>
            </div>
            <span class="inv-stock-badge inv-stock-${stock.key}">${stock.label}</span>
        </div>
        <div class="product-action-stats">
            <div>
                <span>Current Stock</span>
                <strong>${stock.qty}</strong>
            </div>
            <div>
                <span>Price</span>
                <strong>${invEscape(invMoney(product.price))}</strong>
            </div>
            <div>
                <span>POS Availability</span>
                <strong>${invEscape(stock.detail)}</strong>
            </div>
        </div>
    `;
    document.getElementById("modal-product-sku").value = product.sku || "";
    document.getElementById("modal-product-name").value = product.name || "";
    document.getElementById("modal-product-variant-label").value = product.variant_label || "";
    invEnsureCategoryOption(document.getElementById("modal-product-category"), product.category || "General");
    document.getElementById("modal-product-category").value = product.category || "";
    document.getElementById("modal-product-supplier").value = product.supplier || "";
    document.getElementById("modal-product-location").value = product.location_bin || "";
    document.getElementById("modal-product-price").value = Number(product.price || 0).toFixed(2);
    document.getElementById("modal-product-low-stock").value = String(Number(product.low_stock_threshold ?? product.reorder_level ?? 10));
    document.getElementById("modal-product-barcode").value = product.barcode || "";
    document.getElementById("modal-product-image-url").value = product.image_url || "";
    document.getElementById("modal-product-image-file").value = "";
    document.getElementById("modal-product-image-source").value = "upload";
    invToggleModalProductImageInput();
    invRenderModalProductCurrentImage(product.image_url || "");
    invUpdateModalProductImagePreview();
    document.getElementById("product-view-variant").textContent = product.variant_label || "-";
    document.getElementById("product-view-supplier").textContent = product.supplier || "-";
    document.getElementById("product-view-location").textContent = product.location_bin || "-";
    document.getElementById("product-view-barcode").textContent = product.barcode || "-";
    document.getElementById("product-view-low-stock").textContent = String(Number(product.low_stock_threshold ?? product.reorder_level ?? 10));
    document.getElementById("modal-actual-stock").value = Number(product.stock_qty || 0);
    document.getElementById("modal-stock-reason").value = "Physical count adjustment";
    document.getElementById("modal-restock-qty").value = "1";
    document.getElementById("modal-restock-unit-cost").value = "0";
    document.getElementById("modal-restock-sell-price").value = Number(product.price || 0).toFixed(2);
    document.getElementById("modal-restock-reason").value = "Stock in";
    invUpdateModalRestockProjection();
    invUpdateAdjustmentPreview();
    invSetProductEditMode(false);
    invSetModalPanel("adjust");
    document.getElementById("inventory-product-action-modal").style.display = "grid";
}

function invCloseProductActionModal() {
    invModalProductId = null;
    invSetProductEditMode(false);
    if (invModalPreviewObjectUrl) {
        URL.revokeObjectURL(invModalPreviewObjectUrl);
        invModalPreviewObjectUrl = null;
    }
    document.getElementById("inventory-product-action-modal").style.display = "none";
}

function invSetProductEditMode(enabled) {
    invProductEditMode = !!enabled;
    document.getElementById("product-edit-wrap").style.display = invProductEditMode ? "block" : "none";
    document.getElementById("modal-start-edit-product").style.display = invProductEditMode ? "none" : "inline-flex";
    document.getElementById("modal-cancel-edit-product").style.display = invProductEditMode ? "inline-flex" : "none";
    document.getElementById("modal-save-product").style.display = invProductEditMode ? "inline-flex" : "none";
}

function invSetModalPanel(panel) {
    invModalPanel = panel === "restock" ? "restock" : "adjust";
    const isAdjust = invModalPanel === "adjust";
    document.getElementById("modal-adjust-panel").style.display = isAdjust ? "block" : "none";
    document.getElementById("modal-restock-panel").style.display = isAdjust ? "none" : "block";
    document.getElementById("modal-panel-adjust-btn").classList.toggle("is-active", isAdjust);
    document.getElementById("modal-panel-restock-btn").classList.toggle("is-active", !isAdjust);
    document.getElementById("modal-panel-adjust-btn").setAttribute("aria-selected", isAdjust ? "true" : "false");
    document.getElementById("modal-panel-restock-btn").setAttribute("aria-selected", isAdjust ? "false" : "true");
    document.getElementById("modal-adjust-panel").setAttribute("aria-hidden", isAdjust ? "false" : "true");
    document.getElementById("modal-restock-panel").setAttribute("aria-hidden", isAdjust ? "true" : "false");
    document.getElementById("modal-save-adjustment").style.display = isAdjust ? "inline-flex" : "none";
    document.getElementById("modal-restock-submit").style.display = isAdjust ? "none" : "inline-flex";
}

function invUpdateModalRestockProjection() {
    const product = invGetProductById(invModalProductId);
    const qty = Number(document.getElementById("modal-restock-qty").value || 0);
    const unitCost = Number(document.getElementById("modal-restock-unit-cost").value || 0);
    const sellInput = Number(document.getElementById("modal-restock-sell-price").value || 0);
    const sellPrice = sellInput > 0 ? sellInput : Number(product?.price || 0);

    const totalCost = qty * unitCost;
    const profitPerPiece = sellPrice - unitCost;
    const expectedProfit = qty * profitPerPiece;

    document.getElementById("modal-proj-price").textContent = invMoney(sellPrice);
    document.getElementById("modal-proj-profit-piece").textContent = invMoney(profitPerPiece);
    document.getElementById("modal-proj-cost").textContent = invMoney(totalCost);
    document.getElementById("modal-proj-profit").textContent = invMoney(expectedProfit);
}

function invUpdateAdjustmentPreview() {
    const product = invGetProductById(invModalProductId);
    const currentQty = Number(product?.stock_qty || 0);
    const targetQty = Number(document.getElementById("modal-actual-stock").value || 0);
    const diff = targetQty - currentQty;
    const saveButton = document.getElementById("modal-save-adjustment");
    const diffEl = document.getElementById("modal-adjust-diff");
    const hintEl = document.getElementById("modal-adjust-hint");

    document.getElementById("modal-adjust-current").textContent = String(currentQty);
    document.getElementById("modal-adjust-target").textContent = Number.isFinite(targetQty) ? String(targetQty) : "Invalid";
    diffEl.classList.remove("is-positive", "is-negative", "is-neutral");

    if (!Number.isInteger(targetQty) || targetQty < 0) {
        diffEl.textContent = "Invalid";
        diffEl.classList.add("is-negative");
        saveButton.disabled = true;
        saveButton.title = "Enter a valid whole-number stock count.";
        if (hintEl) hintEl.textContent = "Enter a valid whole-number stock count to continue.";
        return;
    }

    if (diff === 0) {
        diffEl.textContent = "No change";
        diffEl.classList.add("is-neutral");
        saveButton.disabled = true;
        saveButton.title = "No stock change to save.";
        if (hintEl) hintEl.textContent = "No change detected. Enter a different stock count to enable saving.";
        return;
    }

    diffEl.textContent = `${diff > 0 ? "+" : ""}${diff}`;
    diffEl.classList.add(diff > 0 ? "is-positive" : "is-negative");
    saveButton.disabled = false;
    saveButton.removeAttribute("title");
    if (hintEl) hintEl.textContent = `This will record a ${diff > 0 ? "+" : ""}${diff} stock adjustment.`;
}

async function invLoadStores() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();

    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }

    invStores = data.stores;
    invActiveStoreId = Number(data.default_store_id || invStores[0].id);
}

async function invLoadProducts() {
    const body = document.getElementById("inventory-product-body");
    const summary = document.getElementById("inventory-stock-summary");
    body.innerHTML = invDataState("loading", "Loading products...", 5);
    summary.innerHTML = invDataState("loading", "Loading stock summary...");

    let data;
    try {
        const response = await fetch(`/store/products?store_id=${invActiveStoreId}`);
        data = await response.json();
    } catch (error) {
        const message = error?.message || "Unable to load products.";
        body.innerHTML = invDataState("error", message, 5);
        summary.innerHTML = invDataState("error", message);
        throw new Error(message);
    }

    if (!data || data.status !== "success") {
        invProducts = [];
        const message = data?.message || "Unable to load products.";
        body.innerHTML = invDataState("error", message, 5);
        summary.innerHTML = invDataState("error", message);
        throw new Error(message);
    }

    invProducts = Array.isArray(data.products) ? data.products : [];
    invRenderProductTable();
}

async function invLoadCategories() {
    const response = await fetch(`/store/categories?store_id=${invActiveStoreId}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Unable to load categories.");
    }

    invCategories = Array.isArray(data.categories) ? data.categories : [];
    invRenderCategorySelects();
}

async function invLoadMovements() {
    if (!invActiveStoreId) return;

    const movementType = encodeURIComponent(invGetElementValue("inventory-movement-type").trim());
    const typeQuery = movementType ? `&type=${movementType}` : "";
    const list = document.getElementById("inventory-movement-list");
    list.innerHTML = invDataState("loading", "Loading stock activity...");

    let data;
    try {
        const response = await fetch(`/store/inventory/movements?store_id=${invActiveStoreId}&limit=8${typeQuery}`);
        data = await response.json();
    } catch (error) {
        const message = error?.message || "Unable to load stock activity.";
        list.innerHTML = invDataState("error", message);
        throw new Error(message);
    }

    if (!data || data.status !== "success") {
        invMovements = [];
        const message = data?.message || "Unable to load stock activity.";
        list.innerHTML = invDataState("error", message);
        throw new Error(message);
    }

    invMovements = Array.isArray(data.movements) ? data.movements : [];
    invRenderMovements();
}

async function invSubmitModalRestock() {
    if (invIsRestocking) return;

    const productId = Number(invModalProductId || 0);
    const qty = Number(document.getElementById("modal-restock-qty").value || 0);
    const unitCost = Number(document.getElementById("modal-restock-unit-cost").value || 0);
    const sellPrice = Number(document.getElementById("modal-restock-sell-price").value || 0);
    const reason = (document.getElementById("modal-restock-reason").value || "Stock in").trim();
    const button = document.getElementById("modal-restock-submit");

    if (!invActiveStoreId || productId <= 0 || qty <= 0 || unitCost < 0 || sellPrice < 0) {
        invSetResult("Please complete a valid stock-in form.", "error");
        return;
    }

    const payload = {
        store_id: invActiveStoreId,
        product_id: productId,
        qty,
        unit_cost: unitCost,
        sell_price: sellPrice,
        reason,
    };

    try {
        invIsRestocking = true;
        button.disabled = true;
        button.textContent = "Submitting...";

        const response = await fetch("/store/inventory/restock", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
        const data = await response.json();

        if (!data || data.status !== "success") {
            invSetResult(data?.message || "Stock-in failed.", "error");
            return;
        }

        invSetResult(
            `Stock-in successful. Total Cost: ${invMoney(data.total_cost)} | Profit/Piece: ${invMoney(data.profit_per_piece)} | Expected Profit: ${invMoney(data.expected_profit)}`,
            "ok"
        );
        await invLoadProducts();
        await invLoadMovements();
        invOpenProductActionModal(productId);
    } catch (error) {
        invSetResult("Stock-in request failed.", "error");
    } finally {
        invIsRestocking = false;
        button.disabled = false;
        button.textContent = "Submit Stock In";
    }
}

function invToggleProductImageInput() {
    const source = document.getElementById("new-product-image-source").value;
    document.getElementById("new-product-image-upload-wrap").style.display = source === "upload" ? "flex" : "none";
    document.getElementById("new-product-image-url-wrap").style.display = source === "url" ? "flex" : "none";
    invUpdateCreateProductImagePreview();
}

function invToggleModalProductImageInput() {
    const source = document.getElementById("modal-product-image-source").value;
    document.getElementById("modal-product-image-upload-wrap").style.display = source === "upload" ? "flex" : "none";
    document.getElementById("modal-product-image-url-wrap").style.display = source === "url" ? "flex" : "none";
    invUpdateModalProductImagePreview();
}

function invRenderModalProductCurrentImage(imageUrl) {
    const img = document.getElementById("modal-product-current-image");
    const empty = document.getElementById("modal-product-current-image-empty");
    const resolved = String(imageUrl || "").trim();
    if (!img || !empty) return;

    if (resolved !== "") {
        img.src = resolved;
        img.style.display = "block";
        empty.style.display = "none";
    } else {
        img.removeAttribute("src");
        img.style.display = "none";
        empty.style.display = "block";
    }
}

function invUpdateModalProductImagePreview() {
    const source = (document.getElementById("modal-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("modal-product-image-url").value || "").trim();
    const imageFile = document.getElementById("modal-product-image-file").files[0] || null;
    const preview = document.getElementById("modal-product-new-image");
    const empty = document.getElementById("modal-product-new-image-empty");
    if (!preview || !empty) return;

    if (invModalPreviewObjectUrl) {
        URL.revokeObjectURL(invModalPreviewObjectUrl);
        invModalPreviewObjectUrl = null;
    }

    let resolved = "";
    if (source === "upload" && imageFile) {
        invModalPreviewObjectUrl = URL.createObjectURL(imageFile);
        resolved = invModalPreviewObjectUrl;
    } else if (source === "url" && imageUrl !== "") {
        resolved = imageUrl;
    }

    if (resolved !== "") {
        preview.src = resolved;
        preview.style.display = "block";
        empty.style.display = "none";
    } else {
        preview.removeAttribute("src");
        preview.style.display = "none";
        empty.style.display = "block";
    }
}

function invUpdateCreateProductProjection() {
    const sku = invGetElementValue("new-product-sku").trim();
    const name = invGetElementValue("new-product-name").trim();
    const imageSource = invGetElementValue("new-product-image-source", "upload").trim();
    const imageUrl = invGetElementValue("new-product-image-url").trim();
    const imageFile = document.getElementById("new-product-image-file").files[0] || null;
    const barcode = invGetElementValue("new-product-barcode").trim();
    const initialStock = Number(document.getElementById("new-product-initial-stock").value || 0);
    const unitCost = Number(document.getElementById("new-product-unit-cost").value || 0);
    const sellPrice = Number(document.getElementById("new-product-sell-price").value || 0);
    const lowStock = Number(document.getElementById("new-product-low-stock").value || 0);

    const markup = unitCost > 0 ? ((sellPrice - unitCost) / unitCost) * 100 : 0;
    const stockValue = Math.max(0, initialStock) * Math.max(0, unitCost);
    const skuExists = invSkuExists(sku);
    const barcodeExists = invBarcodeExists(barcode);

    document.getElementById("new-product-markup").textContent = `Markup: ${markup.toFixed(2)}%`;
    document.getElementById("new-product-stock-value").textContent = `Value: ${invMoney(stockValue)}`;
    invRenderCreateReadiness([
        {
            ok: sku !== "" && name !== "" && !skuExists,
            ready: "Product name and SKU are ready.",
            pending: skuExists ? "Use a unique SKU." : "Add product name and SKU.",
        },
        {
            ok: sellPrice >= 0 && unitCost >= 0 && initialStock >= 0 && Number.isInteger(initialStock) && lowStock >= 0 && Number.isInteger(lowStock),
            ready: "Pricing, threshold, and stock values are valid.",
            pending: "Use valid price, cost, threshold, and whole-number stock values.",
        },
        {
            ok: imageSource === "url" ? imageUrl !== "" : !!imageFile,
            ready: "Product image is selected.",
            pending: "Add an image upload or URL.",
        },
        {
            ok: !barcodeExists,
            ready: barcode === "" ? "Barcode is optional." : "Barcode is unique.",
            pending: "Use a unique barcode or leave it blank.",
        },
        {
            ok: initialStock <= 0 || invGetElementValue("new-product-reason", "Initial stock").trim() !== "",
            ready: "Initial stock reason is recorded.",
            pending: "Add an initial stock reason.",
        },
    ]);
}

function invRenderCreateReadiness(items) {
    const wrap = document.getElementById("new-product-readiness");
    const submitButton = document.getElementById("new-product-submit");
    if (!wrap) return;

    const isReady = items.every((item) => item.ok);
    if (submitButton) {
        submitButton.disabled = !isReady || invIsCreatingProduct;
    }

    wrap.innerHTML = items.map((item) => `
        <div class="readiness-item ${item.ok ? "is-ready" : "is-pending"}">
            <span>${item.ok ? "OK" : "!"}</span>
            <strong>${invEscape(item.ok ? item.ready : item.pending)}</strong>
        </div>
    `).join("");
}

function invUpdateCreateProductImagePreview() {
    const source = (document.getElementById("new-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("new-product-image-url").value || "").trim();
    const imageFile = document.getElementById("new-product-image-file").files[0] || null;
    const preview = document.getElementById("new-product-image-preview");
    const empty = document.getElementById("new-product-image-preview-empty");

    if (invCreatePreviewObjectUrl) {
        URL.revokeObjectURL(invCreatePreviewObjectUrl);
        invCreatePreviewObjectUrl = null;
    }

    let resolved = "";
    if (source === "upload" && imageFile) {
        invCreatePreviewObjectUrl = URL.createObjectURL(imageFile);
        resolved = invCreatePreviewObjectUrl;
    } else if (source === "url" && imageUrl !== "") {
        resolved = imageUrl;
    }

    if (resolved !== "") {
        preview.src = resolved;
        preview.style.display = "block";
        empty.style.display = "none";
    } else {
        preview.removeAttribute("src");
        preview.style.display = "none";
        empty.style.display = "block";
    }
    invUpdateCreateProductProjection();
}

function invCaptureCreateFormState() {
    const fileInput = document.getElementById("new-product-image-file");
    const imageFile = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

    return {
        sku: invGetElementValue("new-product-sku").trim(),
        name: invGetElementValue("new-product-name").trim(),
        variantLabel: invGetElementValue("new-product-variant-label").trim(),
        category: invGetElementValue("new-product-category", "General").trim(),
        supplier: invGetElementValue("new-product-supplier").trim(),
        barcode: invGetElementValue("new-product-barcode").trim(),
        imageSource: invGetElementValue("new-product-image-source", "upload").trim(),
        imageUrl: invGetElementValue("new-product-image-url").trim(),
        sellPrice: invGetElementValue("new-product-sell-price", "0").trim(),
        initialStock: invGetElementValue("new-product-initial-stock", "0").trim(),
        unitCost: invGetElementValue("new-product-unit-cost", "0").trim(),
        location: invGetElementValue("new-product-location").trim(),
        lowStock: invGetElementValue("new-product-low-stock", "0").trim(),
        reason: invGetElementValue("new-product-reason", "Initial stock").trim(),
        imageFileName: imageFile ? imageFile.name : "",
        imageFileSize: imageFile ? Number(imageFile.size || 0) : 0,
        imageMode: invGetElementValue("new-product-image-mode", "shared"),
        variants: invAdditionalVariants().map(({row, image, ...variant}) => ({...variant, imageName:image?.name || "", imageSize:Number(image?.size || 0)})),
    };
}

function invIsCreateFormDirty() {
    if (!invCreateSnapshot) return false;
    const current = invCaptureCreateFormState();
    return JSON.stringify(current) !== JSON.stringify(invCreateSnapshot);
}

async function invRequestCloseProductModal() {
    if (invIsCreatingProduct) return;

    if (invIsCreateFormDirty()) {
        const confirmed = await window.IbemsDialog.confirm("Your unsaved product details will be lost.", {
            title: "Discard product changes?",
            confirmLabel: "Discard changes",
            cancelLabel: "Continue editing",
            tone: "danger",
        });
        if (!confirmed) return;
    }

    invCloseProductModal();
}

async function invCreateProduct() {
    if (invIsCreatingProduct) return;

    const sku = (document.getElementById("new-product-sku").value || "").trim();
    const name = (document.getElementById("new-product-name").value || "").trim();
    const variantLabel = (document.getElementById("new-product-variant-label").value || "").trim();
    const category = (document.getElementById("new-product-category").value || "General").trim() || "General";
    const supplier = (document.getElementById("new-product-supplier").value || "").trim();
    const barcode = (document.getElementById("new-product-barcode").value || "").trim();
    const imageSource = (document.getElementById("new-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("new-product-image-url").value || "").trim();
    const imageFile = document.getElementById("new-product-image-file").files[0] || null;
    const sellPrice = Number(document.getElementById("new-product-sell-price").value || 0);
    const initialStock = Number(document.getElementById("new-product-initial-stock").value || 0);
    const unitCost = Number(document.getElementById("new-product-unit-cost").value || 0);
    const lowStock = Number(document.getElementById("new-product-low-stock").value || 10);
    const locationBin = (document.getElementById("new-product-location").value || "").trim();
    const reason = (invGetElementValue("new-product-reason", "Initial stock") || "Initial stock").trim();
    const button = document.getElementById("new-product-submit");
    const additionalVariants = invAdditionalVariants();

    if (!invActiveStoreId || !sku || !name || sellPrice < 0 || initialStock < 0 || unitCost < 0 || lowStock < 0) {
        invSetResult("Please complete valid product details.", "error");
        return;
    }
    if (imageSource === "url" && imageUrl === "") {
        invSetResult("Please provide an image URL or switch to upload.", "error");
        return;
    }
    if (imageSource === "upload" && !imageFile) {
        invSetResult("Please upload an image file or switch to URL.", "error");
        return;
    }

    const familySkus = [sku, ...additionalVariants.map(v => v.sku)].map(v => v.toLowerCase());
    const familyBarcodes = [barcode, ...additionalVariants.map(v => v.barcode)].filter(Boolean).map(v => v.toLowerCase());
    if (additionalVariants.some(v => !v.label || !v.sku || v.cost < 0 || v.price < 0 || v.stock < 0 || v.low < 0)) {
        invSetResult("Complete every variant with valid size, SKU, pricing, and stock.", "error"); return;
    }
    if (new Set(familySkus).size !== familySkus.length || new Set(familyBarcodes).size !== familyBarcodes.length) {
        invSetResult("Each variant must have a unique SKU and barcode.", "error"); return;
    }

    try {
        invIsCreatingProduct = true;
        button.disabled = true;
        button.textContent = "Creating...";

        const formData = new FormData();
        formData.append("store_id", String(invActiveStoreId));
        formData.append("name", name);
        formData.append("category", category);
        formData.append("supplier", supplier);
        formData.append("location_bin", locationBin);
        formData.append("reason", reason);
        formData.append("variants", JSON.stringify([
            {sku, label:variantLabel || "Default", barcode, price:sellPrice, stock:initialStock, cost:unitCost, low:lowStock},
            ...additionalVariants.map(({row,image,...variant}) => variant),
        ]));
        if (imageSource === "url") {
            formData.append("image_url", imageUrl);
        } else if (imageFile) {
            formData.append("image_file", imageFile);
        }
        if (document.getElementById("new-product-image-mode").value === "per_variant") {
            additionalVariants.forEach((variant, index) => { if (variant.image) formData.append(`variant_image_${index + 1}`, variant.image); });
        }

        const response = await fetch("/store/inventory/add-product-family", {
            method: "POST",
            body: formData,
        });
        const data = await response.json();

        if (!data || data.status !== "success") {
            invSetResult(data?.message || "Product creation failed.", "error");
            return;
        }

        invSetResult(`${data.variant_count} variant${data.variant_count === 1 ? "" : "s"} created atomically for ${name}.`, "ok");
        invCloseProductModal();
        await invLoadCategories();
        await invLoadProducts();
        await invLoadMovements();
    } catch (error) {
        invSetResult(error.message || "Product creation request failed.", "error");
    } finally {
        invIsCreatingProduct = false;
        button.textContent = "Create Product";
        invUpdateCreateProductProjection();
    }
}

async function invAdjustStock(productId) {
    const actualQty = Number(document.getElementById("modal-actual-stock").value || 0);
    const reason = (document.getElementById("modal-stock-reason").value || "Physical count adjustment").trim();
    const button = document.getElementById("modal-save-adjustment");

    if (!Number.isInteger(actualQty) || actualQty < 0) {
        invSetResult("Actual stock must be 0 or higher.", "error");
        return;
    }
    const product = invGetProductById(productId);
    if (product && actualQty === Number(product.stock_qty || 0)) {
        invSetResult("No stock change needed for this product.", "ok");
        invUpdateAdjustmentPreview();
        return;
    }

    button.disabled = true;
    button.textContent = "Saving...";

    try {
        const response = await fetch("/store/inventory/adjust-stock", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                store_id: invActiveStoreId,
                product_id: Number(productId),
                actual_qty: actualQty,
                reason,
            }),
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            invSetResult(data?.message || "Stock adjustment failed.", "error");
            return;
        }

        const diff = Number(data.diff_qty || 0);
        if (diff === 0) {
            invSetResult("No stock change needed for this product.", "ok");
        } else {
            invSetResult(`Stock updated. Previous: ${data.previous_qty}, New: ${data.actual_qty}, Difference: ${diff > 0 ? "+" : ""}${diff}`, "ok");
        }
        invCloseProductActionModal();
        await invLoadProducts();
        await invLoadMovements();
    } catch (error) {
        invSetResult("Stock adjustment request failed.", "error");
    } finally {
        button.disabled = false;
        button.textContent = "Save Adjustment";
    }
}

async function invUpdateProduct(productId) {
    if (invIsUpdatingProduct) return;

    const sku = (document.getElementById("modal-product-sku").value || "").trim();
    const name = (document.getElementById("modal-product-name").value || "").trim();
    const variantLabel = (document.getElementById("modal-product-variant-label").value || "").trim();
    const category = (document.getElementById("modal-product-category").value || "General").trim() || "General";
    const supplier = (document.getElementById("modal-product-supplier").value || "").trim();
    const barcode = (document.getElementById("modal-product-barcode").value || "").trim();
    const sellPrice = Number(document.getElementById("modal-product-price").value || 0);
    const lowStock = Number(document.getElementById("modal-product-low-stock").value || 10);
    const locationBin = (document.getElementById("modal-product-location").value || "").trim();
    const imageSource = (document.getElementById("modal-product-image-source").value || "upload").trim();
    const imageUrl = (document.getElementById("modal-product-image-url").value || "").trim();
    const imageFile = document.getElementById("modal-product-image-file").files[0] || null;
    const button = document.getElementById("modal-save-product");

    if (!invActiveStoreId || !productId || !sku || !name || sellPrice < 0 || lowStock < 0) {
        invSetResult("Please complete valid product details.", "error");
        return;
    }
    if (invSkuExists(sku, productId)) {
        invSetResult("SKU already exists in this store. Use a unique SKU before saving.", "error");
        document.getElementById("modal-product-sku").focus();
        return;
    }
    if (barcode !== "" && invBarcodeExists(barcode, productId)) {
        invSetResult("Barcode already exists in this store. Use a unique barcode or leave it blank.", "error");
        document.getElementById("modal-product-barcode").focus();
        return;
    }
    if (imageSource === "upload" && imageFile && imageFile.size <= 0) {
        invSetResult("Selected product image file is invalid.", "error");
        return;
    }

    try {
        invIsUpdatingProduct = true;
        button.disabled = true;
        button.textContent = "Saving...";

        const formData = new FormData();
        formData.append("store_id", String(invActiveStoreId));
        formData.append("product_id", String(productId));
        formData.append("sku", sku);
        formData.append("name", name);
        formData.append("variant_label", variantLabel);
        formData.append("category", category);
        formData.append("supplier", supplier);
        formData.append("barcode", barcode);
        formData.append("sell_price", String(sellPrice));
        formData.append("low_stock_threshold", String(lowStock));
        formData.append("location_bin", locationBin);
        if (imageSource === "url" && imageUrl !== "") {
            formData.append("image_url", imageUrl);
        } else if (imageSource === "upload" && imageFile) {
            formData.append("image_file", imageFile);
        }

        const response = await fetch("/store/inventory/update-product", {
            method: "POST",
            body: formData,
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            invSetResult(data?.message || "Product update failed.", "error");
            return;
        }

        invSetResult(`Product updated: ${data.product?.name || name}`, "ok");
        await invLoadCategories();
        await invLoadProducts();
        const refreshed = invGetProductById(productId);
        if (refreshed) {
            invOpenProductActionModal(productId);
        }
    } catch (error) {
        invSetResult("Product update request failed.", "error");
    } finally {
        invIsUpdatingProduct = false;
        button.disabled = false;
        button.textContent = "Save Details";
    }
}

async function invOpenProductModal() {
    await invLoadCategories();
    document.getElementById("new-product-sku").value = "";
    delete document.getElementById("new-product-sku").dataset.manual;
    document.getElementById("new-product-name").value = "";
    document.getElementById("new-product-variant-label").value = "";
    document.getElementById("new-product-category").value = "General";
    document.getElementById("new-product-supplier").value = "";
    document.getElementById("new-product-barcode").value = "";
    document.getElementById("new-product-image-source").value = "upload";
    document.getElementById("new-product-image-mode").value = "shared";
    document.getElementById("new-product-variants").innerHTML = "";
    document.getElementById("new-product-variants").classList.remove("is-per-variant");
    invVariantSequence = 0;
    invUpdateVariantCount();
    document.getElementById("new-product-image-file").value = "";
    document.getElementById("new-product-image-url").value = "";
    document.getElementById("new-product-sell-price").value = "0";
    document.getElementById("new-product-initial-stock").value = "0";
    document.getElementById("new-product-unit-cost").value = "0";
    document.getElementById("new-product-location").value = "";
    document.getElementById("new-product-low-stock").value = "0";
    document.getElementById("new-product-reason").value = "Initial stock";
    invToggleProductImageInput();
    invUpdateCreateProductProjection();
    document.getElementById("inventory-product-modal").style.display = "grid";
    invCreateSnapshot = invCaptureCreateFormState();
}

function invCloseProductModal() {
    if (invCreatePreviewObjectUrl) {
        URL.revokeObjectURL(invCreatePreviewObjectUrl);
        invCreatePreviewObjectUrl = null;
    }
    document.getElementById("inventory-product-modal").style.display = "none";
    invCreateSnapshot = null;
}

document.getElementById("inventory-search").addEventListener("input", () => { invPage = 1; invRenderProductTable(); });
["inventory-category-filter", "inventory-stock-filter", "inventory-sort", "inventory-page-size"].forEach((id) => {
    document.getElementById(id).addEventListener("change", () => { invPage = 1; invRenderProductTable(); });
});
document.getElementById("inventory-pager").addEventListener("click", (event) => {
    const button = event.target.closest("[data-page]");
    if (!button || button.disabled) return;
    invPage = Math.max(1, Number(button.dataset.page || 1));
    invRenderProductTable();
});
document.getElementById("inventory-stock-summary").addEventListener("click", (event) => {
    const button = event.target.closest("[data-stock-summary]");
    if (!button) return;
    document.getElementById("inventory-stock-filter").value = button.dataset.stockSummary || "";
    invPage = 1;
    invRenderProductTable();
});
document.getElementById("inventory-toggle-families").addEventListener("click", (event) => {
    const button = event.currentTarget;
    let keys = [];
    try {
        keys = JSON.parse(button.dataset.familyKeys || "[]");
    } catch (error) {
        keys = [];
    }
    const shouldExpand = button.dataset.expand === "true";
    keys.forEach((key) => {
        if (shouldExpand) invExpandedFamilies.add(key);
        else invExpandedFamilies.delete(key);
    });
    invRenderProductTable();
});
document.getElementById("inventory-clear-filters").addEventListener("click", () => {
    document.getElementById("inventory-search").value = "";
    document.getElementById("inventory-category-filter").value = "";
    document.getElementById("inventory-stock-filter").value = "";
    document.getElementById("inventory-sort").value = "name:asc";
    document.getElementById("inventory-page-size").value = "25";
    invPage = 1;
    invRenderProductTable();
});
document.getElementById("inventory-movement-type").addEventListener("change", () => {
    invLoadMovements().catch((error) => invSetResult(error.message || "Unable to filter stock activity.", "error"));
});
document.getElementById("refresh-inventory-movements").addEventListener("click", () => {
    invLoadMovements().catch((error) => invSetResult(error.message || "Unable to refresh stock activity.", "error"));
});

document.getElementById("open-product-modal-top").addEventListener("click", () => {
    invOpenProductModal().catch((error) => invSetResult(error.message || "Unable to open product modal.", "error"));
});
document.getElementById("close-product-modal").addEventListener("click", invRequestCloseProductModal);
document.getElementById("new-product-cancel").addEventListener("click", invRequestCloseProductModal);
document.getElementById("close-product-action-modal").addEventListener("click", invCloseProductActionModal);
document.getElementById("new-product-sku").addEventListener("input", (event) => { event.target.dataset.manual = "true"; invUpdateCreateProductProjection(); });
document.getElementById("new-product-name").addEventListener("input", () => { invSyncFirstVariantSku(); document.querySelectorAll("[data-variant-row]").forEach((row,index) => { const sku=row.querySelector('[data-v="sku"]'); if(sku.dataset.manual!=="true") sku.value=invGeneratedSku(invGetElementValue("new-product-name"),row.querySelector('[data-v="label"]').value,index+2); }); invUpdateCreateProductProjection(); });
document.getElementById("new-product-variant-label").addEventListener("input", () => { invSyncFirstVariantSku(); invUpdateCreateProductProjection(); });
document.getElementById("add-product-variant").addEventListener("click", () => { invAddVariantRow(); invUpdateCreateProductProjection(); });
document.getElementById("new-product-image-mode").addEventListener("change", (event) => { document.getElementById("new-product-variants").classList.toggle("is-per-variant", event.target.value === "per_variant"); });
document.querySelector('[data-barcode-camera-target="new-product-barcode"]').addEventListener("click", () => invOpenBarcodeCamera(document.getElementById("new-product-barcode")));
document.getElementById("inventory-barcode-camera-close").addEventListener("click", invCloseBarcodeCamera);
document.getElementById("inventory-barcode-camera-cancel").addEventListener("click", invCloseBarcodeCamera);
document.getElementById("inventory-barcode-camera-modal").addEventListener("click", (event) => { if (event.target.id === "inventory-barcode-camera-modal") invCloseBarcodeCamera(); });
document.getElementById("new-product-barcode").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-image-source").addEventListener("change", invToggleProductImageInput);
document.getElementById("new-product-image-file").addEventListener("change", invUpdateCreateProductImagePreview);
document.getElementById("new-product-image-url").addEventListener("input", invUpdateCreateProductImagePreview);
document.getElementById("new-product-unit-cost").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-sell-price").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-initial-stock").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-low-stock").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("new-product-reason").addEventListener("input", invUpdateCreateProductProjection);
document.getElementById("modal-product-image-source").addEventListener("change", invToggleModalProductImageInput);
document.getElementById("modal-product-image-file").addEventListener("change", invUpdateModalProductImagePreview);
document.getElementById("modal-product-image-url").addEventListener("input", invUpdateModalProductImagePreview);
document.getElementById("modal-start-edit-product").addEventListener("click", () => invSetProductEditMode(true));
document.getElementById("modal-cancel-edit-product").addEventListener("click", () => invSetProductEditMode(false));
document.getElementById("modal-panel-adjust-btn").addEventListener("click", () => invSetModalPanel("adjust"));
document.getElementById("modal-panel-restock-btn").addEventListener("click", () => invSetModalPanel("restock"));

document.getElementById("inventory-product-modal").addEventListener("click", (event) => {
    if (event.target.id === "inventory-product-modal") {
        invRequestCloseProductModal();
    }
});

document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    const modal = document.getElementById("inventory-product-modal");
    if (!modal || modal.style.display !== "grid") return;
    event.preventDefault();
    invRequestCloseProductModal();
});

document.getElementById("inventory-product-action-modal").addEventListener("click", (event) => {
    if (event.target.id === "inventory-product-action-modal") {
        invCloseProductActionModal();
    }
});

document.getElementById("inventory-product-body").addEventListener("click", (event) => {
    const action = event.target.closest("[data-product-action]");
    if (!action) return;
    invOpenProductActionModal(action.getAttribute("data-product-action"));
});
document.getElementById("inventory-product-body").addEventListener("click", (event) => {
    const toggle = event.target.closest("[data-family-toggle]");
    if (!toggle) return;
    const key = toggle.dataset.familyToggle;
    if (invExpandedFamilies.has(key)) invExpandedFamilies.delete(key);
    else invExpandedFamilies.add(key);
    invRenderProductTable();
});

document.getElementById("modal-restock-qty").addEventListener("input", invUpdateModalRestockProjection);
document.getElementById("modal-restock-unit-cost").addEventListener("input", invUpdateModalRestockProjection);
document.getElementById("modal-restock-sell-price").addEventListener("input", invUpdateModalRestockProjection);
document.getElementById("modal-actual-stock").addEventListener("input", invUpdateAdjustmentPreview);
document.getElementById("modal-restock-submit").addEventListener("click", invSubmitModalRestock);
document.getElementById("new-product-submit").addEventListener("click", invCreateProduct);
document.getElementById("modal-save-adjustment").addEventListener("click", async () => {
    if (!invModalProductId) return;
    await invAdjustStock(invModalProductId);
});
document.getElementById("modal-save-product").addEventListener("click", async () => {
    if (!invModalProductId) return;
    await invUpdateProduct(invModalProductId);
});

window.IbemsPortalNavigation?.onCleanup(invCloseBarcodeCamera);

(async () => {
    try {
        await invLoadStores();
        await invLoadCategories();
        await invLoadProducts();
        await invLoadMovements();
        invToggleProductImageInput();
        invUpdateCreateProductProjection();
    } catch (error) {
        const message = error.message || "Unable to initialize inventory page.";
        if (document.querySelector("#inventory-product-body .data-state--loading")) {
            document.getElementById("inventory-product-body").innerHTML = invDataState("error", message, 5);
        }
        if (document.querySelector("#inventory-stock-summary .data-state--loading")) {
            document.getElementById("inventory-stock-summary").innerHTML = invDataState("error", message);
        }
        if (document.querySelector("#inventory-movement-list .data-state--loading")) {
            document.getElementById("inventory-movement-list").innerHTML = invDataState("error", message);
        }
        invSetResult(message, "error");
    }
})();
