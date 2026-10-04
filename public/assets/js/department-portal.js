(function () {
"use strict";
const page = document.getElementById("department-portal-dashboard");
if (!page) return;
const signal = window.IbemsPortalNavigation?.currentSignal || new AbortController().signal;
const escapeHtml = (value) => { const node = document.createElement("div"); node.textContent = String(value ?? ""); return node.innerHTML; };
const money = (value) => `PHP ${Number(value || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const state = (message, type = "empty") => `<div class="data-state data-state--${type}" role="${type === "error" ? "alert" : "status"}"><i aria-hidden="true" class="bi ${type === "loading" ? "bi-arrow-repeat" : type === "error" ? "bi-exclamation-circle" : "bi-inbox"}"></i><div><strong>${escapeHtml(message)}</strong></div></div>`;
let dashboardRequestId = 0;
const loadDashboard = () => {
const requestId = ++dashboardRequestId;
const period = window.IbemsDashboardPeriod?.get() || "day";
const current = () => !signal.aborted && requestId === dashboardRequestId && (window.IbemsDashboardPeriod?.get() || "day") === period;
document.getElementById("department-portal-summary").innerHTML = state("Loading department accounts...", "loading");
document.getElementById("department-portal-entries").innerHTML = state("Loading recent entries...", "loading");
fetch(`/department/dashboard/data?period=${encodeURIComponent(period)}`, { signal }).then(async (response) => {
    const data = await response.json();
    if (!current()) return;
    if (!response.ok || data.status !== "success") throw new Error(data.message || "Unable to load the department workspace.");
    window.IbemsDashboardPeriod?.setMeta(data.period);
    const assignments = Array.isArray(data.assignments) ? data.assignments : [];
    document.getElementById("department-portal-summary").innerHTML = assignments.map((item) => `<article class="metric-card"><div class="metric-card-head"><span class="metric-card-label">${escapeHtml(item.name)}</span><span class="metric-card-icon metric-card-icon--finance"><i class="bi bi-building"></i></span></div><strong>${money(item.period_charges)}</strong><small>${Number(item.period_entry_count || 0)} selected-period entries · settlements ${money(item.period_settlements)}</small><small>${money(item.remaining_allocation)} remaining this month · ${escapeHtml(String(item.status || "not_configured").replaceAll("_", " ").replace(/^./, (letter) => letter.toUpperCase()))}</small></article>`).join("") || state("No active department accounts are assigned.");
    const entries = Array.isArray(data.recent_entries) ? data.recent_entries : [];
    document.getElementById("department-portal-entries").innerHTML = entries.map((entry) => `<article class="stack-row"><div><strong>${escapeHtml(entry.department_name)}</strong><small>${escapeHtml(entry.requester_name || entry.entry_type)} · ${escapeHtml(window.IbemsFormat?.dateTime(entry.created_at) || entry.created_at || "-")}</small></div><div class="stack-row-value"><strong>${money(entry.amount)}</strong><small>${escapeHtml(entry.reference_no || entry.direction)}</small></div></article>`).join("") || state("No department activity has been recorded yet.");
}).catch((error) => { if (current()) { document.getElementById("department-portal-summary").innerHTML = state(error.message, "error"); document.getElementById("department-portal-entries").innerHTML = state(error.message, "error"); } });
};
window.addEventListener("ibems:dashboard-period-change", loadDashboard, {signal});
loadDashboard();
}());
