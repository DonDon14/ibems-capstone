(function () {
    const forms = Array.from(document.querySelectorAll('.auth-role-form'));
    const status = document.getElementById('status');
    if (!forms.length || !status || typeof fetch !== 'function' || typeof FormData !== 'function') return;
    const buttons = forms.map(form => form.querySelector('button[type="submit"][name="role"]')).filter(Boolean);
    let pending = false;
    let version = 0;
    let savedControls = [];

    function setStatus(message = '', error = false) {
        status.textContent = message;
        status.className = message ? (error ? 'error' : 'loading') : '';
        status.setAttribute('role', error ? 'alert' : 'status');
        status.setAttribute('aria-live', error ? 'assertive' : 'polite');
        status.setAttribute('aria-atomic', 'true');
        if (error) status.focus();
    }

    function restoreControls() {
        savedControls.forEach(({ button, disabled }) => {
            button.disabled = disabled;
            button.removeAttribute('aria-busy');
            button.classList.remove('is-active');
        });
        savedControls = [];
        document.getElementById('role-options')?.setAttribute('aria-busy', 'false');
        pending = false;
    }

    forms.forEach(form => form.addEventListener('submit', async event => {
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (pending) return;
        const button = event.submitter || form.querySelector('button[type="submit"][name="role"]');
        if (!button || button.form !== form || button.disabled || !button.value) return;
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.role = button.value;
        const label = button.querySelector('strong')?.textContent.trim() || 'selected portal';
        const requestVersion = ++version;
        let navigating = false;
        pending = true;
        savedControls = buttons.map(button => ({ button, disabled: button.disabled }));
        buttons.forEach(button => { button.disabled = true; });
        button.classList.add('is-active');
        button.setAttribute('aria-busy', 'true');
        document.getElementById('role-options')?.setAttribute('aria-busy', 'true');
        setStatus(`Opening ${label}…`);
        status.focus();

        try {
            let response;
            try {
                response = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(payload),
                });
            } catch (_) {
                throw new Error('Unable to confirm your portal selection. Check your connection, reload, and try again.');
            }
            let data;
            try { data = await response.json(); }
            catch (_) { throw new Error('The portal response could not be read. Reload the page and try again.'); }
            if (requestVersion !== version) return;
            if (!response.ok || data?.status !== 'success') {
                throw new Error(data?.message || 'Unable to open this portal. Reload to check your available roles.');
            }
            const unavailableDestination = 'Portal selected, but its destination is unavailable. Reload to continue.';
            if (typeof data.redirect_to !== 'string' || !data.redirect_to.trim()) throw new Error(unavailableDestination);
            let destination;
            try { destination = new URL(data.redirect_to, window.location.href); }
            catch (_) { throw new Error(unavailableDestination); }
            if (destination.origin !== window.location.origin || !['http:', 'https:'].includes(destination.protocol)) {
                throw new Error(unavailableDestination);
            }
            window.location.assign(destination.href);
            navigating = true;
        } catch (error) {
            if (requestVersion === version) setStatus(error.message || 'Unable to confirm the portal selection. Reload and try again.', true);
        } finally {
            if (requestVersion === version && !navigating) restoreControls();
        }
    }));

    window.addEventListener('pagehide', () => { version++; });
    window.addEventListener('pageshow', () => { version++; restoreControls(); setStatus(); });
}());
