let acctRows = [];
let acctAdvanceRows = [];
let acctOperatorRows = [];
let acctDataSummary = null;
let activeAcctTab = "employee-debts";
let employeeModalUserId = null;
let employeeModalProfile = null;
let settlementRunsCache = [];
let importCsvPreviewReady = false;
let activeSettlementDetails = null;
let deductionWorkflowPeriods = [];
let deductionWorkflowRegister = [];
let deductionWorkflowItems = [];
let acctPage = 1;
let acctSalarySchedules = [];
let acctDefaultCreditPercentage = 25;
let acctSalarySchedulesPromise = null;

function aEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function aDataState(title, detail = "Try adjusting the search or filters.", icon = "bi-inbox") {
    return `
        <div class="data-state">
            <i class="bi ${aEscape(icon)}" aria-hidden="true"></i>
            <div>
                <strong>${aEscape(title)}</strong>
                ${detail ? `<small>${aEscape(detail)}</small>` : ""}
            </div>
        </div>`;
}

function aMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

async function loadAcctSalarySchedules() {
    if (acctSalarySchedulesPromise) return acctSalarySchedulesPromise;
    acctSalarySchedulesPromise = fetch("/accounting/salary-schedules")
        .then((response) => response.json())
        .then((data) => {
            if (!data || data.status !== "success" || !Array.isArray(data.data) || !data.data.length) {
                throw new Error(data?.message || "Salary schedules are unavailable. Apply the latest development database migration, then refresh this page.");
            }
            acctSalarySchedules = data.data;
            acctDefaultCreditPercentage = Number(data.default_credit_percentage ?? 25);
            return acctSalarySchedules;
        });
    return acctSalarySchedulesPromise;
}

function selectedAcctSalarySchedule() {
    const id = Number(document.getElementById("employee-salary-schedule").value || 0);
    return acctSalarySchedules.find((schedule) => Number(schedule.id) === id) || null;
}

function populateAcctSalaryProfile(profile = {}) {
    const scheduleEl = document.getElementById("employee-salary-schedule");
    const requestedScheduleId = Number(profile.salary_schedule_id || scheduleEl.value || acctSalarySchedules[0]?.id || 0);
    scheduleEl.innerHTML = acctSalarySchedules.map((schedule) =>
        `<option value="${Number(schedule.id)}">${aEscape(schedule.name)} (${aEscape(schedule.code)})</option>`
    ).join("");
    scheduleEl.value = String(requestedScheduleId || acctSalarySchedules[0]?.id || "");

    const schedule = selectedAcctSalarySchedule();
    const rates = Array.isArray(schedule?.rates) ? schedule.rates : [];
    const grades = [...new Set(rates.map((rate) => Number(rate.salary_grade)))];
    const gradeEl = document.getElementById("employee-salary-grade");
    const requestedGrade = Number(String(profile.salary_grade || gradeEl.value || 11).replace(/\D/g, "")) || 11;
    gradeEl.innerHTML = grades.map((grade) => `<option value="${grade}">SG ${grade}</option>`).join("");
    gradeEl.value = String(grades.includes(requestedGrade) ? requestedGrade : (grades.includes(11) ? 11 : grades[0] || ""));

    const grade = Number(gradeEl.value || 0);
    const steps = rates.filter((rate) => Number(rate.salary_grade) === grade).map((rate) => Number(rate.salary_step));
    const stepEl = document.getElementById("employee-salary-step");
    const requestedStep = Number(profile.salary_step || stepEl.value || 1);
    stepEl.innerHTML = steps.map((step) => `<option value="${step}">Step ${step}</option>`).join("");
    stepEl.value = String(steps.includes(requestedStep) ? requestedStep : steps[0] || "");

    document.getElementById("employee-employment-type").value = profile.employment_type || "plantilla";
    const today = new Date().toISOString().slice(0, 10);
    const defaultEffectiveDate = today < (schedule?.effective_from || today)
        ? schedule.effective_from
        : (schedule?.effective_to && today > schedule.effective_to ? schedule.effective_to : today);
    document.getElementById("employee-salary-effective-date").value = profile.salary_effective_date || defaultEffectiveDate;
    document.getElementById("employee-credit-percentage").value = Number(profile.credit_percentage ?? acctDefaultCreditPercentage);
    refreshDynamicCreditPreview();
}

