const statusEl = document.getElementById("status");
const formEl = document.getElementById("login-form");
const submitBtn = document.getElementById("submit-btn");
const frontControllerPrefix = window.location.pathname.includes("/index.php/") ? "/index.php" : "";
const appPath = (path) => `${frontControllerPrefix}${path}`;

function setStatus(message, type, options = {}) {
    statusEl.replaceChildren();
    statusEl.className = type || "";
    statusEl.setAttribute("role", type === "error" ? "alert" : "status");
    if (!message) return;

    const icon = document.createElement("span");
    icon.className = "auth-status-icon";
    icon.setAttribute("aria-hidden", "true");
    icon.innerHTML = `<i class="bi ${options.icon || (type === "ok" ? "bi-check-lg" : "bi-exclamation-lg")}"></i>`;

    const copy = document.createElement("span");
    copy.className = "auth-status-copy";
    const title = document.createElement("strong");
    title.textContent = options.title || (type === "ok" ? "Sign-in complete" : "Unable to sign in");
    const detail = document.createElement("small");
    detail.textContent = message;
    copy.append(title, detail);
    statusEl.append(icon, copy);
}

function roleLabel(role) {
    if (role === "STORE_SYSTEM") return "Store portal";
    if (role === "ACCOUNTING_OFFICE") return "Accounting portal";
    if (role === "ADMIN") return "Admin portal";
    if (role === "USER") return "Employee portal";
    return "IBEMS workspace";
}

function pause(milliseconds) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}

function targetPathByRole(role) {
    if (role === "STORE_SYSTEM") return appPath("/store/dashboard");
    if (role === "ACCOUNTING_OFFICE") return appPath("/accounting/dashboard");
    if (role === "ADMIN") return appPath("/admin/dashboard");
    if (role === "USER") return appPath("/user/dashboard");
    return appPath("/login");
}

async function tryRedirectIfLoggedIn() {
    try {
        const meResp = await fetch(appPath("/auth/me"));
        const meData = await meResp.json();

        if (meData && meData.status === "success" && meData.user) {
            if (meData.user.role) {
                window.location.href = targetPathByRole(meData.user.role);
                return;
            }

            const roles = Array.isArray(meData.user.roles) ? meData.user.roles : [];
            if (roles.length > 1) {
                window.location.href = appPath("/auth/select-role");
            }
        }
    } catch (err) {
        // Stay in login page when not logged in.
    }
}

formEl.addEventListener("submit", async (event) => {
    event.preventDefault();
    setStatus("", "");
    submitBtn.disabled = true;
    const defaultButtonLabel = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="auth-button-loading"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Signing in...</span>';

    const email = document.getElementById("email").value.trim();
    const password = document.getElementById("password").value;
    const payload = new FormData(formEl);
    payload.set("email", email);
    payload.set("password", password);
    let loginSucceeded = false;

    try {
        const response = await fetch(appPath("/auth/login"), {
            method: "POST",
            body: payload,
        });

        const data = await response.json();

        if (!data || data.status !== "success") {
            setStatus(data && data.message ? data.message : "Login failed.", "error");
            return;
        }

        const firstName = String(data.user?.name || "there").trim().split(/\s+/)[0] || "there";
        const role = data.user?.role || null;
        const requiresRoleSelection = Boolean(data.user?.requires_role_selection);
        loginSucceeded = true;
        setStatus(
            requiresRoleSelection
                ? "Your account is ready. Choose the workspace you want to open."
                : `Opening your ${roleLabel(role)} securely.`,
            "ok",
            { title: `Welcome back, ${firstName}!`, icon: "bi-check2-circle" }
        );
        submitBtn.innerHTML = '<span class="auth-button-loading is-complete"><i class="bi bi-check-lg" aria-hidden="true"></i> Signed in</span>';
        await pause(650);
        if (requiresRoleSelection) {
            window.location.href = appPath("/auth/select-role");
            return;
        }

        if (data.user?.redirect_to) {
            window.location.href = data.user.redirect_to;
            return;
        }

        window.location.href = targetPathByRole(role);
    } catch (err) {
        setStatus("Unable to reach server. Please try again.", "error");
    } finally {
        if (!loginSucceeded) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = defaultButtonLabel;
        }
    }
});

tryRedirectIfLoggedIn();
