let reportsStores = [];
let reportsActiveStoreId = null;
let reportsPeriod = "today";
let reportsSummaryData = null;
let reportsTrendChart = null;
let reportsPaymentMixChart = null;
let reportsSelectedReceipt = null;
let reportsReceiptTrigger = null;
let reportsReceiptSequence = 0;
let reportsRequestSequence = 0;

function rMoney(value) {
    return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
}

function rEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function rDateTime(value) {
    return window.IbemsFormat?.dateTime(value) || new Date(value).toLocaleString();
}

function rDataState(type, message, colspan) {
    const safeType = ["loading", "empty", "error", "success"].includes(type) ? type : "loading";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-inbox",
        error: "bi bi-exclamation-circle",
        success: "bi bi-check-circle",
    };
    const role = safeType === "error" ? "alert" : "status";
    return `<tr class="data-state-row"><td colspan="${Number(colspan)}"><div class="data-state data-state--${safeType}" role="${role}" aria-live="polite"><i class="${icons[safeType]}" aria-hidden="true"></i><div><strong>${rEscape(message)}</strong></div></div></td></tr>`;
}

function rRenderTableStates(type, message) {
    document.getElementById("payment-body").innerHTML = rDataState(type, message, 3);
    document.getElementById("cash-movements-body").innerHTML = rDataState(type, message, 5);
    document.getElementById("products-body").innerHTML = rDataState(type, message, 4);
    document.getElementById("trend-body").innerHTML = rDataState(type, message, 3);
    document.getElementById("reports-transactions-body").innerHTML = rDataState(type, message, 6);
    document.getElementById("reports-transaction-count").textContent = message;
    document.getElementById("payment-account-body").innerHTML = rDataState(type, message, 4);
}

function rClearMetrics(label) {
    reportsSummaryData = null;
    ["sum-sales", "sum-cost", "sum-profit", "sum-margin", "sum-transactions", "sum-items", "sum-ticket", "sum-stockin-cost",
        "pay-cash", "pay-cash-meta", "pay-gcash", "pay-gcash-meta", "pay-others", "pay-others-meta", "pay-debt", "pay-debt-meta",
        "cash-opening", "cash-date", "cash-on-hand", "ecash-on-hand", "pay-total-revenue", "reports-range-label"].forEach((id) => {
        const element = document.getElementById(id);
        if (element) element.textContent = label;
    });
    document.getElementById("reports-note").textContent = "";
}

function rSetResult(message, type) {
    const el = document.getElementById("reports-result");
    el.setAttribute("role", type === "error" ? "alert" : "status");
    el.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
    el.setAttribute("aria-atomic", "true");
    el.textContent = message || "";
    el.style.color = type === "error" ? "var(--feedback-error, #b91c1c)" : type === "warning" ? "var(--feedback-warning, #92400e)" : "var(--feedback-success, #166534)";
}

function rSetPeriod(nextPeriod) {
    ++reportsRequestSequence;
    reportsPeriod = nextPeriod;
    document.querySelectorAll(".period-chip").forEach((chip) => {
        chip.classList.toggle("is-active", chip.dataset.period === reportsPeriod);
        chip.setAttribute("aria-pressed", chip.dataset.period === reportsPeriod ? "true" : "false");
    });
    document.getElementById("reports-custom-range").classList.toggle("hidden", reportsPeriod !== "custom");
    if (reportsPeriod === "custom") {
        rClearMetrics("Select dates");
        rRenderTableStates("empty", "Choose dates and select Refresh to load this period.");
        rRenderTrendBars([]);
        rRenderPaymentMix([]);
        rSetChartState("reports-trend-chart", "reports-trend-chart-state", "empty", "Choose dates to view the sales trend.");
        rSetChartState("reports-payment-mix-chart", "reports-payment-mix-chart-state", "empty", "Choose dates to view the payment mix.");
    }
}

function rSetChartState(canvasId, stateId, type, message) {
    const canvas = document.getElementById(canvasId);
    const state = document.getElementById(stateId);
    if (!canvas || !state) return;

    const safeType = ["loading", "empty", "error"].includes(type) ? type : "empty";
    const icons = {
        loading: "bi bi-arrow-repeat",
        empty: "bi bi-bar-chart-line",
        error: "bi bi-exclamation-circle",
    };
    state.className = `reports-chart-state reports-chart-state--${safeType}`;
    state.innerHTML = `<i class="${icons[safeType]}" aria-hidden="true"></i><strong>${rEscape(message)}</strong>`;
    canvas.classList.add("is-hidden");
}

