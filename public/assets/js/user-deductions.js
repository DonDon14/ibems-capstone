(function () {
"use strict";

const udPageSignal = window.IbemsUserNavigation?.currentSignal || new AbortController().signal;
const udPeriod = document.getElementById("ud-period");
const udBody = document.getElementById("ud-body");
if (!udPeriod || !udBody) return;

function udEscape(value) {
    const node = document.createElement("div");
    node.textContent = String(value ?? "");
    return node.innerHTML;
}

function udMoney(value) {
    return `PHP ${Number(value || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function udStatus(value) {
    return String(value || "applied").replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function udState(message, type = "empty") {
    const safeType = ["loading", "empty", "error"].includes(type) ? type : "empty";
    const icon = safeType === "loading" ? "bi-arrow-repeat" : safeType === "error" ? "bi-exclamation-circle" : "bi-inbox";
    const role = safeType === "error" ? "alert" : "status";
    return `<tr class="data-state-row"><td colspan="8"><div class="data-state data-state--${safeType}" role="${role}" aria-live="${safeType === "error" ? "assertive" : "polite"}" aria-atomic="true"><i class="bi ${icon}" aria-hidden="true"></i><div><strong>${udEscape(message)}</strong></div></div></td></tr>`;
}

function udRender(data) {
    const periods = Array.isArray(data.periods) ? data.periods : [];
    const rows = Array.isArray(data.deductions) ? data.deductions : [];
    const summary = data.summary || {};

    udPeriod.innerHTML = periods.length
        ? periods.map((period) => `<option value="${Number(period.id)}" ${Number(period.id) === Number(data.selected_period_id) ? "selected" : ""}>${udEscape(period.label || `${period.period_code} (${period.date_start} to ${period.date_end})`)}</option>`).join("")
        : '<option value="">No applied deduction periods</option>';
    udPeriod.disabled = periods.length === 0;

    document.getElementById("ud-total").textContent = udMoney(summary.total_deducted);
    document.getElementById("ud-before").textContent = udMoney(summary.debt_before);
    document.getElementById("ud-after").textContent = udMoney(summary.debt_after);
    document.getElementById("ud-count").textContent = String(Number(summary.entry_count || 0));

    if (!rows.length) {
        udBody.innerHTML = udState(periods.length
            ? "No applied deduction was recorded for this pay period."
            : "No confirmed deductions have been recorded for your account.");
        return;
    }

    udBody.innerHTML = rows.map((row) => `
        <tr>
            <td data-label="Pay Period"><strong>${udEscape(row.period_label || row.period_code)}</strong><br><span class="meta">${udEscape(row.date_start)} to ${udEscape(row.date_end)}</span></td>
            <td data-label="Applied Date">${udEscape(window.IbemsFormat?.dateTime(row.applied_at) || row.applied_at || "-")}</td>
            <td data-label="Requested">${udEscape(udMoney(row.requested_amount))}</td>
            <td data-label="Deducted"><strong>${udEscape(udMoney(row.deducted_amount))}</strong></td>
            <td data-label="Not deducted">${udEscape(udMoney(row.undeducted_amount))}</td>
            <td data-label="Debt Before">${udEscape(udMoney(row.debt_before))}</td>
            <td data-label="Debt After">${udEscape(udMoney(row.debt_after))}</td>
            <td data-label="Result details"><span class="user-deduction-status">${udEscape(udStatus(row.status))}</span>${row.reason_code ? `<br><span class="meta">Reason: ${udEscape(udStatus(row.reason_code))}</span>` : ""}${row.result_reference ? `<br><span class="meta">Ref: ${udEscape(row.result_reference)}</span>` : ""}</td>
        </tr>
    `).join("");
}

let udRequestId = 0;
async function udLoad(periodId = "") {
    const requestId = ++udRequestId;
    udBody.setAttribute("aria-busy", "true");
    ["ud-total", "ud-before", "ud-after", "ud-count"].forEach((id) => {
        document.getElementById(id).textContent = "Loading…";
    });
    udBody.innerHTML = udState("Loading deductions...", "loading");
    const query = periodId ? `?period_id=${encodeURIComponent(periodId)}` : "";
    try {
        const response = await fetch(`/user/deductions/data${query}`, { signal: udPageSignal });
        const data = await response.json();
        if (udPageSignal.aborted || requestId !== udRequestId) return;
        if (!response.ok || data.status !== "success") throw new Error(data.message || "Unable to load deductions.");
        udRender(data);
    } catch (error) {
        if (udPageSignal.aborted || requestId !== udRequestId) return;
        ["ud-total", "ud-before", "ud-after", "ud-count"].forEach((id) => {
            document.getElementById(id).textContent = "Unavailable";
        });
        udBody.innerHTML = udState("Unable to load deductions. Refresh the page or select a pay period to try again.", "error");
    } finally {
        if (!udPageSignal.aborted && requestId === udRequestId) udBody.setAttribute("aria-busy", "false");
    }
}

udPeriod?.addEventListener("change", () => udLoad(udPeriod.value));
udLoad();
}());
