function sdMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

let sdDaySessions = [];
let sdInventoryRows = [];
let sdTransactionRows = [];
const sdPages = { sessions: 1, inventory: 1, transactions: 1 };
let sdStaleDayTrigger = null;
let sdDetailsSequence = 0;
let sdDetailsLoading = false;
let sdDetailsState = "loading";
let sdActionBusy = false;
let sdActionVersion = 0;
const sdReviewDrafts = new Map();

function sdDateTime(value) {
    return window.IbemsFormat?.dateTime(value) || new Date(value).toLocaleString();
}

function sdEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function sdInitials(text) {
    return String(text || "")
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => (word[0] || "").toUpperCase())
        .join("") || "PR";
}

function sdIdentifierLabel(value) {
    return window.IbemsFormat?.identifierLabel(value) || String(value || "").replace(/[_-]+/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function sdDataState(type, message, colspan = 0, announce = true) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = announce ? (safeType === "error" ? "alert" : "status") : "group";
    const content = `<div class="data-state data-state--${safeType}" role="${role}" aria-live="${announce ? (safeType === "error" ? "assertive" : "polite") : "off"}" aria-atomic="true"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${sdEscape(message)}</strong></div></div>`;
    return colspan > 0 ? `<tr class="data-state-row"><td colspan="${Number(colspan)}">${content}</td></tr>` : content;
}

function sdPageSize(section) {
    const controlName = section === "sessions" ? "session" : section;
    return Math.max(1, Number(document.getElementById(`sd-${controlName}-page-size`)?.value || 10));
}

function sdPageSlice(section, rows) {
    const pageSize = sdPageSize(section);
    const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
    sdPages[section] = Math.min(Math.max(1, sdPages[section] || 1), totalPages);
    const start = (sdPages[section] - 1) * pageSize;
    return { rows: rows.slice(start, start + pageSize), start, totalPages };
}

function sdRenderPager(section, totalRows) {
    const controlName = section === "sessions" ? "session" : section;
    const pager = document.getElementById(`sd-${controlName}-pager`);
    if (!pager) return;
    if (!totalRows) { pager.innerHTML = ""; return; }
    const pageSize = sdPageSize(section);
    const totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
    const page = Math.min(Math.max(1, sdPages[section] || 1), totalPages);
    const start = totalRows === 0 ? 0 : (page - 1) * pageSize + 1;
    const end = Math.min(page * pageSize, totalRows);
    if (totalPages === 1) { pager.innerHTML = `<span>${start}–${end} of ${totalRows}</span>`; return; }
    pager.innerHTML = `
        <span>${start}–${end} of ${totalRows}</span>
        <div class="store-detail-pager-actions">
            <button class="secondary-btn btn-sm" type="button" data-sd-page-section="${section}" data-sd-page="${page - 1}" ${page <= 1 ? "disabled" : ""} aria-label="Previous ${section} page"><i class="bi bi-chevron-left"></i> Previous</button>
            <strong role="status" aria-live="polite" aria-atomic="true">Page ${page} of ${totalPages}</strong>
            <button class="secondary-btn btn-sm" type="button" data-sd-page-section="${section}" data-sd-page="${page + 1}" ${page >= totalPages ? "disabled" : ""} aria-label="Next ${section} page">Next <i class="bi bi-chevron-right"></i></button>
        </div>`;
}

function sdReviewLabel(value) {
    const labels = {
        not_required: "No Review Needed",
        pending: "Pending Review",
        approved: "Approved",
        waived: "Waived",
        corrected: "Corrected",
        needs_investigation: "Needs Investigation",
    };
    return labels[String(value || "not_required")] || "Review";
}

function sdVarianceStatusLabel(value) {
    const labels = {
        balanced: "Balanced",
        shortage: "Shortage",
        overage: "Overage",
    };
    return labels[String(value || "balanced")] || "Variance";
}

function sdShortageAmount(session) {
    return Math.max(0, -Number(session?.variance_cash || 0)) + Math.max(0, -Number(session?.variance_ecash || 0));
}

function sdReviewActionDetails(session, action) {
    const consequences = {
        approve_shortage: `Approve the shortage and assign ${sdMoney(sdShortageAmount(session))} as operator accountability to ${session.accountability_user_name || session.closed_by_name || "the closing operator"}.`,
        waive: "Close the variance without assigning operator accountability. The original counts and variance remain in the audit history.",
        corrected: "Accept the attached correction evidence and close the case as corrected. This does not change the original counts or reopen the store day.",
        needs_investigation: "Keep the variance unresolved and move it into investigation. No debt or final disposition is recorded.",
    };
    return consequences[action] || "Record a review decision for this variance.";
}

function sdReviewPreview(session, action) {
    const varianceTotal = Number(session.variance_cash || 0) + Number(session.variance_ecash || 0);
    const evidenceCount = Array.isArray(session?.variance_case?.attachments) ? session.variance_case.attachments.length : 0;
    const finalAction = ["approve_shortage", "waive", "corrected"].includes(action);
    return [
        `Business date: ${session.business_date || "-"}`,
        `Closed by: ${session.closed_by_name || "-"}`,
        `Expected: ${sdMoney(session.expected_cash || 0)} cash; ${sdMoney(session.expected_ecash || 0)} e-cash`,
        `Counted: ${sdMoney(session.counted_cash || 0)} cash; ${sdMoney(session.counted_ecash || 0)} e-cash`,
        `Total variance: ${sdMoney(varianceTotal)} (${sdVarianceStatusLabel(session.variance_status)})`,
        `Supporting files: ${evidenceCount}`,
        finalAction && evidenceCount === 0 ? "Final disposition blocked: attach at least one supporting file in the variance case first." : "",
        "",
        sdReviewActionDetails(session, action),
    ].join("\n");
}

function sdVarianceClass(value) {
    const status = String(value || "balanced");
    if (status === "shortage") return "is-danger";
    if (status === "overage") return "is-warning";
    return "is-success";
}

function sdReviewClass(value) {
    const status = String(value || "not_required");
    if (status === "pending" || status === "needs_investigation") return "is-warning";
    if (status === "approved") return "is-danger";
    if (status === "waived" || status === "corrected") return "is-success";
    return "is-muted";
}

function sdCaseSummary(session) {
    const varianceCase = session?.variance_case;
    if (!varianceCase) return "";
    const events = Array.isArray(varianceCase.events) ? varianceCase.events : [];
    const eventRows = events.map((event) => `
        <li>
            <strong>${sdEscape(String(event.event_type || "update").replace(/_/g, " "))}</strong>
            by ${sdEscape(event.actor_name || "System")}${event.created_at ? ` on ${sdEscape(sdDateTime(event.created_at))}` : ""}
            ${event.note ? `<div>${sdEscape(event.note)}</div>` : ""}
        </li>
    `).join("");
    const eligible = Array.isArray(session.eligible_reviewers) ? session.eligible_reviewers : [];
    const attachments = Array.isArray(varianceCase.attachments) ? varianceCase.attachments : [];
    const handoffs = Array.isArray(varianceCase.handoffs) ? varianceCase.handoffs : [];
    const latestHandoff = handoffs.length ? handoffs[handoffs.length - 1] : null;
    const handoffOverdue = latestHandoff?.status === "pending" && latestHandoff?.due_at && new Date(latestHandoff.due_at).getTime() < Date.now();
    const portalPrefix = window.location.pathname.includes("/store-admin/") ? "/store-admin" : "/admin";
    return `
        <details class="variance-case">
            <summary>Case ${sdEscape(varianceCase.case_ref || "-")} · ${sdEscape(String(varianceCase.status || "open").replace(/_/g, " "))}</summary>
            <div class="variance-note">Owner: ${sdEscape(varianceCase.owner_name || "Unassigned")}</div>
            ${eligible.length > 0 ? `<div class="variance-note">Eligible independent reviewers: ${eligible.map((reviewer) => sdEscape(reviewer.name || reviewer.email || "Reviewer")).join(", ")}</div>` : ""}
            ${eventRows ? `<ol class="variance-case-timeline">${eventRows}</ol>` : ""}
            ${handoffs.map((handoff) => `<div class="variance-note">Handoff: ${sdEscape(handoff.from_name || "-")} → ${sdEscape(handoff.to_name || "-")} (${sdEscape(handoff.status || "pending")})${handoff.due_at ? ` · due ${sdEscape(sdDateTime(handoff.due_at))}` : ""}</div>`).join("")}
            ${handoffOverdue ? `<div class="variance-case-overdue">Reviewer acknowledgment is overdue.</div>` : ""}
            ${varianceCase.can_acknowledge ? `<button type="button" class="primary-btn btn-sm" data-case-acknowledge="${Number(varianceCase.id || 0)}">Acknowledge handoff</button>` : ""}
            ${attachments.length ? `<ul class="variance-case-files">${attachments.map((attachment) => `<li><a href="${portalPrefix}/variance-case-attachments/${Number(attachment.id)}">${sdEscape(attachment.original_name)}</a> · ${sdEscape(attachment.uploaded_by_name || "-")}${attachment.retention_until ? ` · retain through ${sdEscape(attachment.retention_until)}` : ""}</li>`).join("")}</ul>` : `<div class="variance-note">No evidence files attached. Final disposition is blocked until evidence is attached.</div>`}
            <form class="variance-evidence-form" data-case-id="${Number(varianceCase.id || 0)}">
                <input type="file" name="evidence_file" accept="application/pdf,image/jpeg,image/png" required aria-label="Evidence file">
                <input type="text" name="description" maxlength="255" placeholder="Evidence description" aria-label="Evidence description">
                <button type="submit" class="secondary-btn btn-sm">Attach evidence</button>
                <p class="sd-case-feedback sd-action-feedback" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></p>
            </form>
            ${eligible.length ? `<form class="variance-handoff-form" data-case-id="${Number(varianceCase.id || 0)}">
                <select name="to_user_id" required aria-label="Handoff reviewer"><option value="">Select reviewer</option>${eligible.map((reviewer) => `<option value="${Number(reviewer.id)}">${sdEscape(reviewer.name || reviewer.email)}</option>`).join("")}</select>
                <input type="text" name="note" maxlength="255" required placeholder="Handoff note" aria-label="Handoff note">
                <button type="submit" class="secondary-btn btn-sm">Send handoff</button>
                <p class="sd-case-feedback sd-action-feedback" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></p>
            </form>` : ""}
        </details>
    `;
}

function sdSetActionFeedback(host, message, type = "pending") {
    if (!host) return;
    host.classList.add("sd-action-feedback");
    for (const tone of ["pending", "error", "warning", "success"]) host.classList.toggle(`is-${tone}`, tone === type);
    host.setAttribute("role", type === "error" ? "alert" : "status");
    host.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
    host.setAttribute("aria-atomic", "true");
    host.setAttribute("tabindex", "-1");
    host.textContent = message || "";
}

function sdBeginAction(origin, host, message, button = null, label = "Saving…") {
    const root = document.querySelector("[data-store-id]");
    const signal = window.IbemsPortalNavigation?.currentSignal;
    if (sdActionBusy || sdDetailsLoading || sdDetailsState !== "ready" || signal?.aborted || !root?.isConnected || !origin?.isConnected || !origin.getClientRects().length) return null;
    const version = ++sdActionVersion;
    const current = () => version === sdActionVersion && !signal?.aborted && root.isConnected;
    host ||= document.getElementById("sd-action-feedback");
    const previousFocus = document.activeElement;
    const restoreFocus = origin.contains(previousFocus) || origin.closest(".sd-stale-day-modal")?.contains(previousFocus) || previousFocus === document.body;
    const ownsFocus = () => restoreFocus && (host?.contains(document.activeElement) || document.activeElement === previousFocus || document.activeElement === document.body);
    const controls = Array.from(root.querySelectorAll("button, input, select, textarea"));
    const disabledStates = controls.map(control => [control, control.disabled]);
    const originalLabel = button?.innerHTML;
    const busyHost = origin.closest("form") || origin;
    const originalBusy = busyHost.getAttribute("aria-busy");
    let released = false;
    sdActionBusy = true;
    disabledStates.forEach(([control]) => { control.disabled = true; });
    busyHost.setAttribute("aria-busy", "true");
    if (button) button.textContent = label;
    const pageFeedback = document.getElementById("sd-action-feedback");
    if (host !== pageFeedback) sdSetActionFeedback(pageFeedback, "");
    sdSetActionFeedback(host, message);
    if (ownsFocus()) host?.focus();
    const release = () => {
        if (released || !current()) return;
        released = true;
        disabledStates.forEach(([control, disabled]) => { control.disabled = disabled; });
        if (button) button.innerHTML = originalLabel;
        if (originalBusy === null) busyHost.removeAttribute("aria-busy");
        else busyHost.setAttribute("aria-busy", originalBusy);
        sdActionBusy = false;
    };
    const feedback = (text, type) => {
        if (!current()) return;
        sdSetActionFeedback(host, text, type);
        if (ownsFocus()) { host?.focus({ preventScroll: true }); host?.scrollIntoView({ block: "nearest", inline: "nearest" }); }
    };
    return { current, signal, origin, feedback, ownsFocus, release,
        label(text) { if (current() && button) button.textContent = text; },
        cancel() { if (!current()) return; const owned = ownsFocus(); sdSetActionFeedback(host, ""); release(); if (owned) (previousFocus?.isConnected && !previousFocus.disabled ? previousFocus : origin).focus(); }
    };
}

async function sdPostAction(action, url, options) {
    if (!action.current() || !action.origin.isConnected) return false;
    try {
        const response = await fetch(url, { ...options, signal: action.signal });
        const data = await response.json();
        if (!action.current() || !action.origin.isConnected) return false;
        if (data?.status === "error") {
            action.feedback(typeof data.message === "string" && data.message.trim() ? data.message : "The action was rejected. Review the details before trying again.", "error");
            try { window.IbemsFeedbackSound?.play("error"); } catch (_) {}
            return false;
        }
        if (!response.ok || data?.status !== "success") throw new Error("Confirmation unavailable");
        try { window.IbemsFeedbackSound?.play("success"); } catch (_) {}
        return data;
    } catch (error) {
        if (action.current() && action.origin.isConnected) action.feedback("The action could not be confirmed. Check the store day or variance case before trying again.", "warning");
        return false;
    }
}

async function sdFinishAction(action, message, closeModal = false) {
    if (!action.current()) return;
    const owned = action.ownsFocus();
    action.release();
    if (closeModal) sdCloseStaleDayModal(true, owned);
    const pageFeedback = document.getElementById("sd-action-feedback");
    sdSetActionFeedback(pageFeedback, message, "success");
    if (owned) pageFeedback?.focus();
    let refreshed = false;
    try { refreshed = await loadStoreDetails({ afterAction: true }); } catch (_) {}
    if (!action.current()) return;
    if (!refreshed) sdSetActionFeedback(pageFeedback, `${message} Store details could not be refreshed. Use Retry store details to read the latest records.`, "warning");
    const focused = document.activeElement;
    if (focused === document.getElementById("sd-refresh") || focused?.closest?.("#sd-load-feedback")) focused.scrollIntoView({ block: "nearest", inline: "nearest" });
}

async function sdSubmitCaseForm(form, kind) {
    if (sdActionBusy || !["attachments", "handoff"].includes(kind) || !form.reportValidity()) return;
    const caseId = Number(form.getAttribute("data-case-id") || 0);
    if (!caseId) return;
    const prefix = window.location.pathname.includes("/store-admin/") ? "/store-admin" : "/admin";
    const options = { method: "POST" };
    if (kind === "attachments") options.body = new FormData(form);
    else {
        const payload = Object.fromEntries(new FormData(form).entries());
        options.headers = { "Content-Type": "application/json" };
        options.body = JSON.stringify(payload);
    }
    const attachment = kind === "attachments";
    const action = sdBeginAction(form, form.querySelector(".sd-case-feedback"), attachment ? "Attaching evidence…" : "Sending handoff…", form.querySelector('button[type="submit"]'), attachment ? "Attaching…" : "Sending…");
    if (!action) return;
    try {
        const data = await sdPostAction(action, `${prefix}/variance-cases/${caseId}/${kind}`, options);
        if (data) await sdFinishAction(action, attachment ? "Evidence attached." : "Handoff sent.");
    } finally { action.release(); }
}

async function sdAcknowledgeCase(caseId, trigger) {
    if (!Number.isInteger(Number(caseId)) || Number(caseId) <= 0) return;
    const action = sdBeginAction(trigger, document.getElementById("sd-action-feedback"), "Acknowledging handoff…", trigger, "Acknowledging…");
    if (!action) return;
    const prefix = window.location.pathname.includes("/store-admin/") ? "/store-admin" : "/admin";
    try {
        const data = await sdPostAction(action, `${prefix}/variance-cases/${Number(caseId)}/acknowledge`, { method: "POST" });
        if (data) await sdFinishAction(action, "Handoff acknowledged.");
    } finally { action.release(); }
}

function sdRenderDaySession(session) {
    const wrap = document.getElementById("sd-day-session");
    if (!wrap) return;

    if (!session) {
        wrap.innerHTML = sdDataState("empty", "No store day session recorded yet.");
        return;
    }

    const status = String(session.status || "").toUpperCase() || "UNKNOWN";
    const openedBy = session.opened_by_name || "Unknown";
    const closedBy = session.closed_by_name || "-";
    const isClosed = String(session.status || "") === "closed";
    const varianceStatus = String(session.variance_status || "balanced");
    const reviewStatus = String(session.review_status || "not_required");
    const shortageAmount = sdShortageAmount(session);
    const accountabilityName = session.accountability_user_name || closedBy;
    const canReview = isClosed && reviewStatus !== "not_required" && !["approved", "waived", "corrected"].includes(reviewStatus);
    const isStaleOpen = !isClosed && String(session.business_date || "") < new Date().toLocaleDateString("en-CA");
    const staleResolution = isStaleOpen ? `
        <div class="stale-day-summary" role="alert">
            <div class="stale-day-intro">
                <span class="stale-day-icon" aria-hidden="true"><i class="bi bi-calendar-x"></i></span>
                <div>
                    <strong>Previous day still open</strong>
                    <p>Reconcile the original business date before this store begins a new day.</p>
                </div>
            </div>
            <button type="button" class="danger-btn" data-open-stale-day="${Number(session.id || 0)}">Review and resolve</button>
        </div>
    ` : "";
    const reviewActions = canReview ? `
        <div class="variance-actions">
            ${varianceStatus === "shortage" ? `<button type="button" class="danger-btn btn-sm" data-variance-action="approve_shortage" data-session-id="${Number(session.id || 0)}"><i class="bi bi-person-exclamation"></i> Approve accountability</button>` : ""}
            <button type="button" class="secondary-btn btn-sm" data-variance-action="waive" data-session-id="${Number(session.id || 0)}"><i class="bi bi-slash-circle"></i> Waive variance</button>
            <button type="button" class="secondary-btn btn-sm" data-variance-action="corrected" data-session-id="${Number(session.id || 0)}"><i class="bi bi-file-earmark-check"></i> Accept correction evidence</button>
            <button type="button" class="secondary-btn btn-sm" data-variance-action="needs_investigation" data-session-id="${Number(session.id || 0)}"><i class="bi bi-search"></i> Investigate</button>
        </div>
    ` : "";
    const reviewCopy = canReview && varianceStatus === "shortage"
        ? `<div class="variance-note">Approving assigns ${sdEscape(sdMoney(shortageAmount))} to ${sdEscape(accountabilityName)} as operator accountability.</div>`
        : "";
    const closedSummary = isClosed ? `
        <div class="variance-grid">
            <div>
                <span>Expected Cash</span>
                <strong>${sdEscape(sdMoney(session.expected_cash || 0))}</strong>
            </div>
            <div>
                <span>Counted Cash</span>
                <strong>${sdEscape(sdMoney(session.counted_cash || 0))}</strong>
            </div>
            <div>
                <span>Cash Variance</span>
                <strong class="${Number(session.variance_cash || 0) < 0 ? "text-danger" : ""}">${sdEscape(sdMoney(session.variance_cash || 0))}</strong>
            </div>
            <div>
                <span>Expected E-Cash</span>
                <strong>${sdEscape(sdMoney(session.expected_ecash || 0))}</strong>
            </div>
            <div>
                <span>Counted E-Cash</span>
                <strong>${sdEscape(sdMoney(session.counted_ecash || 0))}</strong>
            </div>
            <div>
                <span>E-Cash Variance</span>
                <strong class="${Number(session.variance_ecash || 0) < 0 ? "text-danger" : ""}">${sdEscape(sdMoney(session.variance_ecash || 0))}</strong>
            </div>
        </div>
        <div class="variance-status-row">
            <span class="variance-pill ${sdVarianceClass(varianceStatus)}">${sdEscape(sdVarianceStatusLabel(varianceStatus))}</span>
            <span class="variance-pill ${sdReviewClass(reviewStatus)}">${sdEscape(sdReviewLabel(reviewStatus))}</span>
        </div>
        ${session.review_note ? `<div class="variance-note">Review note: ${sdEscape(session.review_note)}</div>` : ""}
        ${sdCaseSummary(session)}
        ${Number(session.accountability_amount || 0) > 0 ? `<div class="variance-note">Accountability: ${sdEscape(sdMoney(session.accountability_amount))} assigned to ${sdEscape(accountabilityName)}.</div>` : ""}
        ${reviewCopy}
        ${canReview ? `<div class="variance-review-guidance"><strong>Before deciding</strong><span>Open the case, inspect its timeline and supporting files, then choose a disposition. Closed-day amounts remain immutable; a corrected disposition accepts documented evidence rather than rewriting the original count.</span></div>` : ""}
        ${reviewActions}
    ` : "";
    wrap.innerHTML = `
        <div class="stack-item">
            <div class="stack-item-head">
                <strong>${sdEscape(session.business_date || "-")}</strong>
                <span>${sdEscape(status)}</span>
            </div>
            <div class="stack-meta">
                Opening: ${sdEscape(sdMoney(session.opening_cash || 0))} cash | ${sdEscape(sdMoney(session.opening_ecash || 0))} e-cash
            </div>
            <div class="stack-meta">
                Opened by ${sdEscape(openedBy)}${session.opened_at ? ` at ${sdEscape(sdDateTime(session.opened_at))}` : ""}
            </div>
            <div class="stack-meta">
                Closed by ${sdEscape(closedBy)}${session.closed_at ? ` at ${sdEscape(sdDateTime(session.closed_at))}` : ""}
            </div>
            ${staleResolution}
            ${closedSummary}
        </div>
    `;
}

function sdOpenStaleDayModal(sessionId, trigger) {
    if (sdActionBusy) return;
    const session = sdDaySessions.find((row) => Number(row.id || 0) === Number(sessionId));
    const modal = document.getElementById("sd-stale-day-modal");
    const form = document.getElementById("sd-stale-day-form");
    if (!session || !modal || !form) return;
    sdStaleDayTrigger = trigger || null;
    form.reset();
    sdSetActionFeedback(document.getElementById("sd-stale-result"), "");
    form.setAttribute("data-stale-session-id", String(sessionId));
    document.getElementById("sd-stale-day-description").textContent = `Business date ${session.business_date || "-"} · opened by ${session.opened_by_name || "Unknown"}. The original session identity will be preserved.`;
    document.getElementById("sd-stale-expected-cash").textContent = sdMoney(session.expected_cash || 0);
    document.getElementById("sd-stale-expected-ecash").textContent = sdMoney(session.expected_ecash || 0);
    const breakdown = document.getElementById("sd-stale-payment-breakdown");
    if (breakdown) breakdown.innerHTML = sdExpectedBreakdown(session);
    modal.classList.remove("is-hidden");
    document.body.classList.add("modal-open");
    const card = modal.querySelector(".admin-modal-card");
    if (card) {
        card.scrollTop = 0;
        card.focus({ preventScroll: true });
    }
}

function sdExpectedBreakdown(session) {
    const paymentSales = Array.isArray(session.payment_method_sales) ? session.payment_method_sales : [];
    const electronicSales = paymentSales.filter((row) => !["cash", "debt"].includes(String(row.payment_method || "").toLowerCase()));
    const accounts = Array.isArray(session.payment_account_balances) ? session.payment_account_balances : [];
    const cashMovementNet = Number(session.cash_in || 0) - Number(session.cash_out || 0);
    const ecashMovementNet = Number(session.ecash_in || 0) - Number(session.ecash_out || 0);
    const detailRow = (label, value) => `<div><span>${sdEscape(label)}</span><strong>${sdEscape(sdMoney(value))}</strong></div>`;
    return `
        <div class="stale-day-breakdown-column">
            <h5><i class="bi bi-cash-stack" aria-hidden="true"></i> Cash calculation</h5>
            ${detailRow("Opening cash", session.opening_cash || 0)}
            ${detailRow("Cash payments received", session.cash_sales || 0)}
            ${detailRow("Cash movements (net)", cashMovementNet)}
        </div>
        <div class="stale-day-breakdown-column">
            <h5><i class="bi bi-phone" aria-hidden="true"></i> E-cash calculation</h5>
            ${detailRow("Opening e-cash", session.opening_ecash || 0)}
            ${electronicSales.length ? electronicSales.map((row) => detailRow(`${row.payment_method_label || sdIdentifierLabel(row.payment_method)} received`, row.amount || 0)).join("") : detailRow("Electronic payments received", session.ecash_sales || 0)}
            ${detailRow("E-cash movements (net)", ecashMovementNet)}
        </div>
        ${accounts.length ? `<details class="stale-day-account-breakdown"><summary>Receiving-account balances (${accounts.length})</summary>${accounts.map((account) => detailRow(`${account.payment_method_label || sdIdentifierLabel(account.payment_method)} · ${account.account_name || "Receiving account"}`, account.expected_balance || 0)).join("")}</details>` : ""}`;
}

async function sdRefreshAndOpenStaleDay(sessionId, trigger) {
    if (sdActionBusy || sdDetailsLoading || trigger?.disabled) return;
    if (trigger) {
        trigger.disabled = true;
        trigger.innerHTML = '<i class="bi bi-arrow-repeat" aria-hidden="true"></i> Refreshing totals...';
    }
    const loaded = await loadStoreDetails();
    if (!loaded) {
        if (trigger?.isConnected) {
            trigger.disabled = false;
            trigger.textContent = "Review and resolve";
        }
        return;
    }
    const freshTrigger = document.querySelector(`[data-open-stale-day="${Number(sessionId)}"]`);
    sdOpenStaleDayModal(sessionId, freshTrigger || trigger);
}

function sdCloseStaleDayModal(force = false, restoreFocus = true) {
    if (sdActionBusy && force !== true) return;
    const modal = document.getElementById("sd-stale-day-modal");
    if (!modal || modal.classList.contains("is-hidden")) return;
    modal.classList.add("is-hidden");
    document.body.classList.remove("modal-open");
    if (restoreFocus) sdStaleDayTrigger?.focus();
    sdStaleDayTrigger = null;
}

async function sdResolveStaleDay(form) {
    const modal = document.getElementById("sd-stale-day-modal");
    if (sdActionBusy || !modal || modal.classList.contains("is-hidden") || !form.reportValidity()) return;
    const sessionId = Number(form.getAttribute("data-stale-session-id") || 0);
    if (!sessionId) return;
    const currentSession = sdDaySessions.find((row) => Number(row.id || 0) === sessionId);
    if (!currentSession) return;
    const payload = Object.fromEntries(new FormData(form).entries());
    const action = sdBeginAction(form, document.getElementById("sd-stale-result"), "Review the reconciliation before confirming.", modal.querySelector('button[type="submit"]'), "Reviewing…");
    if (!action) return;
    try {
    const preview = [
        `Business date: ${currentSession?.business_date || "-"}`,
        `Expected cash: ${sdMoney(currentSession?.expected_cash || 0)}`,
        `Counted cash: ${sdMoney(payload.counted_cash || 0)}`,
        `Expected e-cash: ${sdMoney(currentSession?.expected_ecash || 0)}`,
        `Counted e-cash: ${sdMoney(payload.counted_ecash || 0)}`,
        "",
        "The original business date and opener will remain unchanged. Any difference creates a variance case for independent review.",
    ].join("\n");
    const confirmed = await window.IbemsDialog.confirm(preview, {
        title: "Resolve previous store day",
        confirmLabel: "Resolve day",
        tone: "danger",
    });
    if (!action.current()) return;
    if (!confirmed) { action.cancel(); return; }

    const root = document.querySelector("[data-store-id]");
    const prefix = (root?.getAttribute("data-review-url-prefix") || "/admin/store-day-sessions").replace(/\/$/, "");
    action.feedback("Resolving the previous store day…", "pending");
    action.label("Resolving…");
    const data = await sdPostAction(action, `${prefix}/${sessionId}/resolve-stale`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
    });
    if (data) await sdFinishAction(action, typeof data.message === "string" && data.message.trim() ? data.message : "Previous store day resolved.", true);
    } finally { action.release(); }
}

