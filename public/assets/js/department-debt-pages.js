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
    element.textContent = message;
    element.className = `department-result${type ? ` is-${type}` : ""}`;
}

function mountDepartmentModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return null;
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
    window.IbemsPortalNavigation?.onCleanup(() => {
        document.body.classList.remove("department-modal-open");
        modal.remove();
    });
    return modal;
}

function setDepartmentModalOpen(modal, isOpen) {
    if (!modal) return;
    modal.classList.toggle("is-hidden", !isOpen);
    document.body.classList.toggle("department-modal-open", isOpen);
}

function initAdmin() {
    const page = document.getElementById("department-admin-page");
    if (!page) return;
    const departmentModal = mountDepartmentModal("department-modal");
    let departments = [];
    let people = [];
    let headEditorOpen = false;
    let activeHeadSuggestionIndex = -1;

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
            suggestions.innerHTML = '<p class="department-head-search-hint">Start typing to find an active employee.</p>';
            setHeadSuggestionsOpen(true);
            return;
        }
        if (!matches.length) {
            suggestions.innerHTML = '<p class="department-head-search-hint">No active employees match that search.</p>';
            setHeadSuggestionsOpen(true);
            return;
        }
        suggestions.innerHTML = matches.map((person, index) => `<button id="department-head-option-${index}" type="button" class="department-head-option" role="option" aria-selected="false" data-department-head-id="${Number(person.id)}">
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
        options.forEach((option, index) => option.classList.toggle("is-active", index === activeHeadSuggestionIndex));
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

    function render() {
        const body = document.getElementById("department-admin-body");
        const visible = departments.filter(matches);
        document.getElementById("department-total").textContent = String(departments.length);
        document.getElementById("department-active").textContent = String(departments.filter((department) => department.is_active).length);
        document.getElementById("department-with-head").textContent = String(departments.filter((department) => Number(department.head_user_id) > 0).length);
        if (!visible.length) {
            body.innerHTML = '<tr><td colspan="4">No departments match the current filters.</td></tr>';
            return;
        }
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
        setResult("department-admin-result", "Loading departments...");
        try {
            const data = await api("/admin/departments/data");
            departments = Array.isArray(data.departments) ? data.departments : [];
            people = Array.isArray(data.people) ? data.people : [];
            render();
            setResult("department-admin-result", `${departments.length} department${departments.length === 1 ? "" : "s"} loaded.`);
        } catch (error) {
            if (pageSignal.aborted) return;
            document.getElementById("department-admin-body").innerHTML = `<tr><td colspan="4">${escapeHtml(error.message)}</td></tr>`;
            setResult("department-admin-result", error.message, "error");
        }
    }

    function openModal(department = null) {
        document.getElementById("department-modal-title").textContent = department ? "Edit department" : "Add department";
        document.getElementById("department-id").value = department?.id || "";
        document.getElementById("department-code").value = department?.code || "";
        document.getElementById("department-name").value = department?.name || "";
        document.getElementById("department-head").value = department?.head_user_id || "";
        document.getElementById("department-head-search").value = "";
        setHeadSuggestionsOpen(false);
        headEditorOpen = !department || !department.head_user_id;
        renderHeadSummary();
        document.getElementById("department-active-input").value = department?.is_active === false ? "0" : "1";
        document.getElementById("department-status-reason").value = department?.status_reason || "";
        setResult("department-modal-result");
        setDepartmentModalOpen(departmentModal, true);
        document.getElementById("department-code").focus();
    }

    function closeModal() { setDepartmentModalOpen(departmentModal, false); }

    document.getElementById("department-add").addEventListener("click", () => openModal());
    document.getElementById("department-refresh").addEventListener("click", load);
    document.getElementById("department-search").addEventListener("input", render);
    document.getElementById("department-status").addEventListener("change", render);
    document.getElementById("department-close").addEventListener("click", closeModal);
    document.getElementById("department-cancel").addEventListener("click", closeModal);
    document.getElementById("department-head-summary").addEventListener("click", (event) => {
        if (!event.target.closest("[data-change-department-head]")) return;
        headEditorOpen = true;
        renderHeadSummary();
        requestAnimationFrame(() => document.getElementById("department-head-search").focus());
    });
    document.getElementById("department-head-search").addEventListener("focus", (event) => renderHeadSuggestions(event.target.value));
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
    document.getElementById("department-modal").addEventListener("click", (event) => { if (event.target.id === "department-modal") closeModal(); });
    document.getElementById("department-admin-body").addEventListener("click", (event) => {
        const button = event.target.closest("[data-edit-department]");
        if (!button) return;
        openModal(departments.find((department) => Number(department.id) === Number(button.dataset.editDepartment)) || null);
    });
    document.getElementById("department-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const save = document.getElementById("department-save");
        if (Number(document.getElementById("department-head").value || 0) <= 0) {
            headEditorOpen = true;
            renderHeadSummary();
            setResult("department-modal-result", "Choose a department head before saving.", "error");
            requestAnimationFrame(() => document.getElementById("department-head-search").focus());
            return;
        }
        save.disabled = true;
        try {
            await api("/admin/departments/save", jsonOptions({
                id: Number(document.getElementById("department-id").value || 0) || null,
                code: document.getElementById("department-code").value,
                name: document.getElementById("department-name").value,
                head_user_id: Number(document.getElementById("department-head").value || 0),
                is_active: document.getElementById("department-active-input").value === "1",
                status_reason: document.getElementById("department-status-reason").value,
            }));
            setResult("department-modal-result", "Department saved.", "success");
            await load();
            closeModal();
        } catch (error) {
            if (!pageSignal.aborted) setResult("department-modal-result", error.message, "error");
        } finally { save.disabled = false; }
    });
    load();
}

function initAccounting() {
    const page = document.getElementById("department-accounting-page");
    if (!page) return;
    const financeModal = mountDepartmentModal("department-finance-modal");
    const canOperate = page.dataset.canOperate === "true";
    let departments = [];
    let entries = [];

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
        document.getElementById("department-finance-mode").value = mode;
        document.getElementById("department-finance-id").value = department.id;
        document.getElementById("department-finance-period-id").value = department.period_id || "";
        document.getElementById("department-finance-title").textContent = mode === "settlement" ? `Record settlement · ${department.code}` : `Monthly allocation · ${department.code}`;
        document.getElementById("department-finance-eyebrow").textContent = department.name;
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
        document.getElementById("department-settlement-reference").value = "";
        document.getElementById("department-settlement-remarks").value = "";
        setResult("department-finance-result");
        setDepartmentModalOpen(financeModal, true);
    }

    function closeFinance() { setDepartmentModalOpen(financeModal, false); }
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
        save.disabled = true;
        try {
            if (mode === "settlement") {
                await api("/accounting/department-debts/settlement", jsonOptions({
                    department_id: Number(document.getElementById("department-finance-id").value),
                    period_id: Number(document.getElementById("department-finance-period-id").value),
                    amount: Number(document.getElementById("department-settlement-amount").value),
                    reference_no: document.getElementById("department-settlement-reference").value,
                    remarks: document.getElementById("department-settlement-remarks").value,
                }));
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
            await load();
            closeFinance();
        } catch (error) {
            if (!pageSignal.aborted) setResult("department-finance-result", error.message, "error");
        } finally { save.disabled = false; }
    });
    load();
}

function initUser() {
    const page = document.getElementById("department-user-page");
    if (!page) return;
    let pinIsSet = false;
    async function load() {
        try {
            const data = await api("/user/department-authorizations/data");
            const assignments = Array.isArray(data.assignments) ? data.assignments : [];
            const canApprove = assignments.length > 0;
            document.getElementById("department-user-assignments").innerHTML = assignments.length
                ? assignments.map((assignment) => `<article class="department-assignment-card"><strong>${escapeHtml(assignment.name)}</strong><span>${escapeHtml(assignment.code)}</span><small>Department head</small></article>`).join("")
                : '<p>You do not currently have an active department approval assignment.</p>';
            pinIsSet = Boolean(data.pin?.is_set);
            document.getElementById("department-pin-heading").textContent = pinIsSet ? "Replace approval PIN" : "Set approval PIN";
            const currentPasswordWrap = document.getElementById("department-current-password-wrap");
            const currentPassword = document.getElementById("department-current-password");
            currentPasswordWrap.hidden = !pinIsSet;
            currentPassword.required = pinIsSet;
            ["department-pin", "department-pin-confirmation"].forEach((id) => { document.getElementById(id).disabled = !canApprove; });
            document.querySelector('#department-pin-form button[type="submit"]').disabled = !canApprove;
            document.getElementById("department-pin-status").textContent = data.pin?.is_set
                ? `PIN is set${data.pin.updated_at ? ` · last changed ${formatDate(data.pin.updated_at)}` : ""}${data.pin.last_success_at ? ` · last used ${formatDate(data.pin.last_success_at)}` : ""}`
                : (canApprove ? "No department approval PIN is set." : "A department head assignment is required before a PIN can be set.");
        } catch (error) {
            if (!pageSignal.aborted) setResult("department-pin-result", error.message, "error");
        }
    }
    ["department-pin", "department-pin-confirmation"].forEach((id) => document.getElementById(id).addEventListener("input", (event) => {
        event.target.value = String(event.target.value || "").replace(/\D/g, "").slice(0, 6);
    }));
    document.getElementById("department-pin-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const pin = document.getElementById("department-pin").value;
        const confirmation = document.getElementById("department-pin-confirmation").value;
        const currentPassword = document.getElementById("department-current-password").value;
        if (!/^[0-9]{4,6}$/.test(pin) || pin !== confirmation) {
            setResult("department-pin-result", "Enter matching 4–6 digit PINs.", "error");
            return;
        }
        const button = event.currentTarget.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            if (pinIsSet && currentPassword === "") {
                setResult("department-pin-result", "Enter your current account password to replace the PIN.", "error");
                return;
            }
            await api("/user/department-authorizations/pin", jsonOptions({ pin, pin_confirmation: confirmation, current_password: currentPassword }));
            document.getElementById("department-current-password").value = "";
            document.getElementById("department-pin").value = "";
            document.getElementById("department-pin-confirmation").value = "";
            setResult("department-pin-result", "Department approval PIN saved securely.", "success");
            await load();
        } catch (error) {
            if (!pageSignal.aborted) setResult("department-pin-result", error.message, "error");
        } finally { button.disabled = false; }
    });
    load();
}

initAdmin();
initAccounting();
initUser();
}());