function rShowChart(canvasId, stateId) {
    document.getElementById(canvasId)?.classList.remove("is-hidden");
    document.getElementById(stateId)?.classList.add("is-hidden");
}

function rApplyChartTheme(chart) {
    if (!chart) return;
    chart.stop();
    const tokens = getComputedStyle(chart.canvas);
    const text = tokens.getPropertyValue("--text-soft").trim() || "#64748b";
    const border = tokens.getPropertyValue("--border").trim() || "#e2e8f0";
    chart.options.color = text;
    chart.options.animation = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    chart.options.plugins.legend.labels = { ...chart.options.plugins.legend.labels, color: text };
    Object.values(chart.options.scales || {}).forEach((axis) => {
        axis.ticks = { ...axis.ticks, color: text };
        axis.grid = { ...axis.grid, color: border };
        axis.border = { ...axis.border, color: border };
    });
    if (chart.config.type === "doughnut") chart.data.datasets.forEach(dataset => { dataset.borderColor = border; });
    chart.update("none");
}

window.addEventListener("ibems:themechange", () => {
    rApplyChartTheme(reportsTrendChart);
    rApplyChartTheme(reportsPaymentMixChart);
});
window.matchMedia("(prefers-reduced-motion: reduce)").addEventListener("change", () => {
    rApplyChartTheme(reportsTrendChart);
    rApplyChartTheme(reportsPaymentMixChart);
});

function rRenderTrendBars(rows) {
    const canvas = document.getElementById("reports-trend-chart");
    if (!canvas) return;

    if (reportsTrendChart) {
        reportsTrendChart.destroy();
        reportsTrendChart = null;
    }

    const usableRows = Array.isArray(rows)
        ? rows.filter((row) => Number(row.sales || 0) !== 0 || Number(row.transactions || 0) !== 0)
        : [];
    if (usableRows.length === 0) {
        rSetChartState("reports-trend-chart", "reports-trend-chart-state", "empty", "No sales were recorded for this period.");
        return;
    }
    if (typeof window.Chart === "undefined") {
        rSetChartState("reports-trend-chart", "reports-trend-chart-state", "error", "The chart library could not be loaded.");
        return;
    }

    rShowChart("reports-trend-chart", "reports-trend-chart-state");

    const labels = usableRows.map((row) => String(row.date || "").slice(5));
    const sales = usableRows.map((row) => Number(row.sales || 0));
    const transactions = usableRows.map((row) => Number(row.transactions || 0));

    reportsTrendChart = new window.Chart(canvas, {
        data: {
            labels,
            datasets: [
                {
                    type: "bar",
                    label: "Sales",
                    data: sales,
                    yAxisID: "y",
                    borderRadius: 8,
                    maxBarThickness: 28,
                    backgroundColor: "rgba(37, 99, 235, 0.75)",
                    borderColor: "rgba(30, 64, 175, 1)",
                    borderWidth: 1,
                },
                {
                    type: "line",
                    label: "Transactions",
                    data: transactions,
                    yAxisID: "y1",
                    tension: 0.3,
                    borderWidth: 2,
                    borderColor: "rgba(16, 185, 129, 1)",
                    backgroundColor: "rgba(16, 185, 129, 0.2)",
                    pointRadius: 3,
                },
            ],
        },
        options: {
            animation: !window.matchMedia("(prefers-reduced-motion: reduce)").matches,
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: "top" },
                tooltip: {
                    callbacks: {
                        label(context) {
                            if (context.dataset.yAxisID === "y1") {
                                return `Transactions: ${Number(context.parsed.y || 0)}`;
                            }
                            return `Sales: ${rMoney(context.parsed.y || 0)}`;
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    beginAtZero: true,
                    position: "left",
                    ticks: {
                        callback(value) {
                            return window.IbemsFormat?.money(value, { decimals: 0 }) || `PHP ${Number(value).toFixed(0)}`;
                        },
                    },
                },
                y1: {
                    beginAtZero: true,
                    position: "right",
                    grid: { drawOnChartArea: false },
                },
            },
        },
    });
    rApplyChartTheme(reportsTrendChart);
}

