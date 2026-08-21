let uvRows = [];
let uvEditingUserId = null;
let uvViewingUserId = null;
let uvQuickFilter = "all";
let uvPage = 1;

function aMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function aEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
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

function renderRoleChips(roles) {
    return roles.map((role) => `
        <span class="uv-role-chip inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700">${aEscape(formatRoleLabel(role))}</span>
    `).join("");
}

function normalizeRoles(roles, fallbackRole) {
    if (Array.isArray(roles) && roles.length) {
        return [...new Set(roles.map((role) => String(role || "").toUpperCase()).filter(Boolean))];
    }
    const fallback = String(fallbackRole || "").toUpperCase();
    return fallback ? [fallback] : ["USER"];
}

function setRoleChecks(prefix, roles, fallbackRole) {
    const normalized = normalizeRoles(roles, fallbackRole);
    document.querySelectorAll(`input[name="${prefix}-roles"]`).forEach((input) => {
        input.checked = normalized.includes(String(input.value || "").toUpperCase());
    });
}

function getRoleChecks(prefix) {
    return Array.from(document.querySelectorAll(`input[name="${prefix}-roles"]:checked`))
        .map((input) => String(input.value || "").toUpperCase())
        .filter(Boolean);
}

function setUvResult(message, type) {
    const el = document.getElementById("uv-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function getFilteredRows() {
    const q = (document.getElementById("uv-search").value || "").trim().toLowerCase();
    const roleFilter = (document.getElementById("uv-role-filter").value || "").trim().toUpperCase();
    const typeFilter = (document.getElementById("uv-type-filter").value || "").trim().toLowerCase();

    return uvRows.filter((row) => {
        const roleList = normalizeRoles(row.roles, row.role);
        const matchesQuick =
            uvQuickFilter === "all" ? true :
            uvQuickFilter === "active" ? !!row.is_active :
            uvQuickFilter === "debt" ? Number(row.current_debt || 0) > 0 :
            uvQuickFilter === "store_system" ? roleList.includes("STORE_SYSTEM") :
            true;

        if (!matchesQuick) return false;
        if (roleFilter && !roleList.includes(roleFilter)) return false;
        if (typeFilter && String(row.user_type || "").toLowerCase() !== typeFilter) return false;

        if (!q) return true;
        const haystack = `${row.name || ""} ${row.email || ""} ${row.employee_id || ""} ${roleList.join(" ")}`.toLowerCase();
        return haystack.includes(q);
    });
}

function renderTopSummary(rows) {
    const list = Array.isArray(rows) ? rows : [];
    const totalUsers = list.length;
    const totalDebt = list.reduce((sum, row) => sum + Number(row.current_debt || 0), 0);
    const activeFaculty = list.filter((row) => !!row.is_active && String(row.user_type || "").toLowerCase() === "faculty").length;
    const storeOfficers = list.filter((row) => normalizeRoles(row.roles, row.role).includes("STORE_SYSTEM")).length;

    document.getElementById("uv-total-users").textContent = String(totalUsers);
    document.getElementById("uv-total-debt").textContent = aMoney(totalDebt);
    document.getElementById("uv-active-faculty").textContent = String(activeFaculty);
    document.getElementById("uv-store-officers").textContent = String(storeOfficers);
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove("is-hidden");
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add("is-hidden");
}

function setQuickChipState(chip, active) {
    if (!chip) return;
    chip.classList.toggle("is-active", !!active);
    chip.classList.toggle("bg-blue-600", !!active);
    chip.classList.toggle("text-white", !!active);
    chip.classList.toggle("border-blue-700", !!active);
    chip.classList.toggle("shadow-sm", !!active);
    chip.classList.toggle("bg-white", !active);
    chip.classList.toggle("text-slate-700", !active);
    chip.classList.toggle("border-slate-200", !active);
}

function renderUvDataState(type, message) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    return `<div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${aEscape(message)}</strong></div></div>`;
}

