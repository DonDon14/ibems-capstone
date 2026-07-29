let acctRows = [];
let acctAdvanceRows = [];
let acctOperatorRows = [];
let acctDataSummary = null;
let activeAcctTab = "employee-debts";
let modeFilteredRows = [];
let modeSelectedUserId = null;
let employeeModalUserId = null;
let employeeModalProfile = null;
let settlementPreview = null;
let settlementPreviewQuery = "";
let settlementSelectedUserIds = new Set();
let settlementRunsCache = [];
let importCsvPreviewReady = false;
let activeSettlementDetails = null;
let deductionWorkflowPeriods = [];

function aEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function aMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
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
    document.getElementById("acct-tab-employee-count").textContent = String(acctDataSummary?.employee_debt_accounts ?? list.filter((row) => Number(row.current_debt || 0) > 0).length);
    document.getElementById("acct-tab-advance-count").textContent = String(acctDataSummary?.advance_payment_count ?? acctAdvanceRows.length);
    document.getElementById("acct-tab-operator-count").textContent = String(acctDataSummary?.operator_accountability_count ?? acctOperatorRows.length);
}

function renderSettlementSummary(summary) {
    const safe = summary || {};
    document.getElementById("settle-candidate-count").textContent = String(safe.candidate_count || 0);
    document.getElementById("settle-processable-count").textContent = String(safe.processable_count || 0);
    document.getElementById("settle-total-before").textContent = aMoney(safe.total_debt_before || 0);
    document.getElementById("settle-total-deducted").textContent = aMoney(safe.total_deductible || 0);
}

function renderSettlementPreviewRows(rows) {
    const body = document.getElementById("settlement-preview-body");
    const countText = document.getElementById("settlement-preview-count");
    const sourceRows = Array.isArray(rows) ? rows : [];
    const query = settlementPreviewQuery.trim().toLowerCase();
    const list = query
        ? sourceRows.filter((row) => {
            const haystack = `${row.name || ""} ${row.employee_id || ""} ${row.category || ""}`.toLowerCase();
            return haystack.includes(query);
        })
        : sourceRows;

    if (countText) {
        countText.textContent = sourceRows.length > 0
            ? `Showing ${list.length} of ${sourceRows.length} previewed employees.`
            : "Preview the batch to show employees for deduction.";
    }

    if (sourceRows.length === 0) {
        body.innerHTML = '<tr><td colspan="6">No payroll deduction candidates.</td></tr>';
        return;
    }

    if (list.length === 0) {
        body.innerHTML = '<tr><td colspan="6">No employees match this search.</td></tr>';
        return;
    }

    body.innerHTML = list.map((row) => `
        <tr>
            <td>${aEscape(row.name)}<br><small>${aEscape(row.employee_id || "-")}</small></td>
            <td>${aEscape(aCategory(row.category))}</td>
            <td>${aEscape(aMoney(row.monthly_salary || 0))}</td>
            <td>${aEscape(aMoney(row.current_debt || 0))}</td>
            <td>${aEscape(aMoney(row.deductible_amount || 0))}</td>
            <td>${aEscape(aMoney(row.new_debt || 0))}</td>
        </tr>
    `).join("");
}

function getProcessableSettlementRows() {
    const rows = Array.isArray(settlementPreview?.accounts) ? settlementPreview.accounts : [];
    return rows.filter((row) => Number(row.deductible_amount || 0) > 0);
}

function getSelectedSettlementRows() {
    return getProcessableSettlementRows().filter((row) => settlementSelectedUserIds.has(Number(row.user_id)));
}

function renderSettlementRuns(rows) {
    settlementRunsCache = Array.isArray(rows) ? rows : [];
    const wrap = document.getElementById("settlement-runs-list");
    if (settlementRunsCache.length === 0) {
        wrap.innerHTML = "No previous salary deduction batches.";
        syncSettlementApplyState();
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

    syncSettlementApplyState();
}

function hasSettlementRunForMonth(runMonth) {
    if (!runMonth) return false;
    return settlementRunsCache.some((row) => String(row.run_month || "") === runMonth);
}

function getSettlementRunForMonth(runMonth) {
    if (!runMonth) return null;
    return settlementRunsCache.find((row) => String(row.run_month || "") === runMonth) || null;
}

function syncSettlementApplyState() {
    const button = document.getElementById("settlement-apply-btn");
    const runMonth = (document.getElementById("settlement-run-month").value || "").trim();
    const runMonthLabel = runMonth || "-";
    const hasProcessable = Number(settlementPreview?.summary?.processable_count || 0) > 0;
    const existingRun = getSettlementRunForMonth(runMonth);
    const alreadyApplied = !!existingRun;
    const existingWrap = document.getElementById("settlement-existing-run");
    const existingText = document.getElementById("settlement-existing-run-text");
    const existingView = document.getElementById("settlement-existing-run-view");

    button.disabled = !hasProcessable || alreadyApplied;
    button.textContent = alreadyApplied ? "Already Applied" : "Review & Confirm";

    if (alreadyApplied) {
        existingWrap.classList.remove("hidden");
        existingText.textContent = `Salary deduction batch for ${runMonthLabel} already applied.`;
        existingView.dataset.settleRun = String(existingRun.id);
    } else {
        existingWrap.classList.add("hidden");
        existingText.textContent = "";
        delete existingView.dataset.settleRun;
    }
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
        body.innerHTML = '<div class="acct-empty rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">No records found.</div>';
        if (countText) countText.textContent = "Showing 0 records";
        renderSummary([]);
        return;
    }

    if (countText) countText.textContent = `Showing ${rows.length} records`;

    body.innerHTML = rows.map((row) => `
        <article data-row-user="${row.user_id}" class="acct-record-row acct-row-clickable flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:-translate-y-0.5 hover:bg-slate-50">
            <div class="acct-person flex min-w-0 items-center gap-3">
                <div class="acct-avatar inline-flex h-11 w-11 flex-none items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-sm font-bold text-blue-700">${aEscape(String(row.name || "U").split(" ").filter(Boolean).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join("") || "U")}</div>
                <div class="acct-person-meta min-w-0">
                    <div class="acct-name-line flex flex-wrap items-center gap-2">
                        <strong class="text-base font-bold text-slate-900">${aEscape(row.name)}</strong>
                        <span class="table-chip acct-chip">${aEscape(aCategory(row.user_type))}</span>
                        <span class="table-status acct-status ${Number(row.is_active || 0) === 1 ? "is-active" : "is-inactive"}">${Number(row.is_active || 0) === 1 ? "Active" : "Inactive"}</span>
                        ${debtStatusPill(row)}
                    </div>
                    <div class="acct-subline truncate text-sm text-slate-500">${aEscape(row.employee_id || "-")} | ${aEscape(row.email)}</div>
                </div>
            </div>
            <div class="acct-finance flex flex-wrap items-center justify-end gap-4">
                <div class="acct-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Current Debt</span>
                    <strong class="text-sm font-semibold ${Number(row.current_debt || 0) > 0 ? "acct-money-debt text-rose-600" : "text-slate-900"}">${aEscape(aMoney(row.current_debt))}</strong>
                    <div class="table-debt-bar acct-debt-bar"><i style="width:${Math.min(100, (Number(row.current_debt || 0) / Math.max(1, Number(row.credit_limit || 0))) * 100)}%"></i></div>
                </div>
                <div class="acct-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Credit Limit</span>
                    <strong class="text-sm font-semibold text-slate-900">${aEscape(aMoney(row.credit_limit))}</strong>
                </div>
                <div class="acct-fin-kv grid gap-0.5">
                    <span class="text-xs text-slate-500">Available</span>
                    <strong class="text-sm font-semibold text-slate-900">${aEscape(aMoney(row.available_credit))}</strong>
                </div>
            </div>
        </article>
    `).join("");

    renderSummary(rows);
}

