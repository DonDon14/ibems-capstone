let storesData = [];
let officersData = [];
let editingStoreId = null;
let lastOfficerSearchQuery = "";
let editingCurrentLogoUrl = "";
let logoInputMode = "upload";
let storeLogoPreviewObjectUrl = null;
let selectedSupervisorIds = [];
let editingInitialIsActive = true;
let activeOfficerSuggestionIndex = -1;
let activeSupervisorSuggestionIndex = -1;
let storePage = 1;
let storePageSize = 10;
let suppressStoreFilterEvents = false;
const storeShell = document.querySelector(".admin-stores-shell");
const canManageStores = storeShell?.getAttribute("data-can-manage-stores") === "1";
const storesDataUrl = storeShell?.getAttribute("data-stores-data-url") || "/admin/stores/data";
const storeDetailPrefix = (storeShell?.getAttribute("data-store-detail-prefix") || "/admin/stores").replace(/\/$/, "");

function sEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function sDateTime(value) {
    return window.IbemsFormat?.dateTime(value) || new Date(value).toLocaleString();
}

function sDateOnly(value) {
    return window.IbemsFormat?.date?.(value) || new Date(value).toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

function sBool(value) {
    if (typeof value === "boolean") return value;
    return ["1", "t", "true", "yes", "on"].includes(String(value ?? "").trim().toLowerCase());
}

function setStoresResult(message, type) {
    const el = document.getElementById("stores-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function formatRoleLabel(role) {
    return String(role || "")
        .toLowerCase()
        .split("_")
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(" ");
}

function formatTypeLabel(type) {
    const value = String(type || "");
    if (!value) return "-";
    return value.charAt(0).toUpperCase() + value.slice(1).toLowerCase();
}

function getOfficerMatches(query) {
    const q = String(query || "").trim().toLowerCase();
    if (q === "") return [];
    return officersData.filter((officer) =>
        [officer.name, officer.email, officer.employee_id, officer.user_type, officer.role]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(q))
    );
}

function setSelectValue(selectId, value) {
    const select = document.getElementById(selectId);
    if (!select) return;
    select.value = String(value);
    select.dispatchEvent(new Event("change", { bubbles: true }));
}

function getSupervisorMatches(query) {
    const q = String(query || "").trim().toLowerCase();
    if (q === "") return [];
    return officersData.filter((officer) => {
        if (selectedSupervisorIds.includes(Number(officer.id))) return false;
        return [officer.name, officer.email, officer.employee_id, officer.user_type, officer.role]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(q));
    });
}

function setSuggestionBoxOpen(inputId, boxId, isOpen) {
    const input = document.getElementById(inputId);
    const box = document.getElementById(boxId);
    if (input) input.setAttribute("aria-expanded", isOpen ? "true" : "false");
    if (!isOpen && input) {
        input.removeAttribute("aria-activedescendant");
        if (inputId === "store-officer-search") activeOfficerSuggestionIndex = -1;
        if (inputId === "store-supervisor-search") activeSupervisorSuggestionIndex = -1;
    }
    if (box) box.style.display = isOpen ? "grid" : "none";
}

function renderSelectedOfficer() {
    const wrap = document.getElementById("store-officer-selected");
    const input = document.getElementById("store-officer-search");
    const officerId = Number(document.getElementById("store-officer-id")?.value || 0);
    if (!wrap || !input) return;

    if (officerId <= 0) {
        wrap.innerHTML = "";
        input.placeholder = "Search name, email, or employee ID";
        return;
    }

    const officer = findOfficerById(officerId);
    const label = officer ? `${officer.name} (${officer.email})` : `User #${officerId}`;
    wrap.innerHTML = `
        <span class="supervisor-chip">
            <span>${sEscape(label)}</span>
            <button type="button" data-remove-officer="${officerId}" title="Remove store officer" aria-label="Remove ${sEscape(label)} as store officer"><i class="bi bi-x" aria-hidden="true"></i></button>
        </span>
    `;
    input.placeholder = "Search to replace officer";
}

