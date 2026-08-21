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
        input.placeholder = "Optional: type name, email, or employee ID";
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
                <span>${employee}${sEscape(officer.name)} (${sEscape(officer.email)})</span>
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
                <span>${sEscape(officer.employee_id ? `${officer.employee_id} - ` : "")}${sEscape(officer.name)} (${sEscape(officer.email)})</span>
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

function renderStores(rows) {
    const gallery = document.getElementById("stores-gallery");
    if (!Array.isArray(rows) || rows.length === 0) {
        gallery.innerHTML = renderStoresDataState("empty", "No stores found.");
        return;
    }

    gallery.innerHTML = rows.map((row) => {
        const initials = String(row.store_name || "S")
            .split(/\s+/)
            .slice(0, 2)
            .map((part) => part.charAt(0).toUpperCase())
            .join("");
        const logo = row.logo_url
            ? `<img src="${sEscape(row.logo_url)}" alt="${sEscape(row.store_name)} logo" class="store-logo-img">`
            : `<div class="store-logo-fallback">${sEscape(initials || "S")}</div>`;
        const isActive = sBool(row.is_active);
        return `
            <a href="${sEscape(storeDetailPrefix)}/${row.id}" class="store-card">
                <div class="store-card-logo">${logo}</div>
                <div class="store-card-meta">
                    <strong>${sEscape(row.store_name)}</strong>
                    <span class="status-badge ${isActive ? "active" : "inactive"}">${isActive ? "Active" : "Inactive"}</span>
                    <small>${sEscape(row.officer_name || "No assigned officer")}</small><br>
                    ${row.officer_email ? `<small>${sEscape(row.officer_email)}</small><br>` : ""}
                    ${Array.isArray(row.supervisors) && row.supervisors.length ? `<small>Supervisor: ${sEscape(row.supervisors.map((supervisor) => supervisor.name).join(", "))}</small><br>` : ""}
                    <small>${row.created_at ? sEscape(sDateTime(row.created_at)) : "-"}</small>
                </div>
                <div class="store-card-right">
                    ${canManageStores ? `<button class="secondary-btn btn-sm" type="button" data-edit-store="${row.id}"><i class="bi bi-pencil"></i> Edit</button>` : ""}
                </div>
            </a>
        `;
    }).join("");
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
    document.getElementById("store-name").value = mode === "edit" ? store.store_name || "" : "";
    editingCurrentLogoUrl = mode === "edit" ? String(store.logo_url || "") : "";
    document.getElementById("store-logo-url").value = "";
    document.getElementById("store-logo-file").value = "";
    editingInitialIsActive = mode === "edit" ? sBool(store.is_active) : false;
    document.getElementById("store-active").value = editingInitialIsActive ? "1" : "0";
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
        button.textContent = "Save";
    }
}

document.getElementById("store-search-btn").addEventListener("click", async () => {
    await loadStores();
});

document.getElementById("store-refresh-btn").addEventListener("click", async () => {
    document.getElementById("store-search").value = "";
    document.getElementById("store-status-filter").value = "";
    await loadStores();
});
document.getElementById("store-status-filter").addEventListener("change", loadStores);

document.getElementById("open-store-modal")?.addEventListener("click", () => openStoreModal("create"));
document.getElementById("close-store-modal")?.addEventListener("click", closeStoreModal);
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
    const editBtn = event.target.closest("[data-edit-store]");
    if (editBtn) {
        event.preventDefault();
        const storeId = Number(editBtn.getAttribute("data-edit-store"));
        const store = storesData.find((row) => Number(row.id) === storeId);
        if (!store) return;
        openStoreModal("edit", store);
    }
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
