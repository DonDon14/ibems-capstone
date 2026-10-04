(function (root) {
    "use strict";

    function create(storage, actorId, newKey, exclusive = (task) => task()) {
        const storageKey = `ibems.department-settlement.${actorId}`;
        let busy = false;
        function pending() {
            const value = storage.getItem(storageKey);
            if (!value) return null;
            const intent = JSON.parse(value);
            if (!intent || typeof intent.request_key !== "string" || !intent.payload) throw new Error("Stored settlement recovery is invalid. Resolve it before recording another payment.");
            return intent;
        }
        async function submit(payload, send, form) {
            if (busy) throw new Error("A settlement request is already in progress.");
            busy = true;
            try {
                return await exclusive(async () => {
                    const sharedIntent = pending();
                    if (form.resolved || (form.intent && (!sharedIntent || JSON.stringify(sharedIntent) !== JSON.stringify(form.intent)))) {
                        throw new Error("This settlement form is no longer pending. Close and reopen it before recording another payment.");
                    }
                    if (!form.intent && sharedIntent) throw new Error("Another settlement is now pending. Close and reopen the form to recover its original details.");
                    let intent = form.intent;
                    if (intent && JSON.stringify(intent.payload) !== JSON.stringify(payload)) throw new Error("Retry the pending settlement with its original details before recording another payment.");
                    if (!intent) {
                        intent = { request_key: newKey(), payload };
                        // Persist before sending; a navigation abort can occur after the server commits.
                        storage.setItem(storageKey, JSON.stringify(intent));
                        form.intent = intent;
                    }
                    try {
                        const result = await send({ ...intent.payload, request_key: intent.request_key });
                        storage.removeItem(storageKey);
                        form.resolved = true;
                        return result;
                    } catch (error) {
                        if (error.confirmedRejection) {
                            storage.removeItem(storageKey);
                            form.resolved = true;
                        }
                        throw error;
                    }
                });
            } finally { busy = false; }
        }
        function openForm() {
            const form = { intent: pending(), resolved: false };
            return {
                get pending() { return form.intent; },
                submit: (payload, send) => submit(payload, send, form),
            };
        }
        return { pending, openForm };
    }

    if (typeof module === "object" && module.exports) module.exports = { create };
    else root.IbemsDepartmentSettlementRetry = { create };
})(typeof window === "undefined" ? globalThis : window);