function renderOfficerSuggestions(query) {
    const box = document.getElementById("store-officer-suggestions");
    const matches = getOfficerMatches(query);
    activeOfficerSuggestionIndex = -1;
    if (matches.length === 0) {
        box.innerHTML = "";
        setSuggestionBoxOpen("store-officer-search", "store-officer-suggestions", false);
        return;
    }

    box.innerHTML = matches.map((officer, index) => {
        const role = formatRoleLabel(officer.role);
        const type = formatTypeLabel(officer.user_type);
        const employee = officer.employee_id ? `${sEscape(officer.employee_id)} - ` : "";
        const assignedStoreId = Number(officer.assigned_store_id || 0);
        const isAssignedElsewhere = assignedStoreId > 0 && assignedStoreId !== Number(editingStoreId || 0);
        const assignmentText = assignedStoreId > 0
            ? `Assigned to ${officer.assigned_store_name || "another store"}`
            : "Available";
        return `
            <button id="store-officer-option-${index}" class="officer-suggestion-item ${isAssignedElsewhere ? "is-disabled" : ""}" type="button" role="option" aria-selected="false" data-officer-pick="${officer.id}" ${isAssignedElsewhere ? "disabled" : ""}>
                <span class="table-person-cell">${window.IbemsAvatar.html(officer.name, officer.profile_image_url, "table-person-avatar")}<span><strong>${employee}${sEscape(officer.name)}</strong><small>${sEscape(officer.email)}</small></span></span>
                <small>${sEscape(role)} / ${sEscape(type)} - ${sEscape(assignmentText)}</small>
            </button>
        `;
    }).join("");
    setSuggestionBoxOpen("store-officer-search", "store-officer-suggestions", true);
}

function renderSelectedSupervisors() {
    const wrap = document.getElementById("store-supervisor-selected");
    if (!wrap) return;
    if (selectedSupervisorIds.length === 0) {
        wrap.innerHTML = "";
        return;
    }

    wrap.innerHTML = selectedSupervisorIds.map((supervisorId) => {
        const supervisor = findOfficerById(supervisorId);
        const label = supervisor ? `${supervisor.name} (${supervisor.email})` : `User #${supervisorId}`;
        return `
            <span class="supervisor-chip">
                <span>${sEscape(label)}</span>
                <button type="button" data-remove-supervisor="${supervisorId}" title="Remove supervisor" aria-label="Remove ${sEscape(label)} as store supervisor"><i class="bi bi-x" aria-hidden="true"></i></button>
            </span>
        `;
    }).join("");
}

function renderSupervisorSuggestions(query) {
    const box = document.getElementById("store-supervisor-suggestions");
    if (!box) return;
    const matches = getSupervisorMatches(query);
    activeSupervisorSuggestionIndex = -1;
    if (matches.length === 0) {
        box.innerHTML = "";
        setSuggestionBoxOpen("store-supervisor-search", "store-supervisor-suggestions", false);
        return;
    }

    box.innerHTML = matches.map((officer, index) => {
        const roles = Array.isArray(officer.roles) && officer.roles.length ? officer.roles : [officer.role];
        return `
            <button id="store-supervisor-option-${index}" class="officer-suggestion-item" type="button" role="option" aria-selected="false" data-supervisor-pick="${officer.id}">
                <span class="table-person-cell">${window.IbemsAvatar.html(officer.name, officer.profile_image_url, "table-person-avatar")}<span><strong>${sEscape(officer.employee_id ? `${officer.employee_id} - ` : "")}${sEscape(officer.name)}</strong><small>${sEscape(officer.email)}</small></span></span>
                <small>${sEscape(roles.map(formatRoleLabel).join(", "))} / ${sEscape(formatTypeLabel(officer.user_type))}</small>
            </button>
        `;
    }).join("");
    setSuggestionBoxOpen("store-supervisor-search", "store-supervisor-suggestions", true);
}

