(function () {
    const CONTROL_SELECTOR = "select:not([multiple]):not([size]):not([data-no-enhance]), input[type='date']:not([data-no-enhance])";
    const enhanced = new WeakSet();
    let openControl = null;

    const closeOpenControl = (restoreFocus) => {
        if (!openControl) return;
        openControl.close(restoreFocus);
        openControl = null;
    };

    const positionPopup = (trigger, popup, preferredWidth) => {
        const rect = trigger.getBoundingClientRect();
        const margin = 12;
        const heightLimit = popup.classList.contains("ui-date-popup") ? 440 : 364;
        const width = Math.min(
            Math.max(rect.width, preferredWidth),
            window.innerWidth - margin * 2
        );
        const estimatedHeight = Math.min(heightLimit, popup.scrollHeight || 320);
        const spaceBelow = window.innerHeight - rect.bottom;
        const openAbove = spaceBelow < estimatedHeight + margin && rect.top > spaceBelow;

        popup.style.position = "fixed";
        popup.style.width = `${width}px`;
        popup.style.left = `${Math.min(
            Math.max(margin, rect.left),
            Math.max(margin, window.innerWidth - width - margin)
        )}px`;
        popup.style.top = openAbove ? "auto" : `${rect.bottom + 6}px`;
        popup.style.bottom = openAbove ? `${window.innerHeight - rect.top + 6}px` : "auto";
        popup.style.maxHeight = `${Math.max(180, Math.min(heightLimit, openAbove ? rect.top - 18 : spaceBelow - 18))}px`;
    };

    const emitChange = (control) => {
        control.dispatchEvent(new Event("input", { bubbles: true }));
        control.dispatchEvent(new Event("change", { bubbles: true }));
    };

    const preferredMenuWidth = (trigger, options) => {
        const fallback = Math.max(...options.map((option) => option.textContent.trim().length * 8 + 72), 220);
        const canvas = document.createElement("canvas");
        const context = canvas.getContext("2d");
        if (!context) return Math.min(420, fallback);
        const computed = window.getComputedStyle(trigger);
        context.font = `${computed.fontWeight} ${computed.fontSize} ${computed.fontFamily}`;
        const measured = options.reduce(
            (width, option) => Math.max(width, Math.ceil(context.measureText(option.textContent.trim()).width) + 72),
            220
        );
        return Math.min(420, measured);
    };

    const enhanceSelect = (select) => {
        if (enhanced.has(select) || select.closest(".ui-select")) return;
        enhanced.add(select);

        const wrapper = document.createElement("div");
        wrapper.className = "ui-select";
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add("ui-native-control");

        const trigger = document.createElement("button");
        trigger.type = "button";
        trigger.className = "ui-select-trigger";
        trigger.setAttribute("aria-haspopup", "listbox");
        trigger.innerHTML = '<span class="ui-select-value"></span><span class="ui-control-icon" aria-hidden="true"><i class="bi bi-chevron-down"></i></span>';
        wrapper.appendChild(trigger);

        const listbox = document.createElement("div");
        listbox.className = "ui-select-menu";
        listbox.id = `${select.id || `select-${Math.random().toString(36).slice(2)}`}-listbox`;
        listbox.setAttribute("role", "listbox");
        listbox.hidden = true;
        document.body.appendChild(listbox);

        trigger.setAttribute("aria-controls", listbox.id);
        const explicitLabel = select.id ? document.querySelector(`label[for="${CSS.escape(select.id)}"]`) : null;
        const ariaLabel = select.getAttribute("aria-label") || explicitLabel?.textContent?.trim();
        if (ariaLabel) trigger.setAttribute("aria-label", ariaLabel);
        if (select.required) trigger.setAttribute("aria-required", "true");

        let activeIndex = -1;

        const selectableOptions = () => Array.from(select.options);
        const sync = () => {
            const selected = select.selectedOptions[0];
            trigger.querySelector(".ui-select-value").textContent = selected?.textContent?.trim() || "Select an option";
            trigger.disabled = select.disabled;
            trigger.toggleAttribute("aria-invalid", select.getAttribute("aria-invalid") === "true");
            trigger.setAttribute("aria-expanded", listbox.hidden ? "false" : "true");
        };

        const choose = (index) => {
            const option = selectableOptions()[index];
            if (!option || option.disabled) return;
            select.value = option.value;
            emitChange(select);
            sync();
            api.close(true);
        };

        const render = () => {
            listbox.replaceChildren();
            selectableOptions().forEach((option, index) => {
                const item = document.createElement("button");
                item.type = "button";
                item.className = "ui-select-option";
                item.setAttribute("role", "option");
                item.setAttribute("aria-selected", option.selected ? "true" : "false");
                item.disabled = option.disabled;
                item.textContent = option.textContent.trim();
                item.addEventListener("mouseenter", () => setActive(index));
                item.addEventListener("mousedown", (event) => event.preventDefault());
                item.addEventListener("click", () => choose(index));
                if (option.selected) {
                    const check = document.createElement("i");
                    check.className = "bi bi-check2";
                    check.setAttribute("aria-hidden", "true");
                    item.appendChild(check);
                }
                listbox.appendChild(item);
            });
        };

        const setActive = (index) => {
            const options = selectableOptions();
            if (!options.length) return;
            let next = index;
            for (let count = 0; count < options.length && options[next]?.disabled; count += 1) {
                next = (next + 1) % options.length;
            }
            activeIndex = next;
            Array.from(listbox.children).forEach((item, itemIndex) => {
                item.classList.toggle("is-active", itemIndex === activeIndex);
            });
            listbox.children[activeIndex]?.scrollIntoView({ block: "nearest" });
        };

        const api = {
            close(restoreFocus) {
                listbox.hidden = true;
                trigger.classList.remove("is-open");
                trigger.setAttribute("aria-expanded", "false");
                if (restoreFocus) trigger.focus();
                if (openControl === api) openControl = null;
            },
            reposition() {
                if (!listbox.hidden) positionPopup(trigger, listbox, preferredMenuWidth(trigger, selectableOptions()));
            },
        };

        const open = () => {
            closeOpenControl(false);
            render();
            sync();
            listbox.hidden = false;
            trigger.classList.add("is-open");
            trigger.setAttribute("aria-expanded", "true");
            activeIndex = Math.max(0, select.selectedIndex);
            setActive(activeIndex);
            positionPopup(trigger, listbox, preferredMenuWidth(trigger, selectableOptions()));
            openControl = api;
        };

        trigger.addEventListener("click", () => listbox.hidden ? open() : api.close(false));
        trigger.addEventListener("keydown", (event) => {
            if (["ArrowDown", "ArrowUp"].includes(event.key)) {
                event.preventDefault();
                if (listbox.hidden) open();
                else {
                    const direction = event.key === "ArrowDown" ? 1 : -1;
                    const options = selectableOptions();
                    let next = activeIndex;
                    do next = (next + direction + options.length) % options.length;
                    while (options[next]?.disabled && next !== activeIndex);
                    setActive(next);
                }
            } else if (["Enter", " "].includes(event.key)) {
                event.preventDefault();
                if (listbox.hidden) open();
                else choose(activeIndex);
            } else if (event.key === "Escape" && !listbox.hidden) {
                event.preventDefault();
                api.close(true);
            } else if (event.key === "Home" && !listbox.hidden) {
                event.preventDefault();
                setActive(0);
            } else if (event.key === "End" && !listbox.hidden) {
                event.preventDefault();
                setActive(selectableOptions().length - 1);
            }
        });

        select.addEventListener("change", sync);
        select.addEventListener("focus", () => trigger.focus());
        select.addEventListener("invalid", () => {
            trigger.setAttribute("aria-invalid", "true");
            trigger.focus();
        });
        new MutationObserver(() => {
            sync();
            if (!listbox.hidden) render();
        }).observe(select, { childList: true, subtree: true, attributes: true });
        sync();
    };

    const parseDate = (value) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || "")) return null;
        const [year, month, day] = value.split("-").map(Number);
        const result = new Date(year, month - 1, day);
        return result.getFullYear() === year && result.getMonth() === month - 1 && result.getDate() === day ? result : null;
    };
    const isoDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
    const sameDate = (left, right) => left && right && isoDate(left) === isoDate(right);
    const displayDate = (value) => parseDate(value)?.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }) || "";

    const enhanceDate = (input) => {
        if (enhanced.has(input) || input.closest(".ui-date")) return;
        enhanced.add(input);

        const wrapper = document.createElement("div");
        wrapper.className = "ui-date";
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        input.classList.add("ui-native-control");

        const trigger = document.createElement("button");
        trigger.type = "button";
        trigger.className = "ui-date-trigger";
        trigger.setAttribute("aria-haspopup", "dialog");
        trigger.innerHTML = '<span class="ui-date-value"></span><span class="ui-control-icon" aria-hidden="true"><i class="bi bi-calendar3"></i></span>';
        wrapper.appendChild(trigger);
        const explicitLabel = input.id ? document.querySelector(`label[for="${CSS.escape(input.id)}"]`) : null;
        const ariaLabel = input.getAttribute("aria-label") || explicitLabel?.textContent?.trim();
        if (ariaLabel) trigger.setAttribute("aria-label", ariaLabel);
        if (input.required) trigger.setAttribute("aria-required", "true");

        const popup = document.createElement("div");
        popup.className = "ui-date-popup";
        popup.hidden = true;
        popup.setAttribute("role", "dialog");
        popup.setAttribute("aria-label", "Choose date");
        document.body.appendChild(popup);

        const selected = () => parseDate(input.value);
        let viewDate = selected() || new Date();
        viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);
        const minDate = () => parseDate(input.min);
        const maxDate = () => parseDate(input.max);
        const unavailable = (date) => Boolean((minDate() && date < minDate()) || (maxDate() && date > maxDate()));

        const sync = () => {
            trigger.querySelector(".ui-date-value").textContent = displayDate(input.value) || input.placeholder || "Select date";
            trigger.querySelector(".ui-date-value").classList.toggle("is-placeholder", !input.value);
            trigger.disabled = input.disabled;
            trigger.toggleAttribute("aria-invalid", input.getAttribute("aria-invalid") === "true");
        };

        const choose = (date) => {
            if (unavailable(date)) return;
            input.value = isoDate(date);
            emitChange(input);
            sync();
            api.close(true);
        };

        const render = () => {
            const months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            popup.innerHTML = `
                <div class="ui-date-head">
                    <button type="button" data-month="-1" aria-label="Previous month"><i class="bi bi-chevron-left"></i></button>
                    <div><strong>${months[viewDate.getMonth()]}</strong><span>${viewDate.getFullYear()}</span></div>
                    <button type="button" data-month="1" aria-label="Next month"><i class="bi bi-chevron-right"></i></button>
                </div>
                <div class="ui-date-weekdays">${["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"].map(day => `<span>${day}</span>`).join("")}</div>
                <div class="ui-date-grid"></div>
                <div class="ui-date-actions"><button type="button" data-date-clear>Clear</button><button type="button" data-date-today>Today</button></div>`;

            const grid = popup.querySelector(".ui-date-grid");
            const year = viewDate.getFullYear();
            const month = viewDate.getMonth();
            const firstWeekday = new Date(year, month, 1).getDay();
            const start = new Date(year, month, 1 - firstWeekday);
            const today = new Date();
            for (let index = 0; index < 42; index += 1) {
                const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);
                const button = document.createElement("button");
                button.type = "button";
                button.textContent = String(date.getDate());
                button.className = "ui-date-day";
                button.classList.toggle("is-outside", date.getMonth() !== month);
                button.classList.toggle("is-today", sameDate(date, today));
                button.classList.toggle("is-selected", sameDate(date, selected()));
                button.disabled = unavailable(date);
                button.setAttribute("aria-label", date.toLocaleDateString("en-US", { dateStyle: "long" }));
                button.addEventListener("click", () => choose(date));
                grid.appendChild(button);
            }

            popup.querySelectorAll("[data-month]").forEach((button) => button.addEventListener("click", () => {
                viewDate = new Date(year, month + Number(button.dataset.month), 1);
                render();
            }));
            popup.querySelector("[data-date-clear]").addEventListener("click", () => {
                input.value = "";
                emitChange(input);
                sync();
                api.close(true);
            });
            const todayButton = popup.querySelector("[data-date-today]");
            todayButton.disabled = unavailable(today);
            todayButton.addEventListener("click", () => choose(today));
        };

        const api = {
            close(restoreFocus) {
                popup.hidden = true;
                trigger.classList.remove("is-open");
                trigger.setAttribute("aria-expanded", "false");
                if (restoreFocus) trigger.focus();
                if (openControl === api) openControl = null;
            },
            reposition() {
                if (!popup.hidden) positionPopup(trigger, popup, 320);
            },
        };

        const open = () => {
            closeOpenControl(false);
            const current = selected() || new Date();
            viewDate = new Date(current.getFullYear(), current.getMonth(), 1);
            render();
            popup.hidden = false;
            trigger.classList.add("is-open");
            trigger.setAttribute("aria-expanded", "true");
            positionPopup(trigger, popup, 320);
            openControl = api;
        };

        trigger.addEventListener("click", () => popup.hidden ? open() : api.close(false));
        trigger.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && !popup.hidden) {
                event.preventDefault();
                api.close(true);
            }
        });
        input.addEventListener("change", sync);
        input.addEventListener("focus", () => trigger.focus());
        input.addEventListener("invalid", () => {
            trigger.setAttribute("aria-invalid", "true");
            trigger.focus();
        });
        sync();
    };

    const enhance = (root) => {
        if (root.matches?.(CONTROL_SELECTOR)) {
            root.matches("select") ? enhanceSelect(root) : enhanceDate(root);
        }
        root.querySelectorAll?.(CONTROL_SELECTOR).forEach((control) => {
            control.matches("select") ? enhanceSelect(control) : enhanceDate(control);
        });
    };

    document.addEventListener("mousedown", (event) => {
        if (!openControl) return;
        if (!event.target.closest(".ui-select-menu, .ui-select-trigger, .ui-date-popup, .ui-date-trigger")) {
            closeOpenControl(false);
        }
    });
    window.addEventListener("resize", () => openControl?.reposition());
    window.addEventListener("scroll", () => openControl?.reposition(), true);
    document.addEventListener("ibems:modal-open", () => closeOpenControl(false));
    window.addEventListener("ibems:portal-page-unload", () => {
        closeOpenControl(false);
        document.querySelectorAll(".ui-select-menu, .ui-date-popup").forEach((popup) => popup.remove());
    });

    enhance(document);
    new MutationObserver((records) => records.forEach((record) => record.addedNodes.forEach((node) => {
        if (node.nodeType === Node.ELEMENT_NODE) enhance(node);
    }))).observe(document.body, { childList: true, subtree: true });
})();
