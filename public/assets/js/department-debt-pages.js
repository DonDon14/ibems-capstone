(function () {
"use strict";

const pageSignal = window.IbemsPortalNavigation?.currentSignal || new AbortController().signal;

function escapeHtml(value) {
    const node = document.createElement("div");
    node.textContent = String(value ?? "");
    return node.innerHTML;
}

function money(value) {
    return `PHP ${Number(value || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(value) {
    return window.IbemsFormat?.dateTime?.(value) || String(value || "-");
}

async function api(url, options = {}) {
    const response = await fetch(url, { ...options, signal: pageSignal });
    let data = {};
    try { data = await response.json(); } catch (_) { data = {}; }
    if (!response.ok || data.status !== "success") {
        const error = new Error(data.message || "Unable to complete the request.");
        error.status = response.status;
        error.hasServerMessage = data.status === "error" && typeof data.message === "string" && Boolean(data.message.trim());
        error.confirmedRejection = !response.ok && data.status === "error" && data.retry_safe === true;
        throw error;
    }
    return data;
}

function jsonOptions(payload) {
    return { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) };
}

function setResult(id, message = "", type = "") {
    const element = document.getElementById(id);
    if (!element) return;
    element.setAttribute("role", type === "error" ? "alert" : "status");
    element.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
    element.setAttribute("aria-atomic", "true");
    element.textContent = message;
    element.className = `department-result${type ? ` is-${type}` : ""}`;
}

function dataState(type, message) {
    const safeType = ["loading", "empty", "error"].includes(type) ? type : "empty";
    const icon = safeType === "loading" ? "bi-arrow-repeat" : safeType === "error" ? "bi-exclamation-circle" : "bi-inbox";
    const role = safeType === "error" ? "alert" : "status";
    return `<div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="bi ${icon}" aria-hidden="true"></i><div><strong>${escapeHtml(message)}</strong></div></div>`;
}

const departmentModalOpeners = new WeakMap();
function mountDepartmentModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return null;
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
    if (id !== "department-modal") modal.addEventListener("keydown", event => {
        if (event.defaultPrevented) return;
        if (event.key === "Escape") {
            event.preventDefault(); modal.querySelector(".app-modal-close")?.click();
        } else if (event.key === "Tab") {
            const controls = Array.from(modal.querySelectorAll('button, input, select, textarea, a[href], [tabindex]'))
                .filter(control => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length);
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    });
    window.IbemsPortalNavigation?.onCleanup(() => {
        document.body.classList.remove("department-modal-open");
        modal.remove();
    });
    return modal;
}

function setDepartmentModalOpen(modal, isOpen) {
    if (!modal) return;
    if (isOpen) departmentModalOpeners.set(modal, document.activeElement);
    modal.classList.toggle("is-hidden", !isOpen);
    document.body.classList.toggle("department-modal-open", isOpen);
    if (isOpen) modal.querySelector(".app-modal-close")?.focus();
    else {
        const opener = departmentModalOpeners.get(modal);
        if (opener?.isConnected) opener.focus({ preventScroll: true });
    }
}

function initAdmin() {
    const page = document.getElementById("department-admin-page");
    if (!page) return;
    const departmentModal = mountDepartmentModal("department-modal");
    let departments = [];
    let people = [];
    let headEditorOpen = false;
    let activeHeadSuggestionIndex = -1;
    let modalOpener = null;
    let loadRequestId = 0;
    let departmentLoadState = "loading";
    let departmentSaving = false;

    function headMatches(query) {
        const normalized = String(query || "").trim().toLowerCase();
        if (!normalized) return [];
        return people.filter((person) => [person.name, person.employee_id, person.email]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(normalized)))
            .slice(0, 20);
    }

    function setHeadSuggestionsOpen(isOpen) {
        const input = document.getElementById("department-head-search");
        const suggestions = document.getElementById("department-head-suggestions");
        input.setAttribute("aria-expanded", isOpen ? "true" : "false");
        suggestions.classList.toggle("is-hidden", !isOpen);
        if (!isOpen) {
            activeHeadSuggestionIndex = -1;
            input.removeAttribute("aria-activedescendant");
        }
    }

    function renderHeadSuggestions(query) {
        const suggestions = document.getElementById("department-head-suggestions");
        const matches = headMatches(query);
        activeHeadSuggestionIndex = -1;
        if (!String(query || "").trim()) {
            suggestions.replaceChildren();
            setHeadSuggestionsOpen(false);
            return;
        }
        if (!matches.length) {
            suggestions.innerHTML = '<p class="department-head-search-hint" role="status">No active employees match that search.</p>';
            setHeadSuggestionsOpen(true);
            return;
        }
        suggestions.innerHTML = matches.map((person, index) => `<button id="department-head-option-${index}" type="button" tabindex="-1" class="department-head-option" role="option" aria-selected="false" data-department-head-id="${Number(person.id)}">
            ${window.IbemsAvatar.html(person.name, person.profile_image_url, "table-person-avatar")}
            <span><strong>${escapeHtml(person.name)}</strong><small>${escapeHtml(person.employee_id || person.email || "Active employee")}</small></span>
        </button>`).join("");
        setHeadSuggestionsOpen(true);
    }

    function setActiveHeadSuggestion(nextIndex) {
        const input = document.getElementById("department-head-search");
        const options = Array.from(document.querySelectorAll("#department-head-suggestions .department-head-option"));
        if (!options.length) return;
        activeHeadSuggestionIndex = (nextIndex + options.length) % options.length;
        options.forEach((option, index) => {
            const selected = index === activeHeadSuggestionIndex;
            option.classList.toggle("is-active", selected);
            option.setAttribute("aria-selected", String(selected));
        });
        const active = options[activeHeadSuggestionIndex];
        input.setAttribute("aria-activedescendant", active.id);
        active.scrollIntoView({ block: "nearest" });
    }

    function chooseDepartmentHead(personId) {
        const person = people.find((candidate) => Number(candidate.id) === Number(personId));
        if (!person) return;
        document.getElementById("department-head").value = String(person.id);
        document.getElementById("department-head-search").value = "";
        headEditorOpen = false;
        setHeadSuggestionsOpen(false);
        renderHeadSummary();
        document.querySelector("#department-head-summary [data-change-department-head]")?.focus();
    }

    function renderHeadSummary() {
        const summary = document.getElementById("department-head-summary");
        const editor = document.getElementById("department-head-editor");
        const person = people.find((candidate) => Number(candidate.id) === Number(document.getElementById("department-head").value || 0));
        if (!summary || !editor) return;
        summary.innerHTML = person ? `<button type="button" class="assignment-person-card" data-change-department-head>
            ${window.IbemsAvatar.html(person.name, person.profile_image_url, "assignment-person-avatar")}
            <span class="assignment-person-copy"><strong>${escapeHtml(person.name)}</strong><small>${escapeHtml(person.employee_id || person.email)}</small></span>
            <span class="assignment-role-label">Department Head</span><span class="assignment-change-label">Change</span>
        </button>` : `<button type="button" class="assignment-person-card is-empty" data-change-department-head><span class="assignment-empty-icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span><span class="assignment-person-copy"><strong>Not assigned</strong><small>Click to assign department head</small></span><span class="assignment-change-label">Assign</span></button>`;
        editor.classList.toggle("is-hidden", !headEditorOpen);
    }

    function matches(department) {
        const query = String(document.getElementById("department-search").value || "").trim().toLowerCase();
        const status = document.getElementById("department-status").value;
        if (status === "active" && !department.is_active) return false;
        if (status === "inactive" && department.is_active) return false;
        if (!query) return true;
        return [department.code, department.name, department.head_name]
            .some((value) => String(value || "").toLowerCase().includes(query));
    }

    function setAdminListState(type, message = "", detail = "") {
        const region = document.getElementById("department-admin-table-region");
        const isState = type !== "rows";
        region.classList.toggle("is-department-list-state", isState);
        if (isState) region.removeAttribute("tabindex");
        else region.setAttribute("tabindex", "0");
        if (!isState) return;
        const error = type === "error";
        const icon = error ? "bi-exclamation-circle" : type === "loading" ? "bi-arrow-repeat" : "bi-inbox";
        document.getElementById("department-admin-body").innerHTML = `<tr><td colspan="4"><div class="data-state data-state--${type}" role="${error ? "alert" : "status"}" aria-live="${error ? "assertive" : "polite"}" aria-atomic="true" tabindex="-1"><i class="bi ${icon}" aria-hidden="true"></i><div><strong>${escapeHtml(message)}</strong>${detail ? `<small>${escapeHtml(detail)}</small>` : ""}</div>${error ? '<button type="button" class="secondary-btn" data-department-retry>Retry departments</button>' : ""}</div></td></tr>`;
    }

    function render() {
        if (departmentLoadState !== "ready") return;
        const body = document.getElementById("department-admin-body");
        const visible = departments.filter(matches);
        document.getElementById("department-total").textContent = String(departments.length);
        document.getElementById("department-active").textContent = String(departments.filter((department) => department.is_active).length);
        document.getElementById("department-with-head").textContent = String(departments.filter((department) => Number(department.head_user_id) > 0).length);
        if (!visible.length) {
            setAdminListState("empty", departments.length ? "No departments match the current filters." : "No departments have been created.");
            return;
        }
        setAdminListState("rows");
        body.innerHTML = visible.map((department) => {
            return `<tr>
                <td><div class="department-identity"><strong>${escapeHtml(department.name)}</strong><small>${escapeHtml(department.code)}</small></div></td>
                <td><div class="department-identity"><strong>${escapeHtml(department.head_name || "Not assigned")}</strong><small>${escapeHtml(department.head_employee_id || "")}</small></div></td>
                <td><span class="department-status-pill ${department.is_active ? "is-active" : "is-inactive"}">${department.is_active ? "Active" : "Inactive"}</span>${department.status_reason ? `<small class="meta">${escapeHtml(department.status_reason)}</small>` : ""}</td>
                <td><div class="department-actions"><button class="secondary-btn" type="button" data-edit-department="${Number(department.id)}"><i class="bi bi-pencil"></i> Edit</button></div></td>
            </tr>`;
        }).join("");
    }

    async function load() {
        const requestId = ++loadRequestId;
        const body = document.getElementById("department-admin-body");
        const refresh = document.getElementById("department-refresh");
        const restoreListFocus = body.contains(document.activeElement);
        departmentLoadState = "loading";
        departments = [];
        people = [];
        refresh.disabled = true;
        refresh.textContent = "Refreshing…";
        document.getElementById("department-add").disabled = true;
        body.setAttribute("aria-busy", "true");
        setAdminListState("loading", "Loading departments…");
        if (restoreListFocus && document.activeElement === document.body) body.querySelector(".data-state")?.focus({preventScroll: true});
        for (const id of ["department-total", "department-active", "department-with-head"]) document.getElementById(id).textContent = "Loading…";
        setResult("department-admin-result", "");
        try {
            const data = await api("/admin/departments/data");
            if (pageSignal.aborted || requestId !== loadRequestId) return;
            if (!Array.isArray(data.departments) || !Array.isArray(data.people)) throw new Error("Unreadable department list");
            departmentLoadState = "ready";
            departments = data.departments;
            people = data.people;
            render();
            setResult("department-admin-result", `${departments.length} department${departments.length === 1 ? "" : "s"} loaded.`);
            return true;
        } catch (error) {
            if (pageSignal.aborted || requestId !== loadRequestId) return;
            departmentLoadState = "error";
            for (const id of ["department-total", "department-active", "department-with-head"]) document.getElementById(id).textContent = "Unavailable";
            const detail = error.status >= 400 && typeof error.message === "string" ? error.message : "The department list could not be loaded. Retry to refresh it.";
            setAdminListState("error", "Departments unavailable", detail);
            setResult("department-admin-result", "");
            return false;
        } finally {
            if (!pageSignal.aborted && requestId === loadRequestId) {
                body.removeAttribute("aria-busy");
                refresh.disabled = false;
                document.getElementById("department-add").disabled = departmentLoadState !== "ready";
                refresh.textContent = departmentLoadState === "error" ? "Retry departments" : "Refresh";
                if (restoreListFocus && document.activeElement === document.body) {
                    const edit = body.querySelector("[data-edit-department]");
                    const target = body.querySelector("[data-department-retry]") || edit || body.querySelector(".data-state") || refresh;
                    target.focus({preventScroll: target !== edit});
                }
            }
        }
    }

    function syncDepartmentReason() {
        const reason = document.getElementById("department-status-reason");
        reason.required = document.getElementById("department-active-input").value === "0";
        reason.setCustomValidity("");
    }

    function openModal(department = null) {
        if (departmentSaving || pageSignal.aborted || departmentLoadState !== "ready") return;
        modalOpener = document.activeElement;
        document.getElementById("department-modal-title").textContent = department ? "Edit department" : "Add department";
        document.getElementById("department-id").value = department?.id || "";
        document.getElementById("department-code").value = department?.code || "";
        document.getElementById("department-name").value = department?.name || "";
        document.getElementById("department-head").value = department?.head_user_id || "";
        document.getElementById("department-head-search").value = "";
        setHeadSuggestionsOpen(false);
        headEditorOpen = false;
        renderHeadSummary();
        document.getElementById("department-active-input").value = department?.is_active === false ? "0" : "1";
        document.getElementById("department-active-input").dispatchEvent(new Event("change", { bubbles: true }));
        document.getElementById("department-status-reason").value = department?.status_reason || "";
        syncDepartmentReason();
        setResult("department-modal-result");
        setDepartmentModalOpen(departmentModal, true);
        document.getElementById("department-code").focus();
    }

    function closeModal(force = false) {
        if (departmentSaving && force !== true) return;
        setDepartmentModalOpen(departmentModal, false);
        if (modalOpener?.isConnected) modalOpener.focus();
    }

    departmentModal.addEventListener("keydown", (event) => {
        if (event.defaultPrevented) return;
        if (departmentSaving && event.key === "Tab") {
            event.preventDefault();
            document.getElementById("department-modal-result").focus();
            return;
        }
        if (event.key === "Escape") {
            event.preventDefault();
            closeModal();
        } else if (event.key === "Tab") {
            const controls = Array.from(departmentModal.querySelectorAll('button, input, select, textarea, a[href], [tabindex]'))
                .filter(el => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length);
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault(); last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault(); first?.focus();
            }
        }
    });

    document.getElementById("department-add").addEventListener("click", () => openModal());
    document.getElementById("department-refresh").addEventListener("click", () => {
        if (departmentLoadState !== "loading") load();
    });
    document.getElementById("department-search").addEventListener("input", render);
    document.getElementById("department-status").addEventListener("change", render);
    document.getElementById("department-close").addEventListener("click", closeModal);
    document.getElementById("department-cancel").addEventListener("click", closeModal);
    document.getElementById("department-active-input").addEventListener("change", syncDepartmentReason);
    document.getElementById("department-status-reason").addEventListener("input", syncDepartmentReason);
    document.getElementById("department-head-summary").addEventListener("click", (event) => {
        if (!event.target.closest("[data-change-department-head]")) return;
        headEditorOpen = true;
        renderHeadSummary();
        requestAnimationFrame(() => document.getElementById("department-head-search").focus());
    });
    document.getElementById("department-head-search").addEventListener("focus", (event) => {
        if (String(event.target.value || "").trim()) renderHeadSuggestions(event.target.value);
    });
    document.getElementById("department-head-search").addEventListener("input", (event) => renderHeadSuggestions(event.target.value));
    document.getElementById("department-head-search").addEventListener("keydown", (event) => {
        const options = () => Array.from(document.querySelectorAll("#department-head-suggestions .department-head-option"));
        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();
            if (!options().length) renderHeadSuggestions(event.currentTarget.value);
            if (options().length) setActiveHeadSuggestion(activeHeadSuggestionIndex < 0
                ? (event.key === "ArrowDown" ? 0 : options().length - 1)
                : activeHeadSuggestionIndex + (event.key === "ArrowDown" ? 1 : -1));
        } else if (event.key === "Enter" && activeHeadSuggestionIndex >= 0) {
            event.preventDefault();
            options()[activeHeadSuggestionIndex]?.click();
        } else if (event.key === "Escape") {
            event.preventDefault();
            setHeadSuggestionsOpen(false);
            headEditorOpen = false;
            renderHeadSummary();
            document.querySelector("#department-head-summary [data-change-department-head]")?.focus();
        }
    });
    document.getElementById("department-head-suggestions").addEventListener("click", (event) => {
        const option = event.target.closest("[data-department-head-id]");
        if (option) chooseDepartmentHead(Number(option.dataset.departmentHeadId));
    });
    document.getElementById("department-modal").addEventListener("click", (event) => {
        if (event.target.id === "department-modal") {
            closeModal();
            return;
        }
        if (!event.target.closest("#department-head-editor, #department-head-summary")) setHeadSuggestionsOpen(false);
    });
    document.getElementById("department-admin-body").addEventListener("click", (event) => {
        if (event.target.closest("[data-department-retry]")) { load(); return; }
        const button = event.target.closest("[data-edit-department]");
        if (!button) return;
        openModal(departments.find((department) => Number(department.id) === Number(button.dataset.editDepartment)) || null);
    });
    document.getElementById("department-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const save = document.getElementById("department-save");
        if (departmentSaving || save.disabled || pageSignal.aborted || departmentModal.classList.contains("is-hidden")) return;
        const reason = document.getElementById("department-status-reason");
        syncDepartmentReason();
        if (reason.required && !reason.value.trim()) {
            reason.setCustomValidity("Enter a reason before deactivating this department.");
            reason.reportValidity();
            reason.focus();
            return;
        }
        if (Number(document.getElementById("department-head").value || 0) <= 0) {
            headEditorOpen = true;
            renderHeadSummary();
            setResult("department-modal-result", "Choose a department head before saving.", "error");
            requestAnimationFrame(() => document.getElementById("department-head-search").focus());
            return;
        }
        const form = document.getElementById("department-form");
        const result = document.getElementById("department-modal-result");
        const disabledStates = Array.from(form.querySelectorAll("input, select, textarea, button"), control => [control, control.disabled]);
        const saveMarkup = save.innerHTML;
        departmentSaving = true;
        setHeadSuggestionsOpen(false);
        disabledStates.forEach(([control]) => { control.disabled = true; });
        save.textContent = "Saving…";
        form.setAttribute("aria-busy", "true");
        departmentModal.classList.add("is-saving");
        setResult("department-modal-result", "Saving department…");
        result.focus();
        try {
            await api("/admin/departments/save", jsonOptions({
                id: Number(document.getElementById("department-id").value || 0) || null,
                code: document.getElementById("department-code").value,
                name: document.getElementById("department-name").value,
                head_user_id: Number(document.getElementById("department-head").value || 0),
                is_active: document.getElementById("department-active-input").value === "1",
                status_reason: document.getElementById("department-status-reason").value,
            }));
            if (pageSignal.aborted) return;
            setResult("department-modal-result", "Department saved.", "success");
            closeModal(true);
            try {
                const refreshed = await load();
                if (pageSignal.aborted) return;
                setResult("department-admin-result", refreshed === false
                    ? "Department saved. The department list could not be refreshed. Retry departments to load the latest records."
                    : "Department saved.", refreshed === false ? "warning" : "success");
            } catch (refreshError) {
                if (!pageSignal.aborted) setResult("department-admin-result", "Department saved. The department list could not be refreshed. Retry departments to load the latest records.", "warning");
            }
            if (!pageSignal.aborted && document.activeElement === document.body) document.getElementById("department-refresh")?.focus({ preventScroll: true });
        } catch (error) {
            if (!pageSignal.aborted) {
                setResult("department-modal-result", error.hasServerMessage
                    ? error.message
                    : "The save could not be confirmed. Your changes are still here. Check the department list before trying again.", error.hasServerMessage ? "error" : "warning");
                if (document.activeElement === result || document.activeElement === document.body) {
                    result.focus();
                    result.scrollIntoView({block: "nearest", inline: "nearest"});
                }
            }
        } finally {
            departmentSaving = false;
            if (!pageSignal.aborted) {
                disabledStates.forEach(([control, disabled]) => { control.disabled = disabled; });
                save.innerHTML = saveMarkup;
                form.removeAttribute("aria-busy");
                departmentModal.classList.remove("is-saving");
            }
        }
    });
    load();
}