function setActiveSuggestion(kind, nextIndex) {
    const isOfficer = kind === "officer";
    const box = document.getElementById(isOfficer ? "store-officer-suggestions" : "store-supervisor-suggestions");
    const input = document.getElementById(isOfficer ? "store-officer-search" : "store-supervisor-search");
    const items = Array.from(box?.querySelectorAll(".officer-suggestion-item:not(:disabled)") || []);
    if (!input || items.length === 0) return;

    const normalizedIndex = (nextIndex + items.length) % items.length;
    items.forEach((item, index) => item.classList.toggle("is-active", index === normalizedIndex));
    const activeItem = items[normalizedIndex];
    input.setAttribute("aria-activedescendant", activeItem.id);
    activeItem.scrollIntoView({ block: "nearest" });
    if (isOfficer) activeOfficerSuggestionIndex = normalizedIndex;
    else activeSupervisorSuggestionIndex = normalizedIndex;
}

function handlePeoplePickerKeydown(kind, event) {
    const isOfficer = kind === "officer";
    const input = event.currentTarget;
    const box = document.getElementById(isOfficer ? "store-officer-suggestions" : "store-supervisor-suggestions");
    const render = isOfficer ? renderOfficerSuggestions : renderSupervisorSuggestions;
    const items = () => Array.from(box?.querySelectorAll(".officer-suggestion-item:not(:disabled)") || []);
    const activeIndex = () => isOfficer ? activeOfficerSuggestionIndex : activeSupervisorSuggestionIndex;

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        if (items().length === 0) render(input.value || "");
        if (items().length > 0) {
            const nextIndex = activeIndex() < 0
                ? (event.key === "ArrowDown" ? 0 : items().length - 1)
                : activeIndex() + (event.key === "ArrowDown" ? 1 : -1);
            setActiveSuggestion(kind, nextIndex);
        }
        return;
    }
    if (event.key === "Enter" && activeIndex() >= 0) {
        event.preventDefault();
        items()[activeIndex()]?.click();
        return;
    }
    if (event.key === "Escape") {
        setSuggestionBoxOpen(input.id, box.id, false);
        return;
    }
    if (event.key === "Backspace" && input.value === "") {
        if (isOfficer && Number(document.getElementById("store-officer-id").value || 0) > 0) {
            document.getElementById("store-officer-id").value = "";
            renderSelectedOfficer();
        } else if (!isOfficer && selectedSupervisorIds.length > 0) {
            selectedSupervisorIds.pop();
            renderSelectedSupervisors();
        }
    }
}

function findOfficerById(officerId) {
    return officersData.find((officer) => Number(officer.id) === Number(officerId)) || null;
}

function toggleLogoSourceUI() {
    document.getElementById("store-logo-upload-wrap").classList.toggle("is-hidden", logoInputMode !== "upload");
    document.getElementById("store-logo-url-wrap").classList.toggle("is-hidden", logoInputMode !== "url");
    document.getElementById("store-logo-source").value = logoInputMode;
    updateStoreLogoPreview();
}

function updateStoreLogoPreview() {
    const preview = document.getElementById("store-logo-preview");
    const empty = document.getElementById("store-logo-preview-empty");
    const file = document.getElementById("store-logo-file").files?.[0] || null;
    const url = (document.getElementById("store-logo-url").value || "").trim();

    if (storeLogoPreviewObjectUrl) {
        URL.revokeObjectURL(storeLogoPreviewObjectUrl);
        storeLogoPreviewObjectUrl = null;
    }

    let resolved = logoInputMode === "url" ? url : "";
    if (logoInputMode === "upload" && file) {
        storeLogoPreviewObjectUrl = URL.createObjectURL(file);
        resolved = storeLogoPreviewObjectUrl;
    } else if (logoInputMode === "upload" && editingCurrentLogoUrl) {
        resolved = editingCurrentLogoUrl;
    }

    if (resolved) {
        preview.src = resolved;
        preview.style.display = "block";
        empty.style.display = "none";
    } else {
        preview.removeAttribute("src");
        preview.style.display = "none";
        empty.style.display = "block";
    }
}

