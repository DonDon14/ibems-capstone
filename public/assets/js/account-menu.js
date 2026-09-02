(function () {
    "use strict";

    const body = document.body;
    const menu = document.getElementById("account-menu");
    const toggles = Array.from(document.querySelectorAll("[data-account-menu-toggle]"));
    const panel = menu?.querySelector(".account-menu-panel");
    const closeButtons = Array.from(document.querySelectorAll("[data-account-menu-close]"));
    const themeAction = document.querySelector("[data-account-theme]");
    const themeProxy = document.getElementById("theme-toggle");
    let trigger = null;

    if (!menu || !toggles.length || !panel) return;

    const focusableSelector = [
        "a[href]",
        "button:not([disabled])",
        "input:not([disabled])",
        "select:not([disabled])",
        "textarea:not([disabled])",
        "[tabindex]:not([tabindex='-1'])",
    ].join(",");

    function isOpen() {
        return !menu.hidden;
    }

    function syncThemeAction() {
        if (!themeAction) return;
        const isDark = document.documentElement.dataset.theme === "dark";
        const icon = themeAction.querySelector("i");
        const detail = themeAction.querySelector("small");
        if (icon) icon.className = isDark ? "bi bi-sun" : "bi bi-moon-stars";
        if (detail) detail.textContent = isDark ? "Switch to light mode" : "Switch to dark mode";
        themeAction.setAttribute("aria-label", isDark ? "Use light mode" : "Use dark mode");
    }

    function setOpen(open, restoreFocus = true) {
        const shouldOpen = !!open;
        menu.hidden = !shouldOpen;
        toggles.forEach((toggle) => toggle.setAttribute("aria-expanded", shouldOpen ? "true" : "false"));
        body.classList.toggle("account-menu-open", shouldOpen);

        if (shouldOpen) {
            trigger = document.activeElement;
            syncThemeAction();
            window.requestAnimationFrame(() => panel.querySelector(".account-menu-close")?.focus());
        } else if (restoreFocus && trigger instanceof HTMLElement) {
            trigger.focus();
            trigger = null;
        }
    }

    toggles.forEach((toggle) => toggle.addEventListener("click", () => setOpen(!isOpen())));
    closeButtons.forEach((button) => button.addEventListener("click", () => setOpen(false)));

    themeAction?.addEventListener("click", () => {
        themeProxy?.click();
        syncThemeAction();
    });

    document.querySelectorAll("[data-account-menu-action]").forEach((control) => {
        control.addEventListener("click", () => {
            if (control !== themeAction && control.id !== "user-mobile-install") setOpen(false, false);
        });
    });

    menu.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            event.preventDefault();
            setOpen(false);
            return;
        }
        if (event.key !== "Tab" || !window.matchMedia("(max-width: 760px)").matches) return;
        const focusable = Array.from(panel.querySelectorAll(focusableSelector)).filter((element) => {
            return element instanceof HTMLElement && !element.hidden && element.offsetParent !== null;
        });
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    document.addEventListener("click", (event) => {
        if (!isOpen() || window.matchMedia("(max-width: 760px)").matches) return;
        if (event.target === themeProxy) return;
        if (!panel.contains(event.target) && !toggles.some((toggle) => toggle.contains(event.target))) setOpen(false, false);
    });

    window.addEventListener("ibems:themechange", syncThemeAction);
    window.addEventListener("ibems:account-menu-close", () => setOpen(false, false));
    window.addEventListener("resize", () => {
        if (isOpen()) setOpen(false, false);
    });

    syncThemeAction();
}());