async function sdReviewVariance(sessionId, action, trigger) {
    const actionLabels = {
        approve_shortage: "Approve shortage",
        waive: "Waive variance",
        corrected: "Accept correction evidence",
        needs_investigation: "Mark for investigation",
    };
    const session = sdDaySessions.find((row) => Number(row.id || 0) === Number(sessionId));
    if (sdActionBusy || !actionLabels[action]) return;
    if (!session) {
        await window.IbemsDialog.alert("Reload the store details before reviewing this variance.", { title: "Review unavailable", tone: "danger" });
        return;
    }
    const request = sdBeginAction(trigger, document.getElementById("sd-action-feedback"), "Review the variance decision before confirming.", trigger, "Reviewing…");
    if (!request) return;
    try {

    const confirmed = await window.IbemsDialog.confirm(sdReviewPreview(session, action), {
        title: `Review before: ${actionLabels[action] || "Variance decision"}`,
        confirmLabel: "Continue to review note",
        tone: action === "approve_shortage" ? "danger" : "default",
    });
    if (!request.current()) return;
    if (!confirmed) { request.cancel(); return; }

    const evidenceCount = Array.isArray(session?.variance_case?.attachments) ? session.variance_case.attachments.length : 0;
    if (["approve_shortage", "waive", "corrected"].includes(action) && evidenceCount === 0) {
        await window.IbemsDialog.alert("Open the variance case and attach at least one supporting PDF or image before recording a final disposition.", {
            title: "Supporting evidence required",
            tone: "danger",
        });
        if (request.current()) request.cancel();
        return;
    }

    const draftKey = `${Number(sessionId)}:${action}`;
    const note = await window.IbemsDialog.prompt(`${sdReviewActionDetails(session, action)}\n\nEnter the evidence reviewed and reason for this decision.`, {
        title: actionLabels[action] || "Review variance",
        inputLabel: "Decision and evidence note",
        placeholder: "State what was checked, the evidence used, and why this decision is appropriate",
        required: true,
        multiline: true,
        confirmLabel: actionLabels[action] || "Save review",
        defaultValue: sdReviewDrafts.get(draftKey) || "",
    });
    if (!request.current()) return;
    if (note === null) { request.cancel(); return; }
    sdReviewDrafts.set(draftKey, note.trim());

    const root = document.querySelector("[data-store-id]");
    const reviewUrlPrefix = (root?.getAttribute("data-review-url-prefix") || "/admin/store-day-sessions").replace(/\/$/, "");
    request.feedback("Saving the variance review…", "pending");
    request.label("Saving…");
    const data = await sdPostAction(request, `${reviewUrlPrefix}/${Number(sessionId)}/review`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action, review_note: note.trim() }),
    });
    if (data) {
        sdReviewDrafts.delete(draftKey);
        await sdFinishAction(request, "Variance review saved.");
    }
    } finally { request.release(); }
}

