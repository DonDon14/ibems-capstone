(function () {
    const defaultUrl = document.querySelector('meta[name="default-profile-image"]')?.content || "/assets/images/default-profile.svg";

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function imageUrl(value) {
        const url = String(value || "").trim();
        return url || defaultUrl;
    }

    function html(name, url, className = "user-avatar-image") {
        return `<img src="${escapeHtml(imageUrl(url))}" alt="" class="${escapeHtml(className)}" data-profile-avatar loading="lazy">`;
    }

    window.IbemsAvatar = { defaultUrl, imageUrl, html };

    document.addEventListener("error", (event) => {
        const image = event.target;
        if (!(image instanceof HTMLImageElement) || !image.matches("[data-profile-avatar]") || image.dataset.fallbackApplied === "1") return;
        image.dataset.fallbackApplied = "1";
        image.src = defaultUrl;
    }, true);

    const modal = document.getElementById("profile-image-modal");
    const form = document.getElementById("profile-image-form");
    const fileInput = document.getElementById("profile-image-file");
    const picker = document.getElementById("profile-image-picker");
    const preview = document.getElementById("profile-image-preview");
    const fileName = document.getElementById("profile-image-file-name");
    const result = document.getElementById("profile-image-result");
    const saveButton = document.getElementById("profile-image-save");
    let trigger = null;
    let previewObjectUrl = "";
    let modalRevision = 0;
    let uploading = false;

    function setResult(message = "", type = "progress", focus = false) {
        if (!result) return;
        result.textContent = message;
        for (const state of ["error", "success", "warning"]) result.classList.toggle(`is-${state}`, type === state);
        result.setAttribute("role", type === "error" ? "alert" : "status");
        result.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
        if (focus) result.focus();
    }

    function fileError(file) {
        if (!file) return "Choose an image first.";
        if (file.size > 2 * 1024 * 1024) return "Choose an image no larger than 2 MB.";
        const supported = file.type
            ? ["image/jpeg", "image/png", "image/webp", "image/gif"].includes(file.type)
            : /\.(jpe?g|png|webp|gif)$/i.test(file.name || "");
        return supported ? "" : "Choose a JPG, PNG, WebP, or GIF image.";
    }

    function clearSelection() {
        if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
        previewObjectUrl = "";
        fileInput.value = "";
        if (preview) preview.src = document.querySelector("[data-current-user-avatar]")?.getAttribute("src") || defaultUrl;
        if (fileName) fileName.textContent = "No new image selected";
    }

    function closeModal() {
        if (!modal) return;
        modalRevision++;
        modal.classList.add("is-hidden");
        document.body.classList.remove("profile-image-modal-open");
        if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
        previewObjectUrl = "";
        form?.reset();
        if (fileName) fileName.textContent = "No new image selected";
        setResult();
        const returnTarget = trigger?.getClientRects().length ? trigger : document.querySelector(".topbar-profile");
        returnTarget?.focus();
    }

    document.querySelector("[data-profile-image-open]")?.addEventListener("click", (event) => {
        trigger = event.currentTarget;
        modalRevision++;
        const current = document.querySelector("[data-current-user-avatar]")?.getAttribute("src") || defaultUrl;
        if (preview) preview.src = current;
        modal?.classList.remove("is-hidden");
        document.body.classList.add("profile-image-modal-open");
        modal?.querySelector("[data-profile-image-close]")?.focus();
        if (uploading) setResult("Uploading profile picture...");
    });

    document.querySelectorAll("[data-profile-image-close]").forEach((button) => button.addEventListener("click", closeModal));
    modal?.addEventListener("click", (event) => { if (event.target === modal) closeModal(); });
    document.addEventListener("keydown", (event) => { if (event.key === "Escape" && !modal?.classList.contains("is-hidden")) closeModal(); });
    modal?.addEventListener("keydown", (event) => {
        if (event.key !== "Tab") return;
        const controls = Array.from(modal.querySelectorAll('button, input, a[href], [tabindex]'))
            .filter((element) => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (!first) return;
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    picker?.addEventListener("click", () => { if (!uploading) fileInput?.click(); });

    fileInput?.addEventListener("change", () => {
        const file = fileInput.files?.[0];
        if (!file) return;
        const error = fileError(file);
        if (error) {
            clearSelection();
            setResult(error, "error");
            picker?.focus();
            return;
        }
        if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
        previewObjectUrl = URL.createObjectURL(file);
        if (preview) preview.src = previewObjectUrl;
        if (fileName) fileName.textContent = file.name;
        setResult();
    });

    form?.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (uploading) return;
        const file = fileInput?.files?.[0];
        const error = fileError(file);
        if (error) {
            setResult(error, "error");
            picker?.focus();
            return;
        }
        const submittedRevision = modalRevision;
        const controls = [saveButton, fileInput, picker].filter(Boolean);
        const disabledStates = controls.map(control => control.disabled);
        uploading = true;
        setResult("Uploading profile picture...", "progress", modal.contains(document.activeElement));
        controls.forEach(control => { control.disabled = true; });
        form.setAttribute("aria-busy", "true");
        let failureMessage = "The upload was not confirmed. Check your profile picture before retrying.";
        try {
            const body = new FormData();
            body.append("profile_image", file);
            const response = await fetch("/profile/image", { method: "POST", body });
            if (!response.ok) failureMessage = "Unable to update profile picture. Please try again.";
            const data = await response.json();
            if (!response.ok || data?.status === "error") {
                failureMessage = typeof data?.message === "string" && data.message.trim() ? data.message : "Unable to update profile picture. Please try again.";
                throw new Error(failureMessage);
            }
            if (data?.status !== "success") throw new Error(failureMessage);
            const savedUrl = typeof data.profile_image_url === "string" ? data.profile_image_url.trim() : "";
            if (!savedUrl) {
                if (submittedRevision === modalRevision) clearSelection();
                if (!modal.classList.contains("is-hidden")) setResult("Profile picture updated. Reload to refresh the saved image.", "warning");
                return;
            }
            document.querySelectorAll("[data-current-user-avatar]").forEach((image) => {
                image.dataset.fallbackApplied = "0";
                image.src = savedUrl;
            });
            window.dispatchEvent(new CustomEvent("ibems:profile-image-updated", { detail: data }));
            if (submittedRevision === modalRevision) closeModal();
            else if (!modal.classList.contains("is-hidden")) setResult("Profile picture updated.", "success");
        } catch {
            if (!modal.classList.contains("is-hidden")) setResult(failureMessage, "error");
        } finally {
            uploading = false;
            controls.forEach((control, index) => { control.disabled = disabledStates[index]; });
            form.setAttribute("aria-busy", "false");
        }
    });
})();
