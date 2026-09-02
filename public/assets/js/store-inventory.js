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

function invSyncModalBodyLock() {
    const hasOpenModal = Array.from(document.querySelectorAll(".inv-modal"))
        .some((modal) => modal.style.display === "grid");
    document.body.classList.toggle("app-modal-open", hasOpenModal);
}

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
    if (String(product?.stock_policy || "tracked") === "untracked") {
        return {key:"in",label:"Available",detail:`Sold per ${String(product?.unit_code || "unit")}`,qty:0};
    }
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

function invBehaviorLabel(value) {
    return ({stock_item:"Stocked item",prepared_item:"Prepared food",manufactured_item:"Manufactured product",service:"Service",deposit:"Refundable deposit",tracked:"Tracked",untracked:"Untracked"})[String(value || "")] || String(value || "-");
}

function invSyncBehaviorFields(prefix) {
    const itemType = document.getElementById(`${prefix}-item-type`);
    const stockPolicy = document.getElementById(`${prefix}-stock-policy`);
    if (!itemType || !stockPolicy) return;
    if (["service", "deposit"].includes(itemType.value) && stockPolicy.value !== "untracked") {
        stockPolicy.value = "untracked";
        stockPolicy.dispatchEvent(new Event("change", {bubbles:true}));
    }
    const untracked = stockPolicy.value === "untracked";
    if (prefix === "new-product") {
        ["new-product-initial-stock", "new-product-low-stock", "new-product-unit-cost"].forEach((id) => {
            const input = document.getElementById(id); if (input) input.disabled = untracked;
        });
        const help = document.getElementById("new-product-behavior-help");
        if (help) help.textContent = untracked ? "This item can be sold without an on-hand quantity and creates no inventory movement." : "Each POS sale atomically deducts stock and records an inventory movement.";
    }
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