function sdRenderOfficers(officers) {
    const wrap = document.getElementById("sd-officers");
    if (!wrap) return;

    const rows = Array.isArray(officers) ? officers : [];
    if (rows.length === 0) {
        wrap.innerHTML = sdDataState("empty", "No assigned officer.");
        return;
    }

    wrap.innerHTML = rows.map((row) => `
        <div class="stack-item assigned-officer-card">
            ${window.IbemsAvatar.html(row.name, row.profile_image_url, "assigned-officer-avatar")}
            <div class="assigned-officer-copy">
                <div class="stack-item-head">
                    <strong>${sdEscape(row.name || "No assigned officer")}</strong>
                    <span>${sdEscape(sdIdentifierLabel(row.role || "Officer"))}</span>
                </div>
                <div class="stack-meta">${sdEscape(row.email || "-")}</div>
            </div>
        </div>
    `).join("");
}

function sdRenderSessionHistory() {
    const body = document.getElementById("sd-session-history-body");
    if (!body || sdDetailsState !== "ready" || sdActionBusy) return;

    const filter = document.getElementById("sd-session-filter")?.value || "all";
    const unresolved = ["pending", "needs_investigation"];
    const resolved = ["approved", "waived", "corrected"];
    const rows = sdDaySessions.filter((session) => {
        const reviewStatus = String(session.review_status || "not_required");
        if (filter === "unresolved") return unresolved.includes(reviewStatus);
        if (filter === "resolved") return resolved.includes(reviewStatus);
        if (filter === "pending" || filter === "needs_investigation") return reviewStatus === filter;
        return true;
    });

    if (rows.length === 0) {
        sdSetTableMode(body, true);
        body.innerHTML = sdDataState("empty", "No store day sessions match this filter.", 6);
        sdRenderPager("sessions", 0);
        return;
    }

    const page = sdPageSlice("sessions", rows);
    sdSetTableMode(body, false);
    body.innerHTML = page.rows.map((session) => {
        const varianceStatus = String(session.variance_status || "balanced");
        const reviewStatus = String(session.review_status || "not_required");
        const canReview = String(session.status || "") === "closed" && unresolved.includes(reviewStatus);
        const sessionId = Number(session.id || 0);
        const action = canReview
            ? `<div class="variance-actions history-variance-actions">
                ${varianceStatus === "shortage" ? `<button type="button" class="danger-btn btn-sm" data-variance-action="approve_shortage" data-session-id="${sessionId}">Approve accountability</button>` : ""}
                <button type="button" class="secondary-btn btn-sm" data-variance-action="waive" data-session-id="${sessionId}">Waive</button>
                <button type="button" class="secondary-btn btn-sm" data-variance-action="corrected" data-session-id="${sessionId}">Accept correction</button>
                <button type="button" class="secondary-btn btn-sm" data-variance-action="needs_investigation" data-session-id="${sessionId}">Investigate</button>
            </div>`
            : '<span class="text-muted">-</span>';
        return `
            <tr class="${canReview ? "store-day-row-unresolved" : ""}">
                <td>${sdEscape(session.business_date || "-")}</td>
                <td>${sdEscape(String(session.status || "-").toUpperCase())}</td>
                <td><span class="variance-pill ${sdVarianceClass(varianceStatus)}">${sdEscape(sdVarianceStatusLabel(varianceStatus))}</span><div class="stack-meta">${sdEscape(sdMoney(Number(session.variance_cash || 0) + Number(session.variance_ecash || 0)))}</div></td>
                <td><span class="variance-pill ${sdReviewClass(reviewStatus)}">${sdEscape(sdReviewLabel(reviewStatus))}</span>${sdCaseSummary(session)}</td>
                <td>${sdEscape(session.closed_by_name || "-")}</td>
                <td>${action}</td>
            </tr>
        `;
    }).join("");
    sdRenderPager("sessions", rows.length);
}

