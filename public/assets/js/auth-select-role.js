const statusEl = document.getElementById("status");
const optionsEl = document.getElementById("role-options");

function setStatus(message, type) {
    statusEl.textContent = message || "";
    statusEl.className = type || "";
}

function roleLabel(role) {
    const key = String(role || "").toUpperCase();
    if (key === "STORE_SYSTEM") return "Store Officer";
    if (key === "STORE_SUPERVISOR") return "Store Supervisor";
    if (key === "ACCOUNTING_OFFICE") return "Accounting Office";
    if (key === "ADMIN") return "Admin";
    if (key === "USER") return "User";
    return key.replace(/_/g, " ");
}

function targetPathByRole(role) {
    const key = String(role || "").toUpperCase();
    if (key === "STORE_SYSTEM") return "/store/dashboard";
    if (key === "STORE_SUPERVISOR") return "/store-admin/dashboard";
    if (key === "ACCOUNTING_OFFICE") return "/accounting/dashboard";
    if (key === "ADMIN") return "/admin/dashboard";
    if (key === "USER") return "/user/dashboard";
    return "/login";
}

async function chooseRole(role) {
    try {
        const response = await fetch("/auth/select-role", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ role }),
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            setStatus(data?.message || "Unable to set role.", "error");
            return;
        }
        window.location.href = data.redirect_to || targetPathByRole(role);
    } catch (error) {
        setStatus("Unable to set role. Please try again.", "error");
    }
}

function renderRoles(roles, currentRole) {
    const safeRoles = Array.isArray(roles) ? roles : [];
    if (safeRoles.length === 0) {
        optionsEl.innerHTML = "";
        setStatus("No roles assigned for this account.", "error");
        return;
    }

    const activeRole = String(currentRole || "").toUpperCase();
    optionsEl.innerHTML = safeRoles.map((role) => {
        const roleKey = String(role || "").toUpperCase();
        const isActive = roleKey !== "" && roleKey === activeRole;
        return `
        <button type="button" class="auth-role-btn${isActive ? " is-active" : ""}" data-role="${role}">
            <span>${roleLabel(role)}</span>
            ${isActive ? "<small>Current</small>" : ""}
        </button>
    `;
    }).join("");
}

optionsEl.addEventListener("click", async (event) => {
    const btn = event.target.closest("[data-role]");
    if (!btn) return;
    const role = String(btn.getAttribute("data-role") || "");
    if (!role) return;
    await chooseRole(role);
});

async function init() {
    try {
        const meResp = await fetch("/auth/me");
        const meData = await meResp.json();
        if (!meData || meData.status !== "success") {
            window.location.href = "/login";
            return;
        }

        const role = meData.user?.role || "";
        const roles = meData.user?.roles || [];
        renderRoles(roles, role);
    } catch (error) {
        window.location.href = "/login";
    }
}

init();
