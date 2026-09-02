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
                        <div><dt>Store Cashier</dt><dd>${row.officer_name ? `<strong>${sEscape(row.officer_name)}</strong>${row.officer_email ? `<small>${sEscape(row.officer_email)}</small>` : ""}` : '<span class="not-assigned">Not assigned</span>'}</dd></div>
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
    officerEditorOpen = mode !== "edit" || selectedOfficerId <= 0;
    document.getElementById("store-officer-id").value = selectedOfficerId > 0 ? String(selectedOfficerId) : "";
    document.getElementById("store-officer-search").value = "";
    renderSelectedOfficer();
    setSuggestionBoxOpen("store-officer-search", "store-officer-suggestions", false);
    document.getElementById("store-officer-suggestions").innerHTML = "";
    selectedSupervisorIds = mode === "edit" && Array.isArray(store.supervisor_ids)
        ? store.supervisor_ids.map(Number).filter(Boolean)
        : [];
    supervisorEditorOpen = mode !== "edit" || selectedSupervisorIds.length === 0;
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
    officerEditorOpen = false;
    supervisorEditorOpen = false;
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
        setStoresResult("To activate this store, select a Store Cashier and at least one supervisor. Otherwise, keep it inactive and assign them later.", "error");
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
    officerEditorOpen = false;
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
document.getElementById("store-officer-summary")?.addEventListener("click", (event) => {
    if (!event.target.closest("[data-change-officer]")) return;
    officerEditorOpen = true;
    updateAssignmentEditors();
    requestAnimationFrame(() => document.getElementById("store-officer-search")?.focus());
});
document.getElementById("store-supervisor-summary")?.addEventListener("click", (event) => {
    if (!event.target.closest("[data-change-supervisors]")) return;
    supervisorEditorOpen = true;
    updateAssignmentEditors();
    requestAnimationFrame(() => document.getElementById("store-supervisor-search")?.focus());
});
document.getElementById("store-officer-change-done")?.addEventListener("click", () => {
    officerEditorOpen = false;
    setSuggestionBoxOpen("store-officer-search", "store-officer-suggestions", false);
    updateAssignmentEditors();
    document.querySelector("#store-officer-summary [data-change-officer]")?.focus();
});
document.getElementById("store-supervisor-change-done")?.addEventListener("click", () => {
    supervisorEditorOpen = false;
    setSuggestionBoxOpen("store-supervisor-search", "store-supervisor-suggestions", false);
    updateAssignmentEditors();
    document.querySelector("#store-supervisor-summary [data-change-supervisors]")?.focus();
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
