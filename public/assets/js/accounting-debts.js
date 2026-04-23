let acctRows = [];
let modeFilteredRows = [];
let modeSelectedUserId = null;
let employeeModalUserId = null;
let employeeModalProfile = null;
let settlementPreview = null;
let settlementRunsCache = [];

function aEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function aMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function aDateTime(value) {
    return new Date(value).toLocaleString();
}

function aCategory(value) {
    const text = String(value || "");
    if (!text) return "-";
    return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
}

function setAcctResult(message, type) {
    const el = document.getElementById("acct-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function renderSummary(rows) {
    const list = Array.isArray(rows) ? rows : [];
    const totalDebt = list.reduce((sum, row) => sum + Number(row.current_debt || 0), 0);
    document.getElementById("acct-count").textContent = String(list.length);
    document.getElementById("acct-total-debt").textContent = aMoney(totalDebt);
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
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="6">No settlement candidates.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
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

function renderSettlementRuns(rows) {
    settlementRunsCache = Array.isArray(rows) ? rows : [];
    const wrap = document.getElementById("settlement-runs-list");
    if (settlementRunsCache.length === 0) {
        wrap.innerHTML = "No previous settlement runs.";
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
                    Processed: ${aEscape(String(row.total_accounts || 0))} accounts |
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
    button.textContent = alreadyApplied ? "Already Applied" : "Apply Run";

    if (alreadyApplied) {
        existingWrap.classList.remove("hidden");
        existingText.textContent = `Settlement for ${runMonthLabel} already applied.`;
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
    modal.style.display = "grid";
    head.innerHTML = "Loading settlement run details...";
    body.innerHTML = '<tr><td colspan="6">Loading details...</td></tr>';
    document.getElementById("settle-details-count").textContent = "0";
    document.getElementById("settle-details-deducted").textContent = aMoney(0);
    document.getElementById("settle-details-after").textContent = aMoney(0);

    try {
        const response = await fetch(`/accounting/settlement/runs/${Number(runId)}`);
        const data = await response.json();
        if (!data || data.status !== "success") {
            head.innerHTML = '<div class="mode-profile-empty">Failed to load run details.</div>';
            body.innerHTML = '<tr><td colspan="6">No details available.</td></tr>';
            return;
        }

        const run = data.run || {};
        const summary = data.summary || {};
        const notes = run.notes || {};

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
            body.innerHTML = '<tr><td colspan="6">No settled accounts found for this run.</td></tr>';
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
        head.innerHTML = '<div class="mode-profile-empty">Failed to load run details.</div>';
        body.innerHTML = '<tr><td colspan="6">No details available.</td></tr>';
    }
}

function closeSettlementRunDetails() {
    document.getElementById("settlement-run-details-modal").style.display = "none";
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
    const rows = getMainFilteredRows();
    renderRows(rows);
    setAcctResult("", "ok");
}

async function loadData() {
    const params = new URLSearchParams({ limit: "500" });

    const response = await fetch(`/accounting/debts/data?${params.toString()}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        acctRows = [];
        renderRows([]);
        setAcctResult(data?.message || "Unable to load debt records.", "error");
        return;
    }

    acctRows = Array.isArray(data.data) ? data.data : [];
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
    document.getElementById("settlement-run-modal").style.display = "grid";
    document.getElementById("settlement-run-month").value = new Date().toISOString().slice(0, 7);
    document.getElementById("settlement-notes").value = "";
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
        setAcctResult(data?.message || "Failed to preview settlement run.", "error");
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
        setAcctResult(`Settlement for ${runMonth} already exists. Open it from "Recent Settlement Runs".`, "error");
        return;
    }

    setAcctResult("Settlement preview ready.", "ok");
}

async function applySettlementRun() {
    if (!settlementPreview) {
        setAcctResult("Please run preview first.", "error");
        return;
    }

    const runMonth = (document.getElementById("settlement-run-month").value || "").trim();
    const notes = (document.getElementById("settlement-notes").value || "").trim();

    if (!window.confirm(`Apply settlement run for ${runMonth}?`)) {
        return;
    }

    const button = document.getElementById("settlement-apply-btn");
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
            }),
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            setAcctResult(data?.message || "Failed to apply settlement run.", "error");
            return;
        }

        setAcctResult(
            `Settlement run applied (${data.run_month}). Processed: ${data.total_accounts}, Deducted: ${aMoney(data.total_deducted)}.`,
            "ok"
        );
        await loadData();
        await loadSettlementRuns();
        closeSettlementModal();
    } catch (error) {
        setAcctResult("Settlement run request failed.", "error");
    } finally {
        button.textContent = oldText;
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
            <h5 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Selected Person</h5>
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
    document.getElementById("import-csv-modal").style.display = "grid";
    document.getElementById("import-csv-result").textContent = "";
    document.getElementById("import-csv-invalid").innerHTML = "";
}

function closeImportModal() {
    document.getElementById("import-csv-modal").style.display = "none";
}

async function submitImportCsv() {
    const fileInput = document.getElementById("import-csv-file");
    const resultEl = document.getElementById("import-csv-result");
    const invalidEl = document.getElementById("import-csv-invalid");
    const button = document.getElementById("import-csv-submit");
    const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

    if (!file) {
        resultEl.style.color = "#b91c1c";
        resultEl.textContent = "Please choose a CSV file first.";
        return;
    }

    const formData = new FormData();
    formData.append("csv_file", file);

    button.disabled = true;
    button.textContent = "Importing...";
    resultEl.style.color = "#334155";
    resultEl.textContent = "Processing CSV...";
    invalidEl.innerHTML = "";

    try {
        const response = await fetch("/accounting/debts/import-csv", {
            method: "POST",
            body: formData,
        });
        const data = await response.json();
        if (!data || data.status !== "success") {
            resultEl.style.color = "#b91c1c";
            resultEl.textContent = data?.message || "CSV import failed.";
            return;
        }

        resultEl.style.color = "#166534";
        resultEl.textContent = `Import complete. Total: ${data.total_rows}, Valid: ${data.valid_rows}, Invalid: ${data.invalid_rows}`;

        const invalidPreview = Array.isArray(data.invalid_preview) ? data.invalid_preview : [];
        if (invalidPreview.length > 0) {
            invalidEl.innerHTML = invalidPreview.map((row) => `
                <div class="invalid-item rounded-xl border border-slate-200 bg-white p-3">
                    <strong>Line ${row.line}</strong><br>
                    ${aEscape(row.name || "-")} (${aEscape(row.email || "-")})<br>
                    <span>${aEscape(row.error || "Invalid row")}</span>
                </div>
            `).join("");
        }

        await loadData();
    } catch (error) {
        resultEl.style.color = "#b91c1c";
        resultEl.textContent = "CSV import request failed.";
    } finally {
        button.disabled = false;
        button.textContent = "Upload and Import";
    }
}

document.getElementById("acct-refresh-btn").addEventListener("click", async () => {
    await loadData();
});

document.getElementById("acct-search").addEventListener("input", () => {
    applyMainFiltersAndRender();
});

document.getElementById("acct-debt-only").addEventListener("change", () => {
    applyMainFiltersAndRender();
});

document.getElementById("acct-body").addEventListener("click", async (event) => {
    const row = event.target.closest("[data-row-user]");
    if (row) {
        await openEmployeeModal(row.getAttribute("data-row-user"));
    }
});

document.getElementById("open-deduction-mode").addEventListener("click", openMode);
document.getElementById("open-settlement-run").addEventListener("click", openSettlementModal);
document.getElementById("open-import-csv").addEventListener("click", openImportModal);
document.getElementById("close-deduction-mode").addEventListener("click", closeMode);
document.getElementById("close-settlement-run").addEventListener("click", closeSettlementModal);
document.getElementById("close-import-csv").addEventListener("click", closeImportModal);
document.getElementById("deduction-mode-modal").addEventListener("click", (event) => {
    if (event.target.id === "deduction-mode-modal") closeMode();
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
document.getElementById("import-csv-submit").addEventListener("click", submitImportCsv);
document.getElementById("settlement-preview-btn").addEventListener("click", previewSettlementRun);
document.getElementById("settlement-apply-btn").addEventListener("click", applySettlementRun);
document.getElementById("close-settlement-run-details").addEventListener("click", closeSettlementRunDetails);
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
    if (!window.confirm("Confirm full debt deduction for selected person?")) return;

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
    if (!window.confirm(`Confirm manual deduction of ${aMoney(amount)}?`)) return;

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

    if (!window.confirm(`Confirm credit limit update to ${aMoney(value)}?`)) return;

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