function rRenderPaymentMix(rows) {
    const canvas = document.getElementById("reports-payment-mix-chart");
    if (!canvas) return;

    if (reportsPaymentMixChart) {
        reportsPaymentMixChart.destroy();
        reportsPaymentMixChart = null;
    }

    const usableRows = Array.isArray(rows) ? rows.filter((row) => Number(row.sales || 0) > 0) : [];
    if (usableRows.length === 0) {
        rSetChartState("reports-payment-mix-chart", "reports-payment-mix-chart-state", "empty", "No payments were recorded for this period.");
        return;
    }
    if (typeof window.Chart === "undefined") {
        rSetChartState("reports-payment-mix-chart", "reports-payment-mix-chart-state", "error", "The chart library could not be loaded.");
        return;
    }

    rShowChart("reports-payment-mix-chart", "reports-payment-mix-chart-state");

    const labels = usableRows.map((row) => String(row.payment_method || "unknown").toUpperCase());
    const values = usableRows.map((row) => Number(row.sales || 0));

    reportsPaymentMixChart = new window.Chart(canvas, {
        type: "doughnut",
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: [
                    "#2563eb",
                    "#10b981",
                    "#f59e0b",
                    "#8b5cf6",
                    "#ef4444",
                    "#06b6d4",
                    "#64748b",
                ],
                borderWidth: 1,
                borderColor: "#ffffff",
            }],
        },
        options: {
            animation: !window.matchMedia("(prefers-reduced-motion: reduce)").matches,
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: "bottom" },
                tooltip: {
                    callbacks: {
                        label(context) {
                            return `${context.label}: ${rMoney(context.parsed || 0)}`;
                        },
                    },
                },
            },
        },
    });
    rApplyChartTheme(reportsPaymentMixChart);
}

function rRenderSummary(data) {
    const summary = data?.summary || {};
    const stockIn = data?.stock_in || {};

    document.getElementById("sum-sales").textContent = rMoney(summary.total_sales || 0);
    document.getElementById("sum-cost").textContent = rMoney(summary.estimated_cost || 0);
    document.getElementById("sum-profit").textContent = rMoney(summary.estimated_profit || 0);
    document.getElementById("sum-margin").textContent = `${Number(summary.profit_margin_percent || 0).toFixed(2)}%`;
    document.getElementById("sum-transactions").textContent = String(summary.transactions || 0);
    document.getElementById("sum-items").textContent = String(summary.items_sold || 0);
    document.getElementById("sum-ticket").textContent = rMoney(summary.average_ticket || 0);
    document.getElementById("sum-stockin-cost").textContent = rMoney(stockIn.total_cost || 0);

    const note = data?.notes?.profit_basis || "";
    document.getElementById("reports-note").textContent = note;
    const range = data?.range || {};
    const label = `${String(range.from || "-")} to ${String(range.to || "-")} (${String(range.period || "-").toUpperCase()})`;
    const rangeEl = document.getElementById("reports-range-label");
    if (rangeEl) rangeEl.textContent = label;
}

