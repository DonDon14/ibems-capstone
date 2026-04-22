const statusEl = document.getElementById("status");
const optionsEl = document.getElementById("role-options");

function setStatus(message, type) {
    statusEl.textContent = message || "";
    statusEl.className = type || "";
}

function roleLabel(role) {
    const key = String(role || "").toUpperCase();
    if (key === "STORE_SYSTEM") return "Store Officer";
    if (key === "ACCOUNTING_OFFICE") return "Accounting Office";
    if (key === "ADMIN") return "Admin";
    if (key === "USER") return "User";
    return key.replace(/_/g, " ");
}

function targetPathByRole(role) {
    const key = String(role || "").toUpperCase();
    if (key === "STORE_SYSTEM") return "/store/pos";
    if (key === "ACCOUNTING_OFFICE") return "/accounting/debts";
    if (key === "ADMIN") return "/admin/stores";
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

function renderRoles(roles) {
    const safeRoles = Array.isArray(roles) ? roles : [];
    if (safeRoles.length === 0) {
        optionsEl.innerHTML = "";
        setStatus("No roles assigned for this account.", "error");
        return;
    }

    optionsEl.innerHTML = safeRoles.map((role) => `
        <button type="button" class="history-action" data-role="${role}" style="margin-right:8px;margin-bottom:8px;">
            ${roleLabel(role)}
        </button>
    `).join("");
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
        if (role) {
            window.location.href = targetPathByRole(role);
            return;
        }

        renderRoles(roles);
    } catch (error) {
        window.location.href = "/login";
    }
}

init();

