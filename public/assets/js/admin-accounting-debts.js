function aMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function aEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function adDebtDataState(type, message) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    return `<tr class="data-state-row"><td colspan="5"><div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${aEscape(message)}</strong></div></div></td></tr>`;
}

function debtPercent(currentDebt, creditLimit) {
    const current = Number(currentDebt || 0);
    const limit = Number(creditLimit || 0);
    if (limit <= 0) return 0;
    return Math.max(0, Math.min(100, (current / limit) * 100));
}

function adDebtDateTime(value) {
    if (!value) return "-";
    const date = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString("en-PH", { dateStyle: "medium", timeStyle: "short" });
}

function adDebtLabel(value) {
    return String(value || "-")
        .replace(/_/g, " ")
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function adDebtDetailItem(label, value) {
    return `<div class="ad-detail-item"><span>${aEscape(label)}</span><strong>${aEscape(value ?? "-")}</strong></div>`;
}

let adAccountModalTrigger = null;
let adAccountUserId = 0;

function adSetAccountTab(tabName) {
    document.querySelectorAll("[data-ad-account-tab]").forEach((button) => {
        const selected = button.dataset.adAccountTab === tabName;
        button.setAttribute("aria-selected", selected ? "true" : "false");
        button.tabIndex = selected ? 0 : -1;
    });
    document.querySelectorAll("[data-ad-account-panel]").forEach((panel) => {
        panel.hidden = panel.dataset.adAccountPanel !== tabName;
    });
}

function adDebtHistoryMarkup(debt, scope = "general", storeId = 0, storeName = "", targetId = "", dateFrom = "", dateTo = "") {
    const history = Array.isArray(debt.history) ? debt.history : [];
    const pagination = debt.pagination || {};
    const page = Math.max(1, Number(pagination.page || 1));
    const totalPages = Math.max(1, Number(pagination.total_pages || 1));
    const total = Math.max(0, Number(pagination.total || 0));
    const pageSize = Math.max(1, Number(pagination.page_size || 10));
    const activeDateFrom = pagination.date_from || dateFrom || "";
    const activeDateTo = pagination.date_to || dateTo || "";
    const historyTarget = targetId || (scope === "store" ? `ad-store-history-${Number(storeId || 0)}` : "ad-general-history");
    const from = total ? ((page - 1) * pageSize) + 1 : 0;
    const to = Math.min(page * pageSize, total);
    const table = history.length ? `
        <div class="table-standard-wrap">
            <table class="table table-standard">
                <thead><tr><th>Date</th><th>Activity</th><th>Store</th><th>Change</th><th>Balance After</th><th>Recorded By</th></tr></thead>
                <tbody>${history.map((row) => `
                    <tr>
                        <td>${aEscape(adDebtDateTime(row.created_at))}</td>
                        <td><strong>${aEscape(adDebtLabel(row.entry_type))}</strong>${row.remarks ? `<small class="table-cell-note">${aEscape(row.remarks)}</small>` : ""}</td>
                        <td>${aEscape(row.store_name || "General")}</td>
                        <td>${aEscape(row.direction === "credit" ? `-${aMoney(row.amount)}` : `+${aMoney(row.amount)}`)}</td>
                        <td>${aEscape(aMoney(row.debt_after))}</td>
                        <td>${aEscape(row.actor_name || "System")}</td>
                    </tr>`).join("")}</tbody>
            </table>
        </div>` : `<div class="data-state data-state--empty"><i class="bi bi-inbox" aria-hidden="true"></i><div><strong>No debt activity found</strong><small>${storeName ? `No debt entries are linked to ${aEscape(storeName)}.` : "Debt purchases and reductions will appear here."}</small></div></div>`;
    const pager = total > 0 ? `
        <nav class="overview-pager ad-history-pager" aria-label="${scope === "store" ? "Store debt activity pages" : "General debt activity pages"}">
            <span>${from}-${to} of ${total}</span>
            <div>
                <button class="secondary-btn btn-sm" type="button" data-ad-history-page="${page - 1}" data-ad-history-scope="${scope}" data-ad-history-store="${Number(storeId || 0)}" data-ad-history-store-name="${aEscape(storeName)}" data-ad-history-target="${aEscape(historyTarget)}" data-ad-history-date-from="${aEscape(activeDateFrom)}" data-ad-history-date-to="${aEscape(activeDateTo)}" ${page <= 1 ? "disabled" : ""}><i class="bi bi-chevron-left" aria-hidden="true"></i> Previous</button>
                <span>Page ${page} of ${totalPages}</span>
                <button class="secondary-btn btn-sm" type="button" data-ad-history-page="${page + 1}" data-ad-history-scope="${scope}" data-ad-history-store="${Number(storeId || 0)}" data-ad-history-store-name="${aEscape(storeName)}" data-ad-history-target="${aEscape(historyTarget)}" data-ad-history-date-from="${aEscape(activeDateFrom)}" data-ad-history-date-to="${aEscape(activeDateTo)}" ${page >= totalPages ? "disabled" : ""}>Next <i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            </div>
        </nav>` : "";
    return table + pager;
}

async function adLoadDebtHistory(page, storeId = 0, scope = "general", storeName = "", targetId = "", dateFrom = "", dateTo = "") {
    const historyTarget = targetId || (scope === "store" ? `ad-store-history-${Number(storeId || 0)}` : "ad-general-history");
    const target = document.getElementById(historyTarget);
    if (!target || !adAccountUserId) return;
    target.innerHTML = `<div class="data-state data-state--loading"><i class="bi bi-arrow-repeat" aria-hidden="true"></i><div><strong>Loading debt activity...</strong></div></div>`;
    try {
        const params = new URLSearchParams({ page: String(Math.max(1, Number(page || 1))), page_size: "10" });
        if (storeId > 0) params.set("store_id", String(storeId));
        if (dateFrom) params.set("date_from", dateFrom);
        if (dateTo) params.set("date_to", dateTo);
        const response = await fetch(`/admin/accounting-debts/user/${encodeURIComponent(String(adAccountUserId))}?${params}`);
        const data = await response.json();
        if (!response.ok || data.status !== "success") throw new Error(data.message || "Unable to load debt activity.");
        target.innerHTML = adDebtHistoryMarkup(data.data?.debt || {}, scope, storeId, storeName, historyTarget, dateFrom, dateTo);
    } catch (error) {
        target.innerHTML = `<div class="data-state data-state--error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong>${aEscape(error.message || "Unable to load debt activity.")}</strong></div></div>`;
    }
}

function adRenderAccountDetail(payload) {
    const general = payload.general || {};
    const debt = payload.debt || {};
    const stores = Array.isArray(payload.stores) ? payload.stores : [];
    const identityMeta = [general.employee_id || "No employee ID", general.email || "No email"].join(" · ");
    const storesHtml = stores.length ? `<div class="ad-store-list">${stores.map((store) => {
        const storeId = Number(store.store_id || 0);
        const storeName = store.store_name || "Unknown store";
        return `
        <article class="ad-store-card" data-ad-store-card="${storeId}">
            <div class="ad-store-card-main"><strong>${aEscape(store.store_name || "Unknown store")}</strong><small>Last debt activity</small><span>${aEscape(adDebtDateTime(store.last_activity_at))}</span></div>
            <span>Debt entries<strong>${Number(store.debt_transaction_count || 0)}</strong></span>
            <span>Debt added<strong>${aEscape(aMoney(store.debt_added || 0))}</strong></span>
            <span>Store repayments<strong>${aEscape(aMoney(store.store_repayments || 0))}</strong></span>
            <span>Net store activity<strong>${aEscape(aMoney(store.net_store_activity || 0))}</strong></span>
            <button class="secondary-btn btn-sm ad-store-toggle" type="button" data-ad-store-toggle="${storeId}" data-ad-store-name="${aEscape(storeName)}" aria-expanded="false" aria-controls="ad-store-activity-${storeId}"><i class="bi bi-chevron-down" aria-hidden="true"></i><span>View activity</span></button>
            <section id="ad-store-activity-${storeId}" class="ad-store-accordion" data-ad-store-accordion="${storeId}" hidden>
                <div class="ad-detail-section-head"><h6>${aEscape(storeName)} Debt Activity</h6><p>Filter and review only the transactions recorded at this store.</p></div>
                <div class="ad-store-date-filter" aria-label="${aEscape(storeName)} activity date filter">
                    <label><span>From date</span><input type="date" data-ad-store-date-from></label>
                    <label><span>To date</span><input type="date" data-ad-store-date-to></label>
                    <button class="primary-btn btn-sm" type="button" data-ad-store-filter-apply><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
                    <button class="secondary-btn btn-sm" type="button" data-ad-store-filter-clear>Clear</button>
                </div>
                <div id="ad-store-history-${storeId}" data-ad-store-history-target></div>
            </section>
        </article>`;
    }).join("")}</div>` : `<div class="data-state data-state--empty"><i class="bi bi-shop" aria-hidden="true"></i><div><strong>No store debt activity</strong><small>This employee has no debt entries linked to a store.</small></div></div>`;

    document.getElementById("ad-account-modal-content").innerHTML = `
        <div class="ad-account-identity">
            ${window.IbemsAvatar.html(general.name, general.profile_image_url, "table-person-avatar")}
            <div><h5>${aEscape(general.name || "Employee")}</h5><p>${aEscape(identityMeta)}</p></div>
        </div>
        <div class="ad-account-tabs" role="tablist" aria-label="Debt total views">
            <button class="ad-account-tab" type="button" role="tab" aria-selected="true" data-ad-account-tab="general"><i class="bi bi-cash-stack" aria-hidden="true"></i> General</button>
            <button class="ad-account-tab" type="button" role="tab" aria-selected="false" tabindex="-1" data-ad-account-tab="stores"><i class="bi bi-shop" aria-hidden="true"></i> Per Store</button>
        </div>
        <section class="ad-account-panel" role="tabpanel" data-ad-account-panel="general">
            <div class="ad-detail-section-head"><h6>General Debt Totals</h6><p>Combined totals across all stores and non-store debt adjustments.</p></div>
            <div class="ad-detail-grid ad-debt-summary">
                ${adDebtDetailItem("Current Debt", aMoney(debt.current_debt || 0))}
                ${adDebtDetailItem("Credit Limit", aMoney(debt.credit_limit || 0))}
                ${adDebtDetailItem("Available Credit", aMoney(debt.available_credit || 0))}
                ${adDebtDetailItem("Total Debt Added", aMoney(debt.total_added || 0))}
                ${adDebtDetailItem("Total Debt Reduced", aMoney(debt.total_reduced || 0))}
                ${adDebtDetailItem("Debt Activities", Number(debt.activity_count || 0))}
            </div>
            <div class="ad-detail-section-head"><h6>All Debt Activity</h6><p>Purchases, repayments, deductions, and approved corrections from every store.</p></div>
            <div id="ad-general-history">${adDebtHistoryMarkup(debt, "general")}</div>
        </section>
        <section class="ad-account-panel" role="tabpanel" data-ad-account-panel="stores" hidden>
            <div class="ad-detail-section-head"><h6>Debt Totals Per Store</h6><p>Debt purchases and repayments are grouped by the store where they were recorded.</p></div>
            ${storesHtml}
        </section>`;

    document.querySelectorAll("[data-ad-account-tab]").forEach((button) => {
        button.addEventListener("click", () => adSetAccountTab(button.dataset.adAccountTab));
        button.addEventListener("keydown", (event) => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            const tabs = Array.from(document.querySelectorAll("[data-ad-account-tab]"));
            const current = tabs.indexOf(button);
            const offset = event.key === 'ArrowRight' ? 1 : -1;
            const next = tabs[(current + offset + tabs.length) % tabs.length];
            adSetAccountTab(next.dataset.adAccountTab);
            next.focus();
        });
    });
}

