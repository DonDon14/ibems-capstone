(function () {
"use strict";
const page = document.getElementById("department-portal-dashboard");
if (!page) return;
const signal = window.IbemsPortalNavigation?.currentSignal || new AbortController().signal;
const escapeHtml = (value) => { const node = document.createElement("div"); node.textContent = String(value ?? ""); return node.innerHTML; };
const money = (value) => `PHP ${Number(value || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const state = (message) => `<div class="data-state data-state--empty" role="status"><i class="bi bi-inbox"></i><div><strong>${escapeHtml(message)}</strong></div></div>`;
const loadDashboard = () => {
const period = window.IbemsDashboardPeriod?.get() || "day";
fetch(`/department/dashboard/data?period=${encodeURIComponent(period)}`, { signal }).then(async (response) => {
    const data = await response.json();
    if ((window.IbemsDashboardPeriod?.get() || "day") !== period) return;
    if (!response.ok || data.status !== "success") throw new Error(data.message || "Unable to load the department workspace.");
    window.IbemsDashboardPeriod?.setMeta(data.period);
    const assignments = Array.isArray(data.assignments) ? data.assignments : [];
    document.getElementById("department-portal-summary").innerHTML = assignments.map((item) => `<article class="metric-card"><div class="metric-card-head"><span class="metric-card-label">${escapeHtml(item.name)}</span><span class="metric-card-icon metric-card-icon--finance"><i class="bi bi-building"></i></span></div><strong>${money(item.period_charges)}</strong><small>${Number(item.period_entry_count || 0)} selected-period entries · settlements ${money(item.period_settlements)}</small><small>${money(item.remaining_allocation)} remaining this month · ${escapeHtml(item.status)}</small></article>`).join("") || state("No active department accounts are assigned.");
    const entries = Array.isArray(data.recent_entries) ? data.recent_entries : [];
    document.getElementById("department-portal-entries").innerHTML = entries.map((entry) => `<article class="stack-row"><div><strong>${escapeHtml(entry.department_name)}</strong><small>${escapeHtml(entry.requester_name || entry.entry_type)} · ${escapeHtml(entry.created_at)}</small></div><div class="stack-row-value"><strong>${money(entry.amount)}</strong><small>${escapeHtml(entry.reference_no || entry.direction)}</small></div></article>`).join("") || state("No department activity has been recorded yet.");
}).catch((error) => { if (!signal.aborted) { document.getElementById("department-portal-summary").innerHTML = state(error.message); document.getElementById("department-portal-entries").innerHTML = state(error.message); } });
};
window.addEventListener("ibems:dashboard-period-change", loadDashboard, {signal});
loadDashboard();
}());