function aDateTime(value) {
    return window.IbemsFormat?.dateTime(value) || new Date(value).toLocaleString();
}

function csvEscape(value) {
    const text = String(value ?? "");
    if (/[",\r\n]/.test(text)) {
        return `"${text.replace(/"/g, '""')}"`;
    }
    return text;
}

function aCategory(value) {
    const text = String(value || "");
    if (!text) return "-";
    return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
}

function aBool(value) {
    if (typeof value === "boolean") return value;
    if (typeof value === "number") return value === 1;
    return ["1", "true", "t", "yes", "on"].includes(String(value ?? "").trim().toLowerCase());
}

function getDebtStatus(row) {
    const key = String(row?.debt_status || "").trim() || "pending";
    const label = String(row?.debt_status_label || "").trim() || aCategory(key.replace(/_/g, " "));
    const tone = String(row?.debt_status_tone || "").trim() || "info";
    return { key, label, tone };
}

function debtStatusPill(row) {
    const status = getDebtStatus(row);
    return `<span class="acct-debt-status is-${aEscape(status.tone)}">${aEscape(status.label)}</span>`;
}

function setAcctResult(message, type) {
    const el = document.getElementById("acct-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function renderSummary(rows) {
    const list = Array.isArray(rows) ? rows : [];
    const totalDebt = acctDataSummary ? Number(acctDataSummary.employee_debt_total || 0) : list.reduce((sum, row) => sum + Number(row.current_debt || 0), 0);
    document.getElementById("acct-count").textContent = String(list.length);
    document.getElementById("acct-total-debt").textContent = aMoney(totalDebt);
    document.getElementById("acct-tab-employee-count").textContent = String(acctDataSummary?.employee_account_count ?? list.length);
    document.getElementById("acct-tab-advance-count").textContent = String(acctDataSummary?.advance_payment_count ?? acctAdvanceRows.length);
    document.getElementById("acct-tab-operator-count").textContent = String(acctDataSummary?.operator_accountability_count ?? acctOperatorRows.length);
}

function renderSettlementRuns(rows) {
    settlementRunsCache = Array.isArray(rows) ? rows : [];
    const wrap = document.getElementById("settlement-runs-list");
    if (settlementRunsCache.length === 0) {
        wrap.innerHTML = "No previous salary deduction batches.";
        return;
    }

    wrap.innerHTML = settlementRunsCache.map((row) => {
        const notes = row.notes || {};
        const deducted = Number(notes.total_deducted || 0);
        return `
            <button class="history-item run-item w-full rounded-xl border border-slate-200 bg-white p-3 text-left transition hover:bg-slate-50" type="button" data-settle-run="${row.id}">
                <div class="h-top flex items-center justify-between gap-2 text-xs text-slate-500">
                    <span>${aEscape(row.run_month)}</span>
                    <span>${aEscape(aDateTime(row.run_at))}</span>
                </div>
                <div class="h-body mt-1 text-sm text-slate-700">
                    Payroll accounts: ${aEscape(String(row.total_accounts || 0))} |
                    Debt Before: ${aEscape(aMoney(row.total_debt_before || 0))} |
                    Deducted: ${aEscape(aMoney(deducted))}
                </div>
            </button>
        `;
    }).join("");

}

async function openSettlementRunDetails(runId) {
    const modal = document.getElementById("settlement-run-details-modal");
    const head = document.getElementById("settlement-details-head");
    const body = document.getElementById("settlement-details-body");
    const exportBtn = document.getElementById("settlement-details-export");
    const printBtn = document.getElementById("settlement-details-print");
    activeSettlementDetails = null;
    if (exportBtn) exportBtn.disabled = true;
    if (printBtn) printBtn.disabled = true;
    modal.style.display = "grid";
    head.innerHTML = "Loading salary deduction batch details...";
    body.innerHTML = '<tr><td colspan="6">Loading details...</td></tr>';
    document.getElementById("settle-details-count").textContent = "0";
    document.getElementById("settle-details-deducted").textContent = aMoney(0);
    document.getElementById("settle-details-after").textContent = aMoney(0);

    try {
        const response = await fetch(`/accounting/settlement/runs/${Number(runId)}`);
        const data = await response.json();
        if (!data || data.status !== "success") {
            head.innerHTML = '<div class="mode-profile-empty">Failed to load salary deduction batch details.</div>';
            body.innerHTML = '<tr><td colspan="6">No details available.</td></tr>';
            return;
        }

        activeSettlementDetails = data;
        const run = data.run || {};
        const summary = data.summary || {};
        const notes = run.notes || {};
        if (exportBtn) exportBtn.disabled = false;
        if (printBtn) printBtn.disabled = false;

        head.innerHTML = `
            <div><strong>Run Month:</strong> ${aEscape(run.run_month || "-")}</div>
            <div><strong>Run At:</strong> ${aEscape(aDateTime(run.run_at || new Date().toISOString()))}</div>
            <div><strong>Run By:</strong> ${aEscape(run.run_by_name || "Unknown")}</div>
            <div><strong>Notes:</strong> ${aEscape(notes.note || "-")}</div>
        `;

        document.getElementById("settle-details-count").textContent = String(summary.processed_accounts || 0);
        document.getElementById("settle-details-deducted").textContent = aMoney(summary.total_deducted || 0);
        document.getElementById("settle-details-after").textContent = aMoney(summary.total_debt_after || 0);

        const rows = Array.isArray(data.items) ? data.items : [];
        if (rows.length === 0) {
            body.innerHTML = '<tr><td colspan="6">No deducted accounts found for this batch.</td></tr>';
            return;
        }

        body.innerHTML = rows.map((row) => `
            <tr>
                <td>${aEscape(row.name || "-")}<br><small>${aEscape(row.employee_id || "-")}</small></td>
                <td>${aEscape(aCategory(row.category))}</td>
                <td>${aEscape(aMoney(row.monthly_salary || 0))}</td>
                <td>${aEscape(aMoney(row.previous_debt || 0))}</td>
                <td>${aEscape(aMoney(row.deducted_amount || 0))}</td>
                <td>${aEscape(aMoney(row.new_debt || 0))}</td>
            </tr>
        `).join("");
    } catch (error) {
        head.innerHTML = '<div class="mode-profile-empty">Failed to load salary deduction batch details.</div>';
        body.innerHTML = '<tr><td colspan="6">No details available.</td></tr>';
    }
}

function closeSettlementRunDetails() {
    document.getElementById("settlement-run-details-modal").style.display = "none";
    activeSettlementDetails = null;
    document.getElementById("settlement-details-export").disabled = true;
    document.getElementById("settlement-details-print").disabled = true;
}

function exportSettlementDetailsCsv() {
    if (!activeSettlementDetails) return;

    const run = activeSettlementDetails.run || {};
    const summary = activeSettlementDetails.summary || {};
    const rows = Array.isArray(activeSettlementDetails.items) ? activeSettlementDetails.items : [];
    const lines = [
        ["Run Month", run.run_month || ""],
        ["Run At", run.run_at || ""],
        ["Run By", run.run_by_name || ""],
        ["Processed Accounts", summary.processed_accounts || 0],
        ["Total Deducted", Number(summary.total_deducted || 0).toFixed(2)],
        ["Total Debt After", Number(summary.total_debt_after || 0).toFixed(2)],
        [],
        ["Employee ID", "Name", "Category", "Monthly Salary", "Previous Debt", "Deducted", "New Debt"],
        ...rows.map((row) => [
            row.employee_id || "",
            row.name || "",
            aCategory(row.category),
            Number(row.monthly_salary || 0).toFixed(2),
            Number(row.previous_debt || 0).toFixed(2),
            Number(row.deducted_amount || 0).toFixed(2),
            Number(row.new_debt || 0).toFixed(2),
        ]),
    ];

    const csv = lines.map((line) => line.map(csvEscape).join(",")).join("\r\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `salary-deduction-batch-${String(run.run_month || "export")}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

function printSettlementDetails() {
    if (!activeSettlementDetails) return;

    const run = activeSettlementDetails.run || {};
    const summary = activeSettlementDetails.summary || {};
    const rows = Array.isArray(activeSettlementDetails.items) ? activeSettlementDetails.items : [];
    const popup = window.open("", "_blank", "width=980,height=720");
    if (!popup) {
        setAcctResult("Popup blocked. Please allow popups to print settlement details.", "error");
        return;
    }

    popup.document.write(`
        <!doctype html>
        <html>
        <head>
            <title>Salary Deduction Batch ${aEscape(run.run_month || "")}</title>
            <style>
                body { font-family: Arial, sans-serif; color: #0f172a; margin: 24px; }
                h1 { margin: 0 0 4px; font-size: 22px; }
                .meta { color: #475569; margin-bottom: 16px; }
                .summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 16px 0; }
                .card { border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; }
                .card span { display: block; color: #64748b; font-size: 12px; }
                .card strong { font-size: 16px; }
                table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; font-size: 12px; }
                th { background: #f1f5f9; }
            </style>
        </head>
        <body>
            <h1>Salary Deduction Batch Summary</h1>
            <div class="meta">
                Month: ${aEscape(run.run_month || "-")}<br>
                Run At: ${aEscape(aDateTime(run.run_at || new Date().toISOString()))}<br>
                Run By: ${aEscape(run.run_by_name || "Unknown")}
            </div>
            <div class="summary">
                <div class="card"><span>Processed Accounts</span><strong>${aEscape(String(summary.processed_accounts || 0))}</strong></div>
                <div class="card"><span>Total Deducted</span><strong>${aEscape(aMoney(summary.total_deducted || 0))}</strong></div>
                <div class="card"><span>Total Debt After</span><strong>${aEscape(aMoney(summary.total_debt_after || 0))}</strong></div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Category</th>
                        <th>Monthly Salary</th>
                        <th>Previous Debt</th>
                        <th>Deducted</th>
                        <th>New Debt</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.map((row) => `
                        <tr>
                            <td>${aEscape(row.name || "-")}<br>${aEscape(row.employee_id || "-")}</td>
                            <td>${aEscape(aCategory(row.category))}</td>
                            <td>${aEscape(aMoney(row.monthly_salary || 0))}</td>
                            <td>${aEscape(aMoney(row.previous_debt || 0))}</td>
                            <td>${aEscape(aMoney(row.deducted_amount || 0))}</td>
                            <td>${aEscape(aMoney(row.new_debt || 0))}</td>
                        </tr>
                    `).join("")}
                </tbody>
            </table>
        </body>
        </html>
    `);
    popup.document.close();
    popup.focus();
    popup.print();
}

function renderRows(rows) {
    const body = document.getElementById("acct-body");
    const countText = document.getElementById("acct-count-text");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = aDataState("No accounting records found");
        document.getElementById("acct-pager").innerHTML = "";
        if (countText) countText.textContent = "Showing 0 records";
        renderSummary([]);
        return;
    }

    const allRows = [...rows];
    const [sortBy, sortDir] = (document.getElementById("acct-sort").value || "name:asc").split(":");
    const direction = sortDir === "desc" ? -1 : 1;
    allRows.sort((left, right) => {
        let a;
        let b;
        if (sortBy === "debt" || sortBy === "date") {
            a = sortBy === "date" ? String(left.updated_at || left.created_at || "") : Number(left.current_debt || 0);
            b = sortBy === "date" ? String(right.updated_at || right.created_at || "") : Number(right.current_debt || 0);
        } else if (sortBy === "credit") {
            a = Number(left.credit_limit || 0);
            b = Number(right.credit_limit || 0);
        } else {
            a = String(left.name || "").toLowerCase();
            b = String(right.name || "").toLowerCase();
        }
        return (typeof a === "number" ? a - b : a.localeCompare(b)) * direction;
    });
    const pageSize = Math.max(10, Number(document.getElementById("acct-page-size").value || 10));
    const totalPages = Math.max(1, Math.ceil(allRows.length / pageSize));
    acctPage = Math.min(acctPage, totalPages);
    const pageRows = allRows.slice((acctPage - 1) * pageSize, acctPage * pageSize);
    if (countText) {
        const needsSetup = rows.filter((row) => !aBool(row.financial_profile_configured)).length;
        countText.textContent = `Showing ${pageRows.length} of ${rows.length} eligible employees${needsSetup ? ` · ${needsSetup} need financial setup` : ""}`;
    }

    body.innerHTML = pageRows.map((row) => `
        <article data-row-user="${row.user_id}" class="acct-record-row acct-row-clickable interactive-record-row flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
            <div class="acct-person flex min-w-0 items-center gap-3">
                ${window.IbemsAvatar.html(row.name, row.profile_image_url, "acct-avatar h-11 w-11 flex-none rounded-full border border-slate-200 bg-slate-50")}
                <div class="acct-person-meta min-w-0">
                    <div class="acct-name-line flex flex-wrap items-center gap-2">
                        <strong class="text-base font-bold text-slate-900">${aEscape(row.name)}</strong>
                        <span class="table-chip acct-chip">${aEscape(aCategory(row.user_type))}</span>
                        <span class="table-status acct-status ${aBool(row.is_active) ? "is-active" : "is-inactive"}">${aBool(row.is_active) ? "Active" : "Inactive"}</span>
                        ${aBool(row.financial_profile_configured) ? debtStatusPill(row) : '<span class="acct-debt-status is-warning">Needs Financial Setup</span>'}
                    </div>
                    <div class="acct-subline truncate text-sm text-slate-500">${aEscape(row.employee_id || "-")} | ${aEscape(row.email)}</div>
                </div>
            </div>
            <div class="acct-finance flex flex-wrap items-center justify-end gap-4">
                ${!aBool(row.financial_profile_configured) ? '<div class="text-sm font-semibold text-amber-700">Open to set salary and credit limit</div>' : `
                <div class="acct-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Current Debt</span>
                    <strong class="text-sm font-semibold ${Number(row.current_debt || 0) > 0 ? "acct-money-debt text-rose-600" : "text-slate-900"}">${aEscape(aMoney(row.current_debt))}</strong>
                    <div class="table-debt-bar acct-debt-bar"><i style="width:${Math.min(100, (Number(row.current_debt || 0) / Math.max(1, Number(row.credit_limit || 0))) * 100)}%"></i></div>
                </div>
                <div class="acct-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Credit Limit (${aEscape(Number(row.credit_percentage ?? acctDefaultCreditPercentage))}%)</span>
                    <strong class="text-sm font-semibold text-slate-900">${aEscape(aMoney(row.credit_limit))}</strong>
                </div>
                <div class="acct-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Available</span>
                    <strong class="text-sm font-semibold text-slate-900">${aEscape(aMoney(row.available_credit))}</strong>
                </div>
                `}
            </div>
        </article>
    `).join("");

    renderSummary(rows);
    renderAcctPager(totalPages);
}

function renderAcctPager(totalPages) {
    document.getElementById("acct-pager").innerHTML = `
        <button class="secondary-btn btn-sm" type="button" data-page="${acctPage - 1}" ${acctPage <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left"></i> Previous</button>
        <span>Page ${acctPage} of ${totalPages}</span>
        <button class="secondary-btn btn-sm" type="button" data-page="${acctPage + 1}" ${acctPage >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right"></i></button>`;
}

function renderCashbookRows(rows, type) {
    const body = document.getElementById("acct-body");
    const countText = document.getElementById("acct-count-text");
    const list = Array.isArray(rows) ? [...rows] : [];
    const isAdvance = type === "advance";
    const title = isAdvance ? "Direct payment received" : "Store operator shortage";
    const emptyText = isAdvance
        ? "No direct store payments recorded yet."
        : "No approved store operator shortages recorded yet.";

    if (list.length === 0) {
        body.innerHTML = aDataState(emptyText, "This queue will update when qualifying activity is recorded.");
        document.getElementById("acct-pager").innerHTML = "";
        if (countText) countText.textContent = "Showing 0 records";
        renderSummary(acctRows);
        return;
    }

    const [sortBy, sortDir] = (document.getElementById("acct-sort").value || "date:desc").split(":");
    const direction = sortDir === "asc" ? 1 : -1;
    list.sort((left, right) => {
        if (sortBy === "name") return String(left.name || "").localeCompare(String(right.name || "")) * (sortDir === "desc" ? -1 : 1);
        if (sortBy === "date") return String(left.created_at || "").localeCompare(String(right.created_at || "")) * direction;
        return (Number(left.amount || 0) - Number(right.amount || 0)) * direction;
    });
    const pageSize = Math.max(10, Number(document.getElementById("acct-page-size").value || 10));
    const totalPages = Math.max(1, Math.ceil(list.length / pageSize));
    acctPage = Math.min(acctPage, totalPages);
    const pageRows = list.slice((acctPage - 1) * pageSize, acctPage * pageSize);
    if (countText) countText.textContent = `Showing ${pageRows.length} of ${list.length} ${isAdvance ? "direct payments" : "store operator shortages"}`;

    body.innerHTML = pageRows.map((row) => {
        const store = row.store_name || row.meta?.store_name || "-";
        const channel = row.channel ? ` | ${aCategory(row.channel)}` : "";
        return `
            <article class="acct-record-row acct-cashbook-row flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
                <div class="acct-person flex min-w-0 items-center gap-3">
                    <div class="acct-avatar inline-flex h-11 w-11 flex-none items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-sm font-bold ${isAdvance ? "text-emerald-700" : "text-rose-700"}">
                        <i class="bi ${isAdvance ? "bi-wallet2" : "bi-exclamation-octagon"}"></i>
                    </div>
                    <div class="acct-person-meta min-w-0">
                        <div class="acct-name-line flex flex-wrap items-center gap-2">
                            <strong class="text-base font-bold text-slate-900">${aEscape(row.name)}</strong>
                            <span class="table-chip acct-chip">${aEscape(title)}</span>
                            <span class="acct-debt-status is-${isAdvance ? "success" : "danger"}">${aEscape(isAdvance ? "Debt Reduced" : "Needs Accounting Follow-up")}</span>
                        </div>
                        <div class="acct-subline truncate text-sm text-slate-500">${aEscape(row.employee_id || "-")} | ${aEscape(row.email || "-")}</div>
                        <div class="acct-subline truncate text-sm text-slate-500">${aEscape(store)}${aEscape(channel)} | ${aEscape(aDateTime(row.created_at))}</div>
                    </div>
                </div>
                <div class="acct-finance flex flex-wrap items-center justify-end gap-4">
                    <div class="acct-fin-kv grid gap-0.5">
                        <span class="text-xs text-slate-500">${isAdvance ? "Payment Amount" : "Shortage Amount"}</span>
                        <strong class="text-sm font-semibold ${isAdvance ? "text-emerald-700" : "text-rose-600"}">${aEscape(aMoney(row.amount))}</strong>
                    </div>
                    <div class="acct-fin-kv grid gap-0.5">
                        <span class="text-xs text-slate-500">Debt Before</span>
                        <strong class="text-sm font-semibold text-slate-900">${aEscape(aMoney(row.debt_before))}</strong>
                    </div>
                    <div class="acct-fin-kv grid gap-0.5">
                        <span class="text-xs text-slate-500">Debt After</span>
                        <strong class="text-sm font-semibold text-slate-900">${aEscape(aMoney(row.debt_after))}</strong>
                    </div>
                </div>
            </article>
        `;
    }).join("");

    renderSummary(acctRows);
    renderAcctPager(totalPages);
}

function renderActiveAccountingTab() {
    document.querySelectorAll("[data-acct-tab]").forEach((button) => {
        const isActive = button.getAttribute("data-acct-tab") === activeAcctTab;
        button.classList.toggle("is-active", isActive);
        button.setAttribute("aria-selected", isActive ? "true" : "false");
    });

    if (activeAcctTab === "advance-payments") {
        renderCashbookRows(acctAdvanceRows, "advance");
        return;
    }
    if (activeAcctTab === "operator-accountabilities") {
        renderCashbookRows(acctOperatorRows, "operator");
        return;
    }
    renderRows(getMainFilteredRows());
}