function renderUserTable(rows) {
    const body = document.getElementById("uv-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = renderUvDataState("empty", "No users found.");
        return;
    }

    body.innerHTML = rows.map((row) => {
        const roles = normalizeRoles(row.roles, row.role);
        const statusText = row.is_active ? "Active" : "Inactive";
        const debt = Number(row.current_debt || 0);
        const creditLimit = Number(row.credit_limit || 0);
        const profileConfigured = Boolean(row.financial_profile_configured);
        const initials = String(row.name || "U")
            .split(" ")
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part.charAt(0).toUpperCase())
            .join("") || "U";
        return `
        <article class="record-list-item uv-record-row flex flex-wrap items-center justify-between gap-3" data-user-id="${row.id}">
            <button class="uv-row-view flex min-w-0 grow items-center gap-3 text-left" type="button" data-view-user="${row.id}" title="View employee details">
                <div class="uv-avatar inline-flex h-11 w-11 flex-none items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-sm font-bold text-blue-700">${aEscape(initials)}</div>
                <div class="uv-person-meta min-w-0">
                    <div class="uv-name-line flex flex-wrap items-center gap-2">
                        <strong class="text-base font-bold text-slate-900">${aEscape(row.name || "-")}</strong>
                        <span class="uv-status inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${row.is_active ? "is-active bg-emerald-100 text-emerald-700" : "is-inactive bg-amber-100 text-amber-700"}">${statusText}</span>
                    </div>
                    <div class="uv-subline truncate text-sm text-slate-500">${aEscape(row.employee_id || "-")} | ${aEscape(formatTypeLabel(row.user_type))} | ${aEscape(row.email || "-")}</div>
                    <div class="uv-role-list mt-2 flex flex-wrap gap-1.5">${renderRoleChips(roles)}</div>
                </div>
            </button>
            <div class="uv-finance flex flex-wrap items-center justify-end gap-4">
                ${profileConfigured ? `
                <div class="uv-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Debt</span>
                    <strong class="text-sm font-semibold ${debt > 0 ? "uv-money-debt text-rose-600" : "text-slate-900"}">${aEscape(aMoney(debt))}</strong>
                </div>
                <div class="uv-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Credit Limit</span>
                    <strong class="text-sm font-semibold text-slate-900">${aEscape(aMoney(creditLimit))}</strong>
                </div>
                ` : '<div class="uv-fin-kv grid gap-0.5"><span class="text-xs text-slate-500">Financial Profile</span><strong class="text-sm font-semibold text-amber-700">Not configured</strong></div>'}
                <button class="secondary-btn btn-sm uv-edit-btn" type="button" data-edit-user="${row.id}" title="Edit employee profile">
                    <i class="bi bi-pencil"></i> Edit
                </button>
            </div>
        </article>
    `;
    }).join("");
}

function applyUserFiltersAndRender() {
    const filtered = getFilteredRows();
    const [sortBy, sortDir] = (document.getElementById("uv-sort").value || "name:asc").split(":");
    const direction = sortDir === "desc" ? -1 : 1;
    filtered.sort((left, right) => {
        let a;
        let b;
        if (sortBy === "debt") {
            a = Number(left.current_debt || 0);
            b = Number(right.current_debt || 0);
        } else if (sortBy === "credit") {
            a = Number(left.credit_limit || 0);
            b = Number(right.credit_limit || 0);
        } else if (sortBy === "type") {
            a = String(left.user_type || "").toLowerCase();
            b = String(right.user_type || "").toLowerCase();
        } else {
            a = String(left.name || "").toLowerCase();
            b = String(right.name || "").toLowerCase();
        }
        return (typeof a === "number" ? a - b : a.localeCompare(b)) * direction;
    });
    const pageSize = Math.max(10, Number(document.getElementById("uv-page-size").value || 25));
    const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
    uvPage = Math.min(uvPage, totalPages);
    const pageRows = filtered.slice((uvPage - 1) * pageSize, uvPage * pageSize);
    const countText = document.getElementById("uv-count-text");
    if (countText) countText.textContent = `Showing ${pageRows.length} of ${filtered.length} matching records · ${uvRows.length} total`;
    renderTopSummary(filtered);
    renderUserTable(pageRows);
    document.getElementById("uv-pager").innerHTML = `
        <button class="secondary-btn btn-sm" type="button" data-page="${uvPage - 1}" ${uvPage <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left"></i> Previous</button>
        <span>Page ${uvPage} of ${totalPages}</span>
        <button class="secondary-btn btn-sm" type="button" data-page="${uvPage + 1}" ${uvPage >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right"></i></button>`;
}

