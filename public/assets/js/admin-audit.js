let auditRows = [];
let auditPage = 1;

function auditEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function auditDateTime(value) {
    if (!value) return "-";
    return window.IbemsFormat?.dateTime(value) || new Date(String(value).replace(" ", "T")).toLocaleString();
}

function auditSetResult(message, type) {
    const el = document.getElementById("audit-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function auditLabel(value) {
    return window.IbemsFormat?.identifierLabel(value) || String(value ?? "").replace(/[_-]+/g, " ");
}

function auditSetOptions(selectId, placeholder, values) {
    const select = document.getElementById(selectId);
    const current = select.value;
    select.innerHTML = [`<option value="">${auditEscape(placeholder)}</option>`]
        .concat((Array.isArray(values) ? values : []).map((value) => `<option value="${auditEscape(value)}">${auditEscape(auditLabel(value))}</option>`))
        .join("");
    if (current && select.querySelector(`option[value="${CSS.escape(current)}"]`)) {
        select.value = current;
    }
}

function auditRenderSummary(summary) {
    const safe = summary || {};
    document.getElementById("audit-total-events").textContent = String(Number(safe.total_events || 0));
    document.getElementById("audit-today-events").textContent = String(Number(safe.today_events || 0));
    document.getElementById("audit-actor-count").textContent = String(Number(safe.actor_count || 0));
    document.getElementById("audit-action-count").textContent = String(Number(safe.action_count || 0));
}

function auditRenderRows(rows, pagination = {}) {
    const body = document.getElementById("audit-body");
    const list = Array.isArray(rows) ? rows : [];
    const total = Number(pagination.total ?? list.length);
    document.getElementById("audit-count-text").textContent = `Showing ${list.length} of ${total} event${total === 1 ? "" : "s"}`;

    if (list.length === 0) {
        body.innerHTML = '<tr><td colspan="5">No audit events found.</td></tr>';
        return;
    }

    body.innerHTML = list.map((row) => `
        <tr class="audit-row" data-audit-id="${Number(row.id)}" tabindex="0" title="View audit event">
            <td><span class="audit-date">${auditEscape(auditDateTime(row.created_at))}</span></td>
            <td>
                <strong>${auditEscape(row.action_label || row.action || "-")}</strong>
            </td>
            <td>
                <strong>${auditEscape(row.actor_name || "System")}</strong>
                <small>${auditEscape(row.actor_email || "")}</small>
            </td>
            <td>
                <strong>${auditEscape(auditLabel(row.entity) || "-")}</strong>
                <small>${row.entity_id ? `#${Number(row.entity_id)}` : "-"}</small>
            </td>
            <td>${auditEscape(row.payload_summary || "-")}</td>
        </tr>
    `).join("");
}

function auditRenderPager(meta) {
    const page = Number(meta?.page || 1);
    const totalPages = Number(meta?.total_pages || 1);
    document.getElementById("audit-pager").innerHTML = `
        <button class="secondary-btn btn-sm" type="button" data-page="${page - 1}" ${page <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left"></i> Previous</button>
        <span>Page ${page} of ${totalPages}</span>
        <button class="secondary-btn btn-sm" type="button" data-page="${page + 1}" ${page >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right"></i></button>`;
}

async function auditLoad() {
    const params = new URLSearchParams({ page: String(auditPage) });
    const q = (document.getElementById("audit-search").value || "").trim();
    const action = (document.getElementById("audit-action-filter").value || "").trim();
    const entity = (document.getElementById("audit-entity-filter").value || "").trim();
    const dateFrom = (document.getElementById("audit-date-from").value || "").trim();
    const dateTo = (document.getElementById("audit-date-to").value || "").trim();
    const limit = (document.getElementById("audit-limit").value || "100").trim();
    const [sortBy, sortDir] = (document.getElementById("audit-sort").value || "date:desc").split(":");

    if (dateFrom && dateTo && dateFrom > dateTo) {
        auditSetResult("The start date must be on or before the end date.", "error");
        return;
    }

    if (q) params.set("q", q);
    if (action) params.set("action", action);
    if (entity) params.set("entity", entity);
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    params.set("page_size", limit);
    params.set("sort_by", sortBy);
    params.set("sort_dir", sortDir);

    try {
        const response = await fetch(`/admin/audit/data?${params.toString()}`);
        const data = await response.json();
        if (!response.ok || !data || data.status !== "success") {
            throw new Error(data?.message || "Unable to load audit events.");
        }

        auditRows = Array.isArray(data.data) ? data.data : [];
        auditSetOptions("audit-action-filter", "All Actions", data.actions || []);
        auditSetOptions("audit-entity-filter", "All Entities", data.entities || []);
        auditRenderSummary(data.summary || {});
        auditRenderRows(auditRows, data.pagination || {});
        auditRenderPager(data.pagination || {});
        auditSetResult("", "ok");
    } catch (error) {
        auditRows = [];
        auditRenderRows([], {});
        auditRenderPager({});
        auditRenderSummary({});
        auditSetResult(error instanceof Error ? error.message : "Unable to load audit events.", "error");
    }
}

function auditDetailItem(label, value) {
    return `
        <div class="audit-detail-item">
            <span>${auditEscape(label)}</span>
            <strong>${auditEscape(value || "-")}</strong>
        </div>
    `;
}

function auditOpenModal() {
    document.getElementById("audit-view-modal").classList.remove("is-hidden");
}

function auditCloseModal() {
    document.getElementById("audit-view-modal").classList.add("is-hidden");
}

function auditOpenDetail(auditId) {
    const row = auditRows.find((item) => Number(item.id) === Number(auditId));
    if (!row) {
        auditSetResult("Audit event not found.", "error");
        return;
    }

    const payload = JSON.stringify(row.payload || {}, null, 2);
    document.getElementById("audit-view-content").innerHTML = `
        <div class="audit-detail-items">
            ${auditDetailItem("Date", auditDateTime(row.created_at))}
            ${auditDetailItem("Action", row.action_label || row.action)}
            ${auditDetailItem("Raw Action", row.action)}
            ${auditDetailItem("Actor", `${row.actor_name || "System"}${row.actor_email ? ` (${row.actor_email})` : ""}`)}
            ${auditDetailItem("Entity", `${auditLabel(row.entity) || "-"}${row.entity_id ? ` #${row.entity_id}` : ""}`)}
            ${auditDetailItem("Event ID", String(row.id))}
        </div>
        <div class="audit-payload-block">
            <span>Payload JSON</span>
            <pre>${auditEscape(payload)}</pre>
        </div>
    `;
    auditOpenModal();
}

function auditReset() {
    document.getElementById("audit-search").value = "";
    document.getElementById("audit-action-filter").value = "";
    document.getElementById("audit-entity-filter").value = "";
    document.getElementById("audit-date-from").value = "";
    document.getElementById("audit-date-to").value = "";
    document.getElementById("audit-limit").value = "100";
    document.getElementById("audit-sort").value = "date:desc";
    auditPage = 1;
    auditLoad();
}

document.getElementById("audit-search-btn").addEventListener("click", () => { auditPage = 1; auditLoad(); });
document.getElementById("audit-refresh-btn").addEventListener("click", auditReset);
["audit-action-filter", "audit-entity-filter", "audit-date-from", "audit-date-to", "audit-limit", "audit-sort"].forEach((id) => {
    document.getElementById(id).addEventListener("change", () => { auditPage = 1; auditLoad(); });
});
document.getElementById("audit-search").addEventListener("input", () => {
    clearTimeout(window.__auditSearchTimer);
    window.__auditSearchTimer = setTimeout(() => { auditPage = 1; auditLoad(); }, 250);
});
document.getElementById("audit-pager").addEventListener("click", (event) => {
    const button = event.target.closest("[data-page]");
    if (!button || button.disabled) return;
    auditPage = Math.max(1, Number(button.dataset.page || 1));
    auditLoad();
});
document.getElementById("audit-body").addEventListener("click", (event) => {
    const row = event.target.closest(".audit-row");
    if (row) auditOpenDetail(Number(row.getAttribute("data-audit-id") || 0));
});
document.getElementById("audit-body").addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    const row = event.target.closest(".audit-row");
    if (!row) return;
    event.preventDefault();
    auditOpenDetail(Number(row.getAttribute("data-audit-id") || 0));
});
document.getElementById("audit-view-close").addEventListener("click", auditCloseModal);
document.getElementById("audit-view-modal").addEventListener("click", (event) => {
    if (event.target.id === "audit-view-modal") auditCloseModal();
});
document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !document.getElementById("audit-view-modal").classList.contains("is-hidden")) {
        auditCloseModal();
        document.getElementById("audit-view-close").focus();
    }
});

auditLoad();
