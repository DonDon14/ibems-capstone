function setInvestigationMessage(message, type = "ok", focus = false) {
    const element = document.getElementById("investigation-message");
    element.textContent = message || "";
    element.setAttribute("role", type === "error" ? "alert" : "status");
    element.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
    element.setAttribute("aria-atomic", "true");
    element.className = `investigation-action-feedback is-${type === "ok" ? "success" : type}`;
    if (message && focus) { element.focus({preventScroll:true}); element.scrollIntoView({block:"nearest",inline:"nearest"}); }
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
        const canApprove = status === "recommended" && ["ACCOUNTING_OFFICE", "ACCOUNTING_HEAD"].includes(currentRole) && Number(row.recommended_by || 0) !== currentUser;
        return `
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4" data-investigation-id="${Number(row.id)}" data-investigation-revision="${aEscape(`${row.user_id}:${row.status}:${row.updated_at}`)}" data-investigation-user="${Number(row.user_id)}" data-investigation-status="${aEscape(status)}" data-investigation-recommender="${Number(row.recommended_by || 0)}" data-investigation-action="${aEscape(row.recommended_action || "")}" data-investigation-amount="${aEscape(row.recommended_amount ?? "")}">
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
                        <label class="grid gap-1">
                            <span class="text-xs font-semibold text-slate-500">Findings</span>
                            <textarea class="investigation-findings min-h-20 rounded-xl border border-slate-200 bg-white p-3 text-sm" placeholder="Document findings (minimum 10 characters)"></textarea>
                        </label>
                        <label class="grid gap-1">
                            <span class="text-xs font-semibold text-slate-500">Evidence reviewed</span>
                            <textarea class="investigation-result-evidence min-h-16 rounded-xl border border-slate-200 bg-white p-3 text-sm" placeholder="Evidence reviewed"></textarea>
                        </label>
                        <div class="grid gap-2 md:grid-cols-[220px_170px_auto]">
                            <select class="investigation-action h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" aria-label="Recommended action">
                                <option value="no_change">No financial change</option>
                                <option value="partial_reversal">Partial reversal</option>
                                <option value="full_reversal">Full reversal</option>
                            </select>
                            <input class="investigation-amount h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm" type="number" min="0" step="0.01" value="0.00" aria-label="Recommended reversal amount">
                            <button type="button" class="investigation-recommend primary-btn">Submit recommendation</button>
                        </div>
                    </div>
                ` : ""}
                ${status === "recommended" ? `
                    <div class="investigation-recommendation mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        <strong>${aEscape(investigationLabel(row.recommended_action))} · ${aEscape(aMoney(row.recommended_amount || 0))}</strong>
                        <p class="mt-1">Recommended by ${aEscape(row.recommended_by_name || "Unknown")}.</p>
                        ${canApprove
                            ? '<button type="button" class="investigation-approve primary-btn">Approve and post correction</button>'
                            : '<p class="mt-2 font-semibold">Awaiting approval by a different Accounting user.</p>'}
                    </div>
                ` : ""}
                ${status === "closed" ? `<div class="investigation-closed mt-2 text-sm text-emerald-700">Closed by ${aEscape(row.approved_by_name || "Accounting")} · Posted reversal ${aEscape(aMoney(row.recommended_amount || 0))}</div>` : ""}
            </article>
        `;
    }).join("");
}

async function loadInvestigationTransactions(userId, options = {}) {
    return window.IbemsInvestigationReads.transactions(userId, options);
}

async function loadInvestigations(options = {}) {
    return window.IbemsInvestigationReads.queue(options);
}

async function openInvestigationsModal() {
    if (window.IbemsInvestigationActions?.busy) return;
    window.IbemsInvestigationActions?.clear();
    const modal = document.getElementById("debt-investigations-modal");
    window.IbemsInvestigationReads.reset();
    modal.style.display = "grid";
    document.getElementById("close-debt-investigations").focus();
    const userSelect = document.getElementById("investigation-user");
    userSelect.innerHTML = '<option value="">Select employee</option>' + acctRows.map((row) =>
        `<option value="${aEscape(row.user_id)}">${aEscape(row.name)} · ${aEscape(row.employee_id || "-")} · ${aEscape(aMoney(row.current_debt || 0))}</option>`
    ).join("");
    setInvestigationMessage("");
    loadInvestigationTransactions(0);
    await loadInvestigations();
}

function closeInvestigationsModal() {
    if (window.IbemsInvestigationActions?.busy) return;
    window.IbemsInvestigationActions?.clear();
    window.IbemsInvestigationReads.reset();

    document.getElementById("debt-investigations-modal").style.display = "none";
    document.getElementById("open-debt-investigations").focus();
}

document.getElementById("debt-investigations-modal").addEventListener("keydown", (event) => {
    if (event.defaultPrevented) return;
    if (document.body.classList.contains("has-app-dialog")) return;
    const modal = event.currentTarget;
    if (!modal.contains(document.activeElement)) return;
    if (window.IbemsInvestigationActions?.busy && ["Escape","Tab"].includes(event.key)) {
        event.preventDefault();
        document.getElementById("investigation-message").focus();
        return;
    }
    if (event.key === "Escape") {
        if (modal.querySelector('[aria-expanded="true"]')) return;
        event.preventDefault();
        closeInvestigationsModal();
    } else if (event.key === "Tab") {
        const controls = Array.from(modal.querySelectorAll('button, a[href], input, select, textarea, [tabindex]'))
            .filter((element) => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length && getComputedStyle(element).visibility !== "hidden");
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && (document.activeElement === first || (first?.compareDocumentPosition(document.activeElement) & Node.DOCUMENT_POSITION_PRECEDING))) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && (document.activeElement === last || (last?.compareDocumentPosition(document.activeElement) & Node.DOCUMENT_POSITION_FOLLOWING))) {
            event.preventDefault();
            first?.focus();
        }
    }
});

async function openInvestigation() {
    // The transaction guard also protects direct callers while employee reads are unavailable.
    if (document.getElementById("investigation-transaction").disabled && !window.IbemsInvestigationActions?.busy) {
        setInvestigationMessage("Employee transactions are unavailable or still loading. Use Retry employee transactions before opening an investigation.", "warning", true);
        return;
    }
    return window.IbemsInvestigationActions.open();
}

async function recommendInvestigation(button) {
    return window.IbemsInvestigationActions.recommend(button);
}

async function approveInvestigation(button) {
    return window.IbemsInvestigationActions.approve(button);
}
document.getElementById("acct-search").addEventListener("input", () => {
    acctPage = 1;
    applyMainFiltersAndRender();
});

document.getElementById("acct-debt-only").addEventListener("change", () => {
    acctPage = 1;
    applyMainFiltersAndRender();
});

["acct-sort", "acct-page-size"].forEach((id) => {
    document.getElementById(id).addEventListener("change", () => { acctPage = 1; renderActiveAccountingTab(); });
});
document.getElementById("acct-pager").addEventListener("click", (event) => {
    const button = event.target.closest("[data-page]");
    if (!button || button.disabled) return;
    acctPage = Math.max(1, Number(button.dataset.page || 1));
    renderActiveAccountingTab();
    document.getElementById("acct-record-panel")?.focus();
});

document.querySelectorAll("[data-acct-tab]").forEach((button) => {
    button.addEventListener("keydown", (event) => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        const tabs = Array.from(document.querySelectorAll("[data-acct-tab]"));
        const index = tabs.indexOf(button);
        const next = event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
        event.preventDefault();
        tabs[next].click();
        tabs[next].focus();
    });
    button.addEventListener("click", () => {
        activeAcctTab = button.getAttribute("data-acct-tab") || "employee-debts";
        acctPage = 1;
        renderActiveAccountingTab();
    });
});

document.getElementById("acct-body").addEventListener("click", async (event) => {
    const row = event.target.closest("[data-row-user]");
    if (row) {
        row.focus();
        await openEmployeeModal(row.getAttribute("data-row-user"));
    }
});

document.getElementById("acct-body").addEventListener("keydown", (event) => {
    const row = event.target.closest("[data-row-user]");
    if (!row || !["Enter", " "].includes(event.key)) return;
    event.preventDefault();
    if (!event.repeat) row.click();
});

document.getElementById("open-debt-investigations").addEventListener("click", openInvestigationsModal);
document.getElementById("open-settlement-run").addEventListener("click", openSettlementModal);
document.getElementById("open-import-csv").addEventListener("click", openImportModal);
document.getElementById("close-debt-investigations").addEventListener("click", closeInvestigationsModal);
document.getElementById("close-settlement-run").addEventListener("click", closeSettlementModal);
document.getElementById("close-import-csv").addEventListener("click", closeImportModal);
document.getElementById("debt-investigations-modal").addEventListener("click", (event) => {
    if (event.target.id === "debt-investigations-modal") closeInvestigationsModal();
});
document.getElementById("investigation-user").addEventListener("change", (event) => {
    loadInvestigationTransactions(Number(event.target.value || 0));
});
document.getElementById("investigation-open").addEventListener("click", openInvestigation);
document.getElementById("investigation-refresh").addEventListener("click", () => loadInvestigations({manual:true}));
document.getElementById("investigation-queue-retry").addEventListener("click", () => loadInvestigations({manual:true}));
document.getElementById("investigation-transaction-retry").addEventListener("click", () => loadInvestigationTransactions(Number(document.getElementById("investigation-user").value || 0), {manual:true}));
document.getElementById("investigation-list").addEventListener("click", (event) => {
    const recommendButton = event.target.closest(".investigation-recommend");
    if (recommendButton) {
        recommendInvestigation(recommendButton);
        return;
    }
    const approveButton = event.target.closest(".investigation-approve");
    if (approveButton) approveInvestigation(approveButton);
});
document.getElementById("settlement-run-modal").addEventListener("click", (event) => {
    if (event.target.id === "settlement-run-modal") closeSettlementModal();
});
document.getElementById("settlement-run-details-modal").addEventListener("click", (event) => {
    if (event.target.id === "settlement-run-details-modal") closeSettlementRunDetails();
});
document.getElementById("import-csv-modal").addEventListener("click", (event) => {
    if (event.target.id === "import-csv-modal") closeImportModal();
});
document.getElementById("import-csv-preview").addEventListener("click", previewImportCsv);
document.getElementById("import-csv-submit").addEventListener("click", submitImportCsv);
document.getElementById("import-csv-file").addEventListener("change", () => {
    if (!invalidateImportCsvPreview()) return;
    setImportCsvResult("");
    document.getElementById("import-csv-valid").innerHTML = "";
    document.getElementById("import-csv-invalid").innerHTML = "";
});
document.getElementById("close-settlement-run-details").addEventListener("click", closeSettlementRunDetails);
document.getElementById("settlement-details-export").addEventListener("click", exportSettlementDetailsCsv);

document.getElementById("settlement-details-print").addEventListener("click", printSettlementDetails);
document.getElementById("settlement-queue-retry").addEventListener("click", () => loadSettlementRuns({manual:true}));
document.getElementById("settlement-details-retry").addEventListener("click", () => window.IbemsLegacyHistory.retryDetails());
document.getElementById("settlement-runs-list").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-settle-run]");
    if (!button) return;
    await openSettlementRunDetails(button.getAttribute("data-settle-run"));
});
document.getElementById("close-employee-modal").addEventListener("click", closeEmployeeModal);
document.getElementById("employee-modal").addEventListener("click", (event) => {
    if (event.target.id === "employee-modal") closeEmployeeModal();
});

document.getElementById("employee-open-limit-edit").addEventListener("click", () => {
    if (window.IbemsEmployeeActions.busy) return;
    document.getElementById("employee-limit-box").classList.remove("hidden");
    document.getElementById("employee-employment-type").focus();
});

document.getElementById("employee-limit-cancel").addEventListener("click", () => {
    if (window.IbemsEmployeeActions.busy) return;
    document.getElementById("employee-limit-box").classList.add("hidden");
    document.getElementById("employee-open-limit-edit").focus();
});

function refreshDynamicCreditPreview() {
    const salary = Number(document.getElementById("employee-salary-value").value || 0);
    const percentage = Math.min(100, Math.max(0, Number(document.getElementById("employee-credit-percentage").value || 0)));
    document.getElementById("employee-limit-value").value = aMoney(salary * percentage / 100);
}

function resetAcctSalaryToSchedule() {
    const schedule = selectedAcctSalarySchedule();
    const grade = Number(document.getElementById("employee-salary-grade").value || 0);
    const step = Number(document.getElementById("employee-salary-step").value || 0);
    const rate = (schedule?.rates || []).find((item) => Number(item.salary_grade) === grade && Number(item.salary_step) === step);
    document.getElementById("employee-salary-value").value = Number(rate?.monthly_salary || 0).toFixed(2);
    refreshDynamicCreditPreview();
}

document.getElementById("employee-salary-schedule").addEventListener("change", () => populateAcctSalaryProfile({
    salary_schedule_id: document.getElementById("employee-salary-schedule").value,
    employment_type: document.getElementById("employee-employment-type").value,
    credit_percentage: document.getElementById("employee-credit-percentage").value,
}));
document.getElementById("employee-salary-grade").addEventListener("change", () => populateAcctSalaryProfile({
    salary_schedule_id: document.getElementById("employee-salary-schedule").value,
    salary_grade: document.getElementById("employee-salary-grade").value,
    employment_type: document.getElementById("employee-employment-type").value,
    credit_percentage: document.getElementById("employee-credit-percentage").value,
}));
document.getElementById("employee-salary-step").addEventListener("change", resetAcctSalaryToSchedule);
document.getElementById("employee-salary-value").addEventListener("input", refreshDynamicCreditPreview);
document.getElementById("employee-credit-percentage").addEventListener("input", refreshDynamicCreditPreview);

document.getElementById("employee-limit-save").addEventListener("click", () => window.IbemsEmployeeActions.save());

(async () => {
    await loadData();
})();
