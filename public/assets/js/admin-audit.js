let auditRows = [];
let auditPage = 1;
let auditModalOpener = null;
let auditRequestId = 0;
let auditResetting = false;

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
    el.setAttribute("role", type === "error" ? "alert" : "status");
    el.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
    el.setAttribute("aria-atomic", "true");
    el.textContent = message || "";
    el.style.color = type === "error" ? "var(--feedback-error, #b91c1c)" : "var(--feedback-success, #166534)";
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

function auditSetSummaryState(value) {
    ["audit-total-events", "audit-today-events", "audit-actor-count", "audit-action-count"].forEach(id => {
        document.getElementById(id).textContent = value;
    });
}

function auditSetListState(type, message = "", detail = "") {
    const region = document.getElementById("audit-table-region");
    const isState = type !== "rows";
    region.classList.toggle("is-audit-list-state", isState);
    if (isState) region.removeAttribute("tabindex");
    else region.setAttribute("tabindex", "0");
    if (!isState) return;
    const error = type === "error" || type === "validation";
    const icon = error ? "bi-exclamation-circle" : type === "loading" ? "bi-arrow-repeat" : "bi-inbox";
    const action = type === "error" ? '<button type="button" class="secondary-btn" data-audit-retry>Retry audit events</button>'
        : type === "validation" ? '<button type="button" class="secondary-btn" data-audit-review-dates>Review date range</button>' : "";
    document.getElementById("audit-body").innerHTML = `<tr><td colspan="5"><div class="data-state data-state--${error ? "error" : type}" role="${error ? "alert" : "status"}" aria-live="${error ? "assertive" : "polite"}" aria-atomic="true" tabindex="-1"><i class="bi ${icon}" aria-hidden="true"></i><div><strong>${auditEscape(message)}</strong>${detail ? `<small>${auditEscape(detail)}</small>` : ""}</div>${action}</div></td></tr>`;
}

function auditSetDateValidity(invalid) {
    const input = document.getElementById("audit-date-from");
    const trigger = input.closest(".ui-date")?.querySelector(".ui-date-trigger");
    [input, trigger].filter(Boolean).forEach(control => {
        if (invalid) control.setAttribute("aria-invalid", "true");
        else control.removeAttribute("aria-invalid");
    });
}

function auditReviewDates() {
    const input = document.getElementById("audit-date-from");
    const target = input.closest(".ui-date")?.querySelector(".ui-date-trigger") || input;
    if (!target.getClientRects().length) document.querySelector('.audit-filter-panel [data-compact-filter-toggle="filter"]')?.click();
    target.focus();
    target.scrollIntoView({block: "nearest", inline: "nearest"});
}