function renderCashbookRows(rows, type) {
    const body = document.getElementById("acct-body");
    const countText = document.getElementById("acct-count-text");
    const list = Array.isArray(rows) ? rows : [];
    const isAdvance = type === "advance";
    const title = isAdvance ? "Direct payment received" : "Store operator shortage";
    const emptyText = isAdvance
        ? "No direct store payments recorded yet."
        : "No approved store operator shortages recorded yet.";

    if (list.length === 0) {
        body.innerHTML = `<div class="acct-empty rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">${emptyText}</div>`;
        if (countText) countText.textContent = "Showing 0 records";
        renderSummary(acctRows);
        return;
    }

    if (countText) countText.textContent = `Showing ${list.length} ${isAdvance ? "direct payments" : "store operator shortages"}`;

    body.innerHTML = list.map((row) => {
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

    const response = await fetch(`/accounting/debts/data?${params.toString()}`);
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
    renderModeResults();
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
    settlementPreview = null;
    settlementPreviewQuery = "";
    document.getElementById("settlement-run-modal").style.display = "grid";
    document.getElementById("settlement-run-month").value = new Date().toISOString().slice(0, 7);
    document.getElementById("settlement-notes").value = "";
    document.getElementById("settlement-preview-search").value = "";
    renderSettlementSummary(null);
    renderSettlementPreviewRows([]);
    syncSettlementApplyState();
    loadSettlementRuns().catch(() => {
        renderSettlementRuns([]);
    });
}

function closeSettlementModal() {
    document.getElementById("settlement-run-modal").style.display = "none";
    settlementPreview = null;
    settlementPreviewQuery = "";
    closeSettlementConfirmModal();
}

async function previewSettlementRun() {
    const runMonth = (document.getElementById("settlement-run-month").value || "").trim();
    if (!runMonth) {
        setAcctResult("Please choose a run month.", "error");
        return;
    }

    const response = await fetch(`/accounting/settlement/preview?run_month=${encodeURIComponent(runMonth)}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        setAcctResult(data?.message || "Failed to preview salary deduction batch.", "error");
        settlementPreview = null;
        document.getElementById("settlement-apply-btn").disabled = true;
        renderSettlementSummary(null);
        renderSettlementPreviewRows([]);
        return;
    }

    settlementPreview = data;
    renderSettlementSummary(data.summary || {});
    renderSettlementPreviewRows(data.accounts || []);
    syncSettlementApplyState();

    if (hasSettlementRunForMonth(runMonth)) {
        setAcctResult(`Salary deduction batch for ${runMonth} already exists. Open it from the recent batch list.`, "error");
        return;
    }

    setAcctResult("Salary deduction preview ready.", "ok");
}

function applySettlementRun() {
    openSettlementConfirmModal();
}

async function confirmSettlementRunApply() {
    if (!settlementPreview) {
        setAcctResult("Please run preview first.", "error");
        return;
    }

    const runMonth = (document.getElementById("settlement-run-month").value || "").trim();
    const notes = (document.getElementById("settlement-notes").value || "").trim();
    const selectedUserIds = Array.from(settlementSelectedUserIds);
    if (selectedUserIds.length === 0) {
        setAcctResult("Confirm at least one employee before applying deduction.", "error");
        return;
    }

    const button = document.getElementById("confirm-settlement-apply");
    button.disabled = true;
    const oldText = button.textContent;
    button.textContent = "Applying...";

    try {
        const response = await fetch("/accounting/settlement/apply", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                run_month: runMonth,
                notes,
                selected_user_ids: selectedUserIds,
            }),
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            setAcctResult(data?.message || "Failed to apply salary deduction batch.", "error");
            return;
        }

        setAcctResult(
            `Salary deduction batch applied (${data.run_month}). Processed: ${data.total_accounts}, Deducted: ${aMoney(data.total_deducted)}.`,
            "ok"
        );
        await loadData();
        await loadSettlementRuns();
        closeSettlementConfirmModal();
        closeSettlementModal();
    } catch (error) {
        setAcctResult("Salary deduction batch request failed.", "error");
    } finally {
        button.textContent = oldText;
        button.disabled = false;
        syncSettlementApplyState();
    }
}

async function loadProfile(userId) {
    const response = await fetch(`/accounting/debts/profile?user_id=${userId}`);
    const data = await response.json();
    if (!data || data.status !== "success" || !data.data) return null;
    return data.data;
}

async function loadHistory(userId) {
    const response = await fetch(`/accounting/debts/history?user_id=${userId}&limit=20`);
    const data = await response.json();
    if (!data || data.status !== "success" || !Array.isArray(data.data)) return [];
    return data.data;
}

function buildProfileHtml(p) {
    return `
        <div class="mode-profile-card rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h5 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Selected Person</h5>
                ${debtStatusPill(p)}
            </div>
            <div class="mode-profile-grid grid gap-2 md:grid-cols-2">
                <div class="kv rounded-lg border border-slate-200 bg-white p-2.5"><span class="block text-xs text-slate-500">Name</span><strong class="text-sm text-slate-900">${aEscape(p.name)}</strong></div>
                <div class="kv rounded-lg border border-slate-200 bg-white p-2.5"><span class="block text-xs text-slate-500">Employee ID</span><strong class="text-sm text-slate-900">${aEscape(p.employee_id || "-")}</strong></div>
                <div class="kv rounded-lg border border-slate-200 bg-white p-2.5"><span class="block text-xs text-slate-500">Email</span><strong class="text-sm text-slate-900">${aEscape(p.email)}</strong></div>
                <div class="kv rounded-lg border border-slate-200 bg-white p-2.5"><span class="block text-xs text-slate-500">Category</span><strong class="text-sm text-slate-900">${aEscape(aCategory(p.user_type))}</strong></div>
                <div class="kv rounded-lg border border-slate-200 bg-white p-2.5"><span class="block text-xs text-slate-500">Current Debt</span><strong class="text-sm text-slate-900">${aEscape(aMoney(p.current_debt))}</strong></div>
                <div class="kv rounded-lg border border-slate-200 bg-white p-2.5"><span class="block text-xs text-slate-500">Credit Limit</span><strong class="text-sm text-slate-900">${aEscape(aMoney(p.credit_limit))}</strong></div>
                <div class="kv rounded-lg border border-slate-200 bg-white p-2.5"><span class="block text-xs text-slate-500">Available Credit</span><strong class="text-sm text-slate-900">${aEscape(aMoney(p.available_credit))}</strong></div>
                <div class="kv rounded-lg border border-slate-200 bg-white p-2.5"><span class="block text-xs text-slate-500">Updated At</span><strong class="text-sm text-slate-900">${aEscape(aDateTime(p.updated_at))}</strong></div>
            </div>
        </div>
    `;
}

function buildHistoryHtml(rows) {
    if (!Array.isArray(rows) || rows.length === 0) return "No history available.";

    return rows.map((row) => {
        const payload = row.payload || {};
        let detail = "";
        if (row.action === "ACCOUNTING_DEDUCT_DEBT" || row.action === "ACCOUNTING_DEDUCT_FULL_DEBT") {
            detail = `Deducted ${aMoney(payload.deducted_amount)} (Debt: ${aMoney(payload.previous_debt)} -> ${aMoney(payload.new_debt)})`;
        } else if (row.action === "STORE_DEBT_REPAYMENT") {
            detail = `Store payment ${aMoney(payload.paid_amount)} (Debt: ${aMoney(payload.previous_debt)} -> ${aMoney(payload.new_debt)})`;
        } else if (row.action === "ACCOUNTING_UPDATE_CREDIT_LIMIT") {
            detail = `Credit Limit: ${aMoney(payload.previous_credit_limit)} -> ${aMoney(payload.new_credit_limit)}`;
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

async function updateCreditLimit(userId, creditLimit, reason) {
    const response = await fetch("/accounting/debts/credit-limit", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            user_id: Number(userId),
            credit_limit: Number(creditLimit),
            reason,
        }),
    });
    return response.json();
}

function getModeFilters() {
    const q = (document.getElementById("mode-search").value || "").trim().toLowerCase();
    const debtOnly = document.getElementById("mode-debt-only").checked;
    return { q, debtOnly };
}

function renderModeResults() {
    const { q, debtOnly } = getModeFilters();
    const wrap = document.getElementById("mode-results");
    modeFilteredRows = acctRows.filter((row) => {
        if (debtOnly && Number(row.current_debt || 0) <= 0) return false;
        if (!q) return true;
        const haystack = `${row.name || ""} ${row.email || ""} ${row.employee_id || ""}`.toLowerCase();
        return haystack.includes(q);
    });

    if (modeFilteredRows.length === 0) {
        wrap.innerHTML = '<div class="mode-profile-empty rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">No matching records.</div>';
        return;
    }

    wrap.innerHTML = modeFilteredRows.map((row) => `
        <button class="mode-item w-full rounded-xl border p-3 text-left transition ${modeSelectedUserId === Number(row.user_id) ? "is-active border-blue-300 bg-blue-50" : "border-slate-200 bg-white hover:bg-slate-50"}" type="button" data-mode-user="${row.user_id}">
            <span class="name block text-sm font-semibold text-slate-900">${aEscape(row.name)}</span>
            <span class="meta mt-0.5 block text-xs text-slate-500">${aEscape(row.employee_id || "-")} | Debt: ${aEscape(aMoney(row.current_debt))}</span>
        </button>
    `).join("");
}

async function applyDeduction(userId, amount, reason) {
    const response = await fetch("/accounting/debts/deduct", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            user_id: Number(userId),
            amount,
            reason,
        }),
    });
    return response.json();
}

async function applyFullDeduction(userId, reason) {
    const response = await fetch("/accounting/debts/deduct-full", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            user_id: Number(userId),
            reason,
        }),
    });
    return response.json();
}

function openMode() {
    document.getElementById("deduction-mode-modal").style.display = "grid";
    document.getElementById("mode-search").focus();
    renderModeResults();
}

function closeMode() {
    document.getElementById("deduction-mode-modal").style.display = "none";
    modeSelectedUserId = null;
    document.getElementById("mode-manual-box").classList.add("hidden");
    document.getElementById("mode-actions").classList.add("hidden");
    document.getElementById("mode-history-wrap").classList.add("hidden");
    document.getElementById("mode-profile").innerHTML = "Select a person from the left list.";
}

async function selectModeUser(userId) {
    modeSelectedUserId = Number(userId);
    renderModeResults();

    const profile = await loadProfile(modeSelectedUserId);
    if (!profile) {
        document.getElementById("mode-profile").innerHTML = '<div class="mode-profile-empty">Unable to load profile.</div>';
        return;
    }

    document.getElementById("mode-profile").innerHTML = buildProfileHtml(profile);
    document.getElementById("mode-actions").classList.remove("hidden");
    document.getElementById("mode-history-wrap").classList.remove("hidden");
    document.getElementById("mode-deduct-full").disabled = Number(profile.current_debt || 0) <= 0;

    const history = await loadHistory(modeSelectedUserId);
    document.getElementById("mode-history-list").innerHTML = buildHistoryHtml(history);
}

async function openEmployeeModal(userId) {
    employeeModalUserId = Number(userId);
    const modal = document.getElementById("employee-modal");
    const profileEl = document.getElementById("employee-modal-profile");
    const historyEl = document.getElementById("employee-modal-history");

    modal.style.display = "grid";
    profileEl.innerHTML = "Loading profile...";
    historyEl.innerHTML = "Loading history...";
    document.getElementById("employee-modal-actions").classList.add("hidden");
    document.getElementById("employee-limit-box").classList.add("hidden");

    employeeModalProfile = await loadProfile(employeeModalUserId);
    if (!employeeModalProfile) {
        profileEl.innerHTML = '<div class="mode-profile-empty">Unable to load profile.</div>';
        historyEl.innerHTML = "No history available.";
        return;
    }

    profileEl.innerHTML = buildProfileHtml(employeeModalProfile);
    document.getElementById("employee-limit-current").textContent = aMoney(employeeModalProfile.credit_limit || 0);
    document.getElementById("employee-limit-value").value = Number(employeeModalProfile.credit_limit || 0).toFixed(2);
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
            <span>${aEscape(aCategory(row.user_type))} | Salary ${aEscape(aMoney(row.monthly_salary || 0))}${row.credit_limit !== null ? ` | Credit ${aEscape(aMoney(row.credit_limit || 0))}` : ""}</span>
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

function openSettlementConfirmModal() {
    if (!settlementPreview) {
        setAcctResult("Please preview deductions first.", "error");
        return;
    }

    const runMonth = (document.getElementById("settlement-run-month").value || "").trim();
    const rows = getProcessableSettlementRows();
    if (rows.length === 0) {
        setAcctResult("No employees have deductible balances in this preview.", "error");
        return;
    }

    settlementSelectedUserIds = new Set(rows.map((row) => Number(row.user_id)));
    document.getElementById("settlement-confirm-select-all").checked = true;
    document.getElementById("settlement-confirm-modal").dataset.runMonth = runMonth;
    renderSettlementConfirmRows();
    document.getElementById("settlement-confirm-modal").style.display = "grid";
}

function renderSettlementConfirmRows() {
    const rows = getProcessableSettlementRows();
    const selectedRows = getSelectedSettlementRows();
    const runMonth = document.getElementById("settlement-confirm-modal").dataset.runMonth || "";
    const totalDeducted = selectedRows.reduce((sum, row) => sum + Number(row.deductible_amount || 0), 0);
    const summary = document.getElementById("settlement-confirm-summary");
    const body = document.getElementById("settlement-confirm-body");
    summary.innerHTML = `
        <strong>${aEscape(runMonth || "Selected month")}</strong> salary deduction batch will deduct
        <strong>${aEscape(aMoney(totalDeducted))}</strong> from
        <strong>${aEscape(String(selectedRows.length))}</strong> selected employee${selectedRows.length === 1 ? "" : "s"}.
        Confirm each employee below before applying.
    `;
    document.getElementById("confirm-settlement-apply").disabled = selectedRows.length === 0;
    body.innerHTML = rows.map((row) => `
        <tr>
            <td>
                <input class="settlement-confirm-check" type="checkbox" data-settle-user="${aEscape(row.user_id)}" ${settlementSelectedUserIds.has(Number(row.user_id)) ? "checked" : ""} aria-label="Confirm deduction for ${aEscape(row.name || "employee")}">
            </td>
            <td>${aEscape(row.name || "-")}<br><small>${aEscape(row.employee_id || "-")}</small></td>
            <td>${aEscape(aCategory(row.category))}</td>
            <td>${aEscape(aMoney(row.current_debt || 0))}</td>
            <td><strong>${aEscape(aMoney(row.deductible_amount || 0))}</strong></td>
            <td>${aEscape(aMoney(row.new_debt || 0))}</td>
        </tr>
    `).join("");

    const selectAll = document.getElementById("settlement-confirm-select-all");
    selectAll.checked = rows.length > 0 && selectedRows.length === rows.length;
    selectAll.indeterminate = selectedRows.length > 0 && selectedRows.length < rows.length;
}

function closeSettlementConfirmModal() {
    document.getElementById("settlement-confirm-modal").style.display = "none";
    settlementSelectedUserIds = new Set();
    delete document.getElementById("settlement-confirm-modal").dataset.runMonth;
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

function renderWorkflowCandidates() {
    const container = document.getElementById("workflow-candidates");
    const rows = acctRows.filter((row) => Number(row.current_debt || 0) > 0);
    container.innerHTML = rows.length ? rows.map((row) => `
        <label class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:grid-cols-[auto_minmax(0,1fr)_170px] sm:items-center">
            <input type="checkbox" class="workflow-candidate-check h-4 w-4" data-workflow-user="${aEscape(row.user_id)}">
            <span>
                <strong class="block text-sm text-slate-900">${aEscape(row.name || "Employee")}</strong>
                <small class="text-slate-500">${aEscape(row.employee_id || "-")} · Current debt ${aEscape(aMoney(row.current_debt || 0))}</small>
            </span>
            <input type="number" class="workflow-request-amount h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" min="0.01" max="${aEscape(row.current_debt || 0)}" step="0.01" value="${aEscape(Number(row.current_debt || 0).toFixed(2))}" aria-label="Requested amount for ${aEscape(row.name || "employee")}">
        </label>
    `).join("") : '<div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm text-slate-500">No active faculty/staff debt accounts are available.</div>';
}

function workflowStatusLabel(value) {
    return aCategory(String(value || "pending").replace(/_/g, " "));
}

function renderWorkflowResults(items) {
    const section = document.getElementById("workflow-results-section");
    const container = document.getElementById("workflow-results");
    section.classList.remove("hidden");
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
                    <div class="mt-3 grid gap-2 md:grid-cols-[150px_210px_minmax(200px,1fr)_auto]">
                        <input class="workflow-confirmed-amount h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" type="number" min="0" max="${aEscape(item.requested_amount || 0)}" step="0.01" value="${aEscape(Number(item.requested_amount || 0).toFixed(2))}" aria-label="Confirmed amount">
                        <select class="workflow-reason h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" aria-label="Result reason">
                            <option value="">No exception</option>
                            <option value="insufficient_salary">Insufficient salary</option>
                            <option value="not_deducted">Not deducted</option>
                            <option value="employee_not_found">Employee not found</option>
                            <option value="duplicate">Duplicate</option>
                            <option value="returned_for_correction">Returned for correction</option>
                        </select>
                        <input class="workflow-reference h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" maxlength="120" placeholder="Official payroll reference" aria-label="Official payroll reference">
                        <button type="button" class="workflow-confirm-result inline-flex h-10 items-center justify-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">Confirm</button>
                    </div>
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
    const query = selectedBefore?.batch_id ? `?batch_id=${encodeURIComponent(selectedBefore.batch_id)}` : "";
    const response = await fetch(`/accounting/deduction-workflow${query}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        setWorkflowMessage(data?.message || "Unable to load deduction workflow.", "error");
        return;
    }

    deductionWorkflowPeriods = Array.isArray(data.periods) ? data.periods : [];
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
            const detailResponse = await fetch(`/accounting/deduction-workflow?batch_id=${encodeURIComponent(selected.batch_id)}`);
            const detail = await detailResponse.json();
            renderWorkflowResults(Array.isArray(detail.items) ? detail.items : []);
        } else {
            renderWorkflowResults(Array.isArray(data.items) ? data.items : []);
        }
    } else {
        resultsSection.classList.add("hidden");
        prepareSection.classList.remove("hidden");
        renderWorkflowCandidates();
    }
}

