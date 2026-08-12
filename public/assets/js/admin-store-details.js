function sdMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

let sdDaySessions = [];

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

function sdDataState(type, message, colspan = 0) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    const content = `<div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${sdEscape(message)}</strong></div></div>`;
    return colspan > 0 ? `<tr class="data-state-row"><td colspan="${Number(colspan)}">${content}</td></tr>` : content;
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
            </form>
            ${eligible.length ? `<form class="variance-handoff-form" data-case-id="${Number(varianceCase.id || 0)}">
                <select name="to_user_id" required aria-label="Handoff reviewer"><option value="">Select reviewer</option>${eligible.map((reviewer) => `<option value="${Number(reviewer.id)}">${sdEscape(reviewer.name || reviewer.email)}</option>`).join("")}</select>
                <input type="text" name="note" maxlength="255" required placeholder="Handoff note" aria-label="Handoff note">
                <button type="submit" class="secondary-btn btn-sm">Send handoff</button>
            </form>` : ""}
        </details>
    `;
}

async function sdSubmitCaseForm(form, kind) {
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
    const response = await fetch(`${prefix}/variance-cases/${caseId}/${kind}`, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.status !== "success") {
        await window.IbemsDialog.alert(data.message || "Case update failed.", { title: "Case not updated", tone: "danger" });
        return;
    }
    await loadStoreDetails();
}

async function sdAcknowledgeCase(caseId) {
    const prefix = window.location.pathname.includes("/store-admin/") ? "/store-admin" : "/admin";
    const response = await fetch(`${prefix}/variance-cases/${Number(caseId)}/acknowledge`, { method: "POST" });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.status !== "success") {
        await window.IbemsDialog.alert(data.message || "Handoff acknowledgment failed.", { title: "Case not acknowledged", tone: "danger" });
        return;
    }
    await loadStoreDetails();
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
        <form class="variance-evidence-form stale-day-resolution-form" data-stale-session-id="${Number(session.id || 0)}">
            <div class="stale-day-intro">
                <span class="stale-day-icon" aria-hidden="true"><i class="bi bi-calendar-x"></i></span>
                <div>
                    <strong>Previous day still open</strong>
                    <p>Enter independently counted balances. The original business date and opener will be preserved.</p>
                </div>
            </div>
            <div class="stale-day-expected" role="status">
                <div><span>System expected cash</span><strong>${sdEscape(sdMoney(session.expected_cash || 0))}</strong></div>
                <div><span>System expected e-cash</span><strong>${sdEscape(sdMoney(session.expected_ecash || 0))}</strong></div>
                <p>Count independently before entering values. These expectations are shown for reconciliation and must not replace a physical count.</p>
            </div>
            <div class="stale-day-fields">
                <label><span>Counted Cash</span><input type="number" name="counted_cash" min="0" step="0.01" placeholder="PHP 0.00" required></label>
                <label><span>Counted E-Cash</span><input type="number" name="counted_ecash" min="0" step="0.01" placeholder="PHP 0.00" required></label>
                <label class="stale-day-reason"><span>Resolution Reason</span><textarea name="reason" rows="3" required placeholder="Explain why the day remained open and how the balances were independently counted"></textarea></label>
            </div>
            <div class="stale-day-footer">
                <p><i class="bi bi-shield-check" aria-hidden="true"></i> A difference from the system expectation opens a variance case for another reviewer.</p>
                <button type="submit" class="danger-btn">Resolve Previous Day</button>
            </div>
        </form>
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

async function sdResolveStaleDay(form) {
    const sessionId = Number(form.getAttribute("data-stale-session-id") || 0);
    if (!sessionId) return;
    const currentSession = sdDaySessions.find((row) => Number(row.id || 0) === sessionId);
    const payload = Object.fromEntries(new FormData(form).entries());
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
    if (!confirmed) return;

    const root = document.querySelector("[data-store-id]");
    const prefix = (root?.getAttribute("data-review-url-prefix") || "/admin/store-day-sessions").replace(/\/$/, "");
    const response = await fetch(`${prefix}/${sessionId}/resolve-stale`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.status !== "success") {
        await window.IbemsDialog.alert(data.message || "Failed to resolve the previous store day.", { title: "Store day not resolved", tone: "danger" });
        return;
    }
    await window.IbemsDialog.alert(data.message || "Previous store day resolved.", { title: "Store day resolved", tone: "success" });
    await loadStoreDetails();
}

async function sdReviewVariance(sessionId, action) {
    const actionLabels = {
        approve_shortage: "Approve shortage",
        waive: "Waive variance",
        corrected: "Accept correction evidence",
        needs_investigation: "Mark for investigation",
    };
    const session = sdDaySessions.find((row) => Number(row.id || 0) === Number(sessionId));
    if (!session) {
        await window.IbemsDialog.alert("Reload the store details before reviewing this variance.", { title: "Review unavailable", tone: "danger" });
        return;
    }

    const confirmed = await window.IbemsDialog.confirm(sdReviewPreview(session, action), {
        title: `Review before: ${actionLabels[action] || "Variance decision"}`,
        confirmLabel: "Continue to review note",
        tone: action === "approve_shortage" ? "danger" : "default",
    });
    if (!confirmed) return;

    const evidenceCount = Array.isArray(session?.variance_case?.attachments) ? session.variance_case.attachments.length : 0;
    if (["approve_shortage", "waive", "corrected"].includes(action) && evidenceCount === 0) {
        await window.IbemsDialog.alert("Open the variance case and attach at least one supporting PDF or image before recording a final disposition.", {
            title: "Supporting evidence required",
            tone: "danger",
        });
        return;
    }

    const note = await window.IbemsDialog.prompt(`${sdReviewActionDetails(session, action)}\n\nEnter the evidence reviewed and reason for this decision.`, {
        title: actionLabels[action] || "Review variance",
        inputLabel: "Decision and evidence note",
        placeholder: "State what was checked, the evidence used, and why this decision is appropriate",
        required: true,
        multiline: true,
        confirmLabel: actionLabels[action] || "Save review",
    });
    if (note === null) return;

    const root = document.querySelector("[data-store-id]");
    const reviewUrlPrefix = (root?.getAttribute("data-review-url-prefix") || "/admin/store-day-sessions").replace(/\/$/, "");
    const response = await fetch(`${reviewUrlPrefix}/${Number(sessionId)}/review`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action, review_note: note.trim() }),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.status !== "success") {
        await window.IbemsDialog.alert(data.message || "Failed to review variance.", {
            title: "Review not saved",
            tone: "danger",
        });
        return;
    }
    await loadStoreDetails();
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
        <div class="stack-item">
            <div class="stack-item-head">
                <strong>${sdEscape(row.name || "No assigned officer")}</strong>
                <span>${sdEscape(sdIdentifierLabel(row.role || "Officer"))}</span>
            </div>
            <div class="stack-meta">${sdEscape(row.email || "-")}</div>
        </div>
    `).join("");
}

