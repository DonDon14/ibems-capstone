(function () {
    "use strict";

    const openButton = document.querySelector("[data-account-security-open]");
    const modal = document.getElementById("account-security-modal");
    const passwordForm = document.getElementById("account-password-form");
    const pinForm = document.getElementById("account-pin-form");
    if (!openButton || !modal || !passwordForm || !pinForm) return;

    let returnFocus = null;
    let hasDebtPin = false;

    function setResult(id, message = "", success = false) {
        const result = document.getElementById(id);
        if (!result) return;
        result.textContent = message;
        result.classList.toggle("is-success", success);
        result.classList.toggle("is-error", !!message && !success);
    }

    function setBusy(button, busy, busyLabel, readyLabel) {
        if (!button) return;
        button.disabled = busy;
        button.innerHTML = busy
            ? '<i class="bi bi-hourglass-split" aria-hidden="true"></i> ' + busyLabel
            : '<i class="bi bi-check2-circle" aria-hidden="true"></i> ' + readyLabel;
    }

    async function loadStatus() {
        pinForm.hidden = true;
        try {
            const response = await fetch("/profile/security", {headers: {"Accept": "application/json"}});
            const data = await response.json();
            if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Unable to load account security.");
            hasDebtPin = !!data.has_debt_pin;
            pinForm.hidden = !data.can_manage_debt_pin;
            document.getElementById("account-pin-current-wrap").hidden = !hasDebtPin;
            document.getElementById("account-pin-current-password").required = hasDebtPin;
            document.getElementById("account-pin-help").textContent = hasDebtPin
                ? "Enter your password, then choose a new 4 to 6 digit purchase PIN."
                : "Set a 4 to 6 digit PIN for purchases charged to your employee account.";
        } catch (error) {
            setResult("account-password-result", error.message || "Unable to load account security.");
        }
    }

    function openModal() {
        returnFocus = document.activeElement;
        passwordForm.reset();
        pinForm.reset();
        setResult("account-password-result");
        setResult("account-pin-result");
        modal.classList.remove("is-hidden");
        document.body.classList.add("account-security-open");
        loadStatus();
        window.requestAnimationFrame(() => document.getElementById("account-current-password")?.focus());
    }

    function closeModal() {
        modal.classList.add("is-hidden");
        document.body.classList.remove("account-security-open");
        if (returnFocus instanceof HTMLElement) returnFocus.focus();
    }

    async function submitJson(url, payload) {
        const response = await fetch(url, {
            method: "POST",
            headers: {"Accept": "application/json", "Content-Type": "application/json"},
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Unable to save your changes.");
        return data;
    }

    passwordForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const currentPassword = document.getElementById("account-current-password").value;
        const newPassword = document.getElementById("account-new-password").value;
        const confirmation = document.getElementById("account-new-password-confirm").value;
        if (newPassword.length < 8) return setResult("account-password-result", "New password must be at least 8 characters.");
        if (newPassword !== confirmation) return setResult("account-password-result", "New password confirmation does not match.");

        const button = document.getElementById("account-password-save");
        setBusy(button, true, "Updating...", "Update password");
        setResult("account-password-result");
        try {
            const data = await submitJson("/profile/password", {
                current_password: currentPassword,
                new_password: newPassword,
                new_password_confirm: confirmation,
            });
            passwordForm.reset();
            setResult("account-password-result", data.message || "Password updated.", true);
        } catch (error) {
            setResult("account-password-result", error.message || "Unable to update password.");
        } finally {
            setBusy(button, false, "Updating...", "Update password");
        }
    });

    pinForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const pin = document.getElementById("account-new-pin").value;
        const confirmation = document.getElementById("account-new-pin-confirm").value;
        if (!/^[0-9]{4,6}$/.test(pin)) return setResult("account-pin-result", "Purchase PIN must be 4 to 6 digits.");
        if (pin !== confirmation) return setResult("account-pin-result", "Purchase PIN confirmation does not match.");

        const button = document.getElementById("account-pin-save");
        setBusy(button, true, "Saving...", "Save PIN");
        setResult("account-pin-result");
        try {
            const data = await submitJson("/profile/debt-pin", {
                current_password: document.getElementById("account-pin-current-password").value,
                pin,
                pin_confirm: confirmation,
            });
            hasDebtPin = true;
            pinForm.reset();
            document.getElementById("account-pin-current-wrap").hidden = false;
            document.getElementById("account-pin-current-password").required = true;
            document.getElementById("account-pin-help").textContent = "Enter your password, then choose a new 4 to 6 digit purchase PIN.";
            setResult("account-pin-result", data.message || "Purchase PIN saved.", true);
        } catch (error) {
            setResult("account-pin-result", error.message || "Unable to update purchase PIN.");
        } finally {
            setBusy(button, false, "Saving...", "Save PIN");
        }
    });

    ["account-new-pin", "account-new-pin-confirm"].forEach((id) => {
        document.getElementById(id)?.addEventListener("input", (event) => {
            event.target.value = String(event.target.value || "").replace(/\D/g, "").slice(0, 6);
        });
    });

    openButton.addEventListener("click", openModal);
    document.querySelectorAll("[data-account-security-close]").forEach((button) => button.addEventListener("click", closeModal));
    modal.addEventListener("click", (event) => { if (event.target === modal) closeModal(); });
    modal.addEventListener("keydown", (event) => { if (event.key === "Escape") closeModal(); });
}());
