const statusEl = document.getElementById("status");
const formEl = document.getElementById("login-form");
const submitBtn = document.getElementById("submit-btn");

function setStatus(message, type) {
    statusEl.textContent = message || "";
    statusEl.className = type || "";
}

function targetPathByRole(role) {
    if (role === "STORE_SYSTEM") return "/store/pos";
    if (role === "ACCOUNTING_OFFICE") return "/accounting/debts";
    if (role === "ADMIN") return "/admin/stores";
    if (role === "USER") return "/user/dashboard";
    return "/login";
}

async function tryRedirectIfLoggedIn() {
    try {
        const meResp = await fetch("/auth/me");
        const meData = await meResp.json();

        if (meData && meData.status === "success" && meData.user && meData.user.role) {
            window.location.href = targetPathByRole(meData.user.role);
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

    try {
        const response = await fetch("/auth/login", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email, password }),
        });

        const data = await response.json();

        if (!data || data.status !== "success") {
            setStatus(data && data.message ? data.message : "Login failed.", "error");
            return;
        }

        const role = data.user && data.user.role ? data.user.role : null;
        setStatus("Login successful. Redirecting...", "ok");
        window.location.href = targetPathByRole(role);
    } catch (err) {
        setStatus("Unable to reach server. Please try again.", "error");
    } finally {
        submitBtn.disabled = false;
    }
});

tryRedirectIfLoggedIn();
