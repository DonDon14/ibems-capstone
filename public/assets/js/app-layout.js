(function () {
    const KEY = "ibems.sidebar.collapsed";
    const LAST_NAV_PREFIX = "ibems.nav.last.";
    const shell = document.querySelector(".app-shell");
    const btn = document.getElementById("sidebar-toggle");
    const btnIcon = btn ? btn.querySelector("i") : null;
    const sidebar = document.querySelector(".app-sidebar");
    const brand = document.querySelector(".app-brand");
    const navLinks = Array.from(document.querySelectorAll(".app-menu a"));
    const logoutLink = document.querySelector(".sidebar-logout a, .sidebar-logout button");
    if (!shell || !btn) return;

    const isDesktop = () => window.matchMedia("(min-width: 1201px)").matches;
    const isTablet = () => window.matchMedia("(min-width: 981px) and (max-width: 1200px)").matches;
    const canCollapse = () => window.matchMedia("(min-width: 981px)").matches;

    const portalKey = () => {
        const path = window.location.pathname.toLowerCase().replace(/^\/index\.php(?=\/|$)/, "");
        if (path.startsWith("/admin")) return "admin";
        if (path.startsWith("/store")) return "store";
        if (path.startsWith("/accounting")) return "accounting";
        if (path.startsWith("/user")) return "user";
        return "global";
    };

    const applyState = (collapsed) => {
        if (!canCollapse()) {
            shell.classList.remove("sidebar-collapsed");
            return;
        }
        shell.classList.toggle("sidebar-collapsed", !!collapsed);
        btn.setAttribute("aria-pressed", collapsed ? "true" : "false");
        btn.setAttribute("title", collapsed ? "Expand Sidebar" : "Collapse Sidebar");
        btn.setAttribute("aria-label", collapsed ? "Expand Sidebar" : "Collapse Sidebar");
        if (btnIcon) {
            btnIcon.classList.remove("bi-chevron-left", "bi-chevron-right", "bi-layout-sidebar");
            btnIcon.classList.add(collapsed ? "bi-chevron-right" : "bi-chevron-left");
        }
    };

    const applyResponsiveState = () => {
        const savedRaw = localStorage.getItem(KEY);
        const saved = savedRaw === null ? null : savedRaw === "1";

        btn.disabled = false;

        if (isTablet()) {
            applyState(saved === null ? true : saved);
            return;
        }

        if (isDesktop()) {
            applyState(saved === true);
            return;
        }

        applyState(false);
    };

    navLinks.forEach((link) => {
        const label = (link.querySelector("span")?.textContent || link.textContent || "").trim();
        if (label !== "") {
            link.setAttribute("data-tooltip", label);
        }
    });

    if (logoutLink) {
        const label = (logoutLink.querySelector("span")?.textContent || logoutLink.textContent || "").trim();
        if (label !== "") {
            logoutLink.setAttribute("data-tooltip", label);
        }
    }

    const activeLink = navLinks.find((link) => link.classList.contains("is-active"));
    if (activeLink) {
        localStorage.setItem(`${LAST_NAV_PREFIX}${portalKey()}`, activeLink.getAttribute("href") || "");
    }

    if (brand) {
        brand.style.cursor = "pointer";
        brand.addEventListener("click", (event) => {
            if (event.target.closest("a")) return;
            const last = localStorage.getItem(`${LAST_NAV_PREFIX}${portalKey()}`) || "";
            if (last) {
                window.location.href = last;
            }
        });
        brand.setAttribute("title", "Open last visited page");
    }

    applyResponsiveState();

    btn.addEventListener("click", () => {
        if (!canCollapse()) return;
        const next = !shell.classList.contains("sidebar-collapsed");
        applyState(next);
        localStorage.setItem(KEY, next ? "1" : "0");
    });

    window.addEventListener("resize", () => {
        applyResponsiveState();
    });

    const modalSelectors = [
        ".admin-modal",
        ".acct-modal",
        ".inv-modal",
        ".receipt-modal",
        ".app-modal",
    ].join(",");

    document.querySelectorAll(modalSelectors).forEach((modal) => {
        modal.setAttribute("role", modal.getAttribute("role") || "dialog");
        modal.setAttribute("aria-modal", "true");

        const closeButton = modal.querySelector(
            ".admin-modal-close, .acct-modal-close, .inv-modal-close, .app-modal-close, .receipt-close, [data-modal-close]"
        );
        if (closeButton && !closeButton.getAttribute("aria-label")) {
            closeButton.setAttribute("aria-label", "Close dialog");
        }

        const heading = modal.querySelector("h1, h2, h3, h4");
        if (heading) {
            if (!heading.id) {
                heading.id = `${modal.id || "dialog"}-title`;
            }
            if (!modal.getAttribute("aria-labelledby")) {
                modal.setAttribute("aria-labelledby", heading.id);
            }
        }
    });
})();
