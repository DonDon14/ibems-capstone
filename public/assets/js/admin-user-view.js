let uvRows = [];
let uvEditingUserId = null;
let uvViewingUserId = null;
let uvQuickFilter = "all";

function aMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
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
    document.getElementById(id).style.display = "grid";
}

function closeModal(id) {
    document.getElementById(id).style.display = "none";
}

function renderUserTable(rows) {
    const body = document.getElementById("uv-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="8">No users found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => {
        const roles = normalizeRoles(row.roles, row.role);
        return `
        <tr class="uv-row" data-user-id="${row.id}" style="cursor:pointer;">
            <td>${aEscape(row.employee_id || "-")}</td>
            <td>${aEscape(row.name)}</td>
            <td>${aEscape(row.email)}</td>
            <td>${roles.map((role) => `<span class="uv-tag">${aEscape(formatRoleLabel(role))}</span>`).join(" ")}</td>
            <td>${aEscape(formatTypeLabel(row.user_type))}</td>
            <td><span class="uv-status ${row.is_active ? "is-active" : "is-inactive"}">${row.is_active ? "Active" : "Inactive"}</span></td>
            <td>
                <div class="uv-debt-wrap">
                    <span>${aEscape(aMoney(row.current_debt))}</span>
                    <div class="uv-debt-bar"><i style="width:${Math.min(100, (Number(row.current_debt || 0) / Math.max(1, Number(row.credit_limit || 0))) * 100)}%"></i></div>
                </div>
            </td>
            <td>${aEscape(aMoney(row.credit_limit))}</td>
        </tr>
    `;
    }).join("");
}

function applyUserFiltersAndRender() {
    const filtered = getFilteredRows();
    renderTopSummary(filtered);
    renderUserTable(filtered);
}

async function loadUserView() {
    const q = (document.getElementById("uv-search").value || "").trim();
    const params = new URLSearchParams();
    if (q) params.set("q", q);

    const response = await fetch(`/admin/user-view/data?${params.toString()}`);
    const data = await response.json();
    const body = document.getElementById("uv-body");
    if (!data || data.status !== "success") {
        body.innerHTML = '<tr><td colspan="8">Unable to load users.</td></tr>';
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
    document.getElementById("uv-e-salary").value = Number(row.base_salary || 0);
    document.getElementById("uv-e-credit-limit").value = Number(row.credit_limit || 0);
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
    document.getElementById("uv-v-salary").value = aMoney(row.base_salary || 0);
    document.getElementById("uv-v-credit-limit").value = aMoney(row.credit_limit || 0);
    document.getElementById("uv-v-current-debt").value = aMoney(row.current_debt || 0);
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
        base_salary: Number(document.getElementById("uv-e-salary").value || 0),
        credit_limit: Number(document.getElementById("uv-e-credit-limit").value || 0),
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
        base_salary: Number(document.getElementById("uv-a-salary").value || 0),
        credit_limit: Number(document.getElementById("uv-a-credit-limit").value || 0),
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
    setUvResult("User created.", "ok");
    await loadUserView();
}

async function importUsersCsv() {
    const file = document.getElementById("uv-import-file").files?.[0];
    if (!file) {
        setUvResult("Please choose a CSV file.", "error");
        return;
    }

    const fd = new FormData();
    fd.append("csv_file", file);

    const response = await fetch("/admin/user-view/import-csv", {
        method: "POST",
        body: fd,
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        setUvResult(data?.message || "Import failed.", "error");
        return;
    }

    closeModal("uv-import-modal");
    setUvResult(`Import done. Created: ${data.created}, Updated: ${data.updated}, Invalid: ${data.invalid}`, "ok");
    await loadUserView();
}

document.getElementById("uv-search-btn").addEventListener("click", loadUserView);
document.getElementById("uv-refresh-btn").addEventListener("click", () => {
    document.getElementById("uv-search").value = "";
    document.getElementById("uv-role-filter").value = "";
    document.getElementById("uv-type-filter").value = "";
    uvQuickFilter = "all";
    document.querySelectorAll("[data-uv-quick]").forEach((chip) => {
        chip.classList.toggle("is-active", chip.getAttribute("data-uv-quick") === "all");
    });
    loadUserView();
});
document.getElementById("uv-role-filter").addEventListener("change", applyUserFiltersAndRender);
document.getElementById("uv-type-filter").addEventListener("change", applyUserFiltersAndRender);
document.getElementById("uv-search").addEventListener("input", applyUserFiltersAndRender);
document.querySelectorAll("[data-uv-quick]").forEach((chip) => {
    chip.addEventListener("click", () => {
        uvQuickFilter = chip.getAttribute("data-uv-quick") || "all";
        document.querySelectorAll("[data-uv-quick]").forEach((item) => {
            item.classList.toggle("is-active", item === chip);
        });
        applyUserFiltersAndRender();
    });
});

document.getElementById("uv-add-btn").addEventListener("click", () => {
    setRoleChecks("uv-a", ["USER"]);
    openModal("uv-add-modal");
});
document.getElementById("uv-import-btn").addEventListener("click", () => openModal("uv-import-modal"));
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
    const row = event.target.closest(".uv-row");
    if (!row) return;
    const userId = Number(row.getAttribute("data-user-id") || 0);
    if (!userId) return;
    openViewUser(userId);
});

["uv-add-modal", "uv-edit-modal", "uv-import-modal", "uv-view-modal"].forEach((id) => {
    document.getElementById(id).addEventListener("click", (event) => {
        if (event.target.id === id) closeModal(id);
    });
});

loadUserView();
