async function openDeductionWorkflow() {
    document.getElementById("deduction-workflow-modal").style.display = "grid";
    setWorkflowMessage("");
    await loadDeductionWorkflow();
}

function closeDeductionWorkflow() {
    document.getElementById("deduction-workflow-modal").style.display = "none";
}

function updateWorkflowPeriodPreview() {
    const start = document.getElementById("workflow-period-start").value;
    const end = document.getElementById("workflow-period-end").value;
    const preview = document.getElementById("workflow-period-preview");
    if (!start || !end) {
        preview.textContent = "Choose the start and end dates. The period code and label will be generated automatically.";
        return;
    }
    if (end < start) {
        preview.textContent = "End date cannot be before the start date.";
        return;
    }
    const format = (value) => new Intl.DateTimeFormat("en-PH", { month: "long", day: "numeric", year: "numeric" }).format(new Date(`${value}T00:00:00`));
    preview.innerHTML = `<strong>${aEscape(`PAY-${start.replace(/-/g, "")}-${end.replace(/-/g, "")}`)}</strong><br>${aEscape(`${format(start)} - ${format(end)}`)}`;
}

async function createWorkflowPeriod() {
    const button = document.getElementById("workflow-create-period");
    button.disabled = true;
    try {
        const response = await fetch("/accounting/deduction-periods", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
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
    const selected = Array.from(document.querySelectorAll(".workflow-candidate-check:checked"));
    const invalid = selected.find((checkbox) => {
        const row = checkbox.closest("label");
        const choice = row.querySelector(".workflow-deduction-choice").value;
        const amount = Number(row.querySelector(".workflow-request-amount").value || 0);
        const cutoff = Number(row.querySelector(".workflow-request-amount").dataset.cutoffDebt || 0);
        const reason = row.querySelector(".workflow-preparation-reason").value.trim();
        return (choice === "partial" && (amount <= 0 || amount >= cutoff)) || (choice === "none" && !reason);
    });
    if (invalid) {
        setWorkflowMessage("Partial deductions must be greater than zero and below the full debt. No deduction requires a reason.", "error");
        return;
    }
    const requests = selected.map((checkbox) => {
        const row = checkbox.closest("label");
        return {
            user_id: Number(checkbox.dataset.workflowUser || 0),
            deduction_choice: row.querySelector(".workflow-deduction-choice").value,
            requested_amount: Number(row.querySelector(".workflow-request-amount").value || 0),
            preparation_reason: row.querySelector(".workflow-preparation-reason").value.trim(),
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
    document.getElementById("workflow-employees-modal").style.display = "none";
    await loadDeductionWorkflow(periodId);
}

async function advanceWorkflowBatch(action) {
    const periodId = Number(document.getElementById("workflow-period-select").value || 0);
    const period = deductionWorkflowPeriods.find((row) => Number(row.id) === periodId);
    if (!period?.batch_id || !["apply", "reconcile", "finalize"].includes(action)) return;
    const response = await fetch(`/accounting/deduction-batches/${Number(period.batch_id)}/${action}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: "{}",
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        setWorkflowMessage(data?.message || `Failed to ${action} deduction batch.`, "error");
        return;
    }
    const messages = {
        apply: "Prepared deductions were applied atomically in IBEMS.",
        reconcile: "Batch totals reconciled. It now requires independent finalization.",
        finalize: "Deduction period finalized, locked, and recorded in the audit trail.",
    };
    setWorkflowMessage(messages[action]);
    await loadDeductionWorkflow(periodId);
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
                            <button type="button" class="investigation-recommend primary-btn">Submit recommendation</button>
                        </div>
                    </div>
                ` : ""}
                ${status === "recommended" ? `
                    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        <strong>${aEscape(investigationLabel(row.recommended_action))} · ${aEscape(aMoney(row.recommended_amount || 0))}</strong>
                        <p class="mt-1">Recommended by ${aEscape(row.recommended_by_name || "Unknown")}.</p>
                        ${canApprove
                            ? '<button type="button" class="investigation-approve primary-btn">Approve and post correction</button>'
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
});

document.querySelectorAll("[data-acct-tab]").forEach((button) => {
    button.addEventListener("click", () => {
        activeAcctTab = button.getAttribute("data-acct-tab") || "employee-debts";
        acctPage = 1;
        renderActiveAccountingTab();
    });
});

document.getElementById("acct-body").addEventListener("click", async (event) => {
    const row = event.target.closest("[data-row-user]");
    if (row) {
        await openEmployeeModal(row.getAttribute("data-row-user"));
    }
});

document.getElementById("open-deduction-workflow")?.addEventListener("click", openDeductionWorkflow);
document.getElementById("open-debt-investigations").addEventListener("click", openInvestigationsModal);
document.getElementById("open-settlement-run").addEventListener("click", openSettlementModal);
document.getElementById("open-import-csv").addEventListener("click", openImportModal);
document.getElementById("close-deduction-workflow")?.addEventListener("click", closeDeductionWorkflow);
document.getElementById("close-debt-investigations").addEventListener("click", closeInvestigationsModal);
document.getElementById("close-settlement-run").addEventListener("click", closeSettlementModal);
document.getElementById("close-import-csv").addEventListener("click", closeImportModal);
document.getElementById("deduction-workflow-modal").addEventListener("click", (event) => {
    if (event.target.id === "deduction-workflow-modal" && !event.currentTarget.classList.contains("deductions-page-shell")) {
        closeDeductionWorkflow();
    }
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
document.getElementById("workflow-period-start").addEventListener("change", updateWorkflowPeriodPreview);
document.getElementById("workflow-period-end").addEventListener("change", updateWorkflowPeriodPreview);
document.getElementById("workflow-refresh").addEventListener("click", () => loadDeductionWorkflow());
document.getElementById("workflow-period-select").addEventListener("change", (event) => {
    loadDeductionWorkflow(Number(event.target.value || 0));
});
document.getElementById("workflow-prepare-batch").addEventListener("click", prepareWorkflowBatch);
document.getElementById("workflow-open-employees").addEventListener("click", () => {
    document.getElementById("workflow-employees-modal").style.display = "grid";
    document.getElementById("workflow-candidate-search").focus();
});
document.getElementById("workflow-close-employees").addEventListener("click", () => {
    document.getElementById("workflow-employees-modal").style.display = "none";
});
document.getElementById("workflow-employees-modal").addEventListener("click", (event) => {
    if (event.target.id === "workflow-employees-modal") event.currentTarget.style.display = "none";
});
document.getElementById("workflow-candidates").addEventListener("change", (event) => {
    if (event.target.closest(".workflow-candidate-check")) {
        filterWorkflowCandidates();
        return;
    }
    const choice = event.target.closest(".workflow-deduction-choice");
    if (!choice) return;
    const row = choice.closest(".workflow-candidate");
    const amount = row.querySelector(".workflow-request-amount");
    const reason = row.querySelector(".workflow-preparation-reason");
    const cutoff = Number(amount.dataset.cutoffDebt || 0);
    amount.readOnly = choice.value === "full";
    amount.disabled = choice.value === "none";
    amount.classList.toggle("hidden", choice.value === "none");
    reason.classList.toggle("hidden", choice.value !== "none");
    amount.classList.toggle("bg-slate-100", choice.value === "full");
    amount.classList.toggle("text-slate-600", choice.value === "full");
    amount.classList.toggle("bg-white", choice.value === "partial");
    amount.title = choice.value === "full" ? "Full deduction uses the complete cutoff debt" : "";
    if (choice.value === "full") amount.value = cutoff.toFixed(2);
    if (choice.value === "none") amount.value = "0.00";
    if (choice.value === "partial" && (Number(amount.value || 0) <= 0 || Number(amount.value || 0) >= cutoff)) {
        amount.value = (cutoff / 2).toFixed(2);
    }
});
document.getElementById("workflow-candidate-search").addEventListener("input", filterWorkflowCandidates);
document.getElementById("workflow-toggle-filters").addEventListener("click", (event) => {
    const panel = document.getElementById("workflow-filter-options");
    const opening = panel.hidden;
    panel.hidden = !opening;
    event.currentTarget.setAttribute("aria-expanded", opening ? "true" : "false");
});
document.getElementById("workflow-candidate-type").addEventListener("change", filterWorkflowCandidates);
document.getElementById("workflow-candidate-salary").addEventListener("change", filterWorkflowCandidates);
document.getElementById("workflow-select-matching").addEventListener("click", () => {
    document.querySelectorAll(".workflow-candidate:not(.hidden) .workflow-candidate-check").forEach((checkbox) => {
        checkbox.checked = true;
    });
    filterWorkflowCandidates();
});
document.getElementById("workflow-clear-selection").addEventListener("click", () => {
    document.querySelectorAll(".workflow-candidate-check").forEach((checkbox) => {
        checkbox.checked = false;
    });
    filterWorkflowCandidates();
});
document.getElementById("workflow-batch-actions").addEventListener("click", (event) => {
    if (event.target.closest("#workflow-edit-deductions")) {
        renderWorkflowCandidates();
        document.getElementById("workflow-employees-modal").style.display = "grid";
        return;
    }
    const button = event.target.closest("[data-workflow-batch-action]");
    if (button) advanceWorkflowBatch(button.dataset.workflowBatchAction || "");
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
    importCsvPreviewReady = false;
    document.getElementById("import-csv-submit").disabled = true;
    document.getElementById("import-csv-result").textContent = "";
    document.getElementById("import-csv-valid").innerHTML = "";
    document.getElementById("import-csv-invalid").innerHTML = "";
});
document.getElementById("close-settlement-run-details").addEventListener("click", closeSettlementRunDetails);
document.getElementById("settlement-details-export").addEventListener("click", exportSettlementDetailsCsv);

const workflow = document.getElementById("deduction-workflow-modal");
if (workflow?.classList.contains("deductions-page-shell")) {
    workflow.style.display = "block";
    openDeductionWorkflow();
}
document.getElementById("settlement-details-print").addEventListener("click", printSettlementDetails);
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
    document.getElementById("employee-limit-box").classList.remove("hidden");
});

document.getElementById("employee-limit-cancel").addEventListener("click", () => {
    document.getElementById("employee-limit-box").classList.add("hidden");
});

function refreshDynamicCreditPreview() {
    const schedule = selectedAcctSalarySchedule();
    const grade = Number(document.getElementById("employee-salary-grade").value || 0);
    const step = Number(document.getElementById("employee-salary-step").value || 0);
    const rate = (schedule?.rates || []).find((item) => Number(item.salary_grade) === grade && Number(item.salary_step) === step);
    const salary = Number(rate?.monthly_salary || 0);
    const percentage = Math.min(100, Math.max(0, Number(document.getElementById("employee-credit-percentage").value || 0)));
    document.getElementById("employee-salary-value").value = aMoney(salary);
    document.getElementById("employee-limit-value").value = aMoney(salary * percentage / 100);
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
document.getElementById("employee-salary-step").addEventListener("change", refreshDynamicCreditPreview);
document.getElementById("employee-credit-percentage").addEventListener("input", refreshDynamicCreditPreview);

document.getElementById("employee-limit-save").addEventListener("click", async () => {
    if (!employeeModalUserId) return;
    const employmentType = document.getElementById("employee-employment-type").value;
    const salaryGrade = (document.getElementById("employee-salary-grade").value || "").trim();
    const salaryStep = (document.getElementById("employee-salary-step").value || "").trim();
    const salaryScheduleId = Number(document.getElementById("employee-salary-schedule").value || 0);
    const effectiveDate = document.getElementById("employee-salary-effective-date").value;
    const creditPercentage = Number(document.getElementById("employee-credit-percentage").value || -1);
    const value = document.getElementById("employee-limit-value").value;
    const reason = (document.getElementById("employee-limit-reason").value || "").trim();

    if (!salaryScheduleId || !salaryGrade || !salaryStep || !effectiveDate || !reason || creditPercentage < 0 || creditPercentage > 100) {
        setAcctResult("Schedule, grade, step, employment type, effective date, credit percentage, and reason are required.", "error");
        return;
    }

    if (!await window.IbemsDialog.confirm(`Save this salary profile? The credit limit will be ${value} (${creditPercentage}%).`, {
        title: "Update salary-grade profile?",
        confirmLabel: "Save profile",
    })) return;

    const response = await fetch("/accounting/debts/financial-profile", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            user_id: employeeModalUserId,
            employment_type: employmentType,
            salary_schedule_id: salaryScheduleId,
            salary_grade: salaryGrade,
            salary_step: salaryStep,
            salary_effective_date: effectiveDate,
            credit_percentage: creditPercentage,
            reason,
        }),
    });
    const data = await response.json();
    if (!data || data.status !== "success") {
        setAcctResult(data?.message || "Failed to update financial profile.", "error");
        return;
    }

    setAcctResult(
        `${data.financial_profile_created ? "Financial profile created" : "Financial profile updated"}. Salary: ${aMoney(data.new_base_salary)} | Credit: ${aMoney(data.new_credit_limit)}`,
        "ok"
    );

    await loadData();
    await openEmployeeModal(employeeModalUserId);
});

(async () => {
    await loadData();
})();
