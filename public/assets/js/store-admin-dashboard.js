function sadMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function sadDateTime(value) {
    if (!value) return "-";
    return window.IbemsFormat?.dateTime(value) || new Date(value).toLocaleString();
}

function sadEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function sadReviewLabel(value) {
    const labels = {
        pending: "Pending Review",
        needs_investigation: "Needs Investigation",
        approved: "Approved",
        waived: "Waived",
        corrected: "Corrected",
        not_required: "No Review Needed",
    };
    return labels[String(value || "pending")] || "Review";
}

function sadVarianceLabel(value) {
    const labels = {
        shortage: "Shortage",
        overage: "Overage",
        balanced: "Balanced",
    };
    return labels[String(value || "balanced")] || "Variance";
}

function sadDayStatusLabel(value) {
    const labels = {
        open: "Open Today",
        closed: "Closed Today",
        stale_open: "Previous Day Still Open",
        not_started: "Not Opened Today",
    };
    return labels[String(value || "not_started")] || "Store Day Unknown";
}

function sadPillClass(value) {
    const status = String(value || "");
    if (status === "shortage" || status === "approved") return "is-danger";
    if (status === "overage" || status === "pending" || status === "needs_investigation") return "is-warning";
    if (status === "balanced" || status === "waived" || status === "corrected") return "is-success";
    return "is-muted";
}

function sadRoot() {
    return document.querySelector(".store-admin-dashboard");
}

function sadSetText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function sadDataState(type, message, detail = "") {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    return `<div class="data-state data-state--${safeType}" role="${safeType === "error" ? "alert" : "status"}" aria-live="polite">
        <i class="${icons[safeType]}" aria-hidden="true"></i>
        <div><strong>${sadEscape(message)}</strong>${detail ? `<small>${sadEscape(detail)}</small>` : ""}</div>
    </div>`;
}

function sadRenderStores(stores) {
    const wrap = document.getElementById("sad-store-list");
    const root = sadRoot();
    const detailPrefix = (root?.getAttribute("data-store-detail-prefix") || "/store-admin/stores").replace(/\/$/, "");
    const rows = Array.isArray(stores) ? stores : [];
    if (rows.length === 0) {
        wrap.innerHTML = sadDataState("empty", "No stores assigned", "Ask an Administrator to assign this supervisor to a store.");
        return;
    }

    wrap.innerHTML = rows.map((store) => {
        const dayStatus = sadDayStatusLabel(store.day_status);
        const reviewStatus = String(store.review_status || "not_required");
        return `
            <a class="store-admin-store-item" href="${sadEscape(detailPrefix)}/${Number(store.id || 0)}">
                <div>
                    <strong>${sadEscape(store.store_name || "Store")}</strong>
                    <span class="table-person-cell">${window.IbemsAvatar.html(store.officer_name, store.officer_profile_image_url, "table-person-avatar")}<span>${sadEscape(store.officer_name || "No assigned officer")}</span></span>
                </div>
                <div class="store-admin-store-metrics">
                    <span>${sadEscape(sadMoney(store.today_sales_total || 0))}</span>
                    <small>${Number(store.today_txn_count || 0)} transaction${Number(store.today_txn_count || 0) === 1 ? "" : "s"} today</small>
                    <small>${sadEscape(dayStatus)}</small>
                    ${reviewStatus !== "not_required" ? `<small class="text-warning">${sadEscape(sadReviewLabel(reviewStatus))}</small>` : ""}
                </div>
            </a>
        `;
    }).join("");
}