async function openDeductionWorkflow() {
    document.getElementById("deduction-workflow-modal").style.display = "grid";
    setWorkflowMessage("");
    await loadDeductionWorkflow();
}

function closeDeductionWorkflow() {
    document.getElementById("deduction-workflow-modal").style.display = "none";
}

async function createWorkflowPeriod() {
    const button = document.getElementById("workflow-create-period");
    button.disabled = true;
    try {
        const response = await fetch("/accounting/deduction-periods", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                period_code: document.getElementById("workflow-period-code").value.trim(),
                label: document.getElementById("workflow-period-label").value.trim(),
                frequency: document.getElementById("workflow-period-frequency").value,
                date_start: document.getElementById("workflow-period-start").value,
                date_end: document.getElementById("workflow-period-end").value,
            }),
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            setWorkflowMessage(data?.message || "Failed to create deduction period.", "error");
            return;
        }
        setWorkflowMessage("Deduction period created.");
        await loadDeductionWorkflow(Number(data.period?.id || 0));
    } finally {
        button.disabled = false;
    }
}

async function prepareWorkflowBatch() {
    const periodId = Number(document.getElementById("workflow-period-select").value || 0);
    const requests = Array.from(document.querySelectorAll(".workflow-candidate-check:checked")).map((checkbox) => {
        const row = checkbox.closest("label");
        return {
            user_id: Number(checkbox.dataset.workflowUser || 0),
            requested_amount: Number(row.querySelector(".workflow-request-amount").value || 0),
        };
    });
    if (!periodId || !requests.length) {
        setWorkflowMessage("Select a period and at least one employee.", "error");
        return;
    }

    const response = await fetch("/accounting/deduction-batches", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ period_id: periodId, requests }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        setWorkflowMessage(data?.message || "Failed to prepare deduction batch.", "error");
        return;
    }
    setWorkflowMessage("Batch prepared. No employee debt has been reduced yet.");
    await loadDeductionWorkflow(periodId);
}