function renderStoresDataState(type, message) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    return `<div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${sEscape(message)}</strong></div></div>`;
}

function storeNeedsAssignment(row) {
    return Number(row.officer_id || 0) <= 0 || !Array.isArray(row.supervisors) || row.supervisors.length === 0;
}

function sortedStoreRows(rows) {
    const sort = document.getElementById("store-sort")?.value || "name_asc";
    return [...rows].sort((left, right) => {
        const leftName = String(left.store_name || "");
        const rightName = String(right.store_name || "");
        if (sort === "name_desc") return rightName.localeCompare(leftName);
        if (sort === "newest" || sort === "oldest") {
            const leftTime = Date.parse(left.created_at || "") || 0;
            const rightTime = Date.parse(right.created_at || "") || 0;
            return sort === "newest" ? rightTime - leftTime : leftTime - rightTime;
        }
        if (sort === "status") {
            const assignmentOrder = Number(storeNeedsAssignment(right)) - Number(storeNeedsAssignment(left));
            if (assignmentOrder !== 0) return assignmentOrder;
            const activeOrder = Number(sBool(right.is_active)) - Number(sBool(left.is_active));
            return activeOrder !== 0 ? activeOrder : leftName.localeCompare(rightName);
        }
        return leftName.localeCompare(rightName);
    });
}

function storeLogoMarkup(row, sizeClass = "") {
    const initials = String(row.store_name || "S")
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join("");
    const logo = row.logo_url
        ? `<img src="${sEscape(row.logo_url)}" alt="${sEscape(row.store_name)} logo" class="store-logo-img">`
        : `<div class="store-logo-fallback">${sEscape(initials || "S")}</div>`;
    return `<div class="store-card-logo ${sizeClass}">${logo}</div>`;
}

function assignmentWarningMarkup(row) {
    if (!storeNeedsAssignment(row)) return "";
    return `<span class="assignment-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Needs assignment</span>`;
}

function storePaginationMarkup(totalRows) {
    if (totalRows <= storePageSize) {
        return `<div class="store-pagination store-pagination--single" aria-live="polite">
            <span>Showing all ${totalRows} ${totalRows === 1 ? "store" : "stores"}</span>
        </div>`;
    }

    const totalPages = Math.max(1, Math.ceil(totalRows / storePageSize));
    const start = totalRows === 0 ? 0 : (storePage - 1) * storePageSize + 1;
    const end = Math.min(storePage * storePageSize, totalRows);
    return `<div class="store-pagination" aria-label="Store list pagination">
        <span>${start}–${end} of ${totalRows} stores</span>
        <div class="store-page-size"><label for="store-page-size">Rows</label><select id="store-page-size"><option value="10" ${storePageSize === 10 ? "selected" : ""}>10</option><option value="25" ${storePageSize === 25 ? "selected" : ""}>25</option><option value="50" ${storePageSize === 50 ? "selected" : ""}>50</option></select></div>
        <div class="store-page-actions">
            <button type="button" data-store-page="previous" ${storePage <= 1 ? "disabled" : ""} aria-label="Previous store page"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <strong>Page ${storePage} of ${totalPages}</strong>
            <button type="button" data-store-page="next" ${storePage >= totalPages ? "disabled" : ""} aria-label="Next store page"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        </div>
    </div>`;
}

