(() => {
    const forms = Array.from(document.querySelectorAll('[data-deduction-workflow-form]'));
    if (!forms.length) return;
    let pending = false;
    let savedButtons = [];

    for (const form of forms) {
        const button = form.querySelector('button[type="submit"]');
        if (!button) continue;
        const status = document.createElement('p');
        status.className = 'deduction-feedback';
        status.hidden = true;
        status.tabIndex = -1;
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('aria-atomic', 'true');
        status.setAttribute('role', 'status');
        button.before(status);

        function showStatus(message, error = false) {
            status.textContent = message;
            status.className = `deduction-feedback ${error ? 'is-error' : 'is-progress'}`;
            status.hidden = false;
            status.setAttribute('role', error ? 'alert' : 'status');
            status.setAttribute('aria-live', error ? 'assertive' : 'polite');
            if (error) status.focus();
        }

        if (form.dataset.deductionWorkflowForm === 'result') {
            const amount = form.querySelector('[name="confirmed_amount"]');
            const reason = form.querySelector('[name="reason_code"]');
            const syncReason = () => {
                reason.required = Number(amount.value) === 0 || Number(amount.value) < Number(amount.max);
            };
            amount.addEventListener('input', syncReason);
            amount.addEventListener('change', syncReason);
            syncReason();
        }

        form.addEventListener('submit', event => {
            if (event.defaultPrevented) return;
            if (pending) { event.preventDefault(); return; }
            if (form.dataset.deductionWorkflowForm === 'period') {
                const start = form.querySelector('[name="date_start"]').value;
                const end = form.querySelector('[name="date_end"]').value;
                const expected = form.querySelector('[name="expected_processing_date"]').value;
                const message = start && end && end < start ? 'End date cannot be before start date.'
                    : start && expected && expected < start ? 'Expected pay date cannot be before the period starts.' : '';
                if (message) { event.preventDefault(); showStatus(message, true); return; }
            }
            if (form.dataset.deductionWorkflowForm === 'prepare'
                && !form.querySelector('input[name$="[selected]"]:checked')) {
                event.preventDefault();
                showStatus('Select at least one employee before preparing or saving this batch.', true);
                return;
            }
            pending = true;
            savedButtons = forms.flatMap(candidate => Array.from(candidate.querySelectorAll('button[type="submit"]')))
                .map(button => ({ button, disabled: button.disabled, label: button.textContent }));
            savedButtons.forEach(({ button }) => { button.disabled = true; });
            form.setAttribute('aria-busy', 'true');
            button.textContent = 'Processing…';
            showStatus('Processing this deduction action. Wait for the updated workspace before submitting another action.');
            status.focus();
            // Keep named inputs enabled: native POST serializes them after the submit event.
        });
    }

    function restore() {
        savedButtons.forEach(({ button, disabled, label }) => { button.disabled = disabled; button.textContent = label; });
        savedButtons = [];
        pending = false;
        forms.forEach(form => {
            form.setAttribute('aria-busy', 'false');
            form.querySelectorAll('.deduction-feedback.is-progress').forEach(status => { status.hidden = true; status.textContent = ''; });
        });
    }
    window.addEventListener('pageshow', restore);
    window.IbemsPortalNavigation?.onCleanup(() => window.removeEventListener('pageshow', restore));
})();