function rRenderPaymentRows(rows) {
    const body = document.getElementById("payment-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = rDataState("empty", "No payment data for this period.", 3);
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${rEscape(String(row.payment_method || "-").toUpperCase())}</td>
            <td>${Number(row.transactions || 0)}</td>
            <td>${rEscape(rMoney(row.sales || 0))}</td>
        </tr>
    `).join("");
}

function rRenderPaymentRecords(rows, data) {
    const safeRows = Array.isArray(rows) ? rows : [];
    const index = {};
    safeRows.forEach((row) => {
        const key = String(row.payment_method || "").toLowerCase();
        index[key] = {
            sales: Number(row.sales || 0),
            transactions: Number(row.transactions || 0),
        };
    });

    const cash = index.cash || { sales: 0, transactions: 0 };
    const gcash = index.gcash || { sales: 0, transactions: 0 };
    const debt = index.debt || { sales: 0, transactions: 0 };

    const excluded = new Set(["cash", "gcash", "debt"]);
    let othersSales = 0;
    let othersTxn = 0;
    safeRows.forEach((row) => {
        const key = String(row.payment_method || "").toLowerCase();
        if (excluded.has(key)) return;
        othersSales += Number(row.sales || 0);
        othersTxn += Number(row.transactions || 0);
    });

    const totalRevenue = Number(data?.summary?.total_sales || 0);
    const cashDrawer = data?.cash_drawer || {};
    const openingBalance = Number(cashDrawer.opening_cash ?? cashDrawer.opening_balance ?? 0);
    const expectedCashOnHand = Number(cashDrawer.expected_cash_on_hand || 0);
    const expectedEcashOnHand = Number(cashDrawer.expected_ecash_on_hand || 0);
    const openingDate = String(cashDrawer.opening_business_date || "--");

    document.getElementById("pay-cash").textContent = rMoney(cash.sales);
    document.getElementById("pay-cash-meta").textContent = `${cash.transactions} transactions`;
    document.getElementById("pay-gcash").textContent = rMoney(gcash.sales);
    document.getElementById("pay-gcash-meta").textContent = `${gcash.transactions} transactions`;
    document.getElementById("pay-others").textContent = rMoney(othersSales);
    document.getElementById("pay-others-meta").textContent = `${othersTxn} transactions`;
    document.getElementById("pay-debt").textContent = rMoney(debt.sales);
    document.getElementById("pay-debt-meta").textContent = `${debt.transactions} transactions`;

    document.getElementById("cash-opening").textContent = rMoney(openingBalance);
    document.getElementById("cash-date").textContent = `Business date: ${openingDate}`;
    document.getElementById("cash-on-hand").textContent = rMoney(expectedCashOnHand);
    document.getElementById("ecash-on-hand").textContent = rMoney(expectedEcashOnHand);
    document.getElementById("pay-total-revenue").textContent = rMoney(totalRevenue);
}

function rRenderCashMovements(rows) {
    const body = document.getElementById("cash-movements-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = rDataState("empty", "No cash movement records for this range.", 5);
        return;
    }

    body.innerHTML = rows.map((row) => {
        const channel = String(row.channel || "cash").toUpperCase();
        const movementType = String(row.movement_type || "cash_in").toLowerCase() === "cash_out" ? "Cash Out" : "Cash In";
        return `
            <tr>
                <td>${rEscape(row.business_date || "-")}</td>
                <td>${rEscape(channel)}</td>
                <td>${rEscape(movementType)}</td>
                <td>${rEscape(rMoney(row.amount || 0))}</td>
                <td>${rEscape(row.reason || "-")}</td>
            </tr>
        `;
    }).join("");
}

async function rCreateCashMovement() {
    if (!reportsActiveStoreId) return;

    const channel = String(document.getElementById("cash-channel").value || "cash").trim().toLowerCase();
    const movementType = String(document.getElementById("cash-movement-type").value || "cash_in").trim().toLowerCase();
    const amount = Number(document.getElementById("cash-movement-amount").value || 0);
    const reason = String(document.getElementById("cash-movement-reason").value || "").trim();
    const saveBtn = document.getElementById("cash-movement-save");
    const businessDate = reportsSummaryData?.cash_drawer?.business_date || new Date().toISOString().slice(0, 10);

    if (!(channel === "cash" || channel === "ecash")) {
        rSetResult("Channel must be cash or ecash.", "error");
        return;
    }

    if (!(movementType === "cash_in" || movementType === "cash_out")) {
        rSetResult("Type must be cash_in or cash_out.", "error");
        return;
    }

    if (amount <= 0) {
        rSetResult("Amount must be greater than 0.", "error");
        return;
    }

    if (reason === "") {
        rSetResult("Reason is required.", "error");
        return;
    }

    saveBtn.disabled = true;
    saveBtn.textContent = "Saving...";
    try {
        const response = await fetch("/store/cash-movements/create", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                store_id: reportsActiveStoreId,
                business_date: businessDate,
                channel,
                movement_type: movementType,
                amount,
                reason,
            }),
        });
        const data = await response.json();
        if (!response.ok || !data || data.status !== "success") {
            throw new Error(data?.message || "Failed to save cash movement.");
        }

        document.getElementById("cash-movement-amount").value = "";
        document.getElementById("cash-movement-reason").value = "";
        window.IbemsFeedbackSound?.play("success");
        try {
            await rLoadSummary();
            if (!reportsSummaryData) throw new Error("Report refresh failed.");
            rSetResult("Cash movement saved.", "ok");
        } catch (refreshError) {
            rSetResult("Cash movement saved. Report refresh failed; refresh the page to view updated totals.", "warning");
        }
    } catch (error) {
        rSetResult(error.message || "Failed to save cash movement.", "error");
        window.IbemsFeedbackSound?.play("error");
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = "Save Entry";
    }
}

function rRenderProductRows(rows) {
    const body = document.getElementById("products-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = rDataState("empty", "No product sales for this period.", 4);
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${rEscape(row.name || "-")}<br><small>${rEscape(row.sku || "-")}</small></td>
            <td>${Number(row.qty_sold || 0)}</td>
            <td>${rEscape(rMoney(row.revenue || 0))}</td>
            <td>${rEscape(rMoney(row.estimated_profit || 0))}</td>
        </tr>
    `).join("");
}