async function loadUserView() {
    const q = (document.getElementById("uv-search").value || "").trim();
    const params = new URLSearchParams();
    if (q) params.set("q", q);

    const response = await fetch(`/admin/user-view/data?${params.toString()}`);
    const data = await response.json();
    const body = document.getElementById("uv-body");
    if (!data || data.status !== "success") {
        body.innerHTML = renderUvDataState("error", data?.message || "Unable to load users.");
        const countText = document.getElementById("uv-count-text");
        if (countText) countText.textContent = "Showing 0 of 0 records";
        renderTopSummary([]);
        return;
    }

    uvRows = Array.isArray(data.data) ? data.data : [];
    applyUserFiltersAndRender();
}

async function openEditUser(userId) {
    const response = await fetch(`/admin/user-view/${userId}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        setUvResult(data?.message || "Unable to load user detail.", "error");
        return;
    }

    const row = data.data;
    uvEditingUserId = Number(row.id);
    document.getElementById("uv-e-employee-id").value = row.employee_id || "";
    document.getElementById("uv-e-name").value = row.name || "";
    document.getElementById("uv-e-email").value = row.email || "";
    setRoleChecks("uv-e", row.roles, row.role);
    document.getElementById("uv-e-type").value = row.user_type || "staff";
    document.getElementById("uv-e-active").value = row.is_active ? "1" : "0";
    openModal("uv-edit-modal");
}

async function openViewUser(userId) {
    const response = await fetch(`/admin/user-view/${userId}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        setUvResult(data?.message || "Unable to load user detail.", "error");
        return;
    }

    const row = data.data;
    uvViewingUserId = Number(row.id);
    document.getElementById("uv-v-employee-id").value = row.employee_id || "-";
    document.getElementById("uv-v-name").value = row.name || "-";
    document.getElementById("uv-v-email").value = row.email || "-";
    document.getElementById("uv-v-roles").value = normalizeRoles(row.roles, row.role).map(formatRoleLabel).join(", ");
    document.getElementById("uv-v-type").value = formatTypeLabel(row.user_type);
    document.getElementById("uv-v-status").value = row.is_active ? "Active" : "Inactive";
    const profileConfigured = Boolean(row.financial_profile_configured);
    document.getElementById("uv-v-salary").value = profileConfigured ? aMoney(row.base_salary || 0) : "Not configured by Accounting";
    document.getElementById("uv-v-credit-limit").value = profileConfigured ? aMoney(row.credit_limit || 0) : "Not configured by Accounting";
    document.getElementById("uv-v-current-debt").value = profileConfigured ? aMoney(row.current_debt || 0) : "Not configured by Accounting";
    document.getElementById("uv-v-created-at").value = row.created_at || "-";
    openModal("uv-view-modal");
}

