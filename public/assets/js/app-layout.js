(function () {
    const KEY = "ibems.sidebar.collapsed";
    const LAST_NAV_PREFIX = "ibems.nav.last.";
    const shell = document.querySelector(".app-shell");
    const btn = document.getElementById("sidebar-toggle");
    const sidebar = document.querySelector(".app-sidebar");
    const brand = document.querySelector(".app-brand");
    const navLinks = Array.from(document.querySelectorAll(".app-menu a"));
    const logoutForms = Array.from(document.querySelectorAll("form[data-confirm-logout]"));
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

    logoutForms.forEach((form) => {
        form.addEventListener("submit", async (event) => {
            if (form.dataset.logoutConfirmed === "true") return;
            event.preventDefault();

            const confirmed = window.IbemsDialog
                ? await window.IbemsDialog.confirm("Your current session will end and you will need to sign in again.", {
                    title: "Log out of IBEMS?",
                    confirmLabel: "Log out",
                    cancelLabel: "Stay signed in",
                    icon: "bi-box-arrow-right",
                    tone: "danger",
                })
                : window.confirm("Log out of IBEMS?");

            if (!confirmed) return;
            form.dataset.logoutConfirmed = "true";
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) submitButton.disabled = true;
            HTMLFormElement.prototype.submit.call(form);
        });
    });

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

    btn.addEventListener("click", (event) => {
        event.stopPropagation();
        if (!canCollapse()) return;
        const next = !shell.classList.contains("sidebar-collapsed");
        applyState(next);
        localStorage.setItem(KEY, next ? "1" : "0");
        if (event.detail > 0) {
            btn.blur();
        }
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

    const enhanceModalAccessibility = (root = document) => {
        root.querySelectorAll?.(modalSelectors).forEach((modal) => {
            modal.setAttribute("role", modal.getAttribute("role") || "dialog");
            modal.setAttribute("aria-modal", "true");

            const closeButton = modal.querySelector(
                ".admin-modal-close, .acct-modal-close, .inv-modal-close, .app-modal-close, .receipt-close, [data-modal-close]"
            );
            if (closeButton && !closeButton.getAttribute("aria-label")) closeButton.setAttribute("aria-label", "Close dialog");

            const heading = modal.querySelector("h1, h2, h3, h4");
            if (heading) {
                if (!heading.id) heading.id = `${modal.id || "dialog"}-title`;
                if (!modal.getAttribute("aria-labelledby")) modal.setAttribute("aria-labelledby", heading.id);
            }
        });

        root.querySelectorAll?.("[data-inset-modal-scroll]").forEach((card) => {
            if (card.dataset.insetModalScrollReady === "true") return;
            const header = Array.from(card.children).find((child) => child.matches(".acct-modal-head, .admin-modal-head"));
            if (!header) return;

            const scrollRegion = document.createElement("div");
            scrollRegion.className = "app-inset-modal-scroll";
            Array.from(card.children).forEach((child) => {
                if (child !== header) scrollRegion.appendChild(child);
            });
            card.appendChild(scrollRegion);
            card.classList.add("app-inset-modal-card");
            card.dataset.insetModalScrollReady = "true";
        });
    };

    enhanceModalAccessibility();

    let compactPanelSequence = 0;

    const controlWrapper = (control, bar) => {
        const wrapper = control.closest("label, .field, .ui-field, .history-filter-field, .inventory-filter-field, .settings-input-field");
        return wrapper && bar.contains(wrapper) ? wrapper : control;
    };

    const initCompactFilterBar = (bar, index) => {
        if (bar.dataset.compactFiltersReady === "true") return;

        const searchInput = bar.querySelector('input[type="search"]');
        const searchWrapper = searchInput ? controlWrapper(searchInput, bar) : null;
        const advancedControls = Array.from(bar.querySelectorAll('select, input[type="date"], input[type="checkbox"]'));
        const sortControls = advancedControls.filter((control) => /sort/i.test(control.id || control.name || ""));
        const filterControls = advancedControls.filter((control) => !sortControls.includes(control));
        if (!sortControls.length && !filterControls.length) return;

        advancedControls.forEach((control) => {
            control.dataset.compactDefault = control.type === "checkbox" ? String(control.defaultChecked) : control.value;
        });

        const originalButtons = Array.from(bar.querySelectorAll("button")).filter((button) =>
            !searchWrapper?.contains(button) && !button.closest(".ui-select, .ui-date")
        );
        const primary = document.createElement("div");
        primary.className = "compact-filter-primary";
        const actionBox = document.createElement("div");
        actionBox.className = "compact-filter-actions";
        const panelId = `compact-filter-panel-${index + 1}`;

        if (searchWrapper) primary.appendChild(searchWrapper);
        primary.appendChild(actionBox);
        bar.prepend(primary);

        const makeToggle = (mode, label, icon) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "secondary-btn compact-filter-toggle";
            button.dataset.compactFilterToggle = mode;
            button.setAttribute("aria-controls", panelId);
            button.setAttribute("aria-expanded", "false");
            button.innerHTML = `<i class="bi ${icon}" aria-hidden="true"></i><span>${label}</span><span class="compact-filter-count" aria-hidden="true"></span>`;
            actionBox.appendChild(button);
            return button;
        };

        const sortToggle = sortControls.length ? makeToggle("sort", "Sort", "bi-arrow-down-up") : null;
        const filterToggle = filterControls.length ? makeToggle("filter", "Filter", "bi-funnel") : null;
        originalButtons.forEach((button) => actionBox.appendChild(button));

        const panel = document.createElement("div");
        panel.id = panelId;
        panel.className = "compact-filter-panel";
        panel.hidden = true;

        const heading = document.createElement("div");
        heading.className = "compact-filter-panel-head";
        heading.innerHTML = '<div><span>List controls</span><strong data-compact-filter-title>Filters</strong></div><button type="button" class="compact-filter-close" aria-label="Close list controls"><i class="bi bi-x-lg" aria-hidden="true"></i></button>';
        panel.appendChild(heading);

        const appendSection = (mode, title, controls) => {
            if (!controls.length) return;
            const section = document.createElement("section");
            section.className = "compact-filter-section";
            section.dataset.compactFilterSection = mode;
            const sectionTitle = document.createElement("h3");
            sectionTitle.textContent = title;
            const grid = document.createElement("div");
            grid.className = "compact-filter-grid";
            const wrappers = [];
            controls.forEach((control) => {
                const wrapper = controlWrapper(control, bar);
                if (!wrappers.includes(wrapper) && wrapper !== searchWrapper) wrappers.push(wrapper);
            });
            wrappers.forEach((wrapper) => grid.appendChild(wrapper));
            section.append(sectionTitle, grid);
            panel.appendChild(section);
        };

        appendSection("sort", "Sort results", sortControls);
        appendSection("filter", "Filter results", filterControls);

        const footer = document.createElement("div");
        footer.className = "compact-filter-panel-actions";
        footer.innerHTML = '<button type="button" class="secondary-btn" data-compact-filter-reset><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset all</button><button type="button" class="primary-btn" data-compact-filter-apply>Done</button>';
        panel.appendChild(footer);
        bar.appendChild(panel);
        bar.classList.add("compact-filter-bar");
        bar.dataset.compactFiltersReady = "true";
        Array.from(bar.children).forEach((child) => {
            if (child === primary || child === panel) return;
            if (!child.matches("input, select, button, a") && !child.querySelector("input, select, button, a")) {
                child.classList.add("compact-filter-vacated");
            }
        });

        const setOpen = (mode = "filter", open = true) => {
            panel.hidden = !open;
            panel.dataset.mode = mode;
            if (open && window.matchMedia("(min-width: 761px)").matches) {
                window.requestAnimationFrame(() => {
                    panel.style.removeProperty("max-height");
                    panel.style.removeProperty("overflow-y");
                    window.requestAnimationFrame(() => {
                        if (!panel.isConnected || panel.hidden) return;
                        const panelRect = panel.getBoundingClientRect();
                        const availableHeight = Math.max(0, window.innerHeight - panelRect.top - 16);
                        if (panelRect.height > availableHeight) {
                            panel.style.maxHeight = `${availableHeight}px`;
                            panel.style.overflowY = "auto";
                        }
                    });
                });
            } else if (!open) {
                panel.style.removeProperty("max-height");
                panel.style.removeProperty("overflow-y");
            }
            panel.querySelector("[data-compact-filter-title]").textContent = mode === "sort" ? "Sort results" : "Filters";
            [sortToggle, filterToggle].filter(Boolean).forEach((button) => {
                const active = open && button.dataset.compactFilterToggle === mode;
                button.classList.toggle("is-active", active);
                button.setAttribute("aria-expanded", active ? "true" : "false");
            });
            bar.classList.toggle("has-open-filter-panel", open);
        };

        const activeFor = (control) => {
            const baseline = control.dataset.compactDefault ?? "";
            return control.type === "checkbox" ? String(control.checked) !== baseline : control.value !== baseline;
        };

        const updateCounts = () => {
            const update = (button, controls) => {
                if (!button) return;
                const count = controls.filter(activeFor).length;
                const badge = button.querySelector(".compact-filter-count");
                badge.textContent = count ? String(count) : "";
                button.classList.toggle("has-active-filters", count > 0);
                button.setAttribute("aria-label", `${button.dataset.compactFilterToggle === "sort" ? "Sort" : "Filter"}${count ? `, ${count} active` : ""}`);
            };
            update(sortToggle, sortControls);
            update(filterToggle, filterControls);
        };

        [sortToggle, filterToggle].filter(Boolean).forEach((button) => {
            button.addEventListener("click", () => {
                const mode = button.dataset.compactFilterToggle;
                const shouldOpen = panel.hidden || panel.dataset.mode !== mode;
                setOpen(mode, shouldOpen);
            });
        });
        panel.querySelector(".compact-filter-close").addEventListener("click", () => setOpen(panel.dataset.mode, false));
        panel.querySelector("[data-compact-filter-apply]").addEventListener("click", () => setOpen(panel.dataset.mode, false));
        panel.querySelector("[data-compact-filter-reset]").addEventListener("click", () => {
            const resetButton = originalButtons.find((button) => /refresh|reset|clear/i.test(`${button.id} ${button.textContent}`));
            if (resetButton) {
                resetButton.click();
            } else {
                advancedControls.forEach((control) => {
                    if (control.type === "checkbox") control.checked = control.dataset.compactDefault === "true";
                    else control.value = control.dataset.compactDefault || "";
                    control.dispatchEvent(new Event("change", { bubbles: true }));
                });
                if (searchInput) {
                    searchInput.value = "";
                    searchInput.dispatchEvent(new Event("input", { bubbles: true }));
                }
            }
            window.setTimeout(updateCounts, 0);
        });

        advancedControls.forEach((control) => control.addEventListener("change", () => {
            updateCounts();
            if (window.matchMedia("(max-width: 760px)").matches && panel.dataset.mode === "sort" && sortControls.includes(control)) {
                setOpen("sort", false);
            }
        }));
        const pageSignal = window.IbemsPortalNavigation?.currentSignal || window.IbemsUserNavigation?.currentSignal;
        document.addEventListener("click", (event) => {
            if (panel.hidden || bar.contains(event.target)) return;
            const isMobileFilterPopup = window.matchMedia("(max-width: 760px)").matches
                && panel.dataset.mode === "filter"
                && event.target.closest?.(".ui-select-menu, .ui-date-popup");
            if (!isMobileFilterPopup) setOpen(panel.dataset.mode, false);
        }, pageSignal ? { signal: pageSignal } : undefined);
        bar.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && !panel.hidden) {
                const activeToggle = panel.dataset.mode === "sort" ? sortToggle : filterToggle;
                setOpen(panel.dataset.mode, false);
                activeToggle?.focus();
            }
        });
        updateCounts();
    };

    const enhanceCompactFilters = (root = document) => {
        Array.from(root.querySelectorAll?.("[data-compact-filters]") || []).forEach((bar) => {
            compactPanelSequence += 1;
            initCompactFilterBar(bar, compactPanelSequence);
        });
    };

    enhanceCompactFilters();
    document.addEventListener("ibems:portal-page-loaded", (event) => {
        const root = event.detail?.main || document;
        enhanceModalAccessibility(root);
        enhanceCompactFilters(root);
    });
})();