function rRenderTrendRows(rows) {
    const body = document.getElementById("trend-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = rDataState("empty", "No trend data for this period.", 3);
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>${rEscape(row.date || "-")}</td>
            <td>${Number(row.transactions || 0)}</td>
            <td>${rEscape(rMoney(row.sales || 0))}</td>
        </tr>
    `).join("");
}

function rRenderPaymentAccountRows(rows) {
    const body = document.getElementById("payment-account-body"); if (!body) return;
    if (!Array.isArray(rows) || !rows.length) { body.innerHTML = rDataState("empty", "No account-specific payments for this period.", 4); return; }
    body.innerHTML = rows.map((row) => `<tr><td>${rEscape(String(row.payment_method || "-").toUpperCase())}</td><td><strong>${rEscape(row.account_name || "-")}</strong><small class="table-cell-note">ending ${rEscape(String(row.account_number || "").slice(-4))}</small></td><td>${Number(row.transactions || 0)}</td><td>${rEscape(rMoney(row.sales || 0))}</td></tr>`).join("");
}

function rPaymentLabel(row) {
    const lines = Array.isArray(row.payments) ? row.payments : [];
    return (lines.length ? lines.map((line) => line.payment_method) : [row.payment_method])
        .filter(Boolean).map((value) => String(value).replace(/_/g, " ").toUpperCase()).join(" + ") || "-";
}

function rRenderTransactions(rows) {
    const body = document.getElementById("reports-transactions-body");
    const safeRows = Array.isArray(rows) ? rows : [];
    document.getElementById("reports-transaction-count").textContent = `${safeRows.length} receipt${safeRows.length === 1 ? "" : "s"} shown`;
    if (!safeRows.length) { body.innerHTML = rDataState("empty", "No transactions in this report period.", 6); return; }
    body.innerHTML = safeRows.map((row) => `<tr>
        <td>${rEscape(rDateTime(row.created_at))}</td><td><code>${rEscape(row.client_txn_id || `#${row.id}`)}</code></td>
        <td><span class="table-person-cell">${window.IbemsAvatar.html(row.customer_name, row.customer_profile_image_url, "table-person-avatar")}<span>${rEscape(row.customer_name || "Walk-in")}</span></span></td><td>${rEscape(rPaymentLabel(row))}</td><td>${rEscape(rMoney(row.amount))}</td>
        <td><button class="secondary-btn btn-sm" type="button" data-report-receipt="${Number(row.id)}"><i class="bi bi-receipt"></i> View receipt</button></td></tr>`).join("");
}

async function rLoadTransactions(from, to, requestId) {
    const params = new URLSearchParams({store_id:String(reportsActiveStoreId),limit:"100",date_from:from,date_to:to});
    const response = await fetch(`/store/transactions?${params}`); const data = await response.json();
    if (requestId !== reportsRequestSequence) return;
    if (!response.ok || !data || data.status !== "success") throw new Error(data?.message || "Unable to load report transactions.");
    rRenderTransactions(data.transactions);
}

async function rOpenReceipt(id, trigger) {
    const requestId = ++reportsReceiptSequence;
    const modal = document.getElementById("reports-receipt-modal");
    const content = document.getElementById("reports-receipt-content");
    const view = document.getElementById("reports-receipt-view");
    const print = document.getElementById("reports-receipt-print");
    reportsReceiptTrigger = trigger;
    reportsSelectedReceipt = null;
    view.hidden = true;
    view.style.display = "none";
    print.disabled = true;
    content.innerHTML = '<div class="receipt-loading" role="status">Loading receipt...</div>';
    modal.style.display = "grid";
    document.getElementById("reports-receipt-close").focus();
    try {
        const response = await fetch(`/store/transactions/${encodeURIComponent(String(id))}`);
        const data = await response.json();
        if (requestId !== reportsReceiptSequence) return;
        if (!response.ok || !data?.transaction) throw new Error(data?.message || "Unable to load receipt.");
        const tx = data.transaction;
        reportsSelectedReceipt = {transactionId:tx.id,clientTxnId:tx.client_txn_id,createdAt:tx.created_at,storeName:tx.store_name,customerName:tx.customer_name,paymentMethod:tx.payment_method,payments:tx.payments,totalAmount:tx.amount,items:tx.items,lookupUrl:`${location.origin}/store/receipt/${encodeURIComponent(String(tx.id))}?store_id=${encodeURIComponent(String(reportsActiveStoreId))}`};
        window.IbemsReceipt.renderReceipt("reports-receipt-content", reportsSelectedReceipt);
        view.href = reportsSelectedReceipt.lookupUrl;
        view.hidden = false;
        view.style.display = "";
        print.disabled = false;
    } catch (error) {
        if (requestId !== reportsReceiptSequence) return;
        reportsSelectedReceipt = null;
        content.innerHTML = `<div class="receipt-error" role="alert">${rEscape(error?.message || "Unable to load receipt.")} Close this dialog and try again.</div>`;
    }
}
function rCloseReceipt(){++reportsReceiptSequence;reportsSelectedReceipt=null;document.getElementById("reports-receipt-modal").style.display="none";reportsReceiptTrigger?.focus?.();reportsReceiptTrigger=null;}

function rReceiptKeydown(event) {
    const modal = document.getElementById("reports-receipt-modal");
    if (modal.style.display !== "grid") return;
    if (event.key === "Escape") { event.preventDefault(); rCloseReceipt(); return; }
    if (event.key !== "Tab") return;
    const controls = Array.from(modal.querySelectorAll('a[href],button,input,select,textarea,[tabindex]'))
        .filter(element => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length);
    const first = controls[0], last = controls[controls.length - 1];
    if (!first) { event.preventDefault(); return; }
    if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
}

async function rLoadStores() {
    const context = window.IBEMS_PORTAL_CONTEXT || {};
    if (Array.isArray(context.stores) && context.stores.length > 0) {
        reportsStores = context.stores;
        reportsActiveStoreId = window.IbemsStoreScope.resolve(reportsStores, context.default_store_id);
        return;
    }

    const response = await fetch("/store/my-stores");
    const data = await response.json();
    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }

    reportsStores = data.stores;
    reportsActiveStoreId = window.IbemsStoreScope.resolve(reportsStores, data.default_store_id);
}

async function rLoadSummary() {
    const requestId = ++reportsRequestSequence;
    if (!reportsActiveStoreId) return;

    const params = new URLSearchParams({
        store_id: String(reportsActiveStoreId),
        period: reportsPeriod,
    });

    if (reportsPeriod === "custom") {
        const from = (document.getElementById("reports-date-from").value || "").trim();
        const to = (document.getElementById("reports-date-to").value || "").trim();
        if (!from || !to) {
            rSetResult("Choose both From and To dates, then select Refresh.", "ok");
            return;
        }
        if (from > to) { rSetResult("From date cannot be later than To date.", "error"); return; }
        params.set("date_from", from);
        params.set("date_to", to);
    }

    rClearMetrics("Loading…");
    rSetResult("", "ok");
    rRenderTableStates("loading", "Loading report data...");
    rSetChartState("reports-trend-chart", "reports-trend-chart-state", "loading", "Loading sales trend...");
    rSetChartState("reports-payment-mix-chart", "reports-payment-mix-chart-state", "loading", "Loading payment mix...");

    let data;
    try {
        const response = await fetch(`/store/reports/summary?${params.toString()}`);
        data = await response.json();
        if (!response.ok) throw new Error(data?.message || "Unable to load report summary.");
    } catch (error) {
        if (requestId !== reportsRequestSequence) return;
        const message = error?.message || "Unable to load report summary.";
        rClearMetrics("Unavailable");
        rRenderTableStates("error", message);
        rRenderTrendBars([]);
        rRenderPaymentMix([]);
        rSetChartState("reports-trend-chart", "reports-trend-chart-state", "error", message);
        rSetChartState("reports-payment-mix-chart", "reports-payment-mix-chart-state", "error", message);
        rSetResult(message, "error");
        return;
    }
    if (requestId !== reportsRequestSequence) return;
    if (!data || data.status !== "success") {
        const message = data?.message || "Unable to load report summary.";
        rSetResult(message, "error");
        rClearMetrics("Unavailable");
        rRenderTableStates("error", message);
        rRenderTrendBars([]);
        rRenderPaymentMix([]);
        rSetChartState("reports-trend-chart", "reports-trend-chart-state", "error", message);
        rSetChartState("reports-payment-mix-chart", "reports-payment-mix-chart-state", "error", message);
        return;
    }
    if (requestId !== reportsRequestSequence) return;

    reportsSummaryData = data;
    rRenderSummary(data);
    rRenderPaymentRows(data.payment_breakdown || []);
    rRenderPaymentAccountRows(data.payment_account_breakdown || []);
    rRenderPaymentRecords(data.payment_breakdown || [], data);
    rRenderCashMovements(data.cash_movements || []);
    rRenderProductRows(data.top_products || []);
    rRenderTrendRows(data.trend || []);
    rRenderTrendBars(data.trend || []);
    rRenderPaymentMix(data.payment_breakdown || []);
    try { await rLoadTransactions(data.range.from, data.range.to, requestId); } catch(error) {
        if (requestId !== reportsRequestSequence) return;
        document.getElementById("reports-transactions-body").innerHTML=rDataState("error",error.message,6);
        document.getElementById("reports-transaction-count").textContent="Receipts unavailable";
    }
    if (requestId !== reportsRequestSequence) return;
    rSetResult("", "ok");
}

document.querySelectorAll(".period-chip").forEach((chip) => {
    chip.addEventListener("click", async () => {
        rSetPeriod(chip.dataset.period || "today");
        if (reportsPeriod === "custom") { rSetResult("Choose both dates, then select Refresh.", "ok"); return; }
        await rLoadSummary();
    });
});

document.getElementById("reports-refresh-btn").addEventListener("click", async () => {
    await rLoadSummary();
});

function rUpdateCustomResetVisibility(){
    const hasDates=document.getElementById("reports-date-from").value!==""||document.getElementById("reports-date-to").value!=="";
    document.getElementById("reports-custom-clear").classList.toggle("is-hidden",!hasDates);
    if (reportsPeriod === "custom") rSetPeriod("custom");
}
["reports-date-from","reports-date-to"].forEach((id)=>document.getElementById(id).addEventListener("change",rUpdateCustomResetVisibility));
document.getElementById("reports-custom-clear").addEventListener("click",()=>{document.getElementById("reports-date-from").value="";document.getElementById("reports-date-to").value="";rUpdateCustomResetVisibility();rSetResult("Custom dates reset.","ok");});
document.getElementById("reports-transactions-body").addEventListener("click",async(event)=>{const button=event.target.closest("[data-report-receipt]");if(!button)return;try{await rOpenReceipt(button.dataset.reportReceipt,button);}catch(error){rSetResult(error.message,"error");}});
document.getElementById("reports-receipt-close").addEventListener("click",rCloseReceipt);
document.getElementById("reports-receipt-modal").addEventListener("click",event=>{if(event.target.id==="reports-receipt-modal")rCloseReceipt();});
document.getElementById("reports-receipt-print").addEventListener("click",()=>{if(reportsSelectedReceipt&&!window.IbemsReceipt.printReceipt(reportsSelectedReceipt))rSetResult("Popup blocked. Please allow popups.","error");});
document.addEventListener("keydown",rReceiptKeydown);

document.getElementById("cash-movement-save").addEventListener("click", async () => {
    await rCreateCashMovement();
});

(async () => {
    try {
        await rLoadStores();
        rSetPeriod("today");
        await rLoadSummary();
    } catch (error) {
        const message = error?.message || "Failed to initialize reports.";
        rRenderTableStates("error", message);
        rSetResult(message, "error");
    }
})();