function initAccounting() {
    const page = document.getElementById("department-accounting-page");
    if (!page) return;
    const financeModal = mountDepartmentModal("department-finance-modal");
    const batchModal = mountDepartmentModal("department-batch-allocation-modal");
    const canOperate = page.dataset.canOperate === "true";
    const settlementStorage = {
        getItem: (key) => window.localStorage.getItem(key),
        setItem: (key, value) => window.localStorage.setItem(key, value),
        removeItem: (key) => window.localStorage.removeItem(key),
    };
    const settlementRetry = canOperate ? window.IbemsDepartmentSettlementRetry.create(settlementStorage, page.dataset.actorId, () => {
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, (value) => value.toString(16).padStart(2, "0")).join("");
    }, (task) => {
        if (!window.navigator?.locks) return Promise.reject(new Error("Payment recovery is unavailable in this browser. Use a supported browser to record settlements safely."));
        return window.navigator.locks.request(`ibems.department-settlement.${page.dataset.actorId}`, { ifAvailable: true }, (lock) => {
            if (!lock) throw new Error("A settlement request is already in progress in another tab.");
            return task();
        });
    }) : null;
    let settlementForm = null;
    let departments = [];
    let entries = [];
    const batchSelected = new Set();

    function render() {
        const query = String(document.getElementById("department-accounting-search").value || "").trim().toLowerCase();
        const visible = departments.filter((department) => !query || [department.code, department.name, department.head_name].some((value) => String(value || "").toLowerCase().includes(query)));
        const totals = departments.reduce((sum, department) => ({
            allocation: sum.allocation + Number(department.allocation_amount || 0),
            used: sum.used + Number(department.used_amount || 0),
            remaining: sum.remaining + Number(department.remaining_allocation || 0),
            outstanding: sum.outstanding + Number(department.outstanding_amount || 0),
        }), { allocation: 0, used: 0, remaining: 0, outstanding: 0 });
        document.getElementById("department-sum-allocation").textContent = money(totals.allocation);
        document.getElementById("department-sum-used").textContent = money(totals.used);
        document.getElementById("department-sum-remaining").textContent = money(totals.remaining);
        document.getElementById("department-sum-outstanding").textContent = money(totals.outstanding);
        const body = document.getElementById("department-accounting-body");
        body.innerHTML = visible.length ? visible.map((department) => {
            const allocation = Number(department.allocation_amount || 0);
            const used = Number(department.used_amount || 0);
            const percent = allocation > 0 ? Math.min(100, (used / allocation) * 100) : 0;
            return `<tr>
                <td><div class="department-identity"><strong>${escapeHtml(department.name)}</strong><small>${escapeHtml(department.code)} · Head: ${escapeHtml(department.head_name || "Not assigned")}</small></div></td>
                <td><strong>${money(allocation)}</strong></td>
                <td><div class="department-progress"><strong>${money(used)}</strong><div class="department-progress-track"><span style="width:${percent}%"></span></div><small>${money(department.remaining_allocation)} remaining</small></div></td>
                <td><strong>${money(department.outstanding_amount)}</strong></td>
                <td><span class="department-status-pill is-${escapeHtml(department.period_status)}">${escapeHtml(String(department.period_status).replaceAll("_", " "))}</span></td>
                <td>${canOperate ? `<div class="department-actions"><button class="secondary-btn" type="button" data-allocation="${Number(department.id)}"><i class="bi bi-sliders"></i> ${department.period_id ? "Adjust" : "Set allocation"}</button>${Number(department.outstanding_amount) > 0 ? `<button class="primary-btn" type="button" data-settlement="${Number(department.id)}"><i class="bi bi-cash-coin"></i> Settle</button>` : ""}</div>` : '<span class="department-read-only"><i class="bi bi-eye"></i> Read only</span>'}</td>
            </tr>`;
        }).join("") : '<tr><td colspan="6">No department accounts match the current filters.</td></tr>';

        const byId = new Map(departments.map((department) => [Number(department.id), department]));
        const ledger = document.getElementById("department-ledger-body");
        ledger.innerHTML = entries.length ? entries.map((entry) => {
            const department = byId.get(Number(entry.department_id));
            const reference = entry.transaction_id ? `Transaction #${entry.transaction_id}` : (entry.reference_no || "—");
            const details = [entry.requester_name ? `Requested by ${entry.requester_name}` : "", entry.approver_name ? `Approved by ${entry.approver_name}` : "", entry.store_name, entry.remarks].filter(Boolean).join(" · ");
            return `<tr><td>${escapeHtml(formatDate(entry.created_at))}</td><td>${escapeHtml(department?.code || "")}</td><td>${escapeHtml(String(entry.entry_type || "").replaceAll("_", " "))}</td><td>${escapeHtml(reference)}</td><td><strong>${entry.direction === "credit" ? "−" : entry.direction === "debit" ? "+" : ""}${money(entry.amount)}</strong></td><td>${money(entry.outstanding_after)}</td><td>${escapeHtml(details || "—")}</td></tr>`;
        }).join("") : '<tr><td colspan="7">No ledger entries for this month.</td></tr>';
    }

    async function load() {
        const month = document.getElementById("department-period-month").value;
        setResult("department-accounting-result", "Loading department accounts...");
        try {
            const data = await api(`/accounting/department-debts/data?month=${encodeURIComponent(month)}`);
            departments = Array.isArray(data.departments) ? data.departments : [];
            entries = Array.isArray(data.entries) ? data.entries : [];
            render();
            setResult("department-accounting-result", `${departments.length} department account${departments.length === 1 ? "" : "s"} loaded.`);
        } catch (error) {
            if (!pageSignal.aborted) setResult("department-accounting-result", error.message, "error");
        }
    }

    function openFinance(department, mode) {
        let openedSettlementForm = null;
        let pendingSettlement = null;
        try {
            openedSettlementForm = mode === "settlement" ? settlementRetry.openForm() : null;
            pendingSettlement = openedSettlementForm?.pending || null;
        }
        catch (error) { window.alert(error.message); return; }
        if (pendingSettlement && Number(pendingSettlement.payload.department_id) !== Number(department.id)) {
            window.alert("Retry the pending settlement for its original department before recording another payment.");
            return;
        }
        settlementForm = openedSettlementForm;
        document.getElementById("department-finance-mode").value = mode;
        document.getElementById("department-finance-id").value = department.id;
        document.getElementById("department-finance-period-id").value = department.period_id || "";
        document.getElementById("department-finance-title").textContent = mode === "settlement" ? `Record settlement · ${department.code}` : `Monthly allocation · ${department.code}`;
        document.getElementById("department-finance-eyebrow").textContent = department.name;
        document.getElementById("department-finance-context").textContent = mode === "settlement"
            ? `Outstanding liability: ${money(department.outstanding_amount)}. Record only a verified payment with its official reference and reconciliation remarks.`
            : `Used this month: ${money(department.used_amount)}. The allocation cannot be reduced below this amount.`;
        document.getElementById("department-allocation-fields").classList.toggle("is-hidden", mode !== "allocation");
        document.getElementById("department-settlement-fields").classList.toggle("is-hidden", mode !== "settlement");
        document.querySelectorAll("#department-allocation-fields input, #department-allocation-fields select").forEach((control) => {
            control.disabled = mode !== "allocation";
        });
        document.querySelectorAll("#department-settlement-fields input, #department-settlement-fields select").forEach((control) => {
            control.disabled = mode !== "settlement";
        });
        document.getElementById("department-finance-month").value = document.getElementById("department-period-month").value;
        document.getElementById("department-allocation-amount").value = Number(department.allocation_amount || 0).toFixed(2);
        document.getElementById("department-period-status").value = department.period_status === "unconfigured" ? "open" : department.period_status;
        document.getElementById("department-allocation-reason").value = "";
        document.getElementById("department-settlement-amount").value = Number(department.outstanding_amount || 0).toFixed(2);
        document.getElementById("department-settlement-amount").max = Number(department.outstanding_amount || 0).toFixed(2);
        document.getElementById("department-settlement-reference").value = "";
        document.getElementById("department-settlement-remarks").value = "";
        if (pendingSettlement) {
            const original = pendingSettlement.payload;
            document.getElementById("department-finance-period-id").value = original.period_id;
            document.getElementById("department-settlement-amount").value = original.amount;
            document.getElementById("department-settlement-amount").removeAttribute("max");
            document.getElementById("department-settlement-reference").value = original.reference_no;
            document.getElementById("department-settlement-remarks").value = original.remarks;
        }
        document.querySelectorAll("#department-settlement-fields input, #department-settlement-fields textarea").forEach((control) => { control.readOnly = Boolean(pendingSettlement); });
        document.querySelector("#department-finance-save span").textContent = mode === "settlement" ? "Record settlement" : "Save allocation";
        setResult("department-finance-result");
        if (pendingSettlement) setResult("department-finance-result", "A previous request has no confirmed response. Retry its original details safely.");
        setDepartmentModalOpen(financeModal, true);
    }

    function closeFinance() {
        settlementForm = null;
        setDepartmentModalOpen(financeModal, false);
    }

    function updateBatchSummary() {
        const count = batchSelected.size;
        const amount = Math.max(0, Number(document.getElementById("department-batch-amount")?.value || 0));
        document.getElementById("department-batch-selected-count").textContent = `${count} selected`;
        document.getElementById("department-batch-total").textContent = money(count * amount);
        document.getElementById("department-batch-preview-detail").textContent = count
            ? `${money(amount)} per department across ${count} department${count === 1 ? "" : "s"}.`
            : "Select departments and enter an amount.";
    }

    function renderBatchOptions() {
        const options = document.getElementById("department-batch-options");
        const query = String(document.getElementById("department-batch-search").value || "").trim().toLowerCase();
        const visible = departments.filter((department) => !query || [department.code, department.name, department.head_name]
            .some((value) => String(value || "").toLowerCase().includes(query)));
        options.innerHTML = visible.length ? visible.map((department) => {
            const id = Number(department.id);
            return `<label class="department-batch-option${department.is_active ? "" : " is-inactive"}">
                <input type="checkbox" value="${id}" ${batchSelected.has(id) ? "checked" : ""}>
                <span><strong>${escapeHtml(department.name)}</strong><small>${escapeHtml(department.code)} · Head: ${escapeHtml(department.head_name || "Not assigned")}</small></span>
                <em>${department.is_active ? "Active" : "Inactive"}</em>
            </label>`;
        }).join("") : '<p class="department-batch-empty">No departments match this search.</p>';
        updateBatchSummary();
    }

    function openBatchAllocation() {
        batchSelected.clear();
        document.getElementById("department-batch-search").value = "";
        document.getElementById("department-batch-month").value = document.getElementById("department-period-month").value;
        document.getElementById("department-batch-amount").value = "";
        document.getElementById("department-batch-status").value = "open";
        document.getElementById("department-batch-reason").value = "";
        setResult("department-batch-allocation-result");
        renderBatchOptions();
        setDepartmentModalOpen(batchModal, true);
        window.setTimeout(() => document.getElementById("department-batch-search")?.focus(), 0);
    }

    function closeBatchAllocation() { setDepartmentModalOpen(batchModal, false); }

    document.getElementById("department-period-month").addEventListener("change", load);
    document.getElementById("department-accounting-search").addEventListener("input", render);
    document.getElementById("department-accounting-refresh").addEventListener("click", load);
    document.getElementById("department-accounting-body").addEventListener("click", (event) => {
        if (!canOperate) return;
        const allocation = event.target.closest("[data-allocation]");
        const settlement = event.target.closest("[data-settlement]");
        const id = Number(allocation?.dataset.allocation || settlement?.dataset.settlement || 0);
        const department = departments.find((item) => Number(item.id) === id);
        if (department) openFinance(department, settlement ? "settlement" : "allocation");
    });
    if (!canOperate) {
        load();
        return;
    }
    document.getElementById("department-finance-close").addEventListener("click", closeFinance);
    document.getElementById("department-finance-cancel").addEventListener("click", closeFinance);
    document.getElementById("department-finance-modal").addEventListener("click", (event) => { if (event.target.id === "department-finance-modal") closeFinance(); });
    document.getElementById("department-finance-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const mode = document.getElementById("department-finance-mode").value;
        const save = document.getElementById("department-finance-save");
        if (save.disabled) return;
        save.disabled = true;
        try {
            if (mode === "settlement") {
                if (!settlementForm) throw new Error("Close and reopen the settlement form before submitting.");
                await settlementForm.submit({
                    department_id: Number(document.getElementById("department-finance-id").value),
                    period_id: Number(document.getElementById("department-finance-period-id").value),
                    amount: Number(document.getElementById("department-settlement-amount").value),
                    reference_no: document.getElementById("department-settlement-reference").value,
                    remarks: document.getElementById("department-settlement-remarks").value,
                }, (payload) => api("/accounting/department-debts/settlement", jsonOptions(payload)));
            } else {
                await api("/accounting/department-debts/allocation", jsonOptions({
                    department_id: Number(document.getElementById("department-finance-id").value),
                    period_month: document.getElementById("department-finance-month").value,
                    allocation_amount: Number(document.getElementById("department-allocation-amount").value),
                    status: document.getElementById("department-period-status").value,
                    reason: document.getElementById("department-allocation-reason").value,
                }));
            }
            setResult("department-finance-result", mode === "settlement" ? "Settlement recorded." : "Allocation saved.", "success");
            closeFinance();
            await load().catch(() => { setResult("department-finance-result", "Saved successfully. Refresh the department list to see the updated figures.", "success"); });
        } catch (error) {
            if (!pageSignal.aborted) setResult("department-finance-result", error.message, "error");
        } finally { save.disabled = false; }
    });
    document.getElementById("department-batch-allocation-open").addEventListener("click", openBatchAllocation);
    document.getElementById("department-batch-allocation-close").addEventListener("click", closeBatchAllocation);
    document.getElementById("department-batch-allocation-cancel").addEventListener("click", closeBatchAllocation);
    document.getElementById("department-batch-allocation-modal").addEventListener("click", (event) => { if (event.target.id === "department-batch-allocation-modal") closeBatchAllocation(); });
    document.getElementById("department-batch-search").addEventListener("input", renderBatchOptions);
    document.getElementById("department-batch-amount").addEventListener("input", updateBatchSummary);
    document.getElementById("department-batch-options").addEventListener("change", (event) => {
        const checkbox = event.target.closest('input[type="checkbox"]');
        if (!checkbox) return;
        const id = Number(checkbox.value);
        if (checkbox.checked) batchSelected.add(id); else batchSelected.delete(id);
        updateBatchSummary();
    });
    document.getElementById("department-batch-select-all").addEventListener("click", () => {
        departments.filter((department) => department.is_active).forEach((department) => batchSelected.add(Number(department.id)));
        renderBatchOptions();
    });
    document.getElementById("department-batch-clear").addEventListener("click", () => {
        batchSelected.clear();
        renderBatchOptions();
    });
    document.getElementById("department-batch-allocation-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        if (batchSelected.size === 0) {
            setResult("department-batch-allocation-result", "Select at least one department.", "error");
            return;
        }
        const save = document.getElementById("department-batch-allocation-save");
        save.disabled = true;
        try {
            const result = await api("/accounting/department-debts/allocations", jsonOptions({
                department_ids: Array.from(batchSelected),
                period_month: document.getElementById("department-batch-month").value,
                allocation_amount: Number(document.getElementById("department-batch-amount").value),
                status: document.getElementById("department-batch-status").value,
                reason: document.getElementById("department-batch-reason").value,
            }));
            setResult("department-batch-allocation-result", `${Number(result.updated_count || batchSelected.size)} department allocations saved.`, "success");
            document.getElementById("department-period-month").value = document.getElementById("department-batch-month").value;
            await load();
            closeBatchAllocation();
        } catch (error) {
            if (!pageSignal.aborted) setResult("department-batch-allocation-result", error.message, "error");
        } finally { save.disabled = false; }
    });
    load();
}