function renderStores(rows) {
    const gallery = document.getElementById("stores-gallery");
    if (!Array.isArray(rows) || rows.length === 0) {
        gallery.innerHTML = renderStoresDataState("empty", "No stores found.");
        return;
    }

    const sortedRows = sortedStoreRows(rows);
    const totalPages = Math.max(1, Math.ceil(sortedRows.length / storePageSize));
    storePage = Math.min(Math.max(1, storePage), totalPages);
    const visibleRows = sortedRows.slice((storePage - 1) * storePageSize, storePage * storePageSize);
    const cards = visibleRows.map((row) => {
        const supervisors = Array.isArray(row.supervisors) ? row.supervisors : [];
        const isActive = sBool(row.is_active);
        return `
            <article class="store-card">
                ${storeLogoMarkup(row)}
                <div class="store-card-meta">
                    <div class="store-card-heading">
                        <a href="${sEscape(storeDetailPrefix)}/${row.id}" class="store-card-title">${sEscape(row.store_name)}</a>
                        <span class="status-badge ${isActive ? "active" : "inactive"}">${isActive ? "Active" : "Inactive"}</span>
                    </div>
                    ${assignmentWarningMarkup(row)}
                    <dl class="store-mobile-details">
                        <div><dt>Officer</dt><dd>${row.officer_name ? `<strong>${sEscape(row.officer_name)}</strong>${row.officer_email ? `<small>${sEscape(row.officer_email)}</small>` : ""}` : '<span class="not-assigned">Not assigned</span>'}</dd></div>
                        <div><dt>Supervisors</dt><dd>${supervisors.length ? sEscape(supervisors.map((supervisor) => supervisor.name).join(", ")) : "Not assigned"}</dd></div>
                    </dl>
                    <div class="store-card-footer">
                        <small>Created ${row.created_at ? sEscape(sDateOnly(row.created_at)) : "—"}</small>
                        <div class="store-card-actions">
                            <a href="${sEscape(storeDetailPrefix)}/${row.id}" class="store-card-action"><i class="bi bi-eye" aria-hidden="true"></i> View</a>
                            ${canManageStores ? `<button class="store-card-action" type="button" data-edit-store="${row.id}" aria-label="Edit ${sEscape(row.store_name)}"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</button>` : ""}
                        </div>
                    </div>
                </div>
            </article>
        `;
    }).join("");

    gallery.innerHTML = `
        <div class="store-cards-grid">${cards}</div>
        ${storePaginationMarkup(sortedRows.length)}
    `;
}

async function loadOfficers() {
    const response = await fetch("/admin/stores/officers");
    const data = await response.json();
    if (!data || data.status !== "success") {
        throw new Error(data?.message || "Unable to load officers.");
    }
    officersData = Array.isArray(data.data) ? data.data : [];
}