function adCloseStoreAccordions(exceptStoreId = 0) {
    document.querySelectorAll("[data-ad-store-accordion]").forEach((panel) => {
        const storeId = Number(panel.dataset.adStoreAccordion || 0);
        if (storeId === exceptStoreId) return;
        panel.hidden = true;
        panel.closest("[data-ad-store-card]")?.classList.remove("is-expanded");
        const toggle = document.querySelector(`[data-ad-store-toggle="${storeId}"]`);
        toggle?.setAttribute("aria-expanded", "false");
        const icon = toggle?.querySelector("i");
        if (icon) icon.className = "bi bi-chevron-down";
        const label = toggle?.querySelector("span");
        if (label) label.textContent = "View activity";
    });
}

function adToggleStoreAccordion(button) {
    const storeId = Number(button.dataset.adStoreToggle || 0);
    const storeName = button.dataset.adStoreName || "Selected store";
    const panel = document.getElementById(`ad-store-activity-${storeId}`);
    if (!panel || storeId <= 0) return;
    const willOpen = panel.hidden;
    adCloseStoreAccordions(willOpen ? storeId : 0);
    panel.hidden = !willOpen;
    panel.closest("[data-ad-store-card]")?.classList.toggle("is-expanded", willOpen);
    button.setAttribute("aria-expanded", willOpen ? "true" : "false");
    const icon = button.querySelector("i");
    if (icon) icon.className = willOpen ? "bi bi-chevron-up" : "bi bi-chevron-down";
    const label = button.querySelector("span");
    if (label) label.textContent = willOpen ? "Hide activity" : "View activity";
    if (willOpen) {
        const dateFrom = panel.querySelector("[data-ad-store-date-from]")?.value || "";
        const dateTo = panel.querySelector("[data-ad-store-date-to]")?.value || "";
        adLoadDebtHistory(1, storeId, "store", storeName, `ad-store-history-${storeId}`, dateFrom, dateTo);
    }
}