async function confirmWorkflowResult(button) {
    const row = button.closest("[data-workflow-item]");
    const itemId = Number(row?.dataset.workflowItem || 0);
    button.disabled = true;
    try {
        const response = await fetch(`/accounting/deduction-batch-items/${itemId}/confirm`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                confirmed_amount: Number(row.querySelector(".workflow-confirmed-amount").value || 0),
                reason_code: row.querySelector(".workflow-reason").value,
                result_reference: row.querySelector(".workflow-reference").value.trim(),
            }),
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            setWorkflowMessage(data?.message || "Failed to confirm payroll result.", "error");
            return;
        }
        setWorkflowMessage(`Payroll result confirmed. Employee debt is now ${aMoney(data.debt_after)}.`);
        await loadData();
        await loadDeductionWorkflow(Number(document.getElementById("workflow-period-select").value || 0));
    } finally {
        button.disabled = false;
    }
}

function setInvestigationMessage(message, type = "ok") {
    const element = document.getElementById("investigation-message");
    element.textContent = message || "";
    element.className = `mt-3 text-sm font-semibold ${type === "error" ? "text-red-700" : "text-emerald-700"}`;
}

function investigationLabel(value) {
    return aCategory(String(value || "").replace(/_/g, " "));
}

function renderInvestigations(rows) {
    const container = document.getElementById("investigation-list");
    const modal = document.getElementById("debt-investigations-modal");
    const currentRole = modal.dataset.currentRole || "";
    const currentUser = Number(modal.dataset.currentUser || 0);
    if (!rows.length) {
        container.innerHTML = '<div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">No debt investigations have been opened.</div>';
        return;
    }

    container.innerHTML = rows.map((row) => {
        const status = String(row.status || "open");
        const canRecommend = ["open", "investigating"].includes(status);
        const canApprove = status === "recommended" && currentRole === "ACCOUNTING_OFFICE" && Number(row.recommended_by || 0) !== currentUser;
        return `
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4" data-investigation-id="${aEscape(row.id)}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <strong class="block text-sm text-slate-900">#${aEscape(row.id)} · ${aEscape(row.name || "Employee")}</strong>
                        <small class="text-slate-500">${aEscape(row.employee_id || "-")} · ${aEscape(investigationLabel(row.issue_type))} · ${aEscape(row.client_txn_id || "General balance")}</small>
                    </div>
                    <span class="acct-debt-status ${status === "closed" ? "is-success" : status === "recommended" ? "is-warning" : "is-info"}">${aEscape(investigationLabel(status))}</span>
                </div>
                <p class="mt-2 text-sm text-slate-700">${aEscape(row.summary || "")}</p>
                ${row.findings ? `<div class="mt-2 rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-600"><strong>Findings:</strong> ${aEscape(row.findings)}</div>` : ""}
                ${canRecommend ? `
                    <div class="mt-3 grid gap-2">
                        <textarea class="investigation-findings min-h-20 rounded-xl border border-slate-200 bg-white p-3 text-sm" placeholder="Document findings (minimum 10 characters)"></textarea>
                        <textarea class="investigation-result-evidence min-h-16 rounded-xl border border-slate-200 bg-white p-3 text-sm" placeholder="Evidence reviewed"></textarea>
                        <div class="grid gap-2 md:grid-cols-[220px_170px_auto]">
                            <select class="investigation-action h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" aria-label="Recommended action">
                                <option value="no_change">No financial change</option>
                                <option value="partial_reversal">Partial reversal</option>
                                <option value="full_reversal">Full reversal</option>
                            </select>
                            <input class="investigation-amount h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" type="number" min="0" step="0.01" value="0.00" aria-label="Recommended reversal amount">
                            <button type="button" class="investigation-recommend inline-flex h-10 items-center justify-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">Submit recommendation</button>
                        </div>
                    </div>
                ` : ""}
                ${status === "recommended" ? `
                    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        <strong>${aEscape(investigationLabel(row.recommended_action))} · ${aEscape(aMoney(row.recommended_amount || 0))}</strong>
                        <p class="mt-1">Recommended by ${aEscape(row.recommended_by_name || "Unknown")}.</p>
                        ${canApprove
                            ? '<button type="button" class="investigation-approve mt-2 inline-flex h-10 items-center rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700">Approve and post correction</button>'
                            : '<p class="mt-2 font-semibold">Awaiting approval by a different Accounting user.</p>'}
                    </div>
                ` : ""}
                ${status === "closed" ? `<div class="mt-2 text-sm text-emerald-700">Closed by ${aEscape(row.approved_by_name || "Accounting")} · Posted reversal ${aEscape(aMoney(row.recommended_amount || 0))}</div>` : ""}
            </article>
        `;
    }).join("");
}