function sdRenderInventory() {
    const body = document.getElementById("sd-inventory-body");
    const count = document.getElementById("sd-inventory-count");
    if (!body || sdDetailsState !== "ready" || sdActionBusy) return;
    if (count) count.textContent = `${sdInventoryRows.length} ${sdInventoryRows.length === 1 ? "product" : "products"} in this store`;
    if (sdInventoryRows.length === 0) {
        sdSetTableMode(body, true);
        body.innerHTML = sdDataState("empty", "No products in this store.", 3);
        sdRenderPager("inventory", 0);
        return;
    }
    const page = sdPageSlice("inventory", sdInventoryRows);
    sdSetTableMode(body, false);
    body.innerHTML = page.rows.map((row) => `
        <tr>
            <td><div class="inv-product">${row.image_url ? `<img src="${sdEscape(row.image_url)}" alt="${sdEscape(row.name)}" class="inv-product-img">` : `<div class="inv-product-fallback">${sdEscape(sdInitials(row.name))}</div>`}<span>${sdEscape(row.name)}</span></div></td>
            <td>${Number(row.stock_qty || 0)} ${Number(row.stock_qty || 0) <= Number(row.low_stock_threshold ?? 10) && row.is_active ? '<span class="table-chip status-warning">Low</span>' : ""}</td>
            <td class="store-detail-money">${sdEscape(sdMoney(row.price || 0))}</td>
        </tr>`).join("");
    sdRenderPager("inventory", sdInventoryRows.length);
}

