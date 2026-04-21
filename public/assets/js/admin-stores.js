let storesData = [];
let officersData = [];
let editingStoreId = null;
let lastOfficerSearchQuery = "";
let editingCurrentLogoUrl = "";
let logoInputMode = "upload";

function sEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function sDateTime(value) {
    return new Date(value).toLocaleString();
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

function renderOfficerSuggestions(query) {
    const box = document.getElementById("store-officer-suggestions");
    const matches = getOfficerMatches(query);
    if (matches.length === 0) {
        box.style.display = "none";
        box.innerHTML = "";
        return;
    }

    box.innerHTML = matches.map((officer) => {
        const role = formatRoleLabel(officer.role);
        const type = formatTypeLabel(officer.user_type);
        const employee = officer.employee_id ? `${sEscape(officer.employee_id)} - ` : "";
        return `
            <button class="officer-suggestion-item" type="button" data-officer-pick="${officer.id}">
                ${employee}${sEscape(officer.name)} (${sEscape(officer.email)}) - ${sEscape(role)} / ${sEscape(type)}
            </button>
        `;
    }).join("");
    box.style.display = "grid";
}

function findOfficerById(officerId) {
    return officersData.find((officer) => Number(officer.id) === Number(officerId)) || null;
}

function toggleLogoSourceUI() {
    document.getElementById("store-logo-upload-wrap").style.display = logoInputMode === "upload" ? "" : "none";
    document.getElementById("store-logo-url-wrap").style.display = logoInputMode === "url" ? "" : "none";
}

function renderStores(rows) {
    const gallery = document.getElementById("stores-gallery");
    if (!Array.isArray(rows) || rows.length === 0) {
        gallery.innerHTML = '<div class="store-card-empty">No stores found.</div>';
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
        return `
            <a href="/admin/stores/${row.id}" class="store-card">
                <div class="store-card-logo">${logo}</div>
                <div class="store-card-meta">
                    <strong>${sEscape(row.store_name)}</strong>
                    <small>${sEscape(row.officer_name || "No assigned officer")}</small><br>
                    <small>${row.created_at ? sEscape(sDateTime(row.created_at)) : "-"}</small>
                </div>
                <div class="store-card-right">
                    <button
                        class="card-menu-btn"
                        type="button"
                        aria-label="Store actions"
                        data-card-menu-toggle="${row.id}"
                    >...</button>
                    <div class="card-menu" data-card-menu="${row.id}">
                        <button class="card-menu-item" type="button" data-edit-store="${row.id}">Edit</button>
                        <button
                            class="card-menu-item card-menu-status ${Number(row.is_active) === 1 ? "is-active" : "is-inactive"}"
                            type="button"
                            data-toggle-store="${row.id}"
                            data-next-status="${Number(row.is_active) === 1 ? 0 : 1}"
                        >
                            ${Number(row.is_active) === 1 ? "Active" : "Inactive"}
                        </button>
                    </div>
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
    const params = new URLSearchParams();
    if (q) params.set("q", q);

    const response = await fetch(`/admin/stores/data?${params.toString()}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        storesData = [];
        renderStores([]);
        setStoresResult(data?.message || "Unable to load stores.", "error");
        return;
    }

    storesData = Array.isArray(data.data) ? data.data : [];
    renderStores(storesData);
    setStoresResult("", "ok");
}

function openStoreModal(mode, store) {
    editingStoreId = mode === "edit" ? Number(store.id) : null;
    document.getElementById("store-modal-title").textContent = mode === "edit" ? "Edit Store" : "Add Store";
    document.getElementById("store-name").value = mode === "edit" ? store.store_name || "" : "";
    editingCurrentLogoUrl = mode === "edit" ? String(store.logo_url || "") : "";
    document.getElementById("store-logo-url").value = "";
    document.getElementById("store-logo-file").value = "";
    document.getElementById("store-active").value = mode === "edit" ? String(Number(store.is_active) === 1 ? 1 : 0) : "1";
    const selectedOfficerId = mode === "edit" ? Number(store.officer_id || 0) : 0;
    document.getElementById("store-officer-id").value = selectedOfficerId > 0 ? String(selectedOfficerId) : "";
    if (selectedOfficerId > 0) {
        const officer = findOfficerById(selectedOfficerId);
        document.getElementById("store-officer-search").value = officer
            ? `${officer.name} (${officer.email})`
            : `${store.officer_name || ""} (${store.officer_email || ""})`;
    } else {
        document.getElementById("store-officer-search").value = "";
    }
    document.getElementById("store-officer-suggestions").style.display = "none";
    document.getElementById("store-officer-suggestions").innerHTML = "";
    lastOfficerSearchQuery = "";
    if (editingCurrentLogoUrl && /^https?:\/\//i.test(editingCurrentLogoUrl)) {
        logoInputMode = "url";
        document.getElementById("store-logo-url").value = editingCurrentLogoUrl;
    } else {
        logoInputMode = "upload";
    }
    toggleLogoSourceUI();
    document.getElementById("store-modal").style.display = "grid";
}

function closeStoreModal() {
    editingStoreId = null;
    editingCurrentLogoUrl = "";
    document.getElementById("store-officer-suggestions").style.display = "none";
    document.getElementById("store-modal").style.display = "none";
}

async function saveStore() {
    const name = (document.getElementById("store-name").value || "").trim();
    const officerId = Number(document.getElementById("store-officer-id").value || 0);
    const logoUrlInput = (document.getElementById("store-logo-url").value || "").trim();
    const logoFile = document.getElementById("store-logo-file").files?.[0] || null;
    const isActive = Number(document.getElementById("store-active").value || 1);
    const button = document.getElementById("store-save-btn");

    if (!name || officerId <= 0) {
        setStoresResult("Store name and officer are required.", "error");
        return;
    }

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
    formData.append("logo_url", logoUrl);
    if (logoInputMode === "upload" && logoFile) {
        formData.append("logo_file", logoFile);
    }
    if (editingStoreId) {
        formData.append("store_id", String(editingStoreId));
        formData.append("is_active", String(isActive));
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

        if (editingStoreId && isActive !== -1) {
            await fetch("/admin/stores/toggle-status", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    store_id: editingStoreId,
                    is_active: isActive,
                }),
            });
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

async function toggleStoreStatus(storeId, nextStatus) {
    const confirmed = window.confirm(`Confirm ${Number(nextStatus) === 1 ? "activation" : "deactivation"} of this store?`);
    if (!confirmed) return;

    const response = await fetch("/admin/stores/toggle-status", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            store_id: Number(storeId),
            is_active: Number(nextStatus),
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        setStoresResult(data?.message || "Failed to update store status.", "error");
        return;
    }

    setStoresResult("Store status updated.", "ok");
    await loadStores();
}

document.getElementById("store-search-btn").addEventListener("click", async () => {
    await loadStores();
});

document.getElementById("store-refresh-btn").addEventListener("click", async () => {
    document.getElementById("store-search").value = "";
    await loadStores();
});

document.getElementById("open-store-modal").addEventListener("click", () => openStoreModal("create"));
document.getElementById("close-store-modal").addEventListener("click", closeStoreModal);
document.getElementById("store-modal").addEventListener("click", (event) => {
    if (event.target.id === "store-modal") closeStoreModal();
});
document.getElementById("store-save-btn").addEventListener("click", saveStore);
document.getElementById("store-logo-toggle").addEventListener("click", () => {
    logoInputMode = "url";
    toggleLogoSourceUI();
    document.getElementById("store-logo-url").focus();
});
document.getElementById("store-logo-toggle-url").addEventListener("click", () => {
    logoInputMode = "upload";
    toggleLogoSourceUI();
    document.getElementById("store-logo-file").focus();
});
document.getElementById("store-officer-search").addEventListener("input", (event) => {
    const query = event.target.value || "";
    lastOfficerSearchQuery = query;
    document.getElementById("store-officer-id").value = "";
    renderOfficerSuggestions(query);
});
document.getElementById("store-officer-search").addEventListener("focus", (event) => {
    renderOfficerSuggestions(event.target.value || "");
});
document.getElementById("store-officer-suggestions").addEventListener("click", (event) => {
    const item = event.target.closest("[data-officer-pick]");
    if (!item) return;
    const officerId = Number(item.getAttribute("data-officer-pick") || 0);
    if (!officerId) return;

    const picked = officersData.find((officer) => Number(officer.id) === officerId);
    if (!picked) return;

    document.getElementById("store-officer-search").value = `${picked.name} (${picked.email})`;
    document.getElementById("store-officer-id").value = String(officerId);
    document.getElementById("store-officer-suggestions").style.display = "none";
});

document.getElementById("stores-gallery").addEventListener("click", async (event) => {
    const toggleMenuBtn = event.target.closest("[data-card-menu-toggle]");
    if (toggleMenuBtn) {
        event.preventDefault();
        const id = toggleMenuBtn.getAttribute("data-card-menu-toggle");
        document.querySelectorAll(".card-menu.is-open").forEach((menu) => {
            if (menu.getAttribute("data-card-menu") !== id) {
                menu.classList.remove("is-open");
            }
        });
        const menu = document.querySelector(`[data-card-menu="${id}"]`);
        if (menu) {
            menu.classList.toggle("is-open");
        }
        return;
    }

    const editBtn = event.target.closest("[data-edit-store]");
    if (editBtn) {
        event.preventDefault();
        const storeId = Number(editBtn.getAttribute("data-edit-store"));
        const store = storesData.find((row) => Number(row.id) === storeId);
        if (!store) return;
        document.querySelectorAll(".card-menu.is-open").forEach((menu) => menu.classList.remove("is-open"));
        openStoreModal("edit", store);
        return;
    }

    const toggleBtn = event.target.closest("[data-toggle-store]");
    if (toggleBtn) {
        event.preventDefault();
        document.querySelectorAll(".card-menu.is-open").forEach((menu) => menu.classList.remove("is-open"));
        await toggleStoreStatus(
            toggleBtn.getAttribute("data-toggle-store"),
            toggleBtn.getAttribute("data-next-status")
        );
    }
});

document.addEventListener("click", (event) => {
    if (!event.target.closest(".store-card-right")) {
        document.querySelectorAll(".card-menu.is-open").forEach((menu) => menu.classList.remove("is-open"));
    }
    if (!event.target.closest("#store-officer-search") && !event.target.closest("#store-officer-suggestions")) {
        const suggestionBox = document.getElementById("store-officer-suggestions");
        if (suggestionBox) suggestionBox.style.display = "none";
    }
});

(async () => {
    try {
        await loadOfficers();
        await loadStores();
    } catch (error) {
        setStoresResult(error.message || "Failed to initialize store management.", "error");
    }
})();
