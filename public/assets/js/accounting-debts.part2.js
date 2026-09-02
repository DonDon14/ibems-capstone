async function loadDailySummary() {
    const response = await fetch("/accounting/debts/daily-summary");
    const data = await response.json();
    if (!data || data.status !== "success") return;
    document.getElementById("acct-today-count").textContent = String(data.deduction_count || 0);
    document.getElementById("acct-today-amount").textContent = aMoney(data.deducted_amount || 0);
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

async function loadData() {
    const params = new URLSearchParams({ limit: "500" });

    const response = await fetch(`/accounting/debts/data?${params.toString()}`, { cache: "no-store" });
    const data = await response.json();

    if (!data || data.status !== "success") {
        acctRows = [];
        acctAdvanceRows = [];
        acctOperatorRows = [];
        acctDataSummary = null;
        renderRows([]);
        setAcctResult(data?.message || "Unable to load debt records.", "error");
        return;
    }

    acctRows = Array.isArray(data.data) ? data.data : [];
    acctAdvanceRows = Array.isArray(data.advance_payments) ? data.advance_payments : [];
    acctOperatorRows = Array.isArray(data.operator_accountabilities) ? data.operator_accountabilities : [];
    acctDataSummary = data.summary || null;
    applyMainFiltersAndRender();
    await loadDailySummary();
    setAcctResult("", "ok");
}

async function loadSettlementRuns() {
    const response = await fetch("/accounting/settlement/runs?limit=12");
    const data = await response.json();
    if (!data || data.status !== "success") {
        renderSettlementRuns([]);
        return;
    }
    renderSettlementRuns(Array.isArray(data.data) ? data.data : []);
}

function openSettlementModal() {
    document.getElementById("settlement-run-modal").style.display = "grid";
    loadSettlementRuns().catch(() => {
        renderSettlementRuns([]);
    });
}

function closeSettlementModal() {
    document.getElementById("settlement-run-modal").style.display = "none";
}

async function loadProfile(userId) {
    const response = await fetch(`/accounting/debts/profile?user_id=${userId}`, { cache: "no-store" });
    const data = await response.json();
    if (!data || data.status !== "success" || !data.data) return null;
    return data.data;
}

async function loadHistory(userId) {
    const response = await fetch(`/accounting/debts/history?user_id=${userId}&limit=20`, { cache: "no-store" });
    const data = await response.json();
    if (!data || data.status !== "success" || !Array.isArray(data.data)) return [];
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

async function openEmployeeModal(userId) {
    employeeModalUserId = Number(userId);
    const modal = document.getElementById("employee-modal");
    const profileEl = document.getElementById("employee-modal-profile");
    const historyEl = document.getElementById("employee-modal-history");

    modal.style.display = "grid";
    profileEl.innerHTML = aDataState("Loading employee profile", "Fetching current salary and credit information.", "bi-person-vcard");
    historyEl.innerHTML = aDataState("Loading financial history", "Fetching the latest account changes.", "bi-clock-history");
    document.getElementById("employee-modal-actions").classList.add("hidden");
    document.getElementById("employee-limit-box").classList.add("hidden");

    try {
        await loadAcctSalarySchedules();
    } catch (error) {
        profileEl.innerHTML = aDataState("Unable to load employee profile", error.message || "Salary schedules are unavailable.", "bi-exclamation-triangle");
        historyEl.innerHTML = aDataState("Financial history unavailable", "Try reopening this employee after the profile service is available.", "bi-exclamation-triangle");
        return;
    }
    employeeModalProfile = await loadProfile(employeeModalUserId);
    if (!employeeModalProfile) {
        profileEl.innerHTML = aDataState("Unable to load employee profile", "The selected employee record could not be retrieved.", "bi-exclamation-triangle");
        historyEl.innerHTML = aDataState("Financial history unavailable", "No employee profile was returned.", "bi-exclamation-triangle");
        return;
    }

    profileEl.innerHTML = buildEmployeeProfileHtml(employeeModalProfile);
    document.getElementById("employee-limit-current").textContent = aBool(employeeModalProfile.financial_profile_configured)
        ? `${aCategory(employeeModalProfile.employment_type)} | ${employeeModalProfile.salary_grade || "-"}${employeeModalProfile.salary_step ? ` Step ${employeeModalProfile.salary_step}` : ""} | Salary ${aMoney(employeeModalProfile.base_salary || 0)} | ${Number(employeeModalProfile.credit_percentage ?? acctDefaultCreditPercentage)}% Credit ${aMoney(employeeModalProfile.credit_limit || 0)}`
        : "No salary-grade schedule is configured. Add one to calculate the employee’s credit limit.";
    document.getElementById("employee-open-limit-edit").innerHTML = aBool(employeeModalProfile.financial_profile_configured)
        ? '<i class="bi bi-pencil-square"></i> Edit profile'
        : '<i class="bi bi-plus-lg"></i> Configure profile';
    populateAcctSalaryProfile(employeeModalProfile);
    document.getElementById("employee-modal-actions").classList.remove("hidden");

    const history = await loadHistory(employeeModalUserId);
    historyEl.innerHTML = buildHistoryHtml(history);
}

function closeEmployeeModal() {
    document.getElementById("employee-modal").style.display = "none";
    employeeModalUserId = null;
    employeeModalProfile = null;
}

function openImportModal() {
    importCsvPreviewReady = false;
    document.getElementById("import-csv-modal").style.display = "grid";
    document.getElementById("import-csv-result").textContent = "";
    document.getElementById("import-csv-valid").innerHTML = "";
    document.getElementById("import-csv-invalid").innerHTML = "";
    document.getElementById("import-csv-submit").disabled = true;
}

function closeImportModal() {
    document.getElementById("import-csv-modal").style.display = "none";
    importCsvPreviewReady = false;
}

function renderCsvPreview(data) {
    const resultEl = document.getElementById("import-csv-result");
    const validEl = document.getElementById("import-csv-valid");
    const invalidEl = document.getElementById("import-csv-invalid");
    const validPreview = Array.isArray(data.valid_preview) ? data.valid_preview : [];
    const invalidPreview = Array.isArray(data.invalid_preview) ? data.invalid_preview : [];

    resultEl.style.color = data.invalid_rows > 0 ? "#92400e" : "#166534";
    resultEl.textContent = `Preview ready. Total: ${data.total_rows}, Valid: ${data.valid_rows}, Invalid: ${data.invalid_rows}, Create: ${data.create_count}, Update: ${data.update_count}, Credit limit updates: ${data.credit_limit_update_count}`;

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
    const fileInput = document.getElementById("import-csv-file");
    const resultEl = document.getElementById("import-csv-result");
    const validEl = document.getElementById("import-csv-valid");
    const invalidEl = document.getElementById("import-csv-invalid");
    const button = document.getElementById("import-csv-preview");
    const applyButton = document.getElementById("import-csv-submit");
    const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

    importCsvPreviewReady = false;
    applyButton.disabled = true;

    if (!file) {
        resultEl.style.color = "#b91c1c";
        resultEl.textContent = "Please choose a CSV file first.";
        return;
    }

    const formData = new FormData();
    formData.append("csv_file", file);

    button.disabled = true;
    button.textContent = "Previewing...";
    resultEl.style.color = "#334155";
    resultEl.textContent = "Checking CSV...";
    validEl.innerHTML = "";
    invalidEl.innerHTML = "";

    try {
        const response = await fetch("/accounting/debts/preview-csv", {
            method: "POST",
            body: formData,
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            resultEl.style.color = "#b91c1c";
            resultEl.textContent = data?.message || "CSV import failed.";
            return;
        }

        renderCsvPreview(data);
        importCsvPreviewReady = Number(data.valid_rows || 0) > 0;
        applyButton.disabled = !importCsvPreviewReady;
    } catch (error) {
        resultEl.style.color = "#b91c1c";
        resultEl.textContent = "CSV preview request failed.";
    } finally {
        button.disabled = false;
        button.textContent = "Preview CSV";
    }
}

async function submitImportCsv() {
    const fileInput = document.getElementById("import-csv-file");
    const resultEl = document.getElementById("import-csv-result");
    const button = document.getElementById("import-csv-submit");
    const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

    if (!file || !importCsvPreviewReady) {
        resultEl.style.color = "#b91c1c";
        resultEl.textContent = "Preview a valid CSV before applying import.";
        return;
    }

    const formData = new FormData();
    formData.append("csv_file", file);

    button.disabled = true;
    button.textContent = "Applying...";
    resultEl.style.color = "#334155";
    resultEl.textContent = "Applying CSV import...";

    try {
        const response = await fetch("/accounting/debts/import-csv", {
            method: "POST",
            body: formData,
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            resultEl.style.color = "#b91c1c";
            resultEl.textContent = data?.message || "CSV import failed.";
            button.disabled = false;
            button.textContent = "Apply Import";
            return;
        }

        resultEl.style.color = "#166534";
        resultEl.textContent = `Import complete. Total: ${data.total_rows}, Valid: ${data.valid_rows}, Invalid: ${data.invalid_rows}`;
        importCsvPreviewReady = false;
        await loadData();
    } catch (error) {
        resultEl.style.color = "#b91c1c";
        resultEl.textContent = "CSV import request failed.";
        button.disabled = false;
    } finally {
        button.textContent = "Apply Import";
    }
}

document.getElementById("acct-refresh-btn").addEventListener("click", async () => {
    await loadData();
});

function setWorkflowMessage(message, type = "ok") {
    const element = document.getElementById("workflow-message");
    element.textContent = message || "";
    element.className = `text-sm font-semibold ${type === "error" ? "text-red-700" : "text-emerald-700"}`;
}

function filterWorkflowCandidates() {
    const query = document.getElementById("workflow-candidate-search").value.trim().toLowerCase();
    const type = document.getElementById("workflow-candidate-type").value;
    const salary = document.getElementById("workflow-candidate-salary").value;
    const candidates = Array.from(document.querySelectorAll(".workflow-candidate"));
    let matching = 0;
    candidates.forEach((candidate) => {
        const matches = (!query || candidate.dataset.search.includes(query))
            && (type === "all" || candidate.dataset.employeeType === type)
            && (salary === "all" || candidate.dataset.salaryState === salary);
        candidate.classList.toggle("hidden", !matches);
        if (matches) matching++;
    });
    const selected = candidates.filter((candidate) => candidate.querySelector(".workflow-candidate-check").checked).length;
    document.getElementById("workflow-candidate-count").textContent = `Showing ${matching} of ${candidates.length} employees with debt · ${selected} selected`;
    document.getElementById("workflow-summary-selected").textContent = String(selected);
}

function renderWorkflowCandidates() {
    const container = document.getElementById("workflow-candidates");
    const rows = deductionWorkflowRegister.filter((row) => Number(row.cutoff_debt || 0) > 0);
    const existingByUser = new Map(deductionWorkflowItems.map((item) => [Number(item.user_id), item]));
    document.getElementById("workflow-summary-employees").textContent = String(rows.length);
    document.getElementById("workflow-summary-debt").textContent = aMoney(rows.reduce((sum, row) => sum + Number(row.cutoff_debt || 0), 0));
    document.getElementById("workflow-summary-missing").textContent = String(rows.filter((row) => Number(row.salary_reference || 0) <= 0).length);
    container.innerHTML = rows.length ? rows.map((row) => {
        const existing = existingByUser.get(Number(row.user_id));
        const choice = existing?.deduction_choice || (existing && Number(existing.requested_amount || 0) === 0 ? "none" : "full");
        const requested = existing ? Number(existing.requested_amount || 0) : Number(row.cutoff_debt || 0);
        const reason = existing?.preparation_reason || "";
        return `
        <label class="workflow-candidate grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2 lg:grid-cols-[auto_minmax(420px,1fr)_190px_160px] lg:items-center" data-search="${aEscape(`${row.name || ""} ${row.employee_id || ""} ${row.email || ""}`.toLowerCase())}" data-employee-type="${aEscape(String(row.user_type || "").toLowerCase())}" data-salary-state="${Number(row.salary_reference || 0) > 0 ? "provided" : "missing"}">
            <input type="checkbox" class="workflow-candidate-check h-4 w-4" data-workflow-user="${aEscape(row.user_id)}" ${existing ? "checked" : ""}>
            <span class="flex min-w-0 items-center gap-2 overflow-hidden whitespace-nowrap">
                <strong class="shrink-0 text-sm text-slate-900">${aEscape(row.name || "Employee")}</strong>
                <small class="truncate text-slate-500">${aEscape(row.employee_id || "-")} · Cutoff ${aEscape(aMoney(row.cutoff_debt || 0))} · Salary ${Number(row.salary_reference || 0) > 0 ? aEscape(aMoney(row.salary_reference)) : "Not provided"} · Opening ${aEscape(aMoney(row.opening_debt || 0))}</small>
            </span>
            <select class="workflow-deduction-choice h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" aria-label="Deduction choice for ${aEscape(row.name || "employee")}">
                <option value="full" ${choice === "full" ? "selected" : ""}>Deduct full debt</option>
                <option value="partial" ${choice === "partial" ? "selected" : ""}>Partial deduction</option>
                <option value="none" ${choice === "none" ? "selected" : ""}>No deduction</option>
            </select>
            <span class="grid gap-2">
                <input type="number" class="workflow-request-amount ${choice === "none" ? "hidden" : ""} h-10 rounded-xl border border-slate-200 ${choice === "full" ? "bg-slate-100 text-slate-600" : "bg-white"} px-3 text-sm" min="0" max="${aEscape(row.cutoff_debt || 0)}" step="0.01" value="${aEscape(requested.toFixed(2))}" data-cutoff-debt="${aEscape(row.cutoff_debt || 0)}" aria-label="Requested amount for ${aEscape(row.name || "employee")}" ${choice === "full" ? "readonly" : ""}>
                <input type="text" class="workflow-preparation-reason ${choice === "none" ? "" : "hidden"} h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" maxlength="200" value="${aEscape(reason)}" placeholder="Reason for no deduction" aria-label="No deduction reason for ${aEscape(row.name || "employee")}">
            </span>
        </label>
    `}).join("") : '<div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm text-slate-500">No active faculty/staff debt accounts are available.</div>';
    filterWorkflowCandidates();
}

function workflowStatusLabel(value) {
    return aCategory(String(value || "pending").replace(/_/g, " "));
}

function renderWorkflowResults(items, batchStatus, batchCreatedBy) {
    const section = document.getElementById("workflow-results-section");
    const container = document.getElementById("workflow-results");
    const actions = document.getElementById("workflow-batch-actions");
    const workflowModal = document.getElementById("deduction-workflow-modal");
    const currentRole = workflowModal.dataset.currentRole || "";
    const currentUser = Number(workflowModal.dataset.currentUser || 0);
    const selectedPeriod = deductionWorkflowPeriods.find((row) => Number(row.id) === Number(document.getElementById("workflow-period-select").value || 0));
    section.classList.remove("hidden");
    if (batchStatus === "prepared") {
        actions.innerHTML = '<button type="button" id="workflow-edit-deductions" class="secondary-btn">Edit deductions</button> <button type="button" data-workflow-batch-action="apply" class="primary-btn">Apply deductions</button>';
    } else if (batchStatus === "submitted") {
        actions.innerHTML = '<button type="button" data-workflow-batch-action="apply" class="primary-btn">Apply pending deductions</button>';
    } else if (batchStatus === "processed") {
        actions.innerHTML = '<button type="button" data-workflow-batch-action="reconcile" class="primary-btn">Reconcile batch totals</button>';
    } else if (batchStatus === "reconciled") {
        actions.innerHTML = currentRole === "ACCOUNTING_OFFICE" && Number(batchCreatedBy || 0) === currentUser
            ? '<button type="button" data-workflow-batch-action="finalize" class="primary-btn">Finalize period</button>'
            : '<span class="inline-flex rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">Only the assigned Accounting Officer can finalize this period</span>';
    } else if (batchStatus === "finalized") {
        actions.innerHTML = '<span class="inline-flex rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">Finalized and locked</span>';
    } else {
        actions.innerHTML = '<span class="inline-flex rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">Review the current batch status</span>';
    }
    if (!items.length) {
        container.innerHTML = '<div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm text-slate-500">No batch items found.</div>';
        return;
    }

    container.innerHTML = items.map((item) => {
        const pending = String(item.result_status || "pending") === "pending";
        return `
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3" data-workflow-item="${aEscape(item.id)}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <strong class="block text-sm text-slate-900">${aEscape(item.name || "Employee")}</strong>
                        <small class="text-slate-500">${aEscape(item.employee_id || "-")} · Requested ${aEscape(aMoney(item.requested_amount || 0))} · Current debt ${aEscape(aMoney(item.current_debt || 0))}</small>
                    </div>
                    <span class="acct-debt-status ${pending ? "is-info" : "is-success"}">${aEscape(workflowStatusLabel(item.result_status))}</span>
                </div>
                ${pending ? `
                    <div class="mt-2 rounded-lg border border-blue-200 bg-blue-50 p-2 text-sm text-blue-800">This amount will be processed when Apply deductions is selected.</div>
                ` : `
                    <div class="mt-2 text-sm text-slate-600">Confirmed ${aEscape(aMoney(item.confirmed_amount || 0))} · Carryover ${aEscape(aMoney(item.carryover_amount || 0))} · Reference ${aEscape(item.result_reference || "-")}</div>
                `}
            </article>
        `;
    }).join("");
}

async function loadDeductionWorkflow(preferredPeriodId = null) {
    const select = document.getElementById("workflow-period-select");
    const currentPeriodId = preferredPeriodId || Number(select.value || 0);
    const selectedBefore = deductionWorkflowPeriods.find((period) => Number(period.id) === Number(currentPeriodId));
    const params = new URLSearchParams();
    if (currentPeriodId) params.set("period_id", String(currentPeriodId));
    if (selectedBefore?.batch_id) params.set("batch_id", String(selectedBefore.batch_id));
    const query = params.toString() ? `?${params.toString()}` : "";
    const response = await fetch(`/accounting/deduction-workflow${query}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        setWorkflowMessage(data?.message || "Unable to load deduction workflow.", "error");
        return;
    }

    deductionWorkflowPeriods = Array.isArray(data.periods) ? data.periods : [];
    deductionWorkflowRegister = Array.isArray(data.register) ? data.register : [];
    deductionWorkflowItems = Array.isArray(data.items) ? data.items : [];
    select.innerHTML = '<option value="">Select a period</option>' + deductionWorkflowPeriods.map((period) =>
        `<option value="${aEscape(period.id)}">${aEscape(period.period_code)} · ${aEscape(period.label)}</option>`
    ).join("");
    if (currentPeriodId && deductionWorkflowPeriods.some((period) => Number(period.id) === Number(currentPeriodId))) {
        select.value = String(currentPeriodId);
    }

    const selected = deductionWorkflowPeriods.find((period) => Number(period.id) === Number(select.value || 0));
    const summary = document.getElementById("workflow-period-summary");
    const prepareSection = document.getElementById("workflow-prepare-section");
    const resultsSection = document.getElementById("workflow-results-section");
    if (!selected) {
        summary.textContent = "Choose or create a deduction period.";
        prepareSection.classList.add("hidden");
        resultsSection.classList.add("hidden");
        return;
    }

    summary.innerHTML = `<strong>${aEscape(selected.period_code)} · ${aEscape(selected.label)}</strong><br><span>${aEscape(selected.date_start)} to ${aEscape(selected.date_end)} · ${selected.batch_id ? `Batch ${aEscape(workflowStatusLabel(selected.batch_status))}` : "No batch prepared"}</span>`;
    if (selected.batch_id) {
        prepareSection.classList.add("hidden");
        if (!query || Number(selected.batch_id) !== Number(selectedBefore?.batch_id)) {
            const detailResponse = await fetch(`/accounting/deduction-workflow?period_id=${encodeURIComponent(selected.id)}&batch_id=${encodeURIComponent(selected.batch_id)}`);
            const detail = await detailResponse.json();
            deductionWorkflowItems = Array.isArray(detail.items) ? detail.items : [];
            renderWorkflowResults(Array.isArray(detail.items) ? detail.items : [], selected.batch_status, selected.batch_created_by);
        } else {
            renderWorkflowResults(Array.isArray(data.items) ? data.items : [], selected.batch_status, selected.batch_created_by);
        }
    } else {
        resultsSection.classList.add("hidden");
        prepareSection.classList.remove("hidden");
        renderWorkflowCandidates();
    }
}
