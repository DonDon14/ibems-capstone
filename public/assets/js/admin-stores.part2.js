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
                            ${canManageStores ? `<button class="store-card-action" type="button" data-edit-store="${row.id}" aria-label="Edit ${sEscape(row.store_name)}" ${storeStaffState === "ready" ? "" : "disabled"}><i class="bi bi-pencil" aria-hidden="true"></i> Edit</button>` : ""}
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

let storeStaffState = canManageStores ? "loading" : "ready";
let storeStaffRequestSequence = 0;
function setStoreStaffState(state, detail = "") {
    storeStaffState = state;
    const result = document.getElementById("store-staff-result");
    if (result) {
        result.hidden = state === "ready";
        result.innerHTML = state === "ready" ? "" : renderStoresDataState(state === "loading" ? "loading" : "error",
            state === "loading" ? "Loading store staff…" : "Store staff unavailable", detail, state === "error" ? "staff" : "");
        if (state === "loading") result.setAttribute("aria-busy", "true");
        else result.removeAttribute("aria-busy");
    }
    const add = document.getElementById("open-store-modal");
    if (add) add.disabled = state !== "ready";
    document.querySelectorAll("[data-edit-store]").forEach(button => { button.disabled = state !== "ready"; });
}

async function loadOfficers() {
    if (!canManageStores) return;
    const requestId = ++storeStaffRequestSequence;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const isCurrent = () => requestId === storeStaffRequestSequence && !signal?.aborted;
    const result = document.getElementById("store-staff-result");
    const restoreFocus = result?.contains(document.activeElement);
    officersData = [];
    setStoreStaffState("loading");
    if (restoreFocus && document.activeElement === document.body) result.querySelector(".data-state")?.focus();
    let message = "Staff choices could not be loaded.";
    try {
        const response = await fetch("/admin/stores/officers", {signal});
        const data = await response.json();
        if (!isCurrent()) return;
        if (!response.ok || !data || data.status !== "success") {
            if (typeof data?.message === "string" && data.message.trim()) message = data.message;
            throw new Error("Staff unavailable");
        }
        if (!Array.isArray(data.data)) throw new Error("Unreadable staff choices");
        officersData = data.data;
        setStoreStaffState("ready");
        return true;
    } catch (error) {
        if (!isCurrent()) return;
        setStoreStaffState("error", `${message} Store records remain available. Retry to enable Add and Edit.`);
        return false;
    } finally {
        if (isCurrent() && restoreFocus && document.activeElement === document.body) {
            (result.querySelector("[data-store-staff-retry]") || document.getElementById("open-store-modal"))?.focus();
        }
    }
}

let storesRequestSequence = 0;
async function loadStores({resetPage = true, focusStoreId = null} = {}) {
    const requestId = ++storesRequestSequence;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const isCurrent = () => requestId === storesRequestSequence && !signal?.aborted;
    if (resetPage) storePage = 1;
    const q = (document.getElementById("store-search").value || "").trim();
    const status = document.getElementById("store-status-filter")?.value || "";
    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (status) params.set("status", status);

    storesData = [];
    const gallery = document.getElementById("stores-gallery");
    const restoreFocus = gallery.contains(document.activeElement);
    const sort = document.getElementById("store-sort");
    const refresh = document.getElementById("store-refresh-btn");
    if (sort) sort.disabled = true;
    refresh.disabled = true;
    refresh.textContent = "Refreshing…";
    gallery.setAttribute("aria-busy", "true");
    gallery.innerHTML = renderStoresDataState("loading", "Loading stores…");
    if (restoreFocus && document.activeElement === document.body) gallery.querySelector(".data-state")?.focus();
    setStoresResult("", "ok");
    let message = "The store list could not be loaded. Retry to refresh it.";
    try {
        const response = await fetch(`${storesDataUrl}?${params.toString()}`, {signal});
        const data = await response.json();
        if (!isCurrent()) return;
        if (!response.ok || !data || data.status !== "success") {
            if (typeof data?.message === "string" && data.message.trim()) message = data.message;
            throw new Error("Stores unavailable");
        }
        if (!Array.isArray(data.data)) throw new Error("Unreadable store records");
        storesData = data.data;
        renderStores(storesData);
        if (sort) sort.disabled = false;
        setStoresResult("", "ok");
        return true;
    } catch (error) {
        if (!isCurrent()) return;
        storesData = [];
        gallery.innerHTML = renderStoresDataState("error", "Stores unavailable", message, "stores");
        setStoresResult("", "ok");
        return false;
    } finally {
        if (isCurrent()) {
            gallery.removeAttribute("aria-busy");
            refresh.disabled = false;
            refresh.textContent = "Refresh";
            if (restoreFocus && document.activeElement === document.body) {
                (gallery.querySelector("[data-stores-retry]") || (focusStoreId ? gallery.querySelector(`[data-edit-store="${Number(focusStoreId)}"]:not(:disabled)`) : null) || gallery.querySelector(".store-card-title") || gallery.querySelector(".data-state"))?.focus();
            }
        }
    }
}

