let reportsStores = [];
let reportsActiveStoreId = null;
let reportsPeriod = "today";
let reportsSummaryData = null;

function rMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

function rEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function rSetResult(message, type) {
    const el = document.getElementById("reports-result");
    el.textContent = message || "";
    el.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function rSetPeriod(nextPeriod) {
    reportsPeriod = nextPeriod;
    document.querySelectorAll(".period-chip").forEach((chip) => {
        chip.classList.toggle("is-active", chip.dataset.period === reportsPeriod);
    });
    document.getElementById("reports-custom-range").classList.toggle("hidden", reportsPeriod !== "custom");
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
}

function rRenderPaymentRows(rows) {
    const body = document.getElementById("payment-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="3">No payment data for this period.</td></tr>';
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
    const openingBalance = Number(cashDrawer.opening_balance || 0);
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
    document.getElementById("cash-date").textContent = `Initial date: ${openingDate}`;
    document.getElementById("cash-on-hand").textContent = rMoney(expectedCashOnHand);
    document.getElementById("ecash-on-hand").textContent = rMoney(expectedEcashOnHand);
    document.getElementById("pay-total-revenue").textContent = rMoney(totalRevenue);
}

function rRenderCashMovements(rows) {
    const body = document.getElementById("cash-movements-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="5">No cash movement records for this range.</td></tr>';
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
        if (!data || data.status !== "success") {
            throw new Error(data?.message || "Failed to save cash movement.");
        }

        document.getElementById("cash-movement-amount").value = "";
        document.getElementById("cash-movement-reason").value = "";
        rSetResult("Cash movement saved.", "ok");
        await rLoadSummary();
    } catch (error) {
        rSetResult(error.message || "Failed to save cash movement.", "error");
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = "Save Entry";
    }
}

function rRenderProductRows(rows) {
    const body = document.getElementById("products-body");
    if (!Array.isArray(rows) || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="4">No product sales for this period.</td></tr>';
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
        body.innerHTML = '<tr><td colspan="3">No trend data for this period.</td></tr>';
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

async function rLoadStores() {
    const response = await fetch("/store/my-stores");
    const data = await response.json();
    if (!data || data.status !== "success" || !Array.isArray(data.stores) || data.stores.length === 0) {
        throw new Error("No accessible store found.");
    }

    reportsStores = data.stores;
    reportsActiveStoreId = Number(data.default_store_id || reportsStores[0].id);
}

async function rLoadSummary() {
    if (!reportsActiveStoreId) return;

    const params = new URLSearchParams({
        store_id: String(reportsActiveStoreId),
        period: reportsPeriod,
    });

    if (reportsPeriod === "custom") {
        const from = (document.getElementById("reports-date-from").value || "").trim();
        const to = (document.getElementById("reports-date-to").value || "").trim();
        if (!from || !to) {
            rSetResult("Please select date_from and date_to for custom range.", "error");
            return;
        }
        params.set("date_from", from);
        params.set("date_to", to);
    }

    const response = await fetch(`/store/reports/summary?${params.toString()}`);
    const data = await response.json();
    if (!data || data.status !== "success") {
        rSetResult(data?.message || "Unable to load report summary.", "error");
        reportsSummaryData = null;
        rRenderPaymentRows([]);
        rRenderCashMovements([]);
        rRenderProductRows([]);
        rRenderTrendRows([]);
        return;
    }

    reportsSummaryData = data;
    rRenderSummary(data);
    rRenderPaymentRows(data.payment_breakdown || []);
    rRenderPaymentRecords(data.payment_breakdown || [], data);
    rRenderCashMovements(data.cash_movements || []);
    rRenderProductRows(data.top_products || []);
    rRenderTrendRows(data.trend || []);
    rSetResult("", "ok");
}

document.querySelectorAll(".period-chip").forEach((chip) => {
    chip.addEventListener("click", async () => {
        rSetPeriod(chip.dataset.period || "today");
        await rLoadSummary();
    });
});

document.getElementById("reports-refresh-btn").addEventListener("click", async () => {
    await rLoadSummary();
});

document.getElementById("reports-date-from").addEventListener("change", async () => {
    if (reportsPeriod === "custom") await rLoadSummary();
});

document.getElementById("reports-date-to").addEventListener("change", async () => {
    if (reportsPeriod === "custom") await rLoadSummary();
});

document.getElementById("cash-movement-save").addEventListener("click", async () => {
    await rCreateCashMovement();
});

(async () => {
    try {
        await rLoadStores();
        rSetPeriod("today");
        await rLoadSummary();
    } catch (error) {
        rSetResult(error?.message || "Failed to initialize reports.", "error");
    }
})();