function sdRenderTransactions() {
    const body = document.getElementById("sd-transactions-body");
    const count = document.getElementById("sd-transactions-count");
    if (!body || sdDetailsState !== "ready" || sdActionBusy) return;
    if (count) count.textContent = `Latest ${sdTransactionRows.length} ${sdTransactionRows.length === 1 ? "transaction" : "transactions"}`;
    if (sdTransactionRows.length === 0) {
        sdSetTableMode(body, true);
        body.innerHTML = sdDataState("empty", "No transactions yet.", 4);
        sdRenderPager("transactions", 0);
        return;
    }
    const page = sdPageSlice("transactions", sdTransactionRows);
    sdSetTableMode(body, false);
    body.innerHTML = page.rows.map((row) => `
        <tr><td>${sdEscape(sdDateTime(row.created_at))}</td><td>${sdEscape(row.customer_name || "Walk-in")}</td><td>${sdEscape(String(row.payment_method || "").toUpperCase())}</td><td class="store-detail-money">${sdEscape(sdMoney(row.amount || 0))}</td></tr>`).join("");
    sdRenderPager("transactions", sdTransactionRows.length);
}

function sdSetTableMode(body, isState) {
    const region = body?.closest(".table-standard-wrap");
    if (!region) return;
    region.classList.toggle("is-store-details-state", isState);
    if (isState) region.removeAttribute("tabindex");
    else region.setAttribute("tabindex", "0");
}