async function adOpenAccountDetail(userId, trigger) {
    const modal = document.getElementById("ad-account-modal");
    const content = document.getElementById("ad-account-modal-content");
    adAccountModalTrigger = trigger;
    adAccountUserId = Number(userId || 0);
    modal.classList.remove("is-hidden");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("app-modal-open");
    content.innerHTML = `<div class="data-state data-state--loading"><i class="bi bi-arrow-repeat" aria-hidden="true"></i><div><strong>Loading account details...</strong></div></div>`;
    document.getElementById("ad-account-modal-close")?.focus();
    try {
        const response = await fetch(`/admin/accounting-debts/user/${encodeURIComponent(String(userId))}`);
        const data = await response.json();
        if (!response.ok || data.status !== "success") throw new Error(data.message || "Unable to load account details.");
        adRenderAccountDetail(data.data || {});
    } catch (error) {
        content.innerHTML = `<div class="data-state data-state--error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong>${aEscape(error.message || "Unable to load account details.")}</strong></div></div>`;
    }
}

function adCloseAccountDetail() {
    const modal = document.getElementById("ad-account-modal");
    modal.classList.add("is-hidden");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("app-modal-open");
    adAccountModalTrigger?.focus?.();
}

async function loadAdminDebtOverview() {
    const response = await fetch("/admin/accounting-debts/data");
    const data = await response.json();
    if (!data || data.status !== "success") {
        document.getElementById("ad-top-body").innerHTML = adDebtDataState("error", data?.message || "Unable to load debt records.");
        return;
    }

    const summary = data.summary || {};
    document.getElementById("ad-account-count").textContent = String(summary.account_count || 0);
    document.getElementById("ad-debt-accounts").textContent = String(summary.debt_accounts || 0);
    document.getElementById("ad-total-debt").textContent = aMoney(summary.total_debt || 0);
    document.getElementById("ad-today-deducted").textContent = aMoney(summary.today_deduction_amount || 0);

    const rows = Array.isArray(data.top_debts) ? data.top_debts : [];
    const body = document.getElementById("ad-top-body");
    if (rows.length === 0) {
        body.innerHTML = adDebtDataState("empty", "No debt records.");
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr class="ad-account-row" data-ad-account-user="${Number(row.user_id || 0)}" tabindex="0" role="button" aria-label="View ${aEscape(row.name || "employee")} account details">
            <td>${aEscape(row.employee_id || "-")}</td>
            <td><span class="table-person-cell">${window.IbemsAvatar.html(row.name, row.profile_image_url, "table-person-avatar")}<span class="ad-person-copy"><strong>${aEscape(row.name)}</strong><small>View account details</small></span></span></td>
            <td>${aEscape(row.email)}</td>
            <td>
                <div class="table-debt-wrap">
                    <span>${aEscape(aMoney(row.current_debt))}</span>
                    <div class="table-debt-bar"><i style="width:${debtPercent(row.current_debt, row.credit_limit)}%"></i></div>
                </div>
            </td>
            <td>${aEscape(aMoney(row.credit_limit))}</td>
        </tr>
    `).join("");
}

document.getElementById("ad-top-body")?.addEventListener("click", (event) => {
    const row = event.target.closest("[data-ad-account-user]");
    if (row) adOpenAccountDetail(Number(row.dataset.adAccountUser), row);
});
document.getElementById("ad-top-body")?.addEventListener("keydown", (event) => {
    const row = event.target.closest("[data-ad-account-user]");
    if (!row || !["Enter", " "].includes(event.key)) return;
    event.preventDefault();
    adOpenAccountDetail(Number(row.dataset.adAccountUser), row);
});
document.getElementById("ad-account-modal-close")?.addEventListener("click", adCloseAccountDetail);
document.getElementById("ad-account-modal")?.addEventListener("click", (event) => {
    const pageButton = event.target.closest("[data-ad-history-page]");
    if (pageButton) {
        adLoadDebtHistory(
            Number(pageButton.dataset.adHistoryPage || 1),
            Number(pageButton.dataset.adHistoryStore || 0),
            pageButton.dataset.adHistoryScope || "general",
            pageButton.dataset.adHistoryStoreName || "",
            pageButton.dataset.adHistoryTarget || "",
            pageButton.dataset.adHistoryDateFrom || "",
            pageButton.dataset.adHistoryDateTo || ""
        );
        return;
    }
    const storeToggle = event.target.closest("[data-ad-store-toggle]");
    if (storeToggle) {
        adToggleStoreAccordion(storeToggle);
        return;
    }
    const filterButton = event.target.closest("[data-ad-store-filter-apply], [data-ad-store-filter-clear]");
    if (filterButton) {
        const card = filterButton.closest("[data-ad-store-card]");
        const storeId = Number(card?.dataset.adStoreCard || 0);
        const toggle = card?.querySelector("[data-ad-store-toggle]");
        const storeName = toggle?.dataset.adStoreName || "Selected store";
        const dateFromInput = card?.querySelector("[data-ad-store-date-from]");
        const dateToInput = card?.querySelector("[data-ad-store-date-to]");
        if (filterButton.matches("[data-ad-store-filter-clear]")) {
            if (dateFromInput) dateFromInput.value = "";
            if (dateToInput) dateToInput.value = "";
        }
        const dateFrom = dateFromInput?.value || "";
        const dateTo = dateToInput?.value || "";
        const targetId = `ad-store-history-${storeId}`;
        if (dateFrom && dateTo && dateFrom > dateTo) {
            document.getElementById(targetId).innerHTML = `<div class="data-state data-state--error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong>The start date must be on or before the end date.</strong></div></div>`;
            dateFromInput?.focus();
            return;
        }
        adLoadDebtHistory(1, storeId, "store", storeName, targetId, dateFrom, dateTo);
        return;
    }
    const storeCard = event.target.closest("[data-ad-store-card]");
    if (storeCard && !event.target.closest("[data-ad-store-accordion], button, input, label")) {
        const toggle = storeCard.querySelector("[data-ad-store-toggle]");
        if (toggle) adToggleStoreAccordion(toggle);
        return;
    }
    if (event.target.id === "ad-account-modal") adCloseAccountDetail();
});
document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !document.getElementById("ad-account-modal")?.classList.contains("is-hidden")) adCloseAccountDetail();
});

loadAdminDebtOverview();