async function saveEditedUser() {
    if (!uvEditingUserId) return;

    const roles = getRoleChecks("uv-e");
    if (!roles.length) {
        setUvResult("Select at least one role.", "error");
        return;
    }

    const payload = {
        user_id: uvEditingUserId,
        employee_id: (document.getElementById("uv-e-employee-id").value || "").trim(),
        name: (document.getElementById("uv-e-name").value || "").trim(),
        email: (document.getElementById("uv-e-email").value || "").trim(),
        roles,
        user_type: document.getElementById("uv-e-type").value,
        is_active: Number(document.getElementById("uv-e-active").value || 1),
    };

    const response = await fetch("/admin/user-view/update", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        setUvResult(data?.message || "Failed to update user.", "error");
        return;
    }

    closeModal("uv-edit-modal");
    setUvResult("User updated.", "ok");
    await loadUserView();
}

async function createUser() {
    const roles = getRoleChecks("uv-a");
    if (!roles.length) {
        setUvResult("Select at least one role.", "error");
        return;
    }

    const payload = {
        employee_id: (document.getElementById("uv-a-employee-id").value || "").trim(),
        name: (document.getElementById("uv-a-name").value || "").trim(),
        email: (document.getElementById("uv-a-email").value || "").trim(),
        password: (document.getElementById("uv-a-password").value || "").trim(),
        roles,
        user_type: document.getElementById("uv-a-type").value,
        is_active: 1,
    };

    const response = await fetch("/admin/user-view/create", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        setUvResult(data?.message || "Failed to create user.", "error");
        return;
    }

    closeModal("uv-add-modal");
    const initialCredit = Number(data.initial_credit_limit || 0);
    setUvResult(`User created${initialCredit > 0 ? ` with ${aMoney(initialCredit)} base credit` : ""}.`, "ok");
    await loadUserView();
}