let storeModalOpener = null;
let storeSaving = false;
let storeSaveSequence = 0;
function focusStoreFeedback() {
    const result = document.getElementById("store-modal-result");
    result.focus({preventScroll: true});
    result.scrollIntoView({block: "nearest", inline: "nearest"});
}
function keepStoreListFocusVisible() {
    const active = document.activeElement;
    if (active?.closest?.("#stores-gallery")) active.scrollIntoView({block: "nearest", inline: "nearest"});
}
function freezeStoreEditor() {
    const modal = document.getElementById("store-modal");
    const controls = Array.from(modal.querySelectorAll("button, input, select, textarea"));
    const previous = controls.map(control => [control, control.disabled]);
    controls.forEach(control => { control.disabled = true; });
    modal.setAttribute("aria-busy", "true");
    return () => {
        previous.forEach(([control, disabled]) => { control.disabled = disabled; });
        modal.removeAttribute("aria-busy");
    };
}
function openStoreModal(mode, store) {
    if (!canManageStores || storeStaffState !== "ready" || storeSaving) return;
    storeModalOpener = document.activeElement;
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
    const signal = window.IbemsPortalNavigation?.currentSignal;
    requestAnimationFrame(() => {
        if (!signal?.aborted && !storeSaving && document.getElementById("store-modal").style.display === "grid") document.getElementById("store-name")?.focus();
    });
}

function closeStoreModal(force = false) {
    if (storeSaving && force !== true) return;
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
    if (storeModalOpener?.isConnected) storeModalOpener.focus({ preventScroll: true });
}