async function loadInvestigationTransactions(userId) {
    const select = document.getElementById("investigation-transaction");
    select.innerHTML = '<option value="">General balance investigation</option>';
    if (!userId) return;
    const response = await fetch(`/accounting/debt-investigations?user_id=${encodeURIComponent(userId)}`);
    const data = await response.json();
    if (!data || data.status !== "success") return;
    select.innerHTML += (data.transactions || []).map((row) =>
        `<option value="${aEscape(row.id)}">${aEscape(row.client_txn_id)} · ${aEscape(aMoney(row.amount))} · ${aEscape(aDateTime(row.created_at))}</option>`
    ).join("");
}

async function loadInvestigations() {
    const response = await fetch("/accounting/debt-investigations");
    const data = await response.json();
    if (!data || data.status !== "success") {
        setInvestigationMessage(data?.message || "Unable to load investigations.", "error");
        return;
    }
    renderInvestigations(Array.isArray(data.investigations) ? data.investigations : []);
}

async function openInvestigationsModal() {
    const modal = document.getElementById("debt-investigations-modal");
    modal.style.display = "grid";
    const userSelect = document.getElementById("investigation-user");
    userSelect.innerHTML = '<option value="">Select employee</option>' + acctRows.map((row) =>
        `<option value="${aEscape(row.user_id)}">${aEscape(row.name)} · ${aEscape(row.employee_id || "-")} · ${aEscape(aMoney(row.current_debt || 0))}</option>`
    ).join("");
    setInvestigationMessage("");
    await loadInvestigations();
}

