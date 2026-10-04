(function () {
    "use strict";
    const button = document.getElementById("theme-toggle");
    if (!button) return;
    const root = document.documentElement;
    const transitionClass = "theme-transitioning";
    const fallbackTransitionClass = "theme-transitioning-fallback";
    const transitionDuration = 240;
    let transitionTimer = 0;
    let transitionCommitTimer = 0;
    let transitionSequence = 0;
    let activeTransition = null;
    let targetTheme = root.dataset.theme || "light";

    const commit = (theme, persist) => {
        const dark = theme === "dark";
        root.dataset.theme = dark ? "dark" : "light";
        if (persist) {
            try { localStorage.setItem("ibems-theme", dark ? "dark" : "light"); } catch (_) {}
        }
        document.querySelector('meta[name="theme-color"]')?.setAttribute("content", dark ? "#091425" : "#ffffff");
        button.setAttribute("aria-pressed", dark ? "true" : "false");
        button.setAttribute("aria-label", dark ? "Use light mode" : "Use dark mode");
        button.title = dark ? "Use light mode" : "Use dark mode";
        const icon = button.querySelector("i");
        if (icon) icon.className = dark ? "bi bi-sun" : "bi bi-moon-stars";
        window.dispatchEvent(new CustomEvent("ibems:themechange", {detail: {theme: dark ? "dark" : "light"}}));
    };

    const apply = (theme, persist, animate = false) => {
        targetTheme = theme === "dark" ? "dark" : "light";
        const sequence = ++transitionSequence;
        window.clearTimeout(transitionTimer);
        window.clearTimeout(transitionCommitTimer);
        const previousTransition = activeTransition;
        activeTransition = null;
        previousTransition?.skipTransition();
        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        if (!animate || reduceMotion) {
            root.classList.remove(transitionClass);
            root.classList.remove(fallbackTransitionClass);
            commit(theme, persist);
            return;
        }

        root.classList.remove(transitionClass, fallbackTransitionClass);

        if (typeof document.startViewTransition === "function") {
            root.classList.add(transitionClass);
            let transition;
            try {
                transition = document.startViewTransition(() => {
                    if (sequence === transitionSequence) commit(theme, persist);
                });
            } catch (_) {
                root.classList.remove(transitionClass);
                activeTransition = null;
                commit(theme, persist);
                return;
            }
            activeTransition = transition;
            // Skipping a superseded animation rejects ready even when finished succeeds.
            transition.ready?.catch(() => {});
            const finish = () => {
                if (sequence === transitionSequence) {
                    root.classList.remove(transitionClass);
                    activeTransition = null;
                }
            };
            transition.finished.then(finish, finish);
            return;
        }

        root.classList.add(fallbackTransitionClass);
        transitionCommitTimer = window.setTimeout(() => {
            if (sequence === transitionSequence) commit(theme, persist);
        }, transitionDuration / 2);
        transitionTimer = window.setTimeout(() => {
            if (sequence === transitionSequence) root.classList.remove(fallbackTransitionClass);
        }, transitionDuration + 40);
    };

    apply(root.dataset.theme || "light", false);
    window.matchMedia("(prefers-reduced-motion: reduce)").addEventListener?.("change", (event) => {
        if (event.matches) apply(targetTheme, true);
    });
    button.addEventListener("click", () => apply(targetTheme === "dark" ? "light" : "dark", true, true));
}());
