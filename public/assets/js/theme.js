(function () {
    "use strict";
    const button = document.getElementById("theme-toggle");
    if (!button) return;

    const apply = (theme, persist) => {
        const dark = theme === "dark";
        document.documentElement.dataset.theme = dark ? "dark" : "light";
        if (persist) localStorage.setItem("ibems-theme", dark ? "dark" : "light");
        document.querySelector('meta[name="theme-color"]')?.setAttribute("content", dark ? "#091425" : "#ffffff");
        button.setAttribute("aria-pressed", dark ? "true" : "false");
        button.setAttribute("aria-label", dark ? "Use light mode" : "Use dark mode");
        button.title = dark ? "Use light mode" : "Use dark mode";
        const icon = button.querySelector("i");
        if (icon) icon.className = dark ? "bi bi-sun" : "bi bi-moon-stars";
        window.dispatchEvent(new CustomEvent("ibems:themechange", {detail: {theme: dark ? "dark" : "light"}}));
    };

    apply(document.documentElement.dataset.theme || "light", false);
    button.addEventListener("click", () => apply(document.documentElement.dataset.theme === "dark" ? "light" : "dark", true));
}());