function closeInvestigationsModal() {
    document.getElementById("debt-investigations-modal").style.display = "none";
}

async function openInvestigation() {
    const response = await fetch("/accounting/debt-investigations", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            user_id: Number(document.getElementById("investigation-user").value || 0),
            transaction_id: Number(document.getElementById("investigation-transaction").value || 0),
            issue_type: document.getElementById("investigation-issue").value,
            summary: document.getElementById("investigation-summary").value.trim(),
            evidence_summary: document.getElementById("investigation-evidence").value.trim(),
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        setInvestigationMessage(data?.message || "Failed to open investigation.", "error");
        return;
    }
    document.getElementById("investigation-summary").value = "";
    document.getElementById("investigation-evidence").value = "";
    setInvestigationMessage("Investigation opened. Financial records remain unchanged.");
    await loadInvestigations();
}

async function recommendInvestigation(button) {
    const row = button.closest("[data-investigation-id]");
    const id = Number(row?.dataset.investigationId || 0);
    const response = await fetch(`/accounting/debt-investigations/${id}/recommend`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            findings: row.querySelector(".investigation-findings").value.trim(),
            evidence_summary: row.querySelector(".investigation-result-evidence").value.trim(),
            recommended_action: row.querySelector(".investigation-action").value,
            recommended_amount: Number(row.querySelector(".investigation-amount").value || 0),
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        setInvestigationMessage(data?.message || "Failed to submit recommendation.", "error");
        return;
    }
    setInvestigationMessage("Recommendation submitted for independent Accounting approval.");
    await loadInvestigations();
}

