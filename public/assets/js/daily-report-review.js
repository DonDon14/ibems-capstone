(function () {
    const forms = Array.from(document.querySelectorAll('.daily-report-action'));
    const signal = window.IbemsPortalNavigation?.currentSignal;
    const statuses = new Map();
    let pending = null;

    function showStatus(form, message, error = false) {
        const status = statuses.get(form);
        status.textContent = message;
        status.hidden = false;
        status.classList.toggle('is-error', error);
        status.setAttribute('role', error ? 'alert' : 'status');
        status.setAttribute('aria-live', error ? 'assertive' : 'polite');
        return status;
    }

    function restore(returnFocus = false) {
        if (!pending) return;
        pending.buttons.forEach(({button, ariaDisabled}) => {
            if (ariaDisabled === null) button.removeAttribute('aria-disabled');
            else button.setAttribute('aria-disabled', ariaDisabled);
        });
        pending.notes.forEach(({note, readOnly}) => {note.readOnly = readOnly;});
        pending.form.setAttribute('aria-busy', 'false');
        if (returnFocus && document.activeElement === pending.status && pending.submitter?.isConnected) pending.submitter.focus();
        pending.status.hidden = true;
        pending.status.textContent = '';
        pending = null;
    }

    function startSubmission(form, submitter) {
        if (pending) {pending.status.focus(); return;}
        const decision = submitter?.value;
        const message = decision === 'return' ? 'Sending this report back for revision. Wait for the updated reports page.'
            : decision === 'approve' ? 'Sending this report review. Wait for the updated reports page.'
            : form.dataset.reportAction === 'receive' ? 'Recording report receipt. Wait for the updated reports page.'
            : 'Submitting this report snapshot. Wait for the updated reports page.';
        const status = showStatus(form, message);
        status.focus();
        pending = {
            form, status, submitter,
            buttons: forms.flatMap(candidate => Array.from(candidate.querySelectorAll('button[type="submit"]')))
                .map(button => ({button, ariaDisabled: button.getAttribute('aria-disabled')})),
            notes: forms.flatMap(candidate => Array.from(candidate.querySelectorAll('textarea')))
                .map(note => ({note, readOnly: note.readOnly})),
        };
        // Readonly notes and aria-disabled buttons remain successful native POST controls.
        pending.buttons.forEach(({button}) => button.setAttribute('aria-disabled', 'true'));
        pending.notes.forEach(({note}) => {note.readOnly = true;});
        form.setAttribute('aria-busy', 'true');
    }

    forms.forEach(form => {
        const note = form.querySelector('textarea[name="note"]');
        const status = document.createElement('p');
        status.className = 'daily-report-action-result';
        status.hidden = true;
        status.tabIndex = -1;
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('aria-atomic', 'true');
        form.appendChild(status);
        statuses.set(form, status);
        note?.addEventListener('input', () => {
            note.setCustomValidity('');
            if (status.classList.contains('is-error')) {status.hidden = true; status.textContent = '';}
        });
        form.querySelectorAll('button[type="submit"]').forEach(button => {
            button.addEventListener('click', event => {
                if (pending) {event.preventDefault(); pending.status.focus(); return;}
                note?.setCustomValidity('');
            });
        });
        form.addEventListener('submit', event => {
            if (event.defaultPrevented) return;
            if (signal?.aborted) {event.preventDefault(); return;}
            if (pending) {event.preventDefault(); pending.status.focus(); return;}
            const returning = event.submitter?.value === 'return';
            const message = returning && note && !note.value.trim() ? 'Enter a reason before returning this report.' : '';
            note?.setCustomValidity(message);
            if (note && !note.checkValidity()) {
                event.preventDefault();
                showStatus(form, message || note.validationMessage || 'Check the review note before submitting.', true);
                note.reportValidity();
                note.focus();
                return;
            }
            startSubmission(form, event.submitter);
            const submission = pending;
            // A later listener can cancel native submission; wait for event dispatch to finish.
            setTimeout(() => {if (pending === submission && event.defaultPrevented) restore(true);}, 0);
        });
    });

    const flash = document.querySelector('.daily-report-feedback.is-error') || document.querySelector('.daily-report-feedback.is-success');
    const focusFeedback = () => window.requestAnimationFrame(() => {
        if (signal?.aborted || !flash?.isConnected) return;
        const active = document.activeElement;
        if (active === document.body || active?.matches('[data-portal-page-content]')) flash.focus();
    });
    if (flash) {
        focusFeedback();
        document.addEventListener('ibems:portal-page-loaded', focusFeedback);
    }
    const restoreHistory = () => restore(true);
    window.addEventListener('pageshow', restoreHistory);
    window.IbemsPortalNavigation?.onCleanup(() => {
        restore();
        window.removeEventListener('pageshow', restoreHistory);
        document.removeEventListener('ibems:portal-page-loaded', focusFeedback);
    });
})();
