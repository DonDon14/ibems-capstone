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
    const reviewActions = canReview ? `
        <div class="variance-actions">
            ${varianceStatus === "shortage" ? `<button type="button" class="danger-btn btn-sm" data-variance-action="approve_shortage" data-session-id="${Number(session.id || 0)}">Approve Shortage</button>` : ""}
            <button type="button" class="secondary-btn btn-sm" data-variance-action="waive" data-session-id="${Number(session.id || 0)}">Waive</button>
            <button type="button" class="secondary-btn btn-sm" data-variance-action="corrected" data-session-id="${Number(session.id || 0)}">Corrected</button>
            <button type="button" class="secondary-btn btn-sm" data-variance-action="needs_investigation" data-session-id="${Number(session.id || 0)}">Investigate</button>
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
        ${Number(session.accountability_amount || 0) > 0 ? `<div class="variance-note">Accountability: ${sdEscape(sdMoney(session.accountability_amount))} assigned to ${sdEscape(accountabilityName)}.</div>` : ""}
        ${reviewCopy}
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
            ${closedSummary}
        </div>
    `;
}

async function sdReviewVariance(sessionId, action) {
    const actionLabels = {
        approve_shortage: "Approve shortage",
        waive: "Waive variance",
        corrected: "Mark corrected",
        needs_investigation: "Mark for investigation",
    };
    const note = await window.IbemsDialog.prompt("Enter a review note for this variance decision.", {
        title: actionLabels[action] || "Review variance",
        inputLabel: "Review note",
        placeholder: "Explain the decision",
        required: true,
        confirmLabel: "Save review",
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
                <span>${sdEscape(row.role || "Officer")}</span>
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
                ${varianceStatus === "shortage" ? `<button type="button" class="danger-btn btn-sm" data-variance-action="approve_shortage" data-session-id="${sessionId}">Approve</button>` : ""}
                <button type="button" class="secondary-btn btn-sm" data-variance-action="waive" data-session-id="${sessionId}">Waive</button>
                <button type="button" class="secondary-btn btn-sm" data-variance-action="corrected" data-session-id="${sessionId}">Corrected</button>
                <button type="button" class="secondary-btn btn-sm" data-variance-action="needs_investigation" data-session-id="${sessionId}">Investigate</button>
            </div>`
            : '<span class="text-muted">-</span>';
        return `
            <tr class="${canReview ? "store-day-row-unresolved" : ""}">
                <td>${sdEscape(session.business_date || "-")}</td>
                <td>${sdEscape(String(session.status || "-").toUpperCase())}</td>
                <td><span class="variance-pill ${sdVarianceClass(varianceStatus)}">${sdEscape(sdVarianceStatusLabel(varianceStatus))}</span><div class="stack-meta">${sdEscape(sdMoney(Number(session.variance_cash || 0) + Number(session.variance_ecash || 0)))}</div></td>
                <td><span class="variance-pill ${sdReviewClass(reviewStatus)}">${sdEscape(sdReviewLabel(reviewStatus))}</span></td>
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
    const button = event.target.closest("[data-variance-action]");
    if (!button) return;
    event.preventDefault();
    sdReviewVariance(button.getAttribute("data-session-id"), button.getAttribute("data-variance-action"));
});

document.getElementById("sd-session-filter")?.addEventListener("change", sdRenderSessionHistory);

loadStoreDetails();
