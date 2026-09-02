(function () {
    "use strict";

    const allowed = new Set(["day", "week", "month", "year"]);

    function roots() {
        return Array.from(document.querySelectorAll("[data-dashboard-period-filter]"));
    }

    function get(root = roots()[0]) {
        const selected = root?.querySelector("[data-dashboard-period][aria-pressed='true']")?.dataset.dashboardPeriod;
        return allowed.has(selected) ? selected : "day";
    }

    function select(period, root = roots()[0], emit = true) {
        const next = allowed.has(period) ? period : "day";
        if (!root) return next;
        root.querySelectorAll("[data-dashboard-period]").forEach((button) => {
            button.setAttribute("aria-pressed", button.dataset.dashboardPeriod === next ? "true" : "false");
        });
        if (emit && root.dataset.dashboardPeriodMode === "reload") {
            const url = new URL(window.location.href);
            url.searchParams.set("period", next);
            window.location.assign(url);
        } else if (emit) {
            const url = new URL(window.location.href);
            url.searchParams.set("period", next);
            window.history.replaceState(window.history.state, "", url);
            window.dispatchEvent(new CustomEvent("ibems:dashboard-period-change", {detail: {period: next}}));
        }
        return next;
    }

    function setMeta(meta, root = roots()[0]) {
        if (!root || !meta) return;
        select(String(meta.key || "day"), root, false);
        const label = root.querySelector("[data-dashboard-period-label]");
        if (label) label.textContent = String(meta.label || "Selected period");
        document.querySelectorAll("[data-dashboard-selected-period]").forEach((node) => {
            node.textContent = String(meta.label || "Selected period");
        });
    }

    document.addEventListener("click", (event) => {
        const button = event.target.closest("[data-dashboard-period]");
        const root = button?.closest("[data-dashboard-period-filter]");
        if (!button || !root || button.getAttribute("aria-pressed") === "true") return;
        select(button.dataset.dashboardPeriod, root, true);
    });

    window.IbemsDashboardPeriod = {get, select, setMeta};
}());
