function uMoney(value) {
    return `PHP ${Number(value || 0).toFixed(2)}`;
}

async function loadUserSummary() {
    const response = await fetch("/user/summary");
    const data = await response.json();
    if (!data || data.status !== "success" || !data.summary) {
        return;
    }

    const s = data.summary;
    document.getElementById("u-credit-limit").textContent = uMoney(s.credit_limit);
    document.getElementById("u-current-debt").textContent = uMoney(s.current_debt);
    document.getElementById("u-available-credit").textContent = uMoney(s.available_credit);
    document.getElementById("u-debt-added-total").textContent = uMoney(s.debt_added_total);
    document.getElementById("u-debt-deducted-total").textContent = uMoney(s.debt_deducted_total);
    document.getElementById("u-total-spent").textContent = uMoney(s.total_spent);
    document.getElementById("u-txn-count").textContent = String(s.txn_count || 0);
}

loadUserSummary();