function sdSetDetailsBusy(busy) {
    sdDetailsLoading = busy;
    document.querySelectorAll('[data-store-id] .table-standard-wrap, #sd-day-session, #sd-officers').forEach(panel => {
        if (busy) panel.setAttribute("aria-busy", "true");
        else panel.removeAttribute("aria-busy");
    });
    const refresh = document.getElementById("sd-refresh");
    if (refresh) {
        refresh.disabled = busy;
        refresh.textContent = busy ? "Refreshing store details…" : "Refresh store details";
    }
}

function sdSetDetailsState(type, message) {
    sdDetailsState = type;
    sdDaySessions = [];
    sdInventoryRows = [];
    sdTransactionRows = [];
    for (const id of ["sd-txn-count", "sd-sales-total", "sd-product-count", "sd-stock-units", "sd-today-sales", "sd-debt-txns", "sd-active-products", "sd-low-stock"]) {
        const value = document.getElementById(id);
        if (value) value.textContent = type === "loading" ? "Loading…" : "Unavailable";
    }
    for (const [id, columns] of [["sd-day-session", 0], ["sd-officers", 0], ["sd-session-history-body", 6], ["sd-inventory-body", 3], ["sd-transactions-body", 4]]) {
        const panel = document.getElementById(id);
        if (panel) {
            sdSetTableMode(panel, true);
            panel.innerHTML = sdDataState(type, type === "loading" ? "Loading…" : "Store details unavailable.", columns, false);
        }
    }
    for (const section of ["session", "inventory", "transactions"]) {
        const pager = document.getElementById(`sd-${section}-pager`);
        if (pager) pager.innerHTML = "";
    }
    for (const id of ["sd-inventory-count", "sd-transactions-count"]) {
        const count = document.getElementById(id);
        if (count) count.textContent = type === "loading" ? "Loading…" : "Unavailable";
    }
    for (const id of ["sd-session-filter", "sd-session-page-size", "sd-inventory-page-size", "sd-transactions-page-size"]) {
        const control = document.getElementById(id);
        if (control) control.disabled = true;
    }
    const meta = document.getElementById("sd-store-meta");
    if (meta) meta.textContent = type === "loading" ? "Loading store profile…" : "Store profile unavailable.";
    const feedback = document.getElementById("sd-load-feedback");
    if (feedback) feedback.innerHTML = `<div class="data-state data-state--${type}" role="${type === "error" ? "alert" : "status"}" aria-live="${type === "error" ? "assertive" : "polite"}" aria-atomic="true" tabindex="-1"><i class="bi ${type === "error" ? "bi-exclamation-circle" : "bi-arrow-repeat"}" aria-hidden="true"></i><div><strong>${sdEscape(message)}</strong></div>${type === "error" ? '<button type="button" class="secondary-btn" data-sd-retry>Retry store details</button>' : ""}</div>`;
}

