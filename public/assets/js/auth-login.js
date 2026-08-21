const statusEl = document.getElementById("status");
const formEl = document.getElementById("login-form");
const submitBtn = document.getElementById("submit-btn");

function setStatus(message, type) {
    statusEl.textContent = message || "";
    statusEl.className = type || "";
}

function targetPathByRole(role) {
    if (role === "STORE_SYSTEM") return "/store/dashboard";
    if (role === "ACCOUNTING_OFFICE") return "/accounting/dashboard";
    if (role === "ADMIN") return "/admin/dashboard";
    if (role === "USER") return "/user/dashboard";
    return "/login";
}

async function tryRedirectIfLoggedIn() {
    try {
        const meResp = await fetch("/auth/me");
        const meData = await meResp.json();

        if (meData && meData.status === "success" && meData.user) {
            if (meData.user.role) {
                window.location.href = targetPathByRole(meData.user.role);
                return;
            }

            const roles = Array.isArray(meData.user.roles) ? meData.user.roles : [];
            if (roles.length > 1) {
                window.location.href = "/auth/select-role";
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

    const email = document.getElementById("email").value.trim();
    const password = document.getElementById("password").value;
    const payload = new FormData(formEl);
    payload.set("email", email);
    payload.set("password", password);

    try {
        const response = await fetch("/auth/login", {
            method: "POST",
            body: payload,
        });

        const data = await response.json();

        if (!data || data.status !== "success") {
            setStatus(data && data.message ? data.message : "Login failed.", "error");
            return;
        }

        setStatus("Login successful. Redirecting...", "ok");
        if (data.user?.requires_role_selection) {
            window.location.href = "/auth/select-role";
            return;
        }

        if (data.user?.redirect_to) {
            window.location.href = data.user.redirect_to;
            return;
        }

        const role = data.user && data.user.role ? data.user.role : null;
        window.location.href = targetPathByRole(role);
    } catch (err) {
        setStatus("Unable to reach server. Please try again.", "error");
    } finally {
        submitBtn.disabled = false;
    }
});

tryRedirectIfLoggedIn();
