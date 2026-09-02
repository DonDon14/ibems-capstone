let storesData = [];
let officersData = [];
let editingStoreId = null;
let lastOfficerSearchQuery = "";
let editingCurrentLogoUrl = "";
let logoInputMode = "upload";
let storeLogoPreviewObjectUrl = null;
let selectedSupervisorIds = [];
let editingInitialIsActive = true;
let officerEditorOpen = false;
let supervisorEditorOpen = false;
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
        renderOfficerSummary();
        updateAssignmentEditors();
        return;
    }

    const officer = findOfficerById(officerId);
    const label = officer ? `${officer.name} (${officer.email})` : `User #${officerId}`;
    wrap.innerHTML = `
        <span class="supervisor-chip">
            <span>${sEscape(label)}</span>
            <button type="button" data-remove-officer="${officerId}" title="Remove Store Cashier" aria-label="Remove ${sEscape(label)} as Store Cashier"><i class="bi bi-x" aria-hidden="true"></i></button>
        </span>
    `;
    input.placeholder = "Search to replace officer";
    renderOfficerSummary();
    updateAssignmentEditors();
}

function assignmentCard(person, role, changeAttribute) {
    if (!person) {
        return `<button type="button" class="assignment-person-card is-empty" ${changeAttribute}><span class="assignment-empty-icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span><span class="assignment-person-copy"><strong>Not assigned</strong><small>Click to assign ${sEscape(role.toLowerCase())}</small></span><span class="assignment-change-label">Assign</span></button>`;
    }
    return `<button type="button" class="assignment-person-card" ${changeAttribute}>
        ${window.IbemsAvatar.html(person.name, person.profile_image_url, "assignment-person-avatar")}
        <span class="assignment-person-copy"><strong>${sEscape(person.name)}</strong><small>${sEscape(person.employee_id || person.email || role)}</small></span>
        <span class="assignment-role-label">${sEscape(role)}</span><span class="assignment-change-label">Change</span>
    </button>`;
}

function renderOfficerSummary() {
    const summary = document.getElementById("store-officer-summary");
    if (!summary) return;
    summary.innerHTML = assignmentCard(findOfficerById(Number(document.getElementById("store-officer-id")?.value || 0)), "Store Cashier", "data-change-officer");
}

function renderSupervisorSummary() {
    const summary = document.getElementById("store-supervisor-summary");
    if (!summary) return;
    const supervisors = selectedSupervisorIds.map(findOfficerById).filter(Boolean);
    summary.innerHTML = supervisors.length
        ? supervisors.map((person) => assignmentCard(person, "Store Supervisor", "data-change-supervisors")).join("")
        : assignmentCard(null, "Store Supervisor", "data-change-supervisors");
}

function updateAssignmentEditors() {
    document.getElementById("store-officer-editor")?.classList.toggle("is-hidden", !officerEditorOpen);
    document.getElementById("store-supervisor-editor")?.classList.toggle("is-hidden", !supervisorEditorOpen);
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
        renderSupervisorSummary();
        updateAssignmentEditors();
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
    renderSupervisorSummary();
    updateAssignmentEditors();
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
