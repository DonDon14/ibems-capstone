(() => {
    let active = null;
    const el = id => document.getElementById(id);
    const numeric = value => (typeof value === "number" || typeof value === "string" && value.trim() !== "") && Number.isFinite(Number(value));
    const id = value => numeric(value) && Number.isSafeInteger(Number(value)) && Number(value) > 0;
    const amount = value => numeric(value) && Number(value) >= 0;
    const cents = value => Math.round(Number(value) * 100);
    const visible = modal => modal?.isConnected && getComputedStyle(modal).display !== "none";
    const focus = host => { host.focus({preventScroll:true}); host.scrollIntoView({block:"nearest",inline:"nearest"}); };
    function feedback(message = "", type = "pending", owned = false) {
        const host = el("investigation-message");
        host.textContent = message;
        host.className = `investigation-action-feedback is-${type}`;
        host.setAttribute("role", type === "error" ? "alert" : "status");
        host.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
        if (message && owned) focus(host);
    }
    function busy() {
        if (active && !active.current()) active.release();
        return !!active?.locked;
    }
    function begin(button) {
        const modal = el("debt-investigations-modal"), signal = window.IbemsPortalNavigation?.currentSignal;
        if (busy() || !visible(modal) || signal?.aborted || !button?.isConnected || button.disabled || !button.getClientRects().length) return null;
        if (window.IbemsInvestigationReads?.busy) {
            feedback("Wait for investigation records and employee transactions to finish loading before submitting.","warning",modal.contains(document.activeElement));
            return null;
        }
        const previous = document.activeElement, owned = modal.contains(previous) || previous === document.body;
        const controls = Array.from(modal.querySelectorAll("button, input, select, textarea"));
        const original = controls.map(control => [control,control.disabled]);
        const label = button.innerHTML, oldBusy = modal.getAttribute("aria-busy");
        const context = {
            signal, modal, locked:true, consumed:false,
            current:() => active === context && visible(modal) && !signal?.aborted && signal === window.IbemsPortalNavigation?.currentSignal,
            ownsFocus:() => owned && (modal.contains(document.activeElement) || document.activeElement === document.body),
            announce(message,type) { if (context.current()) feedback(message,type,context.ownsFocus()); },
            release() {
                if (!context.locked) return;
                context.locked = false;
                original.forEach(([control,disabled]) => { control.disabled = disabled; control.dispatchEvent(new Event("ibems:control-sync")); });
                button.innerHTML = label;
                if (context.consumed) button.disabled = true;
                if (oldBusy === null) modal.removeAttribute("aria-busy"); else modal.setAttribute("aria-busy",oldBusy);
            },
            label(text) { if(context.current()) button.textContent = text; },
            cancel() { if (!context.current()) return; const ownedNow=context.ownsFocus(); feedback(); context.release(); if(ownedNow && previous?.isConnected && !previous.disabled) previous.focus(); },
            async finish(message,approval) {
                if (!context.current()) return;
                // A confirmed card action cannot be repeated from the stale card if its refresh fails.
                context.consumed = button.id !== "investigation-open";
                context.announce(message,"success");
                context.release();
                const outcomes = await Promise.allSettled([
                    Promise.resolve().then(() => loadInvestigations()),
                    ...(approval ? [Promise.resolve().then(() => loadData())] : []),
                ]);
                if (!context.current()) return;
                context.announce(outcomes.some(result => result.status !== "fulfilled" || result.value !== true)
                    ? `${message} Latest records could not be refreshed. Use Refresh investigations${approval ? " and Accounting Refresh" : ""} to read the latest records before another action.` : message,
                    outcomes.some(result => result.status !== "fulfilled" || result.value !== true) ? "warning" : "success");
            },
        };
        active = context;
        controls.forEach(control => { control.disabled = true; control.dispatchEvent(new Event("ibems:control-sync")); });
        modal.setAttribute("aria-busy","true");
        return context;
    }
    function card(button) {
        const row = button?.closest("[data-investigation-id]");
        if (!row || !id(row.dataset.investigationId) || !id(row.dataset.investigationUser)) return null;
        return {row,id:Number(row.dataset.investigationId),user:Number(row.dataset.investigationUser),status:row.dataset.investigationStatus,
            actor:Number(el("debt-investigations-modal").dataset.currentUser),recommendedBy:Number(row.dataset.investigationRecommender),
            action:row.dataset.investigationAction,amount:row.dataset.investigationAmount};
    }
    function confirmed(data,kind,target,packet) {
        const row = data?.investigation;
        if (data?.status !== "success" || !row || !id(row.id) || Number(row.user_id) !== target.user || kind !== "open" && Number(row.id) !== target.id) return false;
        if (kind === "open") return row.status === "open" && Number(row.transaction_id || 0) === packet.transaction_id && row.issue_type === packet.issue_type && row.summary === packet.summary;
        if (kind === "recommend") return row.status === "recommended" && Number(row.recommended_by) === target.actor && row.recommended_action === packet.recommended_action
            && amount(row.recommended_amount) && cents(row.recommended_amount) === cents(packet.recommended_action === "no_change" ? 0 : packet.recommended_amount);
        return row.status === "closed" && Number(row.approved_by) === target.actor && row.recommended_action === target.action
            && amount(row.recommended_amount) && cents(row.recommended_amount) === cents(target.amount)
            && amount(data.debt_before) && amount(data.debt_after) && cents(data.debt_before) - cents(data.debt_after) === cents(target.amount);
    }
    async function post(context,url,packet,kind,target) {
        try {
            const response = await fetch(url,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(packet),signal:context.signal});
            const data = await response.json();
            if (!context.current()) return null;
            if (data?.status === "error") {
                context.announce(typeof data.message === "string" && data.message.trim() ? data.message : "The investigation action was rejected. Review the details before trying again.","error");
                try { window.IbemsFeedbackSound?.play("error"); } catch (_) {}
                return null;
            }
            if (!response.ok || !confirmed(data,kind,target,packet)) throw Error("Confirmation unavailable");
            try { window.IbemsFeedbackSound?.play("success"); } catch (_) {}
            return data;
        } catch (_) {
            if (context.current()) context.announce("The investigation action could not be confirmed. Refresh investigations and check the case before trying again.","warning");
            return null;
        }
    }
    async function open() {
        if (busy()) return;
        if (!visible(el("debt-investigations-modal")) || el("investigation-open").disabled || !el("investigation-open").getClientRects().length) return;
        if (el("investigation-transaction").disabled) {
            feedback("Employee transactions are unavailable or still loading. Use Retry employee transactions before opening an investigation.","warning",true);
            return;
        }
        const packet = {user_id:Number(el("investigation-user").value || 0),transaction_id:Number(el("investigation-transaction").value || 0),
            issue_type:el("investigation-issue").value,summary:el("investigation-summary").value.trim(),evidence_summary:el("investigation-evidence").value.trim()};
        if (!id(packet.user_id) || !Number.isSafeInteger(packet.transaction_id) || packet.transaction_id < 0
            || !["incorrect_amount","unauthorized_purchase","duplicate_charge","wrong_employee","other"].includes(packet.issue_type) || Array.from(packet.summary).length < 10) {
            feedback("Select an employee, a valid issue type, and enter a summary of at least 10 characters.","error",true); return;
        }
        const context = begin(el("investigation-open")); if (!context) return;
        context.label("Opening…"); context.announce("Opening debt investigation…","pending");
        try {
            const data = await post(context,"/accounting/debt-investigations",packet,"open",{user:packet.user_id});
            if (!data) return;
            el("investigation-summary").value = ""; el("investigation-evidence").value = "";
            await context.finish(`Investigation #${Number(data.investigation.id)} opened. Financial records remain unchanged.`,false);
        } finally { context.release(); }
    }
    async function recommend(button) {
        if (busy()) return;
        const target = card(button); if (!target || !["open","investigating"].includes(target.status)) return;
        const value = name => target.row.querySelector(`.investigation-${name}`).value;
        const packet = {findings:value("findings").trim(),evidence_summary:value("result-evidence").trim(),recommended_action:value("action"),recommended_amount:Number(value("amount") || 0)};
        if (Array.from(packet.findings).length < 10 || !packet.evidence_summary || !["no_change","partial_reversal","full_reversal"].includes(packet.recommended_action)
            || !Number.isFinite(packet.recommended_amount) || packet.recommended_action !== "no_change" && packet.recommended_amount <= 0) {
            feedback("Enter findings of at least 10 characters, evidence reviewed, and a valid action with a positive reversal amount when applicable.","error",true); return;
        }
        const context = begin(button); if (!context) return;
        context.label("Submitting…"); context.announce(`Submitting recommendation for investigation #${target.id}…`,"pending");
        try {
            const data = await post(context,`/accounting/debt-investigations/${target.id}/recommend`,packet,"recommend",target);
            if (data) await context.finish(`Investigation #${target.id}: recommendation submitted for independent Accounting approval.`,false);
        } finally { context.release(); }
    }
    async function approve(button) {
        if (busy()) return;
        const target = card(button), modal = el("debt-investigations-modal");
        if (!target || target.status !== "recommended" || !["ACCOUNTING_OFFICE", "ACCOUNTING_HEAD"].includes(modal.dataset.currentRole) || !id(target.actor)
            || !id(target.recommendedBy) || target.recommendedBy === target.actor || !amount(target.amount)
            || !["no_change","partial_reversal","full_reversal"].includes(target.action) || (target.action === "no_change" ? cents(target.amount) !== 0 : Number(target.amount) <= 0)) return;
        const context = begin(button); if (!context) return;
        context.label("Reviewing…"); context.announce(`Review approval for investigation #${target.id}.`,"pending");
        try {
            let accepted;
            try { accepted = await window.IbemsDialog.confirm(target.action === "no_change"
                ? `Approve investigation #${target.id} and close it with no financial change?`
                : `Approve investigation #${target.id} and post the ${investigationLabel(target.action).toLowerCase()} of ${aMoney(target.amount)}? This changes the employee's debt.`,
                {title:"Approve debt investigation?",confirmLabel:target.action === "no_change" ? "Approve and close" : "Approve and post correction"}); }
            catch (_) { context.announce("The approval review could not be opened. Try the approval again.","warning"); return; }
            if (!context.current()) return;
            if (!accepted) { context.cancel(); return; }
            context.label("Approving…"); context.announce(`Approving investigation #${target.id}…`,"pending");
            const data = await post(context,`/accounting/debt-investigations/${target.id}/approve`,{},"approve",target);
            if (data) await context.finish(target.action === "no_change" ? `Investigation #${target.id} approved and closed. Employee debt is unchanged at ${aMoney(data.debt_after)}.`
                : `Investigation #${target.id}: correction posted. Employee debt changed from ${aMoney(data.debt_before)} to ${aMoney(data.debt_after)}.`,true);
        } finally { context.release(); }
    }
    window.IbemsInvestigationActions = {get busy(){return busy();},open,recommend,approve,clear:()=>{
        if (busy()) return;
        active = null;
        feedback();
    }};
})();
