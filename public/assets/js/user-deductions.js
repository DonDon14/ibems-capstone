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
    return `<tr class="data-state-row"><td colspan="7"><div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="bi ${icon}" aria-hidden="true"></i><div><strong>${udEscape(message)}</strong></div></div></td></tr>`;
}

function udRender(data) {
    const periods = Array.isArray(data.periods) ? data.periods : [];
    const rows = Array.isArray(data.deductions) ? data.deductions : [];
    const summary = data.summary || {};

    udPeriod.innerHTML = periods.length
        ? periods.map((period) => `<option value="${Number(period.id)}" ${Number(period.id) === Number(data.selected_period_id) ? "selected" : ""}>${udEscape(period.label || period.period_code)} (${udEscape(period.date_start)} to ${udEscape(period.date_end)})</option>`).join("")
        : '<option value="">No applied deduction periods</option>';
    udPeriod.disabled = periods.length === 0;

    document.getElementById("ud-total").textContent = udMoney(summary.total_deducted);
    document.getElementById("ud-before").textContent = udMoney(summary.debt_before);
    document.getElementById("ud-after").textContent = udMoney(summary.debt_after);
    document.getElementById("ud-count").textContent = String(Number(summary.entry_count || 0));

    if (!rows.length) {
        udBody.innerHTML = udState("No applied deduction was recorded for this pay period.");
        return;
    }

    udBody.innerHTML = rows.map((row) => `
        <tr>
            <td data-label="Pay Period"><strong>${udEscape(row.period_label || row.period_code)}</strong><br><span class="meta">${udEscape(row.date_start)} to ${udEscape(row.date_end)}</span></td>
            <td data-label="Applied Date">${udEscape(window.IbemsFormat?.dateTime(row.applied_at) || row.applied_at || "-")}</td>
            <td data-label="Requested">${udEscape(udMoney(row.requested_amount))}</td>
            <td data-label="Deducted"><strong>${udEscape(udMoney(row.deducted_amount))}</strong></td>
            <td data-label="Debt Before">${udEscape(udMoney(row.debt_before))}</td>
            <td data-label="Debt After">${udEscape(udMoney(row.debt_after))}</td>
            <td data-label="Status"><span class="user-deduction-status">${udEscape(udStatus(row.status))}</span></td>
        </tr>
    `).join("");
}

async function udLoad(periodId = "") {
    udBody.innerHTML = udState("Loading deductions...", "loading");
    const query = periodId ? `?period_id=${encodeURIComponent(periodId)}` : "";
    try {
        const response = await fetch(`/user/deductions/data${query}`, { signal: udPageSignal });
        const data = await response.json();
        if (!response.ok || data.status !== "success") throw new Error(data.message || "Unable to load deductions.");
        udRender(data);
    } catch (error) {
        if (udPageSignal.aborted) return;
        udBody.innerHTML = udState(error.message || "Unable to load deductions.", "error");
    }
}

udPeriod?.addEventListener("change", () => udLoad(udPeriod.value));
udLoad();
}());