function initUser() {
    const page = document.getElementById("department-user-page");
    if (!page) return;
    let pinIsSet = false;
    let canApprove = false;
    let checking = false;
    let saving = false;
    let saveConfirmed = false;
    const form = document.getElementById("department-pin-form");
    const button = form.querySelector('button[type="submit"]');
    const retry = document.getElementById("department-pin-retry");
    const result = document.getElementById("department-pin-result");
    result.tabIndex = -1;
    function feedback(message = "", type = "", focus = false) {
        setResult("department-pin-result", message, type);
        if (focus) result.focus();
    }
    function syncControls() {
        const blocked = checking || saving || !canApprove;
        ["department-pin", "department-pin-confirmation"].forEach(id => { document.getElementById(id).disabled = blocked; });
        const currentPassword = document.getElementById("department-current-password");
        currentPassword.disabled = blocked || !pinIsSet;
        currentPassword.required = pinIsSet;
        document.getElementById("department-current-password-wrap").hidden = !pinIsSet;
        button.disabled = blocked;
        retry.disabled = checking || saving;
        form.setAttribute("aria-busy", String(checking || saving));
        document.getElementById("department-pin-save-label").textContent = saving ? "Saving…" : checking ? "Checking…" : "Save Department PIN";
    }
    async function load() {
        if (checking || pageSignal.aborted) return false;
        checking = true;
        canApprove = false;
        retry.hidden = true;
        syncControls();
        document.getElementById("department-pin-status").textContent = "Checking PIN status...";
        document.getElementById("department-user-assignments").innerHTML = dataState("loading", "Loading department assignments...");
        try {
            const data = await api("/department/authorizations/data");
            if (pageSignal.aborted) return false;
            const assignments = Array.isArray(data.assignments) ? data.assignments : [];
            canApprove = assignments.length > 0;
            document.getElementById("department-user-assignments").innerHTML = assignments.length
                ? assignments.map((assignment) => `<article class="department-assignment-card"><strong>${escapeHtml(assignment.name)}</strong><span>${escapeHtml(assignment.code)}</span><small>Department head</small></article>`).join("")
                : dataState("empty", "You do not currently have an active department approval assignment.");
            pinIsSet = Boolean(data.pin?.is_set);
            document.getElementById("department-pin-heading").textContent = pinIsSet ? "Replace approval PIN" : "Set approval PIN";
            document.getElementById("department-pin-status").textContent = data.pin?.is_set
                ? `PIN is set${data.pin.updated_at ? ` · last changed ${formatDate(data.pin.updated_at)}` : ""}${data.pin.last_success_at ? ` · last used ${formatDate(data.pin.last_success_at)}` : ""}`
                : (canApprove ? "No department approval PIN is set." : "A department head assignment is required before a PIN can be set.");
            feedback(saveConfirmed ? "Department approval PIN saved securely." : "", saveConfirmed ? "success" : "");
            return true;
        } catch (error) {
            if (!pageSignal.aborted) {
                document.getElementById("department-user-assignments").innerHTML = dataState("error", error.message || "Unable to load department assignments.");
                feedback(saveConfirmed ? "Department approval PIN saved securely. Assignments could not be refreshed. Reload assignments before making another change." : "PIN status is unavailable until assignments can be loaded.", saveConfirmed ? "warning" : "error");
                document.getElementById("department-pin-status").textContent = "PIN status unavailable. Reload assignments to try again.";
                retry.hidden = false;
            }
            return false;
        } finally {
            checking = false;
            if (!pageSignal.aborted) syncControls();
        }
    }
    retry.addEventListener("click", async () => {
        if (saving || checking) return;
        feedback("Reloading department assignments...");
        const loaded = await load();
        if (!pageSignal.aborted) {
            if (loaded && !saveConfirmed) feedback("Department assignments refreshed.");
            result.focus();
        }
    });
    ["department-pin", "department-pin-confirmation"].forEach((id) => document.getElementById(id).addEventListener("input", (event) => {
        event.target.value = String(event.target.value || "").replace(/\D/g, "").slice(0, 6);
    }));
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (saving || checking || !canApprove || pageSignal.aborted) return;
        const pin = document.getElementById("department-pin").value;
        const confirmation = document.getElementById("department-pin-confirmation").value;
        const currentPassword = document.getElementById("department-current-password").value;
        if (!/^[0-9]{4,6}$/.test(pin) || pin !== confirmation) {
            feedback("Enter matching 4–6 digit PINs.", "error", true);
            return;
        }
        if (pinIsSet && currentPassword === "") {
            feedback("Enter your current account password to replace the PIN.", "error", true);
            return;
        }
        saving = true;
        saveConfirmed = false;
        syncControls();
        feedback("Saving the department approval PIN. Wait for confirmation before trying again.", "", true);
        try {
            await api("/department/authorizations/pin", jsonOptions({ pin, pin_confirmation: confirmation, current_password: currentPassword }));
            if (pageSignal.aborted) return;
            document.getElementById("department-current-password").value = "";
            document.getElementById("department-pin").value = "";
            document.getElementById("department-pin-confirmation").value = "";
            window.IbemsPasswordVisibility?.mask(form);
            pinIsSet = true;
            saveConfirmed = true;
            feedback("Department approval PIN saved securely.", "success");
            await load();
        } catch (error) {
            if (!pageSignal.aborted) feedback(error.message || "Unable to save the department approval PIN.", "error", true);
        } finally {
            saving = false;
            if (!pageSignal.aborted) { syncControls(); result.focus(); }
        }
    });
    load();
}

initAdmin();
initAccounting();
initUser();
}());