async function importUsersCsv() {
    const button = document.getElementById("uv-import-submit");
    const result = document.getElementById("uv-import-result");
    if (button.disabled) return;
    const file = document.getElementById("uv-import-file").files?.[0];
    if (!file) {
        result.textContent = "Please choose a CSV file.";
        result.style.color = "#b91c1c";
        return;
    }

    const fd = new FormData();
    fd.append("csv_file", file);

    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    button.innerHTML = '<i class="bi bi-arrow-repeat" style="display:inline-block;animation:data-state-spin .9s linear infinite" aria-hidden="true"></i> Importing...';
    result.textContent = "Uploading and validating employee records...";
    result.style.color = "#475569";

    try {
        const response = await fetch("/admin/user-view/import-csv", {
            method: "POST",
            body: fd,
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.status !== "success") {
            throw new Error(data?.message || "Import failed. Please check the server log and CSV values.");
        }

        result.textContent = `Import complete. Created: ${data.created}, updated: ${data.updated}.`;
        result.style.color = "#166534";
        const invalidNote = Number(data.invalid || 0) > 0 ? ` ${data.invalid} row(s) skipped.` : "";
        closeModal("uv-import-modal");
        setUvResult(`Import done. Created: ${data.created}, updated: ${data.updated}.${invalidNote}`, Number(data.invalid || 0) > 0 ? "error" : "ok");
        await loadUserView();
    } catch (error) {
        result.textContent = error.message || "Import failed.";
        result.style.color = "#b91c1c";
    } finally {
        button.disabled = false;
        button.removeAttribute("aria-busy");
        button.textContent = "Import";
    }
}

function exportFilteredRowsToCsv() {
    const rows = getFilteredRows();
    if (!rows.length) {
        setUvResult("No records to export.", "error");
        return;
    }

    const headers = ["employee_id", "name", "email", "roles", "category", "status", "current_debt", "credit_limit"];
    const lines = [headers.join(",")];

    rows.forEach((row) => {
        const roles = normalizeRoles(row.roles, row.role).join("|");
        const fields = [
            row.employee_id || "",
            row.name || "",
            row.email || "",
            roles,
            row.user_type || "",
            row.is_active ? "Active" : "Inactive",
            Number(row.current_debt || 0).toFixed(2),
            Number(row.credit_limit || 0).toFixed(2),
        ].map((field) => `"${String(field).replace(/"/g, '""')}"`);
        lines.push(fields.join(","));
    });

    const blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `employee-records-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
    setUvResult("Export completed.", "ok");
}

document.getElementById("uv-search-btn").addEventListener("click", loadUserView);
document.getElementById("uv-refresh-btn").addEventListener("click", () => {
    document.getElementById("uv-search").value = "";
    document.getElementById("uv-role-filter").value = "";
    document.getElementById("uv-type-filter").value = "";
    document.getElementById("uv-sort").value = "name:asc";
    document.getElementById("uv-page-size").value = "25";
    uvPage = 1;
    uvQuickFilter = "all";
    document.querySelectorAll("[data-uv-quick]").forEach((chip) => {
        setQuickChipState(chip, chip.getAttribute("data-uv-quick") === "all");
    });
    loadUserView();
});
["uv-role-filter", "uv-type-filter", "uv-sort", "uv-page-size"].forEach((id) => {
    document.getElementById(id).addEventListener("change", () => { uvPage = 1; applyUserFiltersAndRender(); });
});
document.getElementById("uv-search").addEventListener("input", () => { uvPage = 1; applyUserFiltersAndRender(); });
document.querySelectorAll("[data-uv-quick]").forEach((chip) => {
    chip.addEventListener("click", () => {
        uvQuickFilter = chip.getAttribute("data-uv-quick") || "all";
        uvPage = 1;
        document.querySelectorAll("[data-uv-quick]").forEach((item) => {
            setQuickChipState(item, item === chip);
        });
        applyUserFiltersAndRender();
    });
});

document.querySelectorAll("[data-uv-quick]").forEach((chip) => {
    setQuickChipState(chip, chip.classList.contains("is-active"));
});

document.getElementById("uv-add-btn").addEventListener("click", () => {
    setRoleChecks("uv-a", ["USER"]);
    openModal("uv-add-modal");
});
document.getElementById("uv-import-btn").addEventListener("click", () => openModal("uv-import-modal"));
document.getElementById("uv-export-btn").addEventListener("click", exportFilteredRowsToCsv);
document.getElementById("uv-add-close").addEventListener("click", () => closeModal("uv-add-modal"));
document.getElementById("uv-edit-close").addEventListener("click", () => closeModal("uv-edit-modal"));
document.getElementById("uv-import-close").addEventListener("click", () => closeModal("uv-import-modal"));
document.getElementById("uv-view-close").addEventListener("click", () => closeModal("uv-view-modal"));
document.getElementById("uv-add-save").addEventListener("click", createUser);
document.getElementById("uv-edit-save").addEventListener("click", saveEditedUser);
document.getElementById("uv-import-submit").addEventListener("click", importUsersCsv);
document.getElementById("uv-view-edit").addEventListener("click", async () => {
    if (!uvViewingUserId) return;
    closeModal("uv-view-modal");
    await openEditUser(uvViewingUserId);
});

document.getElementById("uv-body").addEventListener("click", (event) => {
    const editBtn = event.target.closest("[data-edit-user]");
    if (editBtn) {
        event.stopPropagation();
        const userId = Number(editBtn.getAttribute("data-edit-user") || 0);
        if (userId) openEditUser(userId);
        return;
    }

    const viewTarget = event.target.closest("[data-view-user], .uv-record-row");
    if (!viewTarget) return;
    const userId = Number(viewTarget.getAttribute("data-view-user") || viewTarget.getAttribute("data-user-id") || 0);
    if (userId) openViewUser(userId);
});
document.getElementById("uv-pager").addEventListener("click", (event) => {
    const button = event.target.closest("[data-page]");
    if (!button || button.disabled) return;
    uvPage = Math.max(1, Number(button.dataset.page || 1));
    applyUserFiltersAndRender();
});

["uv-add-modal", "uv-edit-modal", "uv-import-modal", "uv-view-modal"].forEach((id) => {
    document.getElementById(id).addEventListener("click", (event) => {
        if (event.target.id === id) closeModal(id);
    });
});

loadUserView();
