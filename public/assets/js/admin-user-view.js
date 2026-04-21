let uvRows = [];
let uvEditingUserId = null;

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

function setUvResult(message, type) {
    const el = document.getElementById("uv-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function openModal(id) {
    document.getElementById(id).style.display = "grid";
}

function closeModal(id) {
    document.getElementById(id).style.display = "none";
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
        return;
    }

    uvRows = Array.isArray(data.data) ? data.data : [];
    if (uvRows.length === 0) {
        body.innerHTML = '<tr><td colspan="8">No users found.</td></tr>';
        return;
    }

    body.innerHTML = uvRows.map((row) => `
        <tr class="uv-row" data-user-id="${row.id}" style="cursor:pointer;">
            <td>${aEscape(row.employee_id || "-")}</td>
            <td>${aEscape(row.name)}</td>
            <td>${aEscape(row.email)}</td>
            <td>${aEscape(formatRoleLabel(row.role || "-"))}</td>
            <td>${aEscape(formatTypeLabel(row.user_type))}</td>
            <td>${row.is_active ? "Active" : "Inactive"}</td>
            <td>${aEscape(aMoney(row.current_debt))}</td>
            <td>${aEscape(aMoney(row.credit_limit))}</td>
        </tr>
    `).join("");
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
    document.getElementById("uv-e-role").value = row.role || "USER";
    document.getElementById("uv-e-type").value = row.user_type || "staff";
    document.getElementById("uv-e-salary").value = Number(row.base_salary || 0);
    document.getElementById("uv-e-credit-limit").value = Number(row.credit_limit || 0);
    document.getElementById("uv-e-active").value = row.is_active ? "1" : "0";
    openModal("uv-edit-modal");
}

async function saveEditedUser() {
    if (!uvEditingUserId) return;
    const payload = {
        user_id: uvEditingUserId,
        employee_id: (document.getElementById("uv-e-employee-id").value || "").trim(),
        name: (document.getElementById("uv-e-name").value || "").trim(),
        email: (document.getElementById("uv-e-email").value || "").trim(),
        role: document.getElementById("uv-e-role").value,
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
    const payload = {
        employee_id: (document.getElementById("uv-a-employee-id").value || "").trim(),
        name: (document.getElementById("uv-a-name").value || "").trim(),
        email: (document.getElementById("uv-a-email").value || "").trim(),
        password: (document.getElementById("uv-a-password").value || "").trim(),
        role: document.getElementById("uv-a-role").value,
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
    loadUserView();
});

document.getElementById("uv-add-btn").addEventListener("click", () => openModal("uv-add-modal"));
document.getElementById("uv-import-btn").addEventListener("click", () => openModal("uv-import-modal"));
document.getElementById("uv-add-close").addEventListener("click", () => closeModal("uv-add-modal"));
document.getElementById("uv-edit-close").addEventListener("click", () => closeModal("uv-edit-modal"));
document.getElementById("uv-import-close").addEventListener("click", () => closeModal("uv-import-modal"));
document.getElementById("uv-add-save").addEventListener("click", createUser);
document.getElementById("uv-edit-save").addEventListener("click", saveEditedUser);
document.getElementById("uv-import-submit").addEventListener("click", importUsersCsv);

document.getElementById("uv-body").addEventListener("click", (event) => {
    const row = event.target.closest(".uv-row");
    if (!row) return;
    const userId = Number(row.getAttribute("data-user-id") || 0);
    if (!userId) return;
    openEditUser(userId);
});

["uv-add-modal", "uv-edit-modal", "uv-import-modal"].forEach((id) => {
    document.getElementById(id).addEventListener("click", (event) => {
        if (event.target.id === id) closeModal(id);
    });
});

loadUserView();