async function loadStoreDetails({ retry = false, afterAction = false } = {}) {
    const root = document.querySelector("[data-store-id]");
    if (!root || (retry && sdDetailsLoading) || (sdActionBusy && !afterAction)) return false;
    const storeId = Number(root.getAttribute("data-store-id") || 0);
    if (!storeId) return;

    const detailsUrl = root.getAttribute("data-details-url") || `/admin/stores/${storeId}/data`;
    const requestId = ++sdDetailsSequence;
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const current = () => requestId === sdDetailsSequence && !signal?.aborted && root.isConnected;
    if (!current()) return false;
    const feedback = document.getElementById("sd-load-feedback");
    const refresh = document.getElementById("sd-refresh");
    const previousFocus = document.activeElement;
    const restoreFocus = feedback?.contains(previousFocus) || previousFocus === refresh
        || document.getElementById("sd-action-feedback")?.contains(previousFocus)
        || Boolean(previousFocus?.closest?.("#sd-day-session, #sd-officers, #sd-session-history-body, #sd-inventory-body, #sd-transactions-body"));
    const ownsFocus = () => restoreFocus && (feedback?.contains(document.activeElement) || document.activeElement === previousFocus || document.activeElement === document.body);
    const focusFeedback = () => (feedback?.querySelector("[data-sd-retry]") || feedback?.querySelector(".data-state"))?.focus();
    sdSetDetailsBusy(true);
    sdSetDetailsState("loading", "Loading store details…");
    if (restoreFocus) focusFeedback();
    try {
    const response = await fetch(detailsUrl, { signal });
    const data = await response.json();
    if (!current()) return false;
    if (!response.ok || !data || data.status !== "success") {
        const message = typeof data?.message === "string" && data.message.trim() ? data.message : "Unable to load store details. Try again.";
        const owned = ownsFocus();
        sdSetDetailsState("error", message);
        if (owned) focusFeedback();
        return false;
    }
    const record = value => value && typeof value === "object" && !Array.isArray(value);
    if (!record(data.store) || !record(data.summary) || !["day_sessions", "officers", "inventory", "recent_transactions"].every(key => Array.isArray(data[key]) && data[key].every(record)) || (data.day_session !== null && !record(data.day_session))) {
        throw new Error("Store detail records are unavailable.");
    }

    const store = data.store;
    const summary = data.summary;
    const owned = ownsFocus();
    sdDetailsState = "ready";

    document.getElementById("sd-store-name").textContent = store.store_name || "Store Details";
    document.getElementById("sd-store-meta").textContent =
        `${store.officer_name || "No officer"} | ${store.is_active ? "Active" : "Inactive"}`;
    document.getElementById("sd-txn-count").textContent = String(summary.txn_count || 0);
    document.getElementById("sd-sales-total").textContent = sdMoney(summary.sales_total || 0);
    document.getElementById("sd-product-count").textContent = String(summary.product_count || 0);
    document.getElementById("sd-stock-units").textContent = String(summary.stock_units || 0);
    document.getElementById("sd-today-sales").textContent = sdMoney(summary.today_sales_total || 0);
    document.getElementById("sd-debt-txns").textContent = String(summary.today_debt_txn_count || 0);
    document.getElementById("sd-active-products").textContent = String(summary.active_products || 0);
    document.getElementById("sd-low-stock").textContent = String(summary.low_stock_count || 0);

    sdRenderDaySession(data.day_session || null);
    sdDaySessions = Array.isArray(data.day_sessions) ? data.day_sessions : [];
    sdRenderSessionHistory();
    sdRenderOfficers(data.officers || []);

    sdInventoryRows = Array.isArray(data.inventory) ? data.inventory : [];
    sdTransactionRows = Array.isArray(data.recent_transactions) ? data.recent_transactions : [];
    sdRenderInventory();
    sdRenderTransactions();
    for (const id of ["sd-session-filter", "sd-session-page-size", "sd-inventory-page-size", "sd-transactions-page-size"]) {
        const control = document.getElementById(id);
        if (control) control.disabled = false;
    }
    sdSetDetailsBusy(false);
    if (feedback) feedback.innerHTML = "";
    if (owned) refresh?.focus();
    return true;
    } catch (error) {
        if (!current()) return false;
        const owned = ownsFocus();
        sdSetDetailsState("error", "Unable to load store details. Try again.");
        if (owned) focusFeedback();
        return false;
    } finally {
        if (current()) sdSetDetailsBusy(false);
    }
}