async function approveInvestigation(button) {
    const row = button.closest("[data-investigation-id]");
    const id = Number(row?.dataset.investigationId || 0);
    const response = await fetch(`/accounting/debt-investigations/${id}/approve`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: "{}",
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        setInvestigationMessage(data?.message || "Failed to approve correction.", "error");
        return;
    }
    setInvestigationMessage(`Correction posted. Employee debt changed from ${aMoney(data.debt_before)} to ${aMoney(data.debt_after)}.`);
    await loadData();
    await loadInvestigations();
}

document.getElementById("acct-search").addEventListener("input", () => {
    applyMainFiltersAndRender();
});

document.getElementById("acct-debt-only").addEventListener("change", () => {
    applyMainFiltersAndRender();
});

document.querySelectorAll("[data-acct-tab]").forEach((button) => {
    button.addEventListener("click", () => {
        activeAcctTab = button.getAttribute("data-acct-tab") || "employee-debts";
        renderActiveAccountingTab();
    });
});

document.getElementById("acct-body").addEventListener("click", async (event) => {
    const row = event.target.closest("[data-row-user]");
    if (row) {
        await openEmployeeModal(row.getAttribute("data-row-user"));
    }
});

document.getElementById("open-deduction-mode").addEventListener("click", openMode);
document.getElementById("open-deduction-workflow").addEventListener("click", openDeductionWorkflow);
document.getElementById("open-debt-investigations").addEventListener("click", openInvestigationsModal);
document.getElementById("open-settlement-run").addEventListener("click", openSettlementModal);
document.getElementById("open-import-csv").addEventListener("click", openImportModal);
document.getElementById("close-deduction-mode").addEventListener("click", closeMode);
document.getElementById("close-deduction-workflow").addEventListener("click", closeDeductionWorkflow);
document.getElementById("close-debt-investigations").addEventListener("click", closeInvestigationsModal);
document.getElementById("close-settlement-run").addEventListener("click", closeSettlementModal);
document.getElementById("close-import-csv").addEventListener("click", closeImportModal);
document.getElementById("deduction-mode-modal").addEventListener("click", (event) => {
    if (event.target.id === "deduction-mode-modal") closeMode();
});
document.getElementById("deduction-workflow-modal").addEventListener("click", (event) => {
    if (event.target.id === "deduction-workflow-modal") closeDeductionWorkflow();
});
document.getElementById("debt-investigations-modal").addEventListener("click", (event) => {
    if (event.target.id === "debt-investigations-modal") closeInvestigationsModal();
});
document.getElementById("investigation-user").addEventListener("change", (event) => {
    loadInvestigationTransactions(Number(event.target.value || 0));
});
document.getElementById("investigation-open").addEventListener("click", openInvestigation);
document.getElementById("investigation-refresh").addEventListener("click", loadInvestigations);
document.getElementById("investigation-list").addEventListener("click", (event) => {
    const recommendButton = event.target.closest(".investigation-recommend");
    if (recommendButton) {
        recommendInvestigation(recommendButton);
        return;
    }
    const approveButton = event.target.closest(".investigation-approve");
    if (approveButton) approveInvestigation(approveButton);
});
document.getElementById("workflow-create-period").addEventListener("click", createWorkflowPeriod);
document.getElementById("workflow-refresh").addEventListener("click", () => loadDeductionWorkflow());
document.getElementById("workflow-period-select").addEventListener("change", (event) => {
    loadDeductionWorkflow(Number(event.target.value || 0));
});
document.getElementById("workflow-prepare-batch").addEventListener("click", prepareWorkflowBatch);
document.getElementById("workflow-results").addEventListener("click", (event) => {
    const button = event.target.closest(".workflow-confirm-result");
    if (button) confirmWorkflowResult(button);
});
document.getElementById("settlement-run-modal").addEventListener("click", (event) => {
    if (event.target.id === "settlement-run-modal") closeSettlementModal();
});
document.getElementById("settlement-run-month").addEventListener("change", syncSettlementApplyState);
document.getElementById("settlement-run-details-modal").addEventListener("click", (event) => {
    if (event.target.id === "settlement-run-details-modal") closeSettlementRunDetails();
});
document.getElementById("import-csv-modal").addEventListener("click", (event) => {
    if (event.target.id === "import-csv-modal") closeImportModal();
});
document.getElementById("import-csv-preview").addEventListener("click", previewImportCsv);
document.getElementById("import-csv-submit").addEventListener("click", submitImportCsv);
document.getElementById("import-csv-file").addEventListener("change", () => {
    importCsvPreviewReady = false;
    document.getElementById("import-csv-submit").disabled = true;
    document.getElementById("import-csv-result").textContent = "";
    document.getElementById("import-csv-valid").innerHTML = "";
    document.getElementById("import-csv-invalid").innerHTML = "";
});
document.getElementById("settlement-preview-btn").addEventListener("click", previewSettlementRun);
document.getElementById("settlement-apply-btn").addEventListener("click", applySettlementRun);
document.getElementById("settlement-preview-search").addEventListener("input", (event) => {
    settlementPreviewQuery = event.target.value || "";
    renderSettlementPreviewRows(Array.isArray(settlementPreview?.accounts) ? settlementPreview.accounts : []);
});
document.getElementById("close-settlement-confirm").addEventListener("click", closeSettlementConfirmModal);
document.getElementById("cancel-settlement-confirm").addEventListener("click", closeSettlementConfirmModal);
document.getElementById("confirm-settlement-apply").addEventListener("click", confirmSettlementRunApply);
document.getElementById("settlement-confirm-select-all").addEventListener("change", (event) => {
    const rows = getProcessableSettlementRows();
    settlementSelectedUserIds = event.target.checked
        ? new Set(rows.map((row) => Number(row.user_id)))
        : new Set();
    renderSettlementConfirmRows();
});
document.getElementById("settlement-confirm-body").addEventListener("change", (event) => {
    const checkbox = event.target.closest("[data-settle-user]");
    if (!checkbox) return;
    const userId = Number(checkbox.dataset.settleUser || 0);
    if (userId <= 0) return;
    if (checkbox.checked) {
        settlementSelectedUserIds.add(userId);
    } else {
        settlementSelectedUserIds.delete(userId);
    }
    renderSettlementConfirmRows();
});
document.getElementById("settlement-confirm-modal").addEventListener("click", (event) => {
    if (event.target.id === "settlement-confirm-modal") closeSettlementConfirmModal();
});
document.getElementById("close-settlement-run-details").addEventListener("click", closeSettlementRunDetails);
document.getElementById("settlement-details-export").addEventListener("click", exportSettlementDetailsCsv);
document.getElementById("settlement-details-print").addEventListener("click", printSettlementDetails);
document.getElementById("settlement-runs-list").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-settle-run]");
    if (!button) return;
    await openSettlementRunDetails(button.getAttribute("data-settle-run"));
});
document.getElementById("settlement-existing-run-view").addEventListener("click", async (event) => {
    const runId = event.currentTarget.dataset.settleRun;
    if (!runId) return;
    await openSettlementRunDetails(runId);
});