async function loadStores() {
    storePage = 1;
    const q = (document.getElementById("store-search").value || "").trim();
    const status = document.getElementById("store-status-filter")?.value || "";
    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (status) params.set("status", status);

    const response = await fetch(`${storesDataUrl}?${params.toString()}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        storesData = [];
        document.getElementById("stores-gallery").innerHTML = renderStoresDataState("error", data?.message || "Unable to load stores.");
        setStoresResult(data?.message || "Unable to load stores.", "error");
        return;
    }

    storesData = Array.isArray(data.data) ? data.data : [];
    renderStores(storesData);
    setStoresResult("", "ok");
}

function openStoreModal(mode, store) {
    if (!canManageStores) return;
    setStoresResult("", "ok");
    editingStoreId = mode === "edit" ? Number(store.id) : null;
    document.getElementById("store-modal-title").textContent = mode === "edit" ? "Edit Store" : "Add Store";
    document.getElementById("store-modal-description").textContent = mode === "edit"
        ? "Update store information, assignments, and availability."
        : "Configure store details, staff assignments, and availability.";
    const saveButton = document.getElementById("store-save-btn");
    saveButton.dataset.idleLabel = mode === "edit" ? "Save Changes" : "Create Store";
    saveButton.textContent = saveButton.dataset.idleLabel;
    document.getElementById("store-name").value = mode === "edit" ? store.store_name || "" : "";
    editingCurrentLogoUrl = mode === "edit" ? String(store.logo_url || "") : "";
    document.getElementById("store-logo-url").value = "";
    document.getElementById("store-logo-file").value = "";
    editingInitialIsActive = mode === "edit" ? sBool(store.is_active) : false;
    setSelectValue("store-active", editingInitialIsActive ? "1" : "0");
    document.getElementById("store-deactivation-reason").value = "";
    updateDeactivationReasonVisibility();
    const selectedOfficerId = mode === "edit" ? Number(store.officer_id || 0) : 0;
    document.getElementById("store-officer-id").value = selectedOfficerId > 0 ? String(selectedOfficerId) : "";
    document.getElementById("store-officer-search").value = "";
    renderSelectedOfficer();
    setSuggestionBoxOpen("store-officer-search", "store-officer-suggestions", false);
    document.getElementById("store-officer-suggestions").innerHTML = "";
    selectedSupervisorIds = mode === "edit" && Array.isArray(store.supervisor_ids)
        ? store.supervisor_ids.map(Number).filter(Boolean)
        : [];
    document.getElementById("store-supervisor-search").value = "";
    setSuggestionBoxOpen("store-supervisor-search", "store-supervisor-suggestions", false);
    document.getElementById("store-supervisor-suggestions").innerHTML = "";
    renderSelectedSupervisors();
    lastOfficerSearchQuery = "";
    if (editingCurrentLogoUrl && /^https?:\/\//i.test(editingCurrentLogoUrl)) {
        logoInputMode = "url";
        document.getElementById("store-logo-url").value = editingCurrentLogoUrl;
    } else {
        logoInputMode = "upload";
    }
    toggleLogoSourceUI();
    document.getElementById("store-modal").style.display = "grid";
    document.body.classList.add("admin-stores-modal-open");
    const modalBody = document.querySelector("#store-modal .admin-stores-modal-body");
    if (modalBody) modalBody.scrollTop = 0;
    requestAnimationFrame(() => document.getElementById("store-name")?.focus());
}

function closeStoreModal() {
    editingStoreId = null;
    editingCurrentLogoUrl = "";
    selectedSupervisorIds = [];
    if (storeLogoPreviewObjectUrl) {
        URL.revokeObjectURL(storeLogoPreviewObjectUrl);
        storeLogoPreviewObjectUrl = null;
    }
    setSuggestionBoxOpen("store-officer-search", "store-officer-suggestions", false);
    setSuggestionBoxOpen("store-supervisor-search", "store-supervisor-suggestions", false);
    document.getElementById("store-modal").style.display = "none";
    document.body.classList.remove("admin-stores-modal-open");
}

async function saveStore() {
    if (!canManageStores) return;
    const name = (document.getElementById("store-name").value || "").trim();
    const officerId = Number(document.getElementById("store-officer-id").value || 0);
    const logoUrlInput = (document.getElementById("store-logo-url").value || "").trim();
    const logoFile = document.getElementById("store-logo-file").files?.[0] || null;
    const isActive = Number(document.getElementById("store-active").value || 1);
    const deactivationReason = (document.getElementById("store-deactivation-reason").value || "").trim();
    const button = document.getElementById("store-save-btn");

    if (!name) {
        setStoresResult("Store name is required.", "error");
        return;
    }
    if (isActive === 1 && (officerId <= 0 || selectedSupervisorIds.length === 0)) {
        setStoresResult("To create an active store, select a primary officer and at least one supervisor. Otherwise, choose Inactive and assign them later.", "error");
        (officerId <= 0 ? document.getElementById("store-officer-search") : document.getElementById("store-supervisor-search")).focus();
        return;
    }
    const isDeactivating = Boolean(editingStoreId) && editingInitialIsActive && isActive === 0;
    if (isDeactivating && !deactivationReason) {
        setStoresResult("A deactivation reason is required.", "error");
        document.getElementById("store-deactivation-reason").focus();
        return;
    }
    if (isDeactivating && !window.confirm("Deactivate this store? New operations will be blocked, while historical records remain available.")) return;

    const endpoint = editingStoreId ? "/admin/stores/update" : "/admin/stores/create";
    const formData = new FormData();
    let logoUrl = "";
    if (logoInputMode === "url") {
        logoUrl = logoUrlInput;
    } else if (editingStoreId) {
        logoUrl = editingCurrentLogoUrl;
    }

    formData.append("store_name", name);
    formData.append("officer_id", String(officerId));
    selectedSupervisorIds.forEach((supervisorId) => formData.append("supervisor_ids[]", String(supervisorId)));
    formData.append("logo_url", logoUrl);
    formData.append("is_active", String(isActive));
    if (logoInputMode === "upload" && logoFile) {
        formData.append("logo_file", logoFile);
    }
    if (editingStoreId) {
        formData.append("store_id", String(editingStoreId));
        if (isDeactivating) formData.append("deactivation_reason", deactivationReason);
    }

    button.disabled = true;
    button.textContent = "Saving...";
    try {
        const response = await fetch(endpoint, {
            method: "POST",
            body: formData,
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            setStoresResult(data?.message || "Failed to save store.", "error");
            return;
        }

        setStoresResult(editingStoreId ? "Store updated." : "Store created.", "ok");
        closeStoreModal();
        await loadStores();
    } catch (error) {
        setStoresResult("Store request failed.", "error");
    } finally {
        button.disabled = false;
        button.textContent = button.dataset.idleLabel || "Save Changes";
    }
}

document.getElementById("store-search-btn").addEventListener("click", async () => {
    await loadStores();
});
document.getElementById("store-search").addEventListener("keydown", async (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    await loadStores();
});

document.getElementById("store-refresh-btn").addEventListener("click", async () => {
    document.getElementById("store-search").value = "";
    suppressStoreFilterEvents = true;
    setSelectValue("store-status-filter", "");
    setSelectValue("store-sort", "name_asc");
    suppressStoreFilterEvents = false;
    await loadStores();
});
document.getElementById("store-status-filter").addEventListener("change", () => {
    if (!suppressStoreFilterEvents) loadStores();
});
document.getElementById("store-sort")?.addEventListener("change", () => {
    if (suppressStoreFilterEvents) return;
    storePage = 1;
    renderStores(storesData);
});

document.getElementById("open-store-modal")?.addEventListener("click", () => openStoreModal("create"));
document.getElementById("close-store-modal")?.addEventListener("click", closeStoreModal);
document.getElementById("cancel-store-modal")?.addEventListener("click", closeStoreModal);
document.getElementById("store-modal")?.addEventListener("click", (event) => {
    if (event.target.id === "store-modal") closeStoreModal();
});
document.getElementById("store-save-btn")?.addEventListener("click", saveStore);
function updateDeactivationReasonVisibility() {
    const isDeactivating = Boolean(editingStoreId)
        && editingInitialIsActive
        && document.getElementById("store-active").value === "0";
    const reasonField = document.getElementById("store-deactivation-reason-field");
    if (!reasonField) return;
    reasonField.hidden = !isDeactivating;
    reasonField.classList.toggle("is-hidden", !isDeactivating);
    if (!isDeactivating) document.getElementById("store-deactivation-reason").value = "";
}
document.getElementById("store-active")?.addEventListener("change", updateDeactivationReasonVisibility);
document.getElementById("store-logo-source")?.addEventListener("change", (event) => {
    logoInputMode = event.target.value === "url" ? "url" : "upload";
    toggleLogoSourceUI();
});
document.getElementById("store-logo-file")?.addEventListener("change", updateStoreLogoPreview);
document.getElementById("store-logo-url")?.addEventListener("input", updateStoreLogoPreview);
document.getElementById("store-officer-search")?.addEventListener("input", (event) => {
    const query = event.target.value || "";
    lastOfficerSearchQuery = query;
    renderOfficerSuggestions(query);
});
document.getElementById("store-officer-search")?.addEventListener("focus", (event) => {
    renderOfficerSuggestions(event.target.value || "");
});
document.getElementById("store-officer-search")?.addEventListener("keydown", (event) => handlePeoplePickerKeydown("officer", event));
document.getElementById("store-officer-suggestions")?.addEventListener("click", (event) => {
    const item = event.target.closest("[data-officer-pick]");
    if (!item || item.disabled) return;
    const officerId = Number(item.getAttribute("data-officer-pick") || 0);
    if (!officerId) return;

    const picked = officersData.find((officer) => Number(officer.id) === officerId);
    if (!picked) return;

    document.getElementById("store-officer-search").value = "";
    document.getElementById("store-officer-id").value = String(officerId);
    renderSelectedOfficer();
    setSuggestionBoxOpen("store-officer-search", "store-officer-suggestions", false);
});
document.getElementById("store-officer-selected")?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-remove-officer]");
    if (!button) return;
    document.getElementById("store-officer-id").value = "";
    renderSelectedOfficer();
    document.getElementById("store-officer-search").focus();
});
document.getElementById("store-supervisor-search")?.addEventListener("input", (event) => {
    renderSupervisorSuggestions(event.target.value || "");
});
document.getElementById("store-supervisor-search")?.addEventListener("focus", (event) => {
    renderSupervisorSuggestions(event.target.value || "");
});
document.getElementById("store-supervisor-search")?.addEventListener("keydown", (event) => handlePeoplePickerKeydown("supervisor", event));
document.getElementById("store-supervisor-suggestions")?.addEventListener("click", (event) => {
    const item = event.target.closest("[data-supervisor-pick]");
    if (!item) return;
    const supervisorId = Number(item.getAttribute("data-supervisor-pick") || 0);
    if (!supervisorId || selectedSupervisorIds.includes(supervisorId)) return;

    selectedSupervisorIds.push(supervisorId);
    document.getElementById("store-supervisor-search").value = "";
    setSuggestionBoxOpen("store-supervisor-search", "store-supervisor-suggestions", false);
    renderSelectedSupervisors();
});
document.getElementById("store-supervisor-selected")?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-remove-supervisor]");
    if (!button) return;
    const supervisorId = Number(button.getAttribute("data-remove-supervisor") || 0);
    selectedSupervisorIds = selectedSupervisorIds.filter((id) => id !== supervisorId);
    renderSelectedSupervisors();
    document.getElementById("store-supervisor-search").focus();
});

document.querySelectorAll(".people-picker").forEach((picker) => {
    picker.addEventListener("click", (event) => {
        if (event.target.closest("button")) return;
        picker.querySelector("input")?.focus();
    });
});

document.getElementById("stores-gallery").addEventListener("click", async (event) => {
    const pageButton = event.target.closest("[data-store-page]");
    if (pageButton && !pageButton.disabled) {
        const direction = pageButton.getAttribute("data-store-page");
        storePage += direction === "next" ? 1 : -1;
        renderStores(storesData);
        document.getElementById("stores-gallery").scrollIntoView({ behavior: "smooth", block: "start" });
        return;
    }
    const editBtn = event.target.closest("[data-edit-store]");
    if (editBtn) {
        event.preventDefault();
        const storeId = Number(editBtn.getAttribute("data-edit-store"));
        const store = storesData.find((row) => Number(row.id) === storeId);
        if (!store) return;
        openStoreModal("edit", store);
    }
});
document.getElementById("stores-gallery").addEventListener("change", (event) => {
    if (event.target.id !== "store-page-size") return;
    storePageSize = Number(event.target.value || 10);
    storePage = 1;
    renderStores(storesData);
});

document.addEventListener("click", (event) => {
    if (!event.target.closest("#store-officer-search") && !event.target.closest("#store-officer-suggestions")) {
        const suggestionBox = document.getElementById("store-officer-suggestions");
        if (suggestionBox) setSuggestionBoxOpen("store-officer-search", suggestionBox.id, false);
    }
    if (!event.target.closest("#store-supervisor-search") && !event.target.closest("#store-supervisor-suggestions")) {
        const suggestionBox = document.getElementById("store-supervisor-suggestions");
        if (suggestionBox) setSuggestionBoxOpen("store-supervisor-search", suggestionBox.id, false);
    }
});

(async () => {
    try {
        if (canManageStores) {
            await loadOfficers();
        }
        await loadStores();
    } catch (error) {
        document.getElementById("stores-gallery").innerHTML = renderStoresDataState("error", error.message || "Failed to initialize store management.");
        setStoresResult(error.message || "Failed to initialize store management.", "error");
    }
})();