document.addEventListener("click", (event) => {
    const retryButton = event.target.closest("[data-sd-retry]");
    if (retryButton) { event.preventDefault(); loadStoreDetails({ retry: true }); return; }
    const staleDayButton = event.target.closest("[data-open-stale-day]");
    if (staleDayButton) {
        event.preventDefault();
        sdRefreshAndOpenStaleDay(staleDayButton.getAttribute("data-open-stale-day"), staleDayButton);
        return;
    }
    const pagerButton = event.target.closest("[data-sd-page-section]");
    if (pagerButton) {
        if (pagerButton.disabled) return;
        const section = pagerButton.getAttribute("data-sd-page-section");
        const label = pagerButton.getAttribute("aria-label");
        sdPages[section] = Math.max(1, Number(pagerButton.getAttribute("data-sd-page") || 1));
        if (section === "sessions") sdRenderSessionHistory();
        if (section === "inventory") sdRenderInventory();
        if (section === "transactions") sdRenderTransactions();
        const controlName = section === "sessions" ? "session" : section;
        const pager = document.getElementById(`sd-${controlName}-pager`);
        const controls = Array.from(pager?.querySelectorAll("button") || []);
        const nextFocus = controls.find(control => !control.disabled && control.getAttribute("aria-label") === label)
            || controls.find(control => !control.disabled);
        nextFocus?.focus();
        return;
    }
    const acknowledge = event.target.closest("[data-case-acknowledge]");
    if (acknowledge) {
        event.preventDefault();
        sdAcknowledgeCase(acknowledge.getAttribute("data-case-acknowledge"), acknowledge);
        return;
    }
    const button = event.target.closest("[data-variance-action]");
    if (!button) return;
    event.preventDefault();
    sdReviewVariance(button.getAttribute("data-session-id"), button.getAttribute("data-variance-action"), button);
});

document.addEventListener("submit", (event) => {
    const staleForm = event.target.closest(".stale-day-resolution-form");
    if (staleForm) {
        event.preventDefault();
        sdResolveStaleDay(staleForm);
        return;
    }
    const evidenceForm = event.target.closest(".variance-evidence-form");
    const handoffForm = event.target.closest(".variance-handoff-form");
    if (!evidenceForm && !handoffForm) return;
    event.preventDefault();
    sdSubmitCaseForm(evidenceForm || handoffForm, evidenceForm ? "attachments" : "handoff");
});

document.getElementById("sd-refresh")?.addEventListener("click", () => loadStoreDetails({ retry: true }));
document.getElementById("sd-session-filter")?.addEventListener("change", () => { sdPages.sessions = 1; sdRenderSessionHistory(); });
document.getElementById("sd-session-page-size")?.addEventListener("change", () => { sdPages.sessions = 1; sdRenderSessionHistory(); });
document.getElementById("sd-inventory-page-size")?.addEventListener("change", () => { sdPages.inventory = 1; sdRenderInventory(); });
document.getElementById("sd-transactions-page-size")?.addEventListener("change", () => { sdPages.transactions = 1; sdRenderTransactions(); });
document.getElementById("sd-stale-day-close")?.addEventListener("click", sdCloseStaleDayModal);
document.getElementById("sd-stale-day-cancel")?.addEventListener("click", sdCloseStaleDayModal);
document.getElementById("sd-stale-day-modal")?.addEventListener("click", (event) => { if (event.target.id === "sd-stale-day-modal") sdCloseStaleDayModal(); });
document.getElementById("sd-stale-day-modal")?.addEventListener("keydown", (event) => {
    if (event.key !== "Tab") return;
    if (sdActionBusy) { event.preventDefault(); document.getElementById("sd-stale-result")?.focus(); return; }
    const controls = Array.from(event.currentTarget.querySelectorAll('button, input, textarea, select, a[href], summary, [tabindex="0"]'))
        .filter((element) => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length);
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (!first) return;
    if (event.shiftKey && (document.activeElement === first || !controls.includes(document.activeElement))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !controls.includes(document.activeElement))) {
        event.preventDefault();
        first.focus();
    }
});
document.addEventListener("keydown", (event) => { if (event.key === "Escape" && !event.defaultPrevented && !document.body.classList.contains("has-app-dialog")) sdCloseStaleDayModal(); });

loadStoreDetails();