function sadRenderReviews(reviews) {
    const wrap = document.getElementById("sad-pending-reviews-list");
    const rows = Array.isArray(reviews) ? reviews : [];
    if (rows.length === 0) {
        wrap.innerHTML = sadDataState("success", "No pending variance reviews", "All assigned store-day variances are currently cleared.");
        return;
    }

    wrap.innerHTML = rows.map((row) => {
        const varianceStatus = String(row.variance_status || "balanced");
        const reviewStatus = String(row.review_status || "pending");
        return `
            <div class="store-admin-review-item">
                <div class="store-admin-review-head">
                    <div>
                        <strong>${sadEscape(row.store_name || "Store")}</strong>
                        <span class="dashboard-meta-line">
                            <span class="dashboard-meta-item"><i class="bi bi-calendar3" aria-hidden="true"></i><span>${sadEscape(row.business_date || "-")}</span></span>
                            <span class="dashboard-meta-item"><i class="bi bi-person" aria-hidden="true"></i><span>Closed by ${sadEscape(row.closed_by_name || "Unknown")}</span></span>
                        </span>
                    </div>
                    <div class="variance-status-row">
                        <span class="variance-pill ${sadPillClass(varianceStatus)}">${sadEscape(sadVarianceLabel(varianceStatus))}</span>
                        <span class="variance-pill ${sadPillClass(reviewStatus)}">${sadEscape(sadReviewLabel(reviewStatus))}</span>
                    </div>
                </div>
                <div class="variance-grid">
                    <div><span>Expected Cash</span><strong>${sadEscape(sadMoney(row.expected_cash || 0))}</strong></div>
                    <div><span>Counted Cash</span><strong>${sadEscape(sadMoney(row.counted_cash || 0))}</strong></div>
                    <div><span>Cash Variance</span><strong class="${Number(row.variance_cash || 0) < 0 ? "text-danger" : ""}">${sadEscape(sadMoney(row.variance_cash || 0))}</strong></div>
                    <div><span>Expected E-Cash</span><strong>${sadEscape(sadMoney(row.expected_ecash || 0))}</strong></div>
                    <div><span>Counted E-Cash</span><strong>${sadEscape(sadMoney(row.counted_ecash || 0))}</strong></div>
                    <div><span>E-Cash Variance</span><strong class="${Number(row.variance_ecash || 0) < 0 ? "text-danger" : ""}">${sadEscape(sadMoney(row.variance_ecash || 0))}</strong></div>
                </div>
                <div class="variance-note">Closed ${sadEscape(sadDateTime(row.closed_at))}. Shortage exposure: ${sadEscape(sadMoney(row.shortage_amount || 0))}.</div>
                ${row.case_ref ? `<div class="variance-note">Case ${sadEscape(row.case_ref)} · Owner: ${sadEscape(row.owner_name || "Unassigned")}</div>` : ""}
                ${row.handoff_overdue ? `<div class="variance-case-overdue">Reviewer acknowledgment is overdue.</div>` : ""}
                <div class="variance-actions">
                    ${varianceStatus === "shortage" ? `<button type="button" class="danger-btn btn-sm" data-variance-action="approve_shortage" data-session-id="${Number(row.id || 0)}">Approve Shortage</button>` : ""}
                    <button type="button" class="secondary-btn btn-sm" data-variance-action="waive" data-session-id="${Number(row.id || 0)}">Waive</button>
                    <button type="button" class="secondary-btn btn-sm" data-variance-action="corrected" data-session-id="${Number(row.id || 0)}">Corrected</button>
                    <button type="button" class="secondary-btn btn-sm" data-variance-action="needs_investigation" data-session-id="${Number(row.id || 0)}">Investigate</button>
                </div>
            </div>
        `;
    }).join("");
}

async function sadLoadDashboard() {
    const root = sadRoot();
    if (!root) return;
    const response = await fetch(root.getAttribute("data-dashboard-url") || "/store-admin/dashboard/data");
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.status !== "success") {
        document.getElementById("sad-pending-reviews-list").innerHTML = sadDataState("error", "Variance reviews could not be loaded", "Refresh the page or check the server connection.");
        document.getElementById("sad-store-list").innerHTML = sadDataState("error", "Assigned stores could not be loaded", "Refresh the page or check the server connection.");
        return;
    }

    const summary = data.summary || {};
    sadSetText("sad-assigned-stores", String(summary.assigned_store_count || 0));
    sadSetText("sad-open-days", String(summary.open_day_count || 0));
    sadSetText("sad-pending-reviews", String(summary.pending_review_count || 0));
    sadSetText("sad-today-sales", sadMoney(summary.today_sales_total || 0));
    sadSetText("sad-shortage-total", `Shortage exposure: ${sadMoney(summary.pending_shortage_total || 0)}`);
    sadRenderReviews(data.pending_reviews || []);
    sadRenderStores(data.stores || []);
}

async function sadReviewVariance(sessionId, action) {
    const labels = {
        approve_shortage: "Approve shortage",
        waive: "Waive variance",
        corrected: "Mark corrected",
        needs_investigation: "Mark for investigation",
    };
    const note = await window.IbemsDialog.prompt("Enter a review note for this variance decision.", {
        title: labels[action] || "Review variance",
        inputLabel: "Review note",
        placeholder: "Explain the decision",
        required: true,
        confirmLabel: "Save review",
    });
    if (note === null) return;

    const root = sadRoot();
    const prefix = (root?.getAttribute("data-review-url-prefix") || "/store-admin/store-day-sessions").replace(/\/$/, "");
    const response = await fetch(`${prefix}/${Number(sessionId)}/review`, {
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
    await sadLoadDashboard();
}

document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-variance-action]");
    if (!button) return;
    event.preventDefault();
    sadReviewVariance(button.getAttribute("data-session-id"), button.getAttribute("data-variance-action"));
});

sadLoadDashboard();