document.getElementById("mode-search").addEventListener("input", renderModeResults);
document.getElementById("mode-debt-only").addEventListener("change", renderModeResults);

document.getElementById("mode-results").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-mode-user]");
    if (!button) return;
    await selectModeUser(button.getAttribute("data-mode-user"));
});

document.getElementById("mode-open-manual").addEventListener("click", () => {
    document.getElementById("mode-manual-box").classList.remove("hidden");
});

document.getElementById("mode-cancel-manual").addEventListener("click", () => {
    document.getElementById("mode-manual-box").classList.add("hidden");
});

document.getElementById("mode-deduct-full").addEventListener("click", async () => {
    if (!modeSelectedUserId) return;
    if (!await window.IbemsDialog.confirm("This will deduct the selected person’s full outstanding debt.", {
        title: "Apply full debt deduction?",
        confirmLabel: "Apply deduction",
        tone: "danger",
    })) return;

    const data = await applyFullDeduction(modeSelectedUserId, "Full debt deduction");
    if (!data || data.status !== "success") {
        setAcctResult(data?.message || "Failed to apply full deduction.", "error");
        return;
    }

    setAcctResult(
        `Full deduction applied. Previous debt: ${aMoney(data.previous_debt)} | New debt: ${aMoney(data.new_debt)}`,
        "ok"
    );
    await loadData();
    await selectModeUser(modeSelectedUserId);
});

document.getElementById("mode-apply-manual").addEventListener("click", async () => {
    if (!modeSelectedUserId) return;
    const amount = Number(document.getElementById("mode-manual-amount").value || 0);
    const reason = (document.getElementById("mode-manual-reason").value || "Manual deduction").trim();

    if (amount <= 0) {
        setAcctResult("Deduction amount must be greater than 0.", "error");
        return;
    }
    if (!await window.IbemsDialog.confirm(`Apply a manual deduction of ${aMoney(amount)}?`, {
        title: "Confirm manual deduction",
        confirmLabel: "Apply deduction",
        tone: "danger",
    })) return;

    const data = await applyDeduction(modeSelectedUserId, amount, reason);
    if (!data || data.status !== "success") {
        setAcctResult(data?.message || "Failed to apply deduction.", "error");
        return;
    }

    setAcctResult(
        `Deduction applied. Previous debt: ${aMoney(data.previous_debt)} | New debt: ${aMoney(data.new_debt)}`,
        "ok"
    );
    document.getElementById("mode-manual-amount").value = "";
    await loadData();
    await selectModeUser(modeSelectedUserId);
});

document.getElementById("close-employee-modal").addEventListener("click", closeEmployeeModal);
document.getElementById("employee-modal").addEventListener("click", (event) => {
    if (event.target.id === "employee-modal") closeEmployeeModal();
});

document.getElementById("employee-open-limit-edit").addEventListener("click", () => {
    document.getElementById("employee-limit-box").classList.remove("hidden");
});

document.getElementById("employee-limit-cancel").addEventListener("click", () => {
    document.getElementById("employee-limit-box").classList.add("hidden");
});

document.getElementById("employee-limit-save").addEventListener("click", async () => {
    if (!employeeModalUserId) return;
    const value = Number(document.getElementById("employee-limit-value").value || -1);
    const reason = (document.getElementById("employee-limit-reason").value || "Manual credit limit update").trim();

    if (value < 0) {
        setAcctResult("Credit limit must be 0 or higher.", "error");
        return;
    }

    if (!await window.IbemsDialog.confirm(`Set this person’s credit limit to ${aMoney(value)}?`, {
        title: "Update credit limit?",
        confirmLabel: "Update limit",
    })) return;

    const data = await updateCreditLimit(employeeModalUserId, value, reason);
    if (!data || data.status !== "success") {
        setAcctResult(data?.message || "Failed to update credit limit.", "error");
        return;
    }

    setAcctResult(
        `Credit limit updated. Previous: ${aMoney(data.previous_credit_limit)} | New: ${aMoney(data.new_credit_limit)}`,
        "ok"
    );

    await loadData();
    await openEmployeeModal(employeeModalUserId);
});

(async () => {
    await loadData();
})();
