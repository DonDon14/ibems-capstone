let acctRows = [];
let modeFilteredRows = [];
let modeSelectedUserId = null;
let employeeModalUserId = null;
let employeeModalProfile = null;

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

function renderRows(rows) {
    const body = document.getElementById("acct-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="7">No records found.</td></tr>';
        renderSummary([]);
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr data-row-user="${row.user_id}" class="acct-row-clickable">
            <td>${aEscape(row.employee_id || "-")}</td>
            <td>${aEscape(row.name)}</td>
            <td>${aEscape(row.email)}</td>
            <td>${aEscape(aCategory(row.user_type))}</td>
            <td>${aEscape(aMoney(row.current_debt))}</td>
            <td>${aEscape(aMoney(row.credit_limit))}</td>
            <td>${aEscape(aMoney(row.available_credit))}</td>
        </tr>
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

async function loadData() {
    const search = (document.getElementById("acct-search").value || "").trim();
    const debtOnly = document.getElementById("acct-debt-only").checked;

    const params = new URLSearchParams({ limit: "300" });
    if (search) params.set("q", search);
    if (debtOnly) params.set("debt_only", "1");

    const response = await fetch(`/accounting/debts/data?${params.toString()}`);
    const data = await response.json();

    if (!data || data.status !== "success") {
        acctRows = [];
        renderRows([]);
        setAcctResult(data?.message || "Unable to load debt records.", "error");
        return;
    }

    acctRows = Array.isArray(data.data) ? data.data : [];
    renderRows(acctRows);
    renderModeResults();
    await loadDailySummary();
    setAcctResult("", "ok");
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
        <div class="mode-profile-card">
            <h5>Selected Person</h5>
            <div class="mode-profile-grid">
                <div class="kv"><span>Name</span><strong>${aEscape(p.name)}</strong></div>
                <div class="kv"><span>Employee ID</span><strong>${aEscape(p.employee_id || "-")}</strong></div>
                <div class="kv"><span>Email</span><strong>${aEscape(p.email)}</strong></div>
                <div class="kv"><span>Category</span><strong>${aEscape(aCategory(p.user_type))}</strong></div>
                <div class="kv"><span>Current Debt</span><strong>${aEscape(aMoney(p.current_debt))}</strong></div>
                <div class="kv"><span>Credit Limit</span><strong>${aEscape(aMoney(p.credit_limit))}</strong></div>
                <div class="kv"><span>Available Credit</span><strong>${aEscape(aMoney(p.available_credit))}</strong></div>
                <div class="kv"><span>Updated At</span><strong>${aEscape(aDateTime(p.updated_at))}</strong></div>
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
            <div class="history-item">
                <div class="h-top">
                    <span>${aEscape(aDateTime(row.created_at))}</span>
                    <span>${aEscape(row.actor_name)}</span>
                </div>
                <div class="h-body">${aEscape(detail)}</div>
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
        wrap.innerHTML = '<div class="mode-profile-empty">No matching records.</div>';
        return;
    }

    wrap.innerHTML = modeFilteredRows.map((row) => `
        <button class="mode-item ${modeSelectedUserId === Number(row.user_id) ? "is-active" : ""}" type="button" data-mode-user="${row.user_id}">
            <span class="name">${aEscape(row.name)}</span>
            <span class="meta">${aEscape(row.employee_id || "-")} | Debt: ${aEscape(aMoney(row.current_debt))}</span>
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
                <div class="invalid-item">
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

document.getElementById("acct-search-btn").addEventListener("click", async () => {
    await loadData();
});

document.getElementById("acct-refresh-btn").addEventListener("click", async () => {
    await loadData();
});

document.getElementById("acct-body").addEventListener("click", async (event) => {
    const row = event.target.closest("[data-row-user]");
    if (row) {
        await openEmployeeModal(row.getAttribute("data-row-user"));
    }
});

document.getElementById("open-deduction-mode").addEventListener("click", openMode);
document.getElementById("open-import-csv").addEventListener("click", openImportModal);
document.getElementById("close-deduction-mode").addEventListener("click", closeMode);
document.getElementById("close-import-csv").addEventListener("click", closeImportModal);
document.getElementById("deduction-mode-modal").addEventListener("click", (event) => {
    if (event.target.id === "deduction-mode-modal") closeMode();
});
document.getElementById("import-csv-modal").addEventListener("click", (event) => {
    if (event.target.id === "import-csv-modal") closeImportModal();
});
document.getElementById("import-csv-submit").addEventListener("click", submitImportCsv);

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
