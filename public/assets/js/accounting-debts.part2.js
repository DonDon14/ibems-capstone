let accountingSummaryRequest = 0;
async function loadDailySummary() {
    const request = ++accountingSummaryRequest;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const response = await fetch("/accounting/debts/daily-summary", {signal});
    const data = await response.json();
    if (signal?.aborted || request !== accountingSummaryRequest) return false;
    if (!response.ok || !data || data.status !== "success") throw new Error("Daily summary unavailable.");
    document.getElementById("acct-today-count").textContent = String(data.deduction_count || 0);
    document.getElementById("acct-today-amount").textContent = aMoney(data.deducted_amount || 0);
    return true;
}
let accountingSalaryQueueRequest = 0;
let accountingSalaryQueueLoading = false;
let accountingSalaryQueuePage = 1;
async function loadScheduledSalaryQueue(page = 1, {manual = false} = {}) {
    if (manual && accountingSalaryQueueLoading) return false;
    const body = document.getElementById("acct-salary-queue");
    const feedback = document.getElementById("acct-salary-queue-feedback");
    const refresh = document.getElementById("acct-salary-refresh");
    const count = document.getElementById("acct-salary-due-count");
    const signal = window.IbemsPortalNavigation?.currentSignal;
    if (!body?.isConnected || signal?.aborted) return false;
    page = Number.isSafeInteger(Number(page)) && Number(page) > 0 ? Number(page) : 1;
    accountingSalaryQueuePage = page;
    const request = ++accountingSalaryQueueRequest;
    const isCurrent = () => request === accountingSalaryQueueRequest && !signal?.aborted && body.isConnected;
    const previousFocus = document.activeElement;
    const ownsFocus = body.contains(previousFocus) || feedback.contains(previousFocus) || previousFocus === refresh;
    const restoreFocus = (failed = false) => {
        if (!ownsFocus || !isCurrent() || !(feedback.contains(document.activeElement) || document.activeElement === document.body)) return;
        const target = failed ? feedback.querySelector("button") : previousFocus === refresh ? refresh : body.childElementCount ? body : feedback;
        target?.focus({preventScroll: true});
        target?.scrollIntoView({block: "nearest", inline: "nearest"});
    };
    accountingSalaryQueueLoading = true;
    count.textContent = "Loading…";
    body.innerHTML = "";
    body.setAttribute("aria-busy", "true");
    refresh.disabled = true;
    refresh.textContent = "Refreshing salaries…";
    feedback.className = "salary-queue-feedback is-pending";
    feedback.setAttribute("role", "status");
    feedback.setAttribute("aria-live", "polite");
    feedback.textContent = "Loading scheduled salary changes…";
    if (ownsFocus) feedback.focus();
    let failureMessage = "Unable to load scheduled salaries.";
    try {
        const response = await fetch(`/accounting/debts/scheduled-salary?page=${page}`, {cache: "no-store", signal});
        const data = await response.json();
        if (!isCurrent()) return false;
        if (!response.ok || data?.status !== "success") {
            if (typeof data?.message === "string" && data.message.trim()) failureMessage = data.message;
            throw new Error("Scheduled salary request rejected");
        }
        const integer = value => (typeof value === "number" || typeof value === "string" && value.trim() !== "") && Number.isSafeInteger(Number(value)) && Number(value) >= 0;
        const rows = data.rows;
        if (!Array.isArray(rows) || ![data.due_count, data.total_pending, data.page, data.page_size].every(integer)
            || Number(data.page) !== page || Number(data.page_size) < 1 || Number(data.due_count) > Number(data.total_pending)
            || typeof data.has_more !== "boolean" || rows.length > Number(data.page_size) || rows.length > Number(data.total_pending)
            || rows.some(row => !row || typeof row !== "object" || Array.isArray(row) || !integer(row.user_id) || Number(row.user_id) < 1 || typeof row.name !== "string" || typeof row.effective_date !== "string"
                || !/^\d{4}-\d{2}-\d{2}$/.test(row.effective_date) || ![row.revision, row.salary_grade, row.salary_step].every(integer)
                || ![row.monthly_salary, row.credit_limit].every(value => value !== null && value !== "" && ["number", "string"].includes(typeof value) && Number.isFinite(Number(value)) && Number(value) >= 0))) {
            failureMessage = "Scheduled salary records could not be read.";
            throw new Error("Invalid scheduled salary records");
        }
        count.textContent = `${Number(data.due_count)} due · ${Number(data.total_pending)} pending`;
        feedback.className = "salary-queue-feedback";
        feedback.textContent = "";
        if (rows.length === 0) {
            feedback.textContent = Number(data.total_pending) === 0
                ? "No scheduled salary changes are pending."
                : "This page has no pending changes. Return to the first page.";
            if (Number(data.total_pending) > 0) {
                const first = document.createElement("button");
                first.type = "button";
                first.className = "secondary-btn btn-sm";
                first.textContent = "First page";
                first.addEventListener("click", () => loadScheduledSalaryQueue(1, {manual: true}));
                feedback.appendChild(first);
            }
            return true;
        }
        const today = new Intl.DateTimeFormat("en-CA", {timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit"}).format(new Date());
        body.innerHTML = rows.map((row) => `<div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm">
            <div><strong>${aEscape(row.name)}</strong> <span class="text-slate-500">${aEscape(row.employee_id || "")}</span><br>
            <span>${aEscape(row.effective_date)} · Revision ${aEscape(row.revision)} · ${aEscape(row.salary_grade)} Step ${aEscape(row.salary_step)} · Salary ${aEscape(aMoney(row.monthly_salary))} · Credit ${aEscape(aMoney(row.credit_limit))}</span><br>
            <span class="text-slate-500">Reason: ${aEscape(row.reason || "Not recorded")}</span></div>
            <div class="flex flex-wrap items-center gap-2"><span class="${row.effective_date <= today ? "salary-queue-due text-amber-700" : "text-slate-500"}">${row.effective_date <= today ? "Due for activation" : "Upcoming"}</span>
            <button type="button" class="secondary-btn btn-sm" data-open-salary-user="${Number(row.user_id)}">Review employee</button></div>
        </div>`).join("");
        body.querySelectorAll("[data-open-salary-user]").forEach((button) => button.addEventListener("click", () => openEmployeeModal(Number(button.dataset.openSalaryUser))));
        if (Number(data.total_pending || 0) > Number(data.page_size || 50)) {
            const pager = document.createElement("div");
            pager.className = "salary-queue-pager flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600";
            const label = document.createElement("span");
            label.textContent = `Page ${Number(data.page)} of ${Math.ceil(Number(data.total_pending) / Number(data.page_size))}`;
            pager.appendChild(label);
            const buttons = document.createElement("div");
            buttons.className = "flex gap-2";
            if (Number(data.page) > 1) {
                const previous = document.createElement("button");
                previous.type = "button";
                previous.className = "secondary-btn btn-sm";
                previous.textContent = "Previous";
                previous.addEventListener("click", () => loadScheduledSalaryQueue(Number(data.page) - 1, {manual: true}));
                buttons.appendChild(previous);
            }
            if (data.has_more) {
                const next = document.createElement("button");
                next.type = "button";
                next.className = "secondary-btn btn-sm";
                next.textContent = "Next";
                next.addEventListener("click", () => loadScheduledSalaryQueue(Number(data.page) + 1, {manual: true}));
                buttons.appendChild(next);
            }
            pager.appendChild(buttons);
            body.appendChild(pager);
        }
        return true;
    } catch (error) {
        if (!isCurrent()) return false;
        count.textContent = "Unavailable";
        feedback.className = "salary-queue-feedback is-error";
        feedback.setAttribute("role", "alert");
        feedback.setAttribute("aria-live", "assertive");
        feedback.innerHTML = `<span>${aEscape(failureMessage)}</span><button type="button" class="secondary-btn btn-sm">Retry scheduled salaries</button>`;
        feedback.querySelector("button").addEventListener("click", () => loadScheduledSalaryQueue(page, {manual: true}));
        return false;
    } finally {
        if (isCurrent()) {
            accountingSalaryQueueLoading = false;
            body.removeAttribute("aria-busy");
            refresh.disabled = false;
            refresh.textContent = "Refresh scheduled salaries";
            restoreFocus(feedback.classList.contains("is-error"));
        }
    }
}
function getMainFilteredRows() {
    const keyword = (document.getElementById("acct-search").value || "").trim().toLowerCase();
    const debtOnly = document.getElementById("acct-debt-only").checked;

    return acctRows.filter((row) => {
        if (debtOnly && Number(row.current_debt || 0) <= 0) {
            return false;
        }

        if (!keyword) {
            return true;
        }

        const category = String(row.user_type || "").toLowerCase();
        const haystack = `${row.employee_id || ""} ${row.name || ""} ${row.email || ""} ${category}`.toLowerCase();
        return haystack.includes(keyword);
    });
}

function applyMainFiltersAndRender() {
    renderActiveAccountingTab();
    setAcctResult("", "ok");
}

let accountingDataRequest = 0;
let accountingRecordsLoaded = acctRows.length > 0;
async function loadData() {
    const request = ++accountingDataRequest;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const isCurrent = () => request === accountingDataRequest && !signal?.aborted;
    const params = new URLSearchParams({ limit: "500" });
    try {
    const response = await fetch(`/accounting/debts/data?${params.toString()}`, { cache: "no-store", signal });
    const data = await response.json();
    if (!isCurrent()) return false;
    if (!response.ok || !data || data.status !== "success") {
        throw new Error("Unable to refresh debt records.");
    }

    acctRows = Array.isArray(data.data) ? data.data : [];
    acctAdvanceRows = Array.isArray(data.advance_payments) ? data.advance_payments : [];
    acctOperatorRows = Array.isArray(data.operator_accountabilities) ? data.operator_accountabilities : [];
    acctDataSummary = data.summary || null;
    accountingRecordsLoaded = true;
    applyMainFiltersAndRender();
    await loadDailySummary();
    if (!isCurrent()) return false;
    const queueLoaded = await loadScheduledSalaryQueue();
    if (!isCurrent()) return false;
    if (queueLoaded === false) return false;
    setAcctResult("", "ok");
    return true;
    } catch (error) {
        if (!isCurrent()) return false;
        if (!accountingRecordsLoaded) {
            document.getElementById("acct-body").innerHTML = `<div role="alert">${aDataState("Unable to load Accounting records", "Use Refresh to try again.", "bi-exclamation-triangle")}</div>`;
            document.getElementById("acct-count-text").textContent = "Records unavailable";
            document.getElementById("acct-pager").innerHTML = "";
        }
        setAcctResult(accountingRecordsLoaded
            ? "Unable to refresh all Accounting data. Displayed records may be outdated. Use Refresh to try again."
            : "Accounting records could not be loaded. Use Refresh to try again.", "error");
        return false;
    }
}

async function loadSettlementRuns(options = {}) {
    return window.IbemsLegacyHistory.queue(options);
}

function openSettlementModal() {
    window.IbemsLegacyHistory.reset("queue");
    document.getElementById("settlement-run-modal").style.display = "grid";
    document.getElementById("close-settlement-run").focus();
    return loadSettlementRuns();
}

function closeSettlementModal() {
    if (getComputedStyle(document.getElementById("settlement-run-details-modal")).display !== "none") closeSettlementRunDetails();
    window.IbemsLegacyHistory.reset("queue");
    document.getElementById("settlement-run-modal").style.display = "none";
    document.getElementById("open-settlement-run").focus();
}
async function loadProfile(userId, signal = window.IbemsPortalNavigation?.currentSignal) {
    const response = await fetch(`/accounting/debts/profile?user_id=${userId}`, { cache: "no-store", signal });
    const data = await response.json();
    const profile = data?.data;
    const amount = value => (typeof value === "number" || typeof value === "string" && value.trim() !== "") && Number.isFinite(Number(value)) && Number(value) >= 0;
    if (!response.ok || data?.status !== "success" || !profile || typeof profile !== "object" || Array.isArray(profile)
        || !Number.isSafeInteger(Number(profile.user_id)) || Number(profile.user_id) !== Number(userId)
        || typeof profile.name !== "string" || !profile.name.trim() || typeof profile.financial_profile_configured !== "boolean"
        || ![profile.credit_limit, profile.current_debt, profile.available_credit, profile.credit_percentage].every(amount) || Number(profile.credit_percentage) > 100
        || !(amount(profile.base_salary) || profile.base_salary === null && !profile.financial_profile_configured)
        || !Array.isArray(profile.pending_salary_profiles) || profile.pending_salary_profiles.some(row => !row || typeof row !== "object" || Array.isArray(row)
            || !Number.isSafeInteger(Number(row.id)) || Number(row.id) < 1 || typeof row.effective_date !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(row.effective_date)
            || !Number.isSafeInteger(Number(row.revision)) || Number(row.revision) < 1 || ![row.monthly_salary, row.credit_limit].every(amount))) return null;
    return profile;
}

async function loadHistory(userId, signal = window.IbemsPortalNavigation?.currentSignal) {
    const response = await fetch(`/accounting/debts/history?user_id=${userId}&limit=20`, { cache: "no-store", signal });
    const data = await response.json();
    if (!response.ok || !data || data.status !== "success" || !Array.isArray(data.data)
        || data.data.some(row => !row || typeof row !== "object" || Array.isArray(row) || !Number.isSafeInteger(Number(row.id)) || Number(row.id) < 1
            || typeof row.action !== "string" || !row.action.trim() || typeof row.created_at !== "string" || !Number.isFinite(Date.parse(row.created_at))
            || typeof row.actor_name !== "string" || !row.payload || typeof row.payload !== "object" || Array.isArray(row.payload) && row.payload.length > 0)) {
        throw new Error("Financial history is unavailable.");
    }
    return data.data;
}

function buildEmployeeProfileHtml(p) {
    const debt = Number(p.current_debt || 0);
    const available = Number(p.available_credit || 0);
    const creditPercentage = Number(p.credit_percentage ?? acctDefaultCreditPercentage);

    return `
        <section class="employee-profile-overview">
            <header class="employee-identity">
                ${window.IbemsAvatar.html(p.name, p.profile_image_url, "employee-identity-avatar")}
                <div class="employee-identity-copy">
                    <span class="employee-profile-kicker">Employee financial profile</span>
                    <h5>${aEscape(p.name || "Employee")}</h5>
                    <div class="employee-identity-meta">
                        <span><i class="bi bi-person-vcard" aria-hidden="true"></i>${aEscape(p.employee_id || "No employee ID")}</span>
                        <span><i class="bi bi-envelope" aria-hidden="true"></i>${aEscape(p.email || "No email")}</span>
                        <span><i class="bi bi-briefcase" aria-hidden="true"></i>${aEscape(aCategory(p.user_type))}</span>
                    </div>
                </div>
                <div class="employee-identity-status">${debtStatusPill(p)}</div>
            </header>
            <div class="employee-finance-grid">
                <article class="employee-finance-metric is-salary">
                    <span><i class="bi bi-wallet2" aria-hidden="true"></i>Monthly salary</span>
                    <strong>${aEscape(aMoney(p.base_salary))}</strong>
                </article>
                <article class="employee-finance-metric ${debt > 0 ? "is-debt" : "is-clear"}">
                    <span><i class="bi bi-cash-stack" aria-hidden="true"></i>Current debt</span>
                    <strong>${aEscape(aMoney(debt))}</strong>
                </article>
                <article class="employee-finance-metric is-credit">
                    <span><i class="bi bi-credit-card" aria-hidden="true"></i>Credit limit · ${aEscape(creditPercentage)}%</span>
                    <strong>${aEscape(aMoney(p.credit_limit))}</strong>
                </article>
                <article class="employee-finance-metric ${available > 0 ? "is-available" : "is-muted"}">
                    <span><i class="bi bi-check2-circle" aria-hidden="true"></i>Available credit</span>
                    <strong>${aEscape(aMoney(available))}</strong>
                </article>
            </div>
            <footer class="employee-profile-updated"><i class="bi bi-clock" aria-hidden="true"></i> Last updated ${aEscape(aDateTime(p.updated_at))}</footer>
        </section>
    `;
}

function buildHistoryHtml(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return aDataState("No financial history yet", "Salary, credit, repayment, and deduction changes will appear here.", "bi-clock-history");
    }

    return rows.map((row) => {
        const payload = row.payload || {};
        let detail = "";
        if (row.action === "ACCOUNTING_DEDUCT_DEBT" || row.action === "ACCOUNTING_DEDUCT_FULL_DEBT") {
            detail = `Deducted ${aMoney(payload.deducted_amount)} (Debt: ${aMoney(payload.previous_debt)} -> ${aMoney(payload.new_debt)})`;
        } else if (row.action === "STORE_DEBT_REPAYMENT") {
            detail = `Store payment ${aMoney(payload.paid_amount)} (Debt: ${aMoney(payload.previous_debt)} -> ${aMoney(payload.new_debt)})`;
        } else if (row.action === "ACCOUNTING_UPDATE_CREDIT_LIMIT") {
            detail = `Credit Limit: ${aMoney(payload.previous_credit_limit)} -> ${aMoney(payload.new_credit_limit)}`;
        } else if (row.action === "ACCOUNTING_UPDATE_FINANCIAL_PROFILE") {
            detail = `Salary: ${aMoney(payload.previous_base_salary)} -> ${aMoney(payload.new_base_salary)} | Credit: ${aMoney(payload.previous_credit_limit)} -> ${aMoney(payload.new_credit_limit)}`;
            if (payload.source === "salary_csv_import") {
                const profile = payload.new_salary_profile || {};
                detail = payload.operation === "create"
                    ? `Initial imported salary: ${aMoney(payload.new_base_salary)} | Credit: ${aMoney(payload.new_credit_limit)}`
                    : detail;
                detail += ` | Effective ${profile.salary_effective_date || "-"} | Import batch #${payload.import_batch_id}, line ${payload.source_line}`;
            }
        } else if (row.action === "SALARY_PROFILE_ACTIVATED") {
            const previous = payload.previous_profile || {};
            detail = `Salary activated effective ${payload.effective_date}: ${aMoney(previous.base_salary)} -> ${aMoney(payload.new_monthly_salary)} | Credit: ${aMoney(previous.credit_limit)} -> ${aMoney(payload.new_credit_limit)} | Reason: ${payload.reason || "-"}`;
        } else if (row.action === "ACCOUNTING_SCHEDULE_FINANCIAL_PROFILE" || row.action === "ACCOUNTING_REVISE_SCHEDULED_PROFILE") {
            const profile = payload.profile || {};
            detail = payload.effective_date && payload.revision && profile.monthly_salary !== undefined && profile.credit_limit !== undefined
                ? `${row.action === "ACCOUNTING_REVISE_SCHEDULED_PROFILE" ? "Salary schedule revised" : "Salary scheduled"} for ${payload.effective_date} · Revision ${payload.revision} | Salary: ${aMoney(profile.monthly_salary)} | Credit: ${aMoney(profile.credit_limit)} | Reason: ${payload.reason || "-"}`
                : "Salary scheduling recorded; the detailed snapshot is unavailable in this earlier entry.";
        } else {
            detail = row.action;
        }

        return `
            <div class="history-item rounded-xl border border-slate-200 bg-white p-3">
                <div class="h-top flex items-center justify-between gap-2 text-xs text-slate-500">
                    <span>${aEscape(aDateTime(row.created_at))}</span>
                    <span>${aEscape(row.actor_name)}</span>
                </div>
                <div class="h-body mt-1 text-sm text-slate-700">${aEscape(detail)}</div>
            </div>
        `;
    }).join("");
}

let employeeModalReturnFocus;
let employeeModalRequest = 0;
let employeeModalLoading = false;
let employeeModalReadSignal;
let employeeReadRelease = () => {};
function employeeReadFeedback(message = "", type = "pending") {
    const feedback = document.getElementById("employee-read-feedback");
    feedback.textContent = message;
    feedback.className = `employee-action-feedback is-${type}`;
    feedback.setAttribute("role", type === "error" ? "alert" : "status");
    feedback.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
}
async function openEmployeeModal(userId, {preserveFeedback = false, focus = true, retry = false} = {}) {
    if (window.IbemsEmployeeActions?.busy) return false;
    if (window.IbemsPortalNavigation?.currentSignal?.aborted) return false;
    const selectedUserId = Number(userId);
    if (!Number.isSafeInteger(selectedUserId) || selectedUserId < 1 || employeeModalLoading && Number(employeeModalUserId) === selectedUserId
        && employeeModalReadSignal === window.IbemsPortalNavigation?.currentSignal) return false;
    employeeReadRelease();
    const request = ++employeeModalRequest;
    const pageSignal = window.IbemsPortalNavigation?.currentSignal;
    employeeModalReadSignal = pageSignal;
    const isCurrent = () => request === employeeModalRequest && !pageSignal?.aborted && pageSignal === window.IbemsPortalNavigation?.currentSignal && modal.isConnected !== false;
    employeeModalUserId = selectedUserId;
    const modal = document.getElementById("employee-modal");
    const profileEl = document.getElementById("employee-modal-profile");
    const historyEl = document.getElementById("employee-modal-history");
    const retryButton = document.getElementById("employee-read-retry");
    const feedback = document.getElementById("employee-read-feedback");
    const recoveryOwned = () => retry && (document.activeElement === retryButton || document.activeElement === feedback || document.activeElement === document.body);
    const focusResult = element => { if (recoveryOwned()) { element.focus({preventScroll:true}); element.scrollIntoView({block:"nearest",inline:"nearest"}); } };
    const unavailable = message => {
        employeeReadFeedback(message, "error");
        retryButton.hidden = false;
        retryButton.disabled = false;
        retryButton.textContent = employeeModalProfile ? "Retry financial history" : "Retry employee details";
        focusResult(retryButton);
        return false;
    };

    if (getComputedStyle(modal).display === "none") employeeModalReturnFocus = document.activeElement;
    modal.style.display = "grid";
    if (focus) document.getElementById("close-employee-modal").focus();
    if (!preserveFeedback) window.IbemsEmployeeActions?.clear();
    employeeModalProfile = null;
    employeeModalLoading = true;
    modal.setAttribute("aria-busy", "true");
    profileEl.setAttribute("aria-busy", "true");
    historyEl.setAttribute("aria-busy", "true");
    retryButton.disabled = true;
    retryButton.hidden = true;
    employeeReadFeedback("Loading employee details…");
    focusResult(feedback);
    profileEl.innerHTML = aDataState("Loading employee profile", "Fetching current salary and credit information.", "bi-person-vcard");
    historyEl.innerHTML = aDataState("Loading financial history", "Fetching the latest account changes.", "bi-clock-history");
    document.getElementById("employee-modal-actions").classList.add("hidden");
    document.getElementById("employee-limit-box").classList.add("hidden");

    try {
        try {
            await loadAcctSalarySchedules();
        } catch (error) {
            if (!isCurrent()) return;
            profileEl.innerHTML = aDataState("Employee profile unavailable", "Salary reference information could not be loaded.", "bi-exclamation-triangle");
            historyEl.innerHTML = aDataState("Financial history unavailable", "Employee details could not be loaded.", "bi-exclamation-triangle");
            return unavailable("Salary schedules could not be loaded. Retry employee details to check again.");
        }
        if (!isCurrent()) return;
        let profile;
        try {
            profile = await loadProfile(selectedUserId, pageSignal);
        } catch (error) {
            if (!isCurrent()) return;
            profileEl.innerHTML = aDataState("Unable to load employee profile", "The employee details could not be retrieved.", "bi-exclamation-triangle");
            historyEl.innerHTML = aDataState("Financial history unavailable", "Employee profile could not be loaded.", "bi-exclamation-triangle");
            return unavailable("Employee details could not be loaded. Retry employee details to check again.");
        }
        if (!isCurrent()) return;
        employeeModalProfile = profile;
        if (!employeeModalProfile) {
            profileEl.innerHTML = aDataState("Unable to load employee profile", "The selected employee record could not be retrieved.", "bi-exclamation-triangle");
            historyEl.innerHTML = aDataState("Financial history unavailable", "No employee profile was returned.", "bi-exclamation-triangle");
            return unavailable("Employee details could not be loaded. Retry employee details to check again.");
        }

        profileEl.innerHTML = buildEmployeeProfileHtml(employeeModalProfile);
        document.getElementById("employee-limit-current").textContent = aBool(employeeModalProfile.financial_profile_configured)
            ? `${aCategory(employeeModalProfile.employment_type)} | ${employeeModalProfile.salary_grade || "-"}${employeeModalProfile.salary_step ? ` Step ${employeeModalProfile.salary_step}` : ""} | Salary ${aMoney(employeeModalProfile.base_salary || 0)} | ${Number(employeeModalProfile.credit_percentage ?? acctDefaultCreditPercentage)}% Credit ${aMoney(employeeModalProfile.credit_limit || 0)}`
            : "No salary-grade schedule is configured. Add one to calculate the employee’s credit limit.";
        document.getElementById("employee-open-limit-edit").innerHTML = aBool(employeeModalProfile.financial_profile_configured)
            ? '<i class="bi bi-pencil-square"></i> Edit profile'
            : '<i class="bi bi-plus-lg"></i> Configure profile';
        const pending = Array.isArray(employeeModalProfile.pending_salary_profiles) ? employeeModalProfile.pending_salary_profiles : [];
        const manilaToday = new Intl.DateTimeFormat("en-CA", {timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit"}).format(new Date());
        const pendingEl = document.getElementById("employee-pending-profiles");
        pendingEl.innerHTML = pending.length
            ? `<strong>Pending salary changes</strong>${pending.map((item) => `<div class="employee-pending-decision mt-1 rounded-lg border px-3 py-2"><p>Effective ${aEscape(item.effective_date)} · Revision ${aEscape(item.revision)} · ${aEscape(item.salary_grade)} Step ${aEscape(item.salary_step)} · Salary ${aEscape(aMoney(item.monthly_salary))} · Credit ${aEscape(aMoney(item.credit_limit))} · Reason: ${aEscape(item.reason || "Not recorded")}${item.effective_date <= manilaToday ? "" : " · Not effective yet"}</p>${item.effective_date <= manilaToday ? `<div class="mt-2"><button type="button" class="secondary-btn btn-sm" data-activate-salary-id="${Number(item.id)}">Activate now</button></div>` : ""}</div>`).join("")}`
            : "";
        pendingEl.querySelectorAll("[data-activate-salary-id]").forEach((button) => button.addEventListener("click", () => window.IbemsEmployeeActions.activate(button)));
        populateAcctSalaryProfile(employeeModalProfile);

        try {
            profileEl.setAttribute("aria-busy", "false");
            const history = await loadHistory(selectedUserId, pageSignal);
            if (!isCurrent()) return;
            historyEl.innerHTML = buildHistoryHtml(history);
            employeeReadFeedback();
            focusResult(profileEl);
            return true;
        } catch (error) {
            if (!isCurrent()) return;
            historyEl.innerHTML = aDataState("Unable to load financial history", "The latest account changes could not be retrieved.", "bi-exclamation-triangle");
            return unavailable("Financial history could not be loaded. Retry financial history to check again.");
        }
    } finally {
        if (isCurrent()) {
            employeeModalLoading = false;
            modal.setAttribute("aria-busy", "false");
            profileEl.setAttribute("aria-busy", "false");
            historyEl.setAttribute("aria-busy", "false");
            retryButton.disabled = false;
            if (employeeModalProfile) document.getElementById("employee-modal-actions").classList.remove("hidden");
        }
    }
}

async function retryEmployeeHistory() {
    if (employeeModalLoading || window.IbemsEmployeeActions?.busy || !employeeModalProfile) return false;
    const userId = employeeModalUserId;
    const request = employeeModalRequest;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const modal = document.getElementById("employee-modal");
    const history = document.getElementById("employee-modal-history");
    const button = document.getElementById("employee-read-retry");
    const feedback = document.getElementById("employee-read-feedback");
    const current = () => request === employeeModalRequest && userId === employeeModalUserId && !signal?.aborted
        && signal === window.IbemsPortalNavigation?.currentSignal && modal.isConnected !== false;
    if (!current() || getComputedStyle(modal).display === "none") return false;
    const ownsFocus = () => document.activeElement === button || document.activeElement === feedback || document.activeElement === document.body;
    const focus = element => { if (ownsFocus()) { element.focus({preventScroll:true}); element.scrollIntoView({block:"nearest",inline:"nearest"}); } };
    const controls = Array.from(document.getElementById("employee-modal-actions").querySelectorAll("button, input, select, textarea"));
    const original = controls.map(control => [control, control.disabled]);
    let released = false;
    const release = () => {
        if (released) return;
        released = true;
        original.forEach(([control, disabled]) => { control.disabled = disabled; });
        if (employeeReadRelease === release) employeeReadRelease = () => {};
    };
    employeeReadRelease = release;
    controls.forEach(control => { control.disabled = true; });
    employeeModalLoading = true;
    employeeModalReadSignal = signal;
    modal.setAttribute("aria-busy", "true");
    history.setAttribute("aria-busy", "true");
    button.disabled = true;
    button.hidden = true;
    employeeReadFeedback("Loading financial history…");
    focus(feedback);
    history.innerHTML = aDataState("Loading financial history", "Fetching the latest account changes.", "bi-clock-history");
    try {
        const rows = await loadHistory(userId, signal);
        if (!current()) return false;
        history.innerHTML = buildHistoryHtml(rows);
        employeeReadFeedback();
        focus(history);
        return true;
    } catch (_) {
        if (!current()) return false;
        history.innerHTML = aDataState("Unable to load financial history", "The latest account changes could not be retrieved.", "bi-exclamation-triangle");
        employeeReadFeedback("Financial history could not be loaded. Retry financial history to check again.", "error");
        button.hidden = false;
        button.disabled = false;
        focus(button);
        return false;
    } finally {
        if (current()) {
            employeeModalLoading = false;
            modal.setAttribute("aria-busy", "false");
            history.setAttribute("aria-busy", "false");
            button.disabled = false;
            release();
        }
    }
}

function closeEmployeeModal() {
    if (window.IbemsEmployeeActions?.busy) return;
    employeeReadRelease();
    employeeModalRequest += 1;
    employeeModalLoading = false;
    employeeModalReadSignal = undefined;
    document.getElementById("employee-modal").style.display = "none";
    employeeModalUserId = null;
    employeeModalProfile = null;
    if (employeeModalReturnFocus?.isConnected) employeeModalReturnFocus.focus();
}

document.getElementById("employee-modal").addEventListener("keydown", (event) => {
    if (event.defaultPrevented) return;
    if (document.body.classList.contains("has-app-dialog")) return;
    const modal = event.currentTarget;
    if (!modal.contains(document.activeElement)) return;
    if (window.IbemsEmployeeActions?.busy) {
        if (["Tab", "Escape"].includes(event.key)) {
            event.preventDefault();
            document.getElementById("employee-action-feedback").focus();
        }
        return;
    }
    if (event.key === "Escape") {
        if (modal.querySelector('[aria-expanded="true"]')) return;
        event.preventDefault();
        closeEmployeeModal();
        return;
    }
    if (event.key !== "Tab") return;
    const controls = Array.from(modal.querySelectorAll('button, a[href], input, select, textarea, [tabindex]'))
        .filter((element) => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length && getComputedStyle(element).visibility !== "hidden");
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
    }
});

document.getElementById("employee-read-retry").addEventListener("click", () => {
    if (employeeModalLoading || window.IbemsEmployeeActions?.busy || !employeeModalUserId) return;
    if (employeeModalProfile) retryEmployeeHistory();
    else openEmployeeModal(employeeModalUserId, {preserveFeedback:true, focus:false, retry:true});
});

function invalidateImportCsvPreview() {
    return window.IbemsAccountingImport.invalidate();
}

function openImportModal() {
    if (window.IbemsAccountingImport.applying) return;
    invalidateImportCsvPreview();
    document.getElementById("import-csv-modal").style.display = "grid";
    document.getElementById("close-import-csv").focus();
    setImportCsvResult("");
    document.getElementById("import-csv-valid").innerHTML = "";
    document.getElementById("import-csv-invalid").innerHTML = "";
}

function closeImportModal() {
    if (window.IbemsAccountingImport.applying) return;
    invalidateImportCsvPreview();
    document.getElementById("import-csv-modal").style.display = "none";
    document.getElementById("open-import-csv").focus();
}

function setImportCsvResult(message, type = "normal") {
    window.IbemsAccountingImport.feedback(message, type === "normal" ? "neutral" : type);
}
function renderCsvPreview(data) {
    const resultEl = document.getElementById("import-csv-result");
    const validEl = document.getElementById("import-csv-valid");
    const invalidEl = document.getElementById("import-csv-invalid");
    const validPreview = Array.isArray(data.valid_preview) ? data.valid_preview : [];
    const invalidPreview = Array.isArray(data.invalid_preview) ? data.invalid_preview : [];

    setImportCsvResult(`Preview ready. Total: ${data.total_rows}, Valid: ${data.valid_rows}, Invalid: ${data.invalid_rows}, Create: ${data.create_count}, Update: ${data.update_count}, Credit limit updates: ${data.credit_limit_update_count}`, data.invalid_rows > 0 ? "warning" : "success");

    validEl.innerHTML = validPreview.length > 0 ? validPreview.map((row) => `
        <div class="import-preview-item is-valid rounded-xl border border-emerald-200 bg-emerald-50 p-3">
            <strong>Line ${row.line}: ${aEscape(row.action === "create" ? "Create" : "Update")}</strong><br>
            ${aEscape(row.name || "-")} (${aEscape(row.email || "-")})<br>
            <span>${aEscape(aCategory(row.user_type))} | ${aEscape(aCategory(row.employment_type))} | ${aEscape(row.salary_schedule_code || "-")} · ${aEscape(row.salary_grade || "-")}${row.salary_step ? ` Step ${aEscape(row.salary_step)}` : ""} | Salary ${aEscape(aMoney(row.monthly_salary || 0))} | ${aEscape(Number(row.credit_percentage ?? acctDefaultCreditPercentage))}% Credit ${aEscape(aMoney(row.credit_limit || 0))}</span>
        </div>
    `).join("") : "";

    invalidEl.innerHTML = invalidPreview.length > 0 ? invalidPreview.map((row) => `
        <div class="invalid-item rounded-xl border border-amber-200 bg-amber-50 p-3">
            <strong>Line ${row.line}</strong><br>
            ${aEscape(row.name || "-")} (${aEscape(row.email || "-")})<br>
            <span>${aEscape(row.error || "Invalid row")}</span>
        </div>
    `).join("") : "";
}

async function previewImportCsv() {
    return window.IbemsAccountingImport.preview();
}

async function submitImportCsv() {
    return window.IbemsAccountingImport.apply();
}
document.getElementById("acct-refresh-btn").addEventListener("click", async () => {
    await loadData();
});
document.getElementById("acct-salary-refresh").addEventListener("click", () => loadScheduledSalaryQueue(accountingSalaryQueuePage, {manual: true}));

function containAccountingDialogKeyboard(modalId, closeDialog) {
document.getElementById(modalId).addEventListener("keydown", (event) => {
    if (event.defaultPrevented) return;
    if (document.body.classList.contains("has-app-dialog") || !event.currentTarget.contains(document.activeElement)) return;
    if (modalId === "import-csv-modal" && window.IbemsAccountingImport.applying && ["Tab","Escape"].includes(event.key)) {
        event.preventDefault();
        document.getElementById("import-csv-result").focus();
        return;
    }
    if (event.key === "Escape") {
        if (event.currentTarget.querySelector('[aria-expanded="true"]')) return;
        event.preventDefault();
        closeDialog();
        return;
    }
    if (event.key !== "Tab") return;
    const controls = Array.from(event.currentTarget.querySelectorAll("button, input, select, textarea, a[href], [tabindex]"))
        .filter((control) => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length);
    const first = controls[0];
    const last = controls[controls.length - 1];
    if ((event.shiftKey && (document.activeElement === first || (first?.compareDocumentPosition(document.activeElement) & Node.DOCUMENT_POSITION_PRECEDING)))
        || (!event.shiftKey && (document.activeElement === last || (last?.compareDocumentPosition(document.activeElement) & Node.DOCUMENT_POSITION_FOLLOWING)))) {
        event.preventDefault();
        (event.shiftKey ? last : first)?.focus();
    }
});
}
containAccountingDialogKeyboard("import-csv-modal", closeImportModal);
containAccountingDialogKeyboard("settlement-run-modal", closeSettlementModal);
containAccountingDialogKeyboard("settlement-run-details-modal", closeSettlementRunDetails);
