(() => {
    let queueVersion = 0, transactionVersion = 0;
    let queueLoading = false, transactionLoading = false;
    let queueSignal, transactionSignal, transactionUser = 0;
    let openBaseline = null, selectedTransaction = "";
    const drafts = new Map();
    const fields = ["investigation-findings", "investigation-result-evidence", "investigation-action", "investigation-amount"];
    const el = id => document.getElementById(id);
    const numeric = value => (typeof value === "number" || typeof value === "string" && value.trim() !== "") && Number.isFinite(Number(value));
    const id = value => numeric(value) && Number.isSafeInteger(Number(value)) && Number(value) > 0;
    const date = value => typeof value === "string" && value.trim() && Number.isFinite(Date.parse(value));
    const active = (modal, signal) => modal?.isConnected && getComputedStyle(modal).display !== "none" && !signal?.aborted && signal === window.IbemsPortalNavigation?.currentSignal;
    function feedback(host, message = "", type = "pending") {
        host.textContent = message;
        host.className = `investigation-read-feedback is-${type}`;
        host.setAttribute("role", type === "error" ? "alert" : "status");
        host.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
    }
    function focus(element) {
        element.focus({preventScroll:true});
        element.scrollIntoView({block:"nearest",inline:"nearest"});
    }
    function preserveDrafts(container) {
        container.querySelectorAll("[data-investigation-id]").forEach(row => {
            const values = fields.map(name => row.querySelector(`.${name}`)?.value);
            if (values.some(value => value !== undefined)) drafts.set(row.dataset.investigationId, {revision:row.dataset.investigationRevision,values});
        });
    }
    function restoreDrafts(container) {
        container.querySelectorAll("[data-investigation-id]").forEach(row => {
            const draft = drafts.get(row.dataset.investigationId);
            if (!draft || draft.revision !== row.dataset.investigationRevision) return;
            fields.forEach((name,index) => {
                const field = row.querySelector(`.${name}`);
                if (field && draft.values[index] !== undefined) {
                    field.value = draft.values[index];
                    field.dispatchEvent(new Event("ibems:control-sync"));
                }
            });
        });
    }
    function validCases(rows) {
        const seen = new Set();
        return Array.isArray(rows) && rows.every(row => {
            if (!row || typeof row !== "object" || Array.isArray(row) || !id(row.id) || seen.has(Number(row.id)) || !id(row.user_id)
                || !["open","investigating","recommended","closed"].includes(row.status) || typeof row.name !== "string" || !row.name.trim()
                || typeof row.summary !== "string" || !row.summary.trim() || !["incorrect_amount","unauthorized_purchase","duplicate_charge","wrong_employee","other"].includes(row.issue_type)
                || !date(row.updated_at)) return false;
            seen.add(Number(row.id));
            return !["recommended","closed"].includes(row.status) || id(row.recommended_by)
                && ["no_change","partial_reversal","full_reversal"].includes(row.recommended_action)
                && numeric(row.recommended_amount) && Number(row.recommended_amount) >= 0;
        });
    }
    async function queue({manual = false} = {}) {
        const signal = window.IbemsPortalNavigation?.currentSignal;
        const modal = el("debt-investigations-modal");
        if (!active(modal,signal) || window.IbemsInvestigationActions?.busy || manual && queueLoading && queueSignal === signal) return false;
        queueSignal = signal;
        const request = ++queueVersion;
        const current = () => request === queueVersion && active(modal,signal);
        const records = el("investigation-list"), message = el("investigation-queue-feedback"), retry = el("investigation-queue-retry"), refresh = el("investigation-refresh");
        const previous = document.activeElement;
        const owned = records.contains(previous) || previous === retry || previous === refresh || previous === message;
        const ownsFocus = () => owned && (document.activeElement === message || document.activeElement === document.body);
        preserveDrafts(records);
        queueLoading = true;
        refresh.disabled = true; refresh.textContent = "Refreshing investigations…";
        retry.hidden = true; retry.disabled = true;
        records.innerHTML = ""; records.tabIndex = -1; records.setAttribute("aria-busy","true");
        feedback(message,"Loading investigations…");
        if (owned) focus(message);
        try {
            const response = await fetch("/accounting/debt-investigations",{signal,cache:"no-store"});
            const data = await response.json();
            if (!current()) return false;
            if (!response.ok || data?.status !== "success" || !validCases(data.investigations)) throw Error("Investigation records unavailable");
            refresh.disabled = false;
            if (data.investigations.length) {
                renderInvestigations(data.investigations);
                restoreDrafts(records);
                const present = new Set(data.investigations.map(row=>String(row.id)));
                for (const key of drafts.keys()) if (!present.has(key)) drafts.delete(key);
                records.tabIndex = 0; feedback(message);
                if (ownsFocus()) focus(previous === refresh ? refresh : records);
            } else {
                drafts.clear(); feedback(message,"No debt investigations have been opened.","neutral");
                if (ownsFocus()) focus(previous === refresh ? refresh : message);
            }
            return true;
        } catch (_) {
            if (!current()) return false;
            feedback(message,"Investigation records could not be loaded. Retry investigations to check again.","error");
            retry.hidden = false; retry.disabled = false;
            if (ownsFocus()) focus(retry);
            return false;
        } finally {
            if (current()) {
                queueLoading = false; refresh.disabled = false; refresh.textContent = "Refresh investigations";
                retry.disabled = false; records.setAttribute("aria-busy","false");
            }
        }
    }
    function releaseOpen() {
        if (openBaseline) { openBaseline[0].disabled = openBaseline[1]; openBaseline = null; }
    }
    async function transactions(userId,{manual = false} = {}) {
        const signal = window.IbemsPortalNavigation?.currentSignal;
        const modal = el("debt-investigations-modal");
        userId = Number(userId);
        if (!active(modal,signal) || window.IbemsInvestigationActions?.busy || manual && transactionLoading && transactionSignal === signal && userId === transactionUser) return false;
        if (transactionSignal !== signal) releaseOpen();
        const request = ++transactionVersion;
        const current = () => request === transactionVersion && active(modal,signal) && Number(el("investigation-user").value || 0) === userId;
        const select = el("investigation-transaction"), message = el("investigation-transaction-feedback"), retry = el("investigation-transaction-retry"), open = el("investigation-open");
        const trigger = select.closest(".ui-select")?.querySelector(".ui-select-trigger") || select;
        const previous = document.activeElement;
        const owned = previous === retry || previous === message || previous === select || previous === trigger;
        const ownsFocus = () => owned && (document.activeElement === message || document.activeElement === document.body);
        if (userId === transactionUser && !select.disabled) selectedTransaction = select.value;
        else if (userId !== transactionUser) selectedTransaction = "";
        transactionUser = userId; transactionSignal = signal;
        if (!id(userId)) {
            transactionLoading = false; selectedTransaction = "";
            select.innerHTML = '<option value="">General balance investigation</option>'; select.disabled = false;
            select.removeAttribute("aria-busy"); releaseOpen(); retry.hidden = true; retry.disabled = false; feedback(message);
            select.dispatchEvent(new Event("ibems:control-sync"));
            return true;
        }
        transactionLoading = true;
        if (!openBaseline) openBaseline = [open,open.disabled];
        open.disabled = true; select.disabled = true;
        select.innerHTML = '<option value="">Loading transactions…</option>'; select.setAttribute("aria-busy","true");
        retry.hidden = true; retry.disabled = true;
        feedback(message,"Loading employee debt transactions…");
        if (owned) focus(message);
        try {
            const response = await fetch(`/accounting/debt-investigations?user_id=${encodeURIComponent(userId)}`,{signal,cache:"no-store"});
            const data = await response.json();
            if (!current()) return false;
            const seen = new Set();
            if (!response.ok || data?.status !== "success" || !Array.isArray(data.transactions) || data.transactions.some(row => {
                if (!row || typeof row !== "object" || Array.isArray(row) || !id(row.id) || seen.has(Number(row.id))
                    || typeof row.client_txn_id !== "string" || !row.client_txn_id.trim() || !numeric(row.amount) || Number(row.amount) < 0 || !date(row.created_at)) return true;
                seen.add(Number(row.id)); return false;
            })) throw Error("Employee transactions unavailable");
            select.innerHTML = '<option value="">General balance investigation</option>' + data.transactions.map(row =>
                `<option value="${Number(row.id)}">${aEscape(row.client_txn_id)} · ${aEscape(aMoney(row.amount))} · ${aEscape(aDateTime(row.created_at))}</option>`).join("");
            const retained = !selectedTransaction || data.transactions.some(row=>String(row.id)===selectedTransaction);
            select.value = retained ? selectedTransaction : "";
            selectedTransaction = select.value;
            select.disabled = false; releaseOpen();
            feedback(message,!retained ? "The previously selected transaction is no longer available. Review the transaction selection." : data.transactions.length ? "" : "No debt transactions found. You can investigate the general balance.",!retained ? "warning" : "neutral");
            select.dispatchEvent(new Event("ibems:control-sync"));
            if (ownsFocus()) focus(trigger);
            return true;
        } catch (_) {
            if (!current()) return false;
            select.innerHTML = '<option value="">Transactions unavailable</option>';
            feedback(message,"Employee debt transactions could not be loaded. Retry employee transactions to check again.","error");
            retry.hidden = false; retry.disabled = false;
            if (ownsFocus()) focus(retry);
            return false;
        } finally {
            if (current()) { transactionLoading = false; select.setAttribute("aria-busy","false"); retry.disabled = false; }
        }
    }
    function reset() {
        ++queueVersion; ++transactionVersion; queueLoading = false; transactionLoading = false;
        transactionUser = 0; selectedTransaction = ""; drafts.clear(); releaseOpen();
        el("investigation-refresh").disabled = false; el("investigation-refresh").textContent = "Refresh investigations";
        ["queue","transaction"].forEach(name => { el(`investigation-${name}-retry`).hidden = true; feedback(el(`investigation-${name}-feedback`)); });
    }
    window.IbemsInvestigationReads = {queue,transactions,reset,get busy(){
        const modal = el("debt-investigations-modal"), signal = window.IbemsPortalNavigation?.currentSignal;
        return active(modal,signal) && (queueLoading && queueSignal === signal || transactionLoading && transactionSignal === signal);
    }};
})();