async function saveStore() {
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const modal = document.getElementById("store-modal");
    if (!canManageStores || storeSaving || signal?.aborted || !modal?.isConnected || modal.style.display !== "grid") return;
    const button = document.getElementById("store-save-btn");
    if (button.disabled) return;
    const savedStoreId = editingStoreId;
    const name = (document.getElementById("store-name").value || "").trim();
    const officerId = Number(document.getElementById("store-officer-id").value || 0);
    const logoUrlInput = (document.getElementById("store-logo-url").value || "").trim();
    const logoFile = document.getElementById("store-logo-file").files?.[0] || null;
    const isActive = Number(document.getElementById("store-active").value || 1);
    const deactivationReason = (document.getElementById("store-deactivation-reason").value || "").trim();

    if (!name) {
        setStoresResult("Store name is required.", "error");
        document.getElementById("store-name").focus();
        return;
    }
    if (isActive === 1 && (officerId <= 0 || selectedSupervisorIds.length === 0)) {
        setStoresResult("To activate this store, select a Store Cashier and at least one supervisor. Otherwise, keep it inactive and assign them later.", "error");
        if (officerId <= 0) officerEditorOpen = true;
        else supervisorEditorOpen = true;
        updateAssignmentEditors();
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

    const requestId = ++storeSaveSequence;
    const isPageCurrent = () => requestId === storeSaveSequence && !signal?.aborted && modal.isConnected && button.isConnected;
    const isEditorCurrent = () => isPageCurrent() && modal.style.display === "grid" && editingStoreId === savedStoreId;
    storeSaving = true;
    const restoreControls = freezeStoreEditor();
    let released = false;
    const release = () => { if (!released) { released = true; restoreControls(); storeSaving = false; button.textContent = button.dataset.idleLabel || "Save Changes"; } };
    setStoresResult("Saving store…", "pending");
    button.textContent = "Saving...";
    focusStoreFeedback();
    try {
        const response = await fetch(endpoint, {
            method: "POST",
            body: formData,
            signal,
        });
        const data = await response.json();
        if (!isEditorCurrent()) return;
        if (!response.ok || !data || data.status !== "success") {
            if (data?.status === "error") setStoresResult(typeof data.message === "string" && data.message.trim() ? data.message : "The store could not be saved. Check the details and try again.", "error");
            else setStoresResult("The store save could not be confirmed. Check the store list before trying again.", "warning");
            if (modal.contains(document.activeElement) || document.activeElement === document.body) focusStoreFeedback();
            return;
        }

        const confirmation = savedStoreId ? "Store updated." : "Store created.";
        release();
        closeStoreModal(true);
        try {
            const refreshed = await loadStores({focusStoreId: savedStoreId});
            if (!isPageCurrent()) return;
            if (refreshed === false) throw new Error("Store list refresh failed.");
            setStoresResult(confirmation, "ok", "page");
            keepStoreListFocusVisible();
        } catch (refreshError) {
            if (!isPageCurrent()) return;
            setStoresResult(`${confirmation} The store list could not be refreshed. Use Retry stores to load the latest stores.`, "warning", "page");
            keepStoreListFocusVisible();
        }
    } catch (error) {
        if (!isEditorCurrent()) return;
        setStoresResult("The store save could not be confirmed. Check the store list before trying again.", "warning");
        if (modal.contains(document.activeElement) || document.activeElement === document.body) focusStoreFeedback();
    } finally {
        if (!released && isEditorCurrent()) release();
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
    if (suppressStoreFilterEvents || document.getElementById("store-sort").disabled) return;
    storePage = 1;
    renderStores(storesData);
});

document.getElementById("open-store-modal")?.addEventListener("click", () => openStoreModal("create"));
document.getElementById("close-store-modal")?.addEventListener("click", closeStoreModal);
document.getElementById("cancel-store-modal")?.addEventListener("click", closeStoreModal);
document.getElementById("store-modal")?.addEventListener("click", (event) => {
    if (event.target.id === "store-modal") closeStoreModal();
});
document.getElementById("store-modal")?.addEventListener("keydown", event => {
    if (event.defaultPrevented) return;
    if (storeSaving && event.key === "Tab") { event.preventDefault(); focusStoreFeedback(); return; }
    if (event.key === "Escape") {
        event.preventDefault(); closeStoreModal();
    } else if (event.key === "Tab") {
        const controls = Array.from(event.currentTarget.querySelectorAll('button, input, select, textarea, a[href], [tabindex]'))
            .filter(control => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
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
    if (event.target.closest("[data-stores-retry]")) { await loadStores({resetPage: false}); return; }
    const pageButton = event.target.closest("[data-store-page]");
    if (pageButton && !pageButton.disabled) {
        const direction = pageButton.getAttribute("data-store-page");
        storePage += direction === "next" ? 1 : -1;
        renderStores(storesData);
        if (document.activeElement === document.body) {
            const buttons = Array.from(document.querySelectorAll("[data-store-page]"));
            (buttons.find(button => !button.disabled && button.getAttribute("data-store-page") === direction) || buttons.find(button => !button.disabled))?.focus();
        }
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
    const signal = window.IbemsPortalNavigation?.currentSignal;
    requestAnimationFrame(() => {
        if (signal?.aborted || document.activeElement !== document.body) return;
        const select = document.getElementById("store-page-size");
        (select?.closest(".ui-select")?.querySelector(".ui-select-trigger") || select || document.querySelector("#stores-gallery .store-card-title"))?.focus();
    });
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

document.getElementById("store-staff-result")?.addEventListener("click", event => {
    if (event.target.closest("[data-store-staff-retry]")) loadOfficers();
});
if (canManageStores) loadOfficers();
loadStores();
