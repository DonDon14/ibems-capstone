(function () {
    "use strict";

    const openButton = document.getElementById("u-open-pin-modal");
    const modal = document.getElementById("u-debt-pin-modal");
    const form = document.getElementById("u-debt-pin-form");
    if (!openButton || !modal || !form) return;

    let hasDebtPin = false;

    function setResult(message = "", tone = "") {
        const result = document.getElementById("u-pin-form-result");
        if (!result) return;
        result.textContent = message;
        result.classList.remove("is-error", "is-success");
        if (tone) result.classList.add(tone === "success" ? "is-success" : "is-error");
    }

    function renderStatus(message = "") {
        const status = document.getElementById("u-debt-pin-menu-status");
        if (!status) return;
        status.textContent = message || (hasDebtPin ? "PIN configured - change it securely" : "No PIN configured - set one now");
    }

    async function loadStatus() {
        try {
            const response = await fetch("/user/debt-pin/status");
            const data = await response.json();
            if (!data || data.status !== "success") throw new Error();
            hasDebtPin = !!data.has_pin;
            renderStatus();
        } catch (error) {
            renderStatus("PIN status is temporarily unavailable");
        }
    }

    function openModal() {
        const currentPasswordWrap = document.getElementById("u-current-password-wrap");
        const help = document.getElementById("u-pin-form-help");
        form.reset();
        setResult();
        currentPasswordWrap?.classList.toggle("is-hidden", !hasDebtPin);
        if (help) {
            help.textContent = hasDebtPin
                ? "Enter your current password, then choose a new 4 to 6 digit debt PIN."
                : "Use a 4 to 6 digit PIN. Stores will ask for this only when charging purchases to debt.";
        }
        modal.classList.remove("is-hidden");
        window.requestAnimationFrame(() => {
            document.getElementById(hasDebtPin ? "u-current-password" : "u-debt-pin")?.focus();
        });
    }

    function closeModal() {
        modal.classList.add("is-hidden");
        openButton.focus();
    }

    function normalizePin(event) {
        event.target.value = String(event.target.value || "").replace(/\D/g, "").slice(0, 6);
    }

    async function submitPin(event) {
        event.preventDefault();
        const pin = String(document.getElementById("u-debt-pin")?.value || "");
        const pinConfirm = String(document.getElementById("u-debt-pin-confirm")?.value || "");
        const currentPassword = String(document.getElementById("u-current-password")?.value || "");

        if (!/^[0-9]{4,6}$/.test(pin)) {
            setResult("Debt PIN must be 4 to 6 digits.", "error");
            return;
        }
        if (pin !== pinConfirm) {
            setResult("Debt PIN confirmation does not match.", "error");
            return;
        }
        if (hasDebtPin && !currentPassword) {
            setResult("Enter your current password to change your debt PIN.", "error");
            return;
        }

        const submitButton = document.getElementById("u-save-pin");
        try {
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
            }
            const response = await fetch("/user/debt-pin/set", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ current_password: currentPassword, pin, pin_confirm: pinConfirm }),
            });
            const data = await response.json();
            if (!data || data.status !== "success") {
                throw new Error(data?.message || "Unable to update debt PIN.");
            }
            hasDebtPin = true;
            renderStatus();
            setResult(data.message || "Debt PIN saved.", "success");
            window.setTimeout(closeModal, 550);
        } catch (error) {
            setResult(error.message || "Unable to update debt PIN.", "error");
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = '<i class="bi bi-check2-circle"></i> Save PIN';
            }
        }
    }

    openButton.addEventListener("click", openModal);
    document.getElementById("u-pin-modal-close")?.addEventListener("click", closeModal);
    document.getElementById("u-pin-modal-cancel")?.addEventListener("click", closeModal);
    modal.addEventListener("click", (event) => {
        if (event.target === modal) closeModal();
    });
    modal.addEventListener("keydown", (event) => {
        if (event.key === "Escape") closeModal();
    });
    document.getElementById("u-debt-pin")?.addEventListener("input", normalizePin);
    document.getElementById("u-debt-pin-confirm")?.addEventListener("input", normalizePin);
    form.addEventListener("submit", submitPin);

    loadStatus();
}());
