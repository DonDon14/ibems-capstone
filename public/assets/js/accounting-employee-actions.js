(() => {
    let busy = false;
    let version = 0;
    const result = () => document.getElementById("employee-action-feedback");
    function feedback(message = "", type = "pending", focus = false) {
        const host = result();
        if (!host) return;
        host.textContent = message;
        host.className = `employee-action-feedback is-${type}`;
        host.setAttribute("role", type === "error" ? "alert" : "status");
        host.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
        if (focus) { host.focus({preventScroll: true}); host.scrollIntoView({block: "nearest", inline: "nearest"}); }
    }
    function begin(button) {
        const modal = document.getElementById("employee-modal");
        const signal = window.IbemsPortalNavigation?.currentSignal;
        const userId = Number(employeeModalUserId);
        if (busy || signal?.aborted || !modal?.isConnected || !button?.isConnected || button.disabled || !button.getClientRects().length
            || getComputedStyle(modal).display === "none" || !employeeModalProfile || !Number.isSafeInteger(userId) || userId < 1) return null;
        const request = ++version;
        let modalRequest = employeeModalRequest;
        const current = () => request === version && !signal?.aborted && modal.isConnected && getComputedStyle(modal).display !== "none"
            && Number(employeeModalUserId) === userId && employeeModalRequest === modalRequest;
        const previous = document.activeElement;
        const owned = modal.contains(previous) || previous === document.body;
        const ownsFocus = () => owned && (modal.contains(document.activeElement) || document.activeElement === document.body);
        const controls = Array.from(modal.querySelectorAll("button, input, select, textarea"));
        const original = controls.map(control => [control, control.disabled]);
        const label = button.innerHTML;
        const oldBusy = modal.getAttribute("aria-busy");
        let released = false;
        busy = true;
        controls.forEach(control => { control.disabled = true; });
        modal.setAttribute("aria-busy", "true");
        button.textContent = "Reviewing…";
        feedback("Review the salary change before confirming.", "pending", ownsFocus());
        const release = () => {
            if (released || !current()) return;
            released = true; busy = false;
            original.forEach(([control, disabled]) => { control.disabled = disabled; });
            button.innerHTML = label;
            if (oldBusy === null) modal.removeAttribute("aria-busy"); else modal.setAttribute("aria-busy", oldBusy);
        };
        return {userId, signal, current, ownsFocus, release,
            label(text) { if (current()) button.textContent = text; },
            announce(message,type) { if (current()) feedback(message,type,ownsFocus()); },
            cancel() { if (!current()) return; const ownedNow=ownsFocus(); feedback(); release(); if(ownedNow && previous?.isConnected && !previous.disabled) previous.focus(); },
            async finish(message) {
                if (!current()) return;
                const ownedNow = ownsFocus();
                feedback(message, "success", ownedNow);
                release();
                // This action owns the following employee read, but later openings/saves still supersede it.
                modalRequest = employeeModalRequest + 1;
                const outcomes = await Promise.allSettled([
                    Promise.resolve().then(() => openEmployeeModal(userId, {preserveFeedback: true, focus: ownedNow})), Promise.resolve().then(() => loadData()),
                ]);
                if (!current()) return;
                if (outcomes.some(outcome => outcome.status !== "fulfilled" || outcome.value !== true)) {
                    feedback(`${message} Employee details or Accounting totals could not be refreshed. Close and reopen this employee or use Refresh to read the latest records.`, "warning", ownsFocus());
                } else feedback(message, "success", ownsFocus());
            }
        };
    }
    const amount = value => (typeof value === "number" || typeof value === "string" && value.trim() !== "") && Number.isFinite(Number(value)) && Number(value) >= 0;
    async function review(action,message,options) {
        try { return await window.IbemsDialog.confirm(message,options); }
        catch (_) { action.announce("The salary confirmation could not be opened. Try the action again.","warning"); return null; }
    }
    function confirmation(data, activation) {
        if (data?.status === "success") return activation ? amount(data.monthly_salary) && amount(data.credit_limit) : amount(data.new_base_salary) && amount(data.new_credit_limit);
        return !activation && data?.status === "scheduled" && /^\d{4}-\d{2}-\d{2}$/.test(data.effective_date || "") && Number.isSafeInteger(Number(data.revision)) && Number(data.revision) > 0;
    }
    async function post(action,url,options,activation=false) {
        if (!action.current()) return null;
        try {
            const response = await fetch(url,{...options,signal:action.signal});
            const data = await response.json();
            if (!action.current()) return null;
            if (data?.status === "error") {
                action.announce(typeof data.message === "string" && data.message.trim() ? data.message : "The salary change was rejected. Review the details before trying again.","error");
                try { window.IbemsFeedbackSound?.play("error"); } catch (_) {}
                return null;
            }
            if (!response.ok || !confirmation(data,activation)) throw new Error("Confirmation unavailable");
            try { window.IbemsFeedbackSound?.play("success"); } catch (_) {}
            return data;
        } catch (_) {
            action.announce("The salary change could not be confirmed. Check the employee profile and pending changes before trying again.","warning");
            return null;
        }
    }
    async function save() {
        const button = document.getElementById("employee-limit-save");
        if (busy || !button?.getClientRects().length || button.disabled) return;
        const value = id => document.getElementById(id).value;
        const packet = {
            user_id:Number(employeeModalUserId), employment_type:value("employee-employment-type"),
            salary_schedule_id:Number(value("employee-salary-schedule")), salary_grade:value("employee-salary-grade").trim(), salary_step:value("employee-salary-step").trim(),
            salary_effective_date:value("employee-salary-effective-date"),monthly_salary:value("employee-salary-value").trim(),credit_percentage:Number(value("employee-credit-percentage")),reason:value("employee-limit-reason").trim(),
        };
        if (!packet.user_id || !packet.salary_schedule_id || !packet.salary_grade || !packet.salary_step || !packet.salary_effective_date || !packet.reason
            || !Number.isFinite(packet.credit_percentage) || packet.credit_percentage < 0 || packet.credit_percentage > 100 || !/^[1-9][0-9]{0,8}(?:\.[0-9]{1,2})?$/.test(packet.monthly_salary)) {
            feedback("Schedule, grade, step, valid monthly salary, effective date, credit percentage, and reason are required.","error",true);
            return;
        }
        const credit = value("employee-limit-value");
        const today = new Intl.DateTimeFormat("en-CA",{timeZone:"Asia/Manila",year:"numeric",month:"2-digit",day:"2-digit"}).format(new Date());
        const future = packet.salary_effective_date > today;
        const action = begin(button);
        if (!action) return;
        try {
            const approved = await review(action,future
                ? `Schedule this salary profile for ${packet.salary_effective_date}? Current salary and credit remain unchanged until activation. The future credit limit will be ${credit} (${packet.credit_percentage}%).`
                : `Save this salary profile? The credit limit will be ${credit} (${packet.credit_percentage}%).`, {
                title:future ? "Schedule salary profile?" : "Update salary-grade profile?", confirmLabel:future ? "Schedule profile" : "Save profile",
            });
            if (!action.current()) return;
            if (approved === null) return;
            if (!approved) { action.cancel(); return; }
            action.label(future ? "Scheduling…" : "Saving…");
            action.announce(future ? "Scheduling salary profile…" : "Saving financial profile…","pending");
            const data = await post(action,"/accounting/debts/financial-profile",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(packet)});
            if (!data) return;
            const message = data.status === "scheduled"
                ? `Salary change scheduled for ${data.effective_date} (revision ${data.revision}). Current salary and credit remain unchanged.`
                : `${data.financial_profile_created ? "Financial profile created" : "Financial profile updated"}. Salary: ${aMoney(data.new_base_salary)} | Credit: ${aMoney(data.new_credit_limit)}`;
            await action.finish(message);
        } finally { action.release(); }
    }
    async function activate(button) {
        const profileId = Number(button?.dataset.activateSalaryId || 0);
        if (!Number.isSafeInteger(profileId) || profileId < 1) return;
        const action = begin(button);
        if (!action) return;
        try {
            const approved = await review(action,"Activate this due salary profile now? This will change the employee's current salary and credit limit, while preserving existing debt.",{
                title:"Activate scheduled salary?",confirmLabel:"Activate profile",
            });
            if (!action.current()) return;
            if (approved === null) return;
            if (!approved) { action.cancel(); return; }
            action.label("Activating…"); action.announce("Activating scheduled salary profile…","pending");
            const data = await post(action,`/accounting/debts/scheduled-salary/${profileId}/activate`,{method:"POST"},true);
            if (data) await action.finish(`Salary profile activated. Current salary: ${aMoney(data.monthly_salary)} · Credit: ${aMoney(data.credit_limit)}.`);
        } finally { action.release(); }
    }
    window.IbemsEmployeeActions = {get busy(){return busy;},clear:()=>feedback(),save,activate};
})();
