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
    const preview = document.getElementById("profile-image-preview");
    const fileName = document.getElementById("profile-image-file-name");
    const result = document.getElementById("profile-image-result");
    const saveButton = document.getElementById("profile-image-save");
    let trigger = null;
    let previewObjectUrl = "";

    function closeModal() {
        if (!modal) return;
        modal.classList.add("is-hidden");
        document.body.classList.remove("profile-image-modal-open");
        if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
        previewObjectUrl = "";
        form?.reset();
        if (fileName) fileName.textContent = "No new image selected";
        if (result) result.textContent = "";
        trigger?.focus?.();
    }

    document.querySelector("[data-profile-image-open]")?.addEventListener("click", (event) => {
        trigger = event.currentTarget;
        const current = document.querySelector("[data-current-user-avatar]")?.getAttribute("src") || defaultUrl;
        if (preview) preview.src = current;
        modal?.classList.remove("is-hidden");
        document.body.classList.add("profile-image-modal-open");
        fileInput?.focus();
    });

    document.querySelectorAll("[data-profile-image-close]").forEach((button) => button.addEventListener("click", closeModal));
    modal?.addEventListener("click", (event) => { if (event.target === modal) closeModal(); });
    document.addEventListener("keydown", (event) => { if (event.key === "Escape" && !modal?.classList.contains("is-hidden")) closeModal(); });

    fileInput?.addEventListener("change", () => {
        const file = fileInput.files?.[0];
        if (!file) return;
        if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
        previewObjectUrl = URL.createObjectURL(file);
        if (preview) preview.src = previewObjectUrl;
        if (fileName) fileName.textContent = file.name;
        if (result) result.textContent = "";
    });

    form?.addEventListener("submit", async (event) => {
        event.preventDefault();
        const file = fileInput?.files?.[0];
        if (!file) {
            if (result) result.textContent = "Choose an image first.";
            return;
        }
        saveButton.disabled = true;
        if (result) result.textContent = "Uploading profile picture...";
        try {
            const body = new FormData();
            body.append("profile_image", file);
            const response = await fetch("/profile/image", { method: "POST", body });
            const data = await response.json();
            if (!response.ok || data.status !== "success") throw new Error(data.message || "Unable to update profile picture.");
            document.querySelectorAll("[data-current-user-avatar]").forEach((image) => {
                image.dataset.fallbackApplied = "0";
                image.src = data.profile_image_url;
            });
            window.dispatchEvent(new CustomEvent("ibems:profile-image-updated", { detail: data }));
            closeModal();
        } catch (error) {
            if (result) result.textContent = error.message || "Unable to update profile picture.";
        } finally {
            saveButton.disabled = false;
        }
    });
})();