function sdRenderSessionHistory() {
    const body = document.getElementById("sd-session-history-body");
    if (!body) return;

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
        body.innerHTML = sdDataState("empty", "No store day sessions match this filter.", 6);
        return;
    }

    body.innerHTML = rows.map((session) => {
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
}

async function loadStoreDetails() {
    const root = document.querySelector("[data-store-id]");
    if (!root) return;
    const storeId = Number(root.getAttribute("data-store-id") || 0);
    if (!storeId) return;

    const detailsUrl = root.getAttribute("data-details-url") || `/admin/stores/${storeId}/data`;
    const response = await fetch(detailsUrl);
    const data = await response.json();
    if (!data || data.status !== "success") {
        const message = data?.message || "Unable to load store details.";
        document.getElementById("sd-store-meta").textContent = message;
        document.getElementById("sd-day-session").innerHTML = sdDataState("error", message);
        document.getElementById("sd-officers").innerHTML = sdDataState("error", message);
        document.getElementById("sd-inventory-body").innerHTML = sdDataState("error", message, 3);
        document.getElementById("sd-transactions-body").innerHTML = sdDataState("error", message, 4);
        return;
    }

    const store = data.store || {};
    const summary = data.summary || {};

    document.getElementById("sd-store-name").textContent = sdEscape(store.store_name || "Store Details");
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

    const inventory = Array.isArray(data.inventory) ? data.inventory : [];
    const inventoryBody = document.getElementById("sd-inventory-body");
    if (inventory.length === 0) {
        inventoryBody.innerHTML = sdDataState("empty", "No products in this store.", 3);
    } else {
        inventoryBody.innerHTML = inventory.map((row) => `
            <tr>
                <td>
                    <div class="inv-product">
                        ${row.image_url
                            ? `<img src="${sdEscape(row.image_url)}" alt="${sdEscape(row.name)}" class="inv-product-img">`
                            : `<div class="inv-product-fallback">${sdEscape(sdInitials(row.name))}</div>`
                        }
                        <span>${sdEscape(row.name)}</span>
                    </div>
                </td>
                <td>
                    ${Number(row.stock_qty || 0)}
                    ${Number(row.stock_qty || 0) <= Number(row.low_stock_threshold ?? 10) && row.is_active ? '<span class="table-chip status-warning">Low</span>' : ""}
                </td>
                <td>${sdEscape(sdMoney(row.price || 0))}</td>
            </tr>
        `).join("");
    }

    const txns = Array.isArray(data.recent_transactions) ? data.recent_transactions : [];
    const txnBody = document.getElementById("sd-transactions-body");
    if (txns.length === 0) {
        txnBody.innerHTML = sdDataState("empty", "No transactions yet.", 4);
    } else {
        txnBody.innerHTML = txns.map((row) => `
            <tr>
                <td>${sdEscape(sdDateTime(row.created_at))}</td>
                <td>${sdEscape(row.customer_name || "Walk-in")}</td>
                <td>${sdEscape(String(row.payment_method || "").toUpperCase())}</td>
                <td>${sdEscape(sdMoney(row.amount || 0))}</td>
            </tr>
        `).join("");
    }
}

document.addEventListener("click", (event) => {
    const acknowledge = event.target.closest("[data-case-acknowledge]");
    if (acknowledge) {
        event.preventDefault();
        sdAcknowledgeCase(acknowledge.getAttribute("data-case-acknowledge"));
        return;
    }
    const button = event.target.closest("[data-variance-action]");
    if (!button) return;
    event.preventDefault();
    sdReviewVariance(button.getAttribute("data-session-id"), button.getAttribute("data-variance-action"));
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

document.getElementById("sd-session-filter")?.addEventListener("change", sdRenderSessionHistory);

loadStoreDetails();