function auditRenderRows(rows, pagination = {}) {
    const body = document.getElementById("audit-body");
    const list = Array.isArray(rows) ? rows : [];
    const total = Number(pagination.total ?? list.length);
    document.getElementById("audit-count-text").textContent = `Showing ${list.length} of ${total} event${total === 1 ? "" : "s"}`;

    if (list.length === 0) {
        auditSetListState("empty", "No audit events found.");
        return;
    }

    auditSetListState("rows");

    body.innerHTML = list.map((row) => `
        <tr class="audit-row" data-audit-id="${Number(row.id)}" tabindex="0" title="View audit event">
            <td><span class="audit-date">${auditEscape(auditDateTime(row.created_at))}</span></td>
            <td>
                <strong>${auditEscape(row.action_label || row.action || "-")}</strong>
            </td>
            <td>
                <span class="table-person-cell">
                    ${window.IbemsAvatar.html(row.actor_name || "System", row.actor_profile_image_url, "table-person-avatar")}
                    <span><strong>${auditEscape(row.actor_name || "System")}</strong><small>${auditEscape(row.actor_email || "")}</small></span>
                </span>
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
    if (Number(meta?.total ?? auditRows.length) === 0) {
        document.getElementById("audit-pager").innerHTML = "";
        return;
    }
    const page = Number(meta?.page || 1);
    const totalPages = Number(meta?.total_pages || 1);
    document.getElementById("audit-pager").innerHTML = `
        <button class="secondary-btn btn-sm" type="button" aria-label="Previous audit page" data-page="${page - 1}" ${page <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left"></i> Previous</button>
        <span>Page ${page} of ${totalPages}</span>
        <button class="secondary-btn btn-sm" type="button" aria-label="Next audit page" data-page="${page + 1}" ${page >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right"></i></button>`;
}

async function auditLoad() {
    const requestId = ++auditRequestId;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const isCurrent = () => requestId === auditRequestId && !signal?.aborted;
    const body = document.getElementById("audit-body");
    const pager = document.getElementById("audit-pager");
    const focusLabel = pager.contains(document.activeElement) ? document.activeElement.getAttribute("aria-label") : null;
    const restoreListFocus = body.contains(document.activeElement) || Boolean(focusLabel);
    const params = new URLSearchParams({ page: String(auditPage) });
    const q = (document.getElementById("audit-search").value || "").trim();
    const action = (document.getElementById("audit-action-filter").value || "").trim();
    const entity = (document.getElementById("audit-entity-filter").value || "").trim();
    const dateFrom = (document.getElementById("audit-date-from").value || "").trim();
    const dateTo = (document.getElementById("audit-date-to").value || "").trim();
    const limit = (document.getElementById("audit-limit").value || "100").trim();
    const [sortBy, sortDir] = (document.getElementById("audit-sort").value || "date:desc").split(":");

    if (dateFrom && dateTo && dateFrom > dateTo) {
        auditRows = [];
        body.removeAttribute("aria-busy");
        auditSetDateValidity(true);
        auditSetSummaryState("Needs correction");
        auditSetListState("validation", "Date range needs correction", "The start date must be on or before the end date.");
        document.getElementById("audit-count-text").textContent = "Date range needs correction";
        document.getElementById("audit-pager").innerHTML = "";
        auditSetResult("", "ok");
        if (restoreListFocus && document.activeElement === document.body) body.querySelector("[data-audit-review-dates]")?.focus({preventScroll: true});
        return;
    }

    auditSetDateValidity(false);

    if (q) params.set("q", q);
    if (action) params.set("action", action);
    if (entity) params.set("entity", entity);
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    params.set("page_size", limit);
    params.set("sort_by", sortBy);
    params.set("sort_dir", sortDir);

    auditRows = [];
    body.setAttribute("aria-busy", "true");
    auditSetSummaryState("Loading…");
    auditSetListState("loading", "Loading audit events…");
    if (restoreListFocus && document.activeElement === document.body) body.querySelector(".data-state")?.focus({preventScroll: true});
    document.getElementById("audit-count-text").textContent = "Loading events…";
    document.getElementById("audit-pager").innerHTML = "";
    auditSetResult("", "ok");
    let failureMessage = "The audit events could not be loaded. Retry to refresh them.";
    try {
        const response = await fetch(`/admin/audit/data?${params.toString()}`, { signal });
        const data = await response.json();
        if (!isCurrent()) return;
        if (!response.ok || !data || data.status !== "success") {
            if (typeof data?.message === "string" && data.message.trim()) failureMessage = data.message;
            throw new Error("Audit unavailable");
        }
        if (!Array.isArray(data.data)) throw new Error("Unreadable audit records");

        auditRows = Array.isArray(data.data) ? data.data : [];
        auditSetOptions("audit-action-filter", "All Actions", data.actions || []);
        auditSetOptions("audit-entity-filter", "All Entities", data.entities || []);
        auditRenderSummary(data.summary || {});
        auditRenderRows(auditRows, data.pagination || {});
        auditRenderPager(data.pagination || {});
        auditSetResult("", "ok");
    } catch (error) {
        if (!isCurrent()) return;
        auditRows = [];
        auditSetListState("error", "Audit events unavailable", failureMessage);
        document.getElementById("audit-count-text").textContent = "Events unavailable";
        document.getElementById("audit-pager").innerHTML = "";
        auditSetSummaryState("Unavailable");
        auditSetResult("", "ok");
    } finally {
        if (isCurrent()) {
            body.removeAttribute("aria-busy");
            if (restoreListFocus && document.activeElement === document.body) {
                const buttons = Array.from(pager.querySelectorAll("button"));
                const target = buttons.find(button => !button.disabled && button.getAttribute("aria-label") === focusLabel)
                    || (focusLabel ? buttons.find(button => !button.disabled) : null)
                    || body.querySelector("[data-audit-retry]") || body.querySelector(".audit-row")
                    || body.querySelector(".data-state") || document.getElementById("audit-refresh-btn");
                target?.focus({ preventScroll: true });
            }
        }
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
    auditModalOpener = document.activeElement;
    document.getElementById("audit-view-modal").classList.remove("is-hidden");
    document.body.classList.add("audit-modal-open");
    document.getElementById("audit-view-content").scrollTop = 0;
    document.getElementById("audit-view-close").focus();
}

function auditCloseModal() {
    document.getElementById("audit-view-modal").classList.add("is-hidden");
    document.body.classList.remove("audit-modal-open");
    if (auditModalOpener?.isConnected) auditModalOpener.focus({ preventScroll: true });
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
            <div class="audit-detail-item"><span>Actor</span><div class="table-person-cell">${window.IbemsAvatar.html(row.actor_name || "System", row.actor_profile_image_url, "table-person-avatar")}<strong>${auditEscape(`${row.actor_name || "System"}${row.actor_email ? ` (${row.actor_email})` : ""}`)}</strong></div></div>
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
    auditResetting = true;
    try {
        ["audit-action-filter", "audit-entity-filter", "audit-date-from", "audit-date-to", "audit-limit", "audit-sort"].forEach(id => {
            document.getElementById(id).dispatchEvent(new Event("change", { bubbles: true }));
        });
    } finally { auditResetting = false; }
    auditPage = 1;
    auditLoad();
}

document.getElementById("audit-search-btn").addEventListener("click", () => { auditPage = 1; auditLoad(); });
document.getElementById("audit-refresh-btn").addEventListener("click", auditReset);
["audit-action-filter", "audit-entity-filter", "audit-date-from", "audit-date-to", "audit-limit", "audit-sort"].forEach((id) => {
    document.getElementById(id).addEventListener("change", () => { if (!auditResetting) { auditPage = 1; auditLoad(); } });
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
    if (event.target.closest("[data-audit-retry]")) { auditLoad(); return; }
    if (event.target.closest("[data-audit-review-dates]")) { event.stopPropagation(); auditReviewDates(); return; }
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
    const modal = document.getElementById("audit-view-modal");
    if (event.defaultPrevented || modal.classList.contains("is-hidden")) return;
    if (event.key === "Escape") {
        event.preventDefault();
        auditCloseModal();
    } else if (event.key === "Tab") {
        const controls = Array.from(modal.querySelectorAll('button, [tabindex]'))
            .filter(control => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault(); last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault(); first?.focus();
        }
    }
});

auditLoad();
