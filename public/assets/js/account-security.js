(function () {
    "use strict";

    const openButton = document.querySelector("[data-account-security-open]");
    const modal = document.getElementById("account-security-modal");
    const passwordForm = document.getElementById("account-password-form");
    const pinForm = document.getElementById("account-pin-form");
    if (!openButton || !modal || !passwordForm || !pinForm) return;

    let returnFocus = null;
    let hasDebtPin = false;
    let statusRequestId = 0;
    let statusLoading = false;
    let modalRevision = 0;
    let pendingSave = null;
    const statusActions = document.getElementById("account-security-status-actions");
    const retryStatus = document.getElementById("account-security-retry");

    function setResult(id, message = "", state = "error", focus = false) {
        const result = document.getElementById(id);
        if (!result) return;
        result.textContent = message;
        const error = !!message && state === "error";
        result.setAttribute("role", error ? "alert" : "status");
        result.setAttribute("aria-live", error ? "assertive" : "polite");
        result.setAttribute("aria-atomic", "true");
        result.classList.toggle("is-success", !!message && state === "success");
        result.classList.toggle("is-error", error);
        if (focus) result.focus();
    }

    function setBusy(button, busy, busyLabel, readyLabel) {
        if (!button) return;
        button.disabled = busy;
        button.innerHTML = busy
            ? '<i class="bi bi-hourglass-split" aria-hidden="true"></i> ' + busyLabel
            : '<i class="bi bi-check2-circle" aria-hidden="true"></i> ' + readyLabel;
    }

    async function loadStatus(focusResult = false) {
        const requestId = ++statusRequestId;
        const signal = window.IbemsPortalNavigation?.currentSignal;
        const current = () => requestId === statusRequestId && !signal?.aborted && !modal.classList.contains("is-hidden");
        statusLoading = true;
        pinForm.hidden = true;
        setResult("account-password-result", "Loading account security…", "progress");
        const result = document.getElementById("account-password-result");
        result.classList.remove("is-success");
        if (focusResult) result.focus();
        if (statusActions) statusActions.hidden = true;
        if (retryStatus) retryStatus.disabled = true;
        passwordForm.setAttribute("aria-busy", "true");
        try {
            const response = await fetch("/profile/security", {headers: {"Accept": "application/json"}, signal});
            const data = await response.json();
            if (!current()) return;
            if (!response.ok || data?.status !== "success") throw new Error(data?.message || "Unable to load account security.");
            if (typeof data.has_debt_pin !== "boolean" || typeof data.can_manage_debt_pin !== "boolean") throw new Error("Account security status was not confirmed. Retry to check your account.");
            setResult("account-password-result");
            hasDebtPin = !!data.has_debt_pin;
            pinForm.hidden = !data.can_manage_debt_pin;
            document.getElementById("account-pin-current-wrap").hidden = !hasDebtPin;
            document.getElementById("account-pin-current-password").required = hasDebtPin;
            document.getElementById("account-pin-help").textContent = hasDebtPin
                ? "Enter your password, then choose a new 4 to 6 digit purchase PIN."
                : "Set a 4 to 6 digit PIN for purchases charged to your employee account.";
            if (focusResult && document.activeElement === result) document.getElementById("account-current-password")?.focus();
        } catch (error) {
            if (!current()) return;
            setResult("account-password-result", error.message || "Unable to load account security.");
            if (statusActions) statusActions.hidden = false;
        } finally {
            if (requestId === statusRequestId) {
                statusLoading = false;
                if (retryStatus) retryStatus.disabled = false;
                passwordForm.setAttribute("aria-busy", "false");
            }
        }
    }

    function openModal() {
        if (pendingSave) {
            setResult(pendingSave.resultId, pendingSave.progress, "progress", true);
            return;
        }
        const revision = ++modalRevision;
        returnFocus = document.querySelector('[data-account-menu-toggle]') || document.activeElement;
        passwordForm.reset();
        pinForm.reset();
        setResult("account-password-result");
        setResult("account-pin-result");
        modal.classList.remove("is-hidden");
        document.body.classList.add("account-security-open");
        const openingFocus = document.activeElement;
        loadStatus();
        window.requestAnimationFrame(() => {
            if (revision === modalRevision && document.activeElement === openingFocus && !modal.classList.contains("is-hidden")) document.getElementById("account-current-password")?.focus();
        });
    }

    function closeModal() {
        if (pendingSave) {
            setResult(pendingSave.resultId, pendingSave.progress, "progress", true);
            return;
        }
        dismissModal();
        if (returnFocus instanceof HTMLElement) returnFocus.focus();
    }

    function dismissModal() {
        modalRevision++;
        statusRequestId++;
        statusLoading = false;
        passwordForm.setAttribute("aria-busy", "false");
        modal.classList.add("is-hidden");
        document.body.classList.remove("account-security-open");
    }

    async function submitJson(url, payload, unconfirmedMessage) {
        let failureMessage = unconfirmedMessage;
        try {
            const response = await fetch(url, {
                method: "POST",
                headers: {"Accept": "application/json", "Content-Type": "application/json"},
                body: JSON.stringify(payload),
            });
            if (!response.ok) failureMessage = "Unable to save your changes. Please try again.";
            const data = await response.json();
            if (!response.ok || data?.status === "error") {
                failureMessage = typeof data?.message === "string" && data.message.trim() ? data.message : "Unable to save your changes. Please try again.";
                throw new Error(failureMessage);
            }
            if (data?.status !== "success") throw new Error(failureMessage);
            return data;
        } catch {
            throw new Error(failureMessage);
        }
    }

    async function saveChanges({form, button, resultId, progress, busyLabel, readyLabel, url, payload, unconfirmedMessage, successMessage, onSuccess}) {
        const revision = modalRevision;
        const signal = window.IbemsPortalNavigation?.currentSignal;
        const current = () => revision === modalRevision && !signal?.aborted && !modal.classList.contains("is-hidden");
        const controls = [openButton, ...Array.from(modal.querySelectorAll("input, button")).filter(control => !control.matches("[data-account-security-close]"))];
        const disabledStates = controls.map(control => control.disabled);
        const buttonHtml = button.innerHTML;
        pendingSave = {resultId, progress};
        setResult(resultId, progress, "progress", modal.contains(document.activeElement));
        controls.forEach(control => {control.disabled = true;});
        setBusy(button, true, busyLabel, readyLabel);
        form.setAttribute("aria-busy", "true");
        modal.setAttribute("aria-busy", "true");
        try {
            const data = await submitJson(url, payload, unconfirmedMessage);
            if (!current()) return;
            form.reset();
            onSuccess?.();
            setResult(resultId, typeof data.message === "string" && data.message.trim() ? data.message : successMessage, "success");
        } catch (error) {
            if (current()) setResult(resultId, error.message);
        } finally {
            pendingSave = null;
            button.innerHTML = buttonHtml;
            controls.forEach((control, index) => {control.disabled = disabledStates[index];});
            form.setAttribute("aria-busy", "false");
            modal.setAttribute("aria-busy", "false");
        }
    }

    passwordForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (pendingSave || statusLoading || modal.classList.contains("is-hidden") || window.IbemsPortalNavigation?.currentSignal?.aborted) return;
        const currentPassword = document.getElementById("account-current-password").value;
        const newPassword = document.getElementById("account-new-password").value;
        const confirmation = document.getElementById("account-new-password-confirm").value;
        if (!currentPassword) return setResult("account-password-result", "Enter your current password.", "error", true);
        if (newPassword.length < 8) return setResult("account-password-result", "New password must be at least 8 characters.", "error", true);
        if (newPassword.length > 255) return setResult("account-password-result", "New password must be no more than 255 characters.", "error", true);
        if (newPassword !== confirmation) return setResult("account-password-result", "New password confirmation does not match.", "error", true);

        const button = document.getElementById("account-password-save");
        await saveChanges({
            form: passwordForm, button, resultId: "account-password-result",
            progress: "Updating password… Please wait.", busyLabel: "Updating...", readyLabel: "Update password",
            url: "/profile/password", payload: {
                current_password: currentPassword,
                new_password: newPassword,
                new_password_confirm: confirmation,
            },
            unconfirmedMessage: "The password change was not confirmed. Check which password works before retrying.",
            successMessage: "Password updated."
        });
    });

    pinForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (pendingSave || statusLoading || pinForm.hidden || modal.classList.contains("is-hidden") || window.IbemsPortalNavigation?.currentSignal?.aborted) return;
        const pin = document.getElementById("account-new-pin").value;
        const confirmation = document.getElementById("account-new-pin-confirm").value;
        if (hasDebtPin && !document.getElementById("account-pin-current-password").value) return setResult("account-pin-result", "Enter your current password to change your purchase PIN.", "error", true);
        if (!/^[0-9]{4,6}$/.test(pin)) return setResult("account-pin-result", "Purchase PIN must be 4 to 6 digits.", "error", true);
        if (pin !== confirmation) return setResult("account-pin-result", "Purchase PIN confirmation does not match.", "error", true);

        const button = document.getElementById("account-pin-save");
        await saveChanges({
            form: pinForm, button, resultId: "account-pin-result",
            progress: "Saving purchase PIN… Please wait.", busyLabel: "Saving...", readyLabel: "Save PIN",
            url: "/profile/debt-pin", payload: {
                current_password: document.getElementById("account-pin-current-password").value,
                pin,
                pin_confirm: confirmation,
            },
            unconfirmedMessage: "The purchase PIN change was not confirmed. Check your account security before retrying.",
            successMessage: "Purchase PIN saved.",
            onSuccess: () => {
                hasDebtPin = true;
                document.getElementById("account-pin-current-wrap").hidden = false;
                document.getElementById("account-pin-current-password").required = true;
                document.getElementById("account-pin-help").textContent = "Enter your password, then choose a new 4 to 6 digit purchase PIN.";
            }
        });
    });

    ["account-new-pin", "account-new-pin-confirm"].forEach((id) => {
        document.getElementById(id)?.addEventListener("input", (event) => {
            event.target.value = String(event.target.value || "").replace(/\D/g, "").slice(0, 6);
        });
    });

    openButton.addEventListener("click", openModal);
    window.addEventListener("ibems:portal-page-unload", () => {
        dismissModal();
        passwordForm.reset();
        pinForm.reset();
        pinForm.hidden = true;
        setResult("account-password-result");
        setResult("account-pin-result");
    });
    retryStatus?.addEventListener("click", () => {
        if (pendingSave || statusLoading || modal.classList.contains("is-hidden")) return;
        loadStatus(true);
    });
    document.querySelectorAll("[data-account-security-close]").forEach((button) => button.addEventListener("click", closeModal));
    modal.addEventListener("click", (event) => { if (event.target === modal) closeModal(); });
    modal.addEventListener("keydown", (event) => {
        if (event.defaultPrevented) return;
        if (event.key === "Escape") {
            event.preventDefault();
            closeModal();
            return;
        }
        if (event.key !== "Tab") return;
        const controls = Array.from(modal.querySelectorAll('button, input, select, textarea, a[href], [tabindex]'))
            .filter(control => !control.disabled && control.tabIndex >= 0 && control.offsetParent !== null);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (!first) return;
        if (pendingSave && !controls.includes(document.activeElement)) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
}());
