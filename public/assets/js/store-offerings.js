(() => {
    const root = document.getElementById('offering-app');
    if (!root) return;
    const pageSignal = window.IbemsPortalNavigation?.currentSignal;
    const isActive = () => root.isConnected && !pageSignal?.aborted;
    const el = id => document.getElementById(id);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const money = cents => (Number(cents) / 100).toFixed(2);
    let storeId = 0, state = null, busy = false, bookingKey = crypto.randomUUID();
    const cashier = root.dataset.role === 'STORE_SYSTEM';
    el('food-template-form').querySelector('[data-variant-rows]').innerHTML = window.FoodTemplateUI.variant();
    const result = (message, error = false) => {
        if (!isActive()) return;
        const target = el('offering-result');
        target.setAttribute('role', error ? 'alert' : 'status');
        target.setAttribute('aria-live', error ? 'assertive' : 'polite');
        target.setAttribute('aria-atomic', 'true');
        target.textContent = message;
        target.classList.toggle('is-error', error);
        if (message && error) {
            target.tabIndex = -1;
            target.focus();
        }
    };
    async function request(url, body) {
        const response = await fetch(url, body ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({...body, store_id:storeId})} : {signal:pageSignal});
        const data = await response.json();
        if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Request failed.');
        return data;
    }
    function components(form) {
        return Object.fromEntries(Array.from(form.querySelectorAll('[data-asset]')).filter(input => Number(input.value) > 0).map(input => [input.dataset.asset, Number(input.value)]));
    }
    function values(form) { return Object.fromEntries(new FormData(form).entries()); }
    async function action(name, body, form = null) {
        if (!isActive() || busy || !state) return;
        if (body.booking_id) body.version = Number(state.bookings.find(row => Number(row.id) === Number(body.booking_id))?.version ?? -1);
        busy = true;
        const lockedControls = Array.from(root.querySelectorAll('button, input, select, textarea')).map(control => [control, control.disabled]);
        lockedControls.forEach(([control]) => { control.disabled = true; });
        result('Saving…');
        try {
            if (name === 'template' && form) body = await window.FoodTemplateUI.payload(form, storeId);
            const data = await request('/store/offerings/' + name, body);
            if (name === 'book') bookingKey = crypto.randomUUID();
            if (form && ['template','asset','package','book'].includes(name)) form.reset();
            const rentalMessages = {
                book: 'Reservation recorded. Collect the rental fee before issuing equipment.',
                issue: 'Equipment issue recorded. Check the deposit held in the booking below.',
                return: 'Reusable equipment return recorded. Check remaining quantities before refunding the deposit.',
                refund: 'Cash deposit refund recorded.',
                cancel: 'Unpaid reservation cancelled.',
            };
            const savedMessage = rentalMessages[name] || (data.published_product_ids?.length ? 'Dish saved and published to today’s POS. Open POS to sell it.' : name === 'template' ? 'Template saved. Publish its availability below when you are ready to sell it today.' : data.transaction_id ? `Fee recorded. Receipt #${data.transaction_id}. Collect the separate deposit when issuing equipment.` : 'Saved for this store.');
            if (isActive() && ['pay', 'issue', 'refund'].includes(name)) window.IbemsFeedbackSound?.play('success');
            try {
                await load();
                result(savedMessage);
            } catch (_) {
                state = null;
                result(`${savedMessage} The updated view could not be loaded. Refresh before another operation.`);
            }
        } catch (error) {
            try { await load(); } catch (_) { state = null; }
            result(state ? error.message : `${error.message} The current view could not be refreshed. Reload before another operation.`, true);
            if (isActive() && ['pay', 'issue', 'refund'].includes(name)) window.IbemsFeedbackSound?.play('error');
        } finally {
            busy = false;
            lockedControls.forEach(([control, disabled]) => { if (control.isConnected) control.disabled = state ? disabled : true; });
        }
    }
    function render() {
        const section = new URL(window.location.href).searchParams.get('section');
        root.querySelector('h1').textContent = section === 'food' ? 'Daily food' : section === 'rental' ? 'Rentals' : 'Store operations';
        el('offering-store').hidden = !cashier;
        root.querySelector('label[for="offering-store"]').hidden = !cashier;
        el('offering-date').textContent = 'Business date: ' + state.business_date;
        el('food-pos-link').href = '/store/pos?store_id=' + storeId;
        el('food-section').hidden = section === 'rental' || !state.capabilities.includes('food_service');
        el('rental-section').hidden = section === 'food' || (!state.capabilities.includes('rental') && !state.bookings.length);
        el('rental-book-form').hidden = !state.capabilities.includes('rental');
        el('rental-configuration').hidden = cashier || !state.capabilities.includes('rental');
        el('rental-assets').innerHTML = state.assets.map(asset => `<p>${escape(asset.name)} — ${Number(asset.quantity)} total reusable pieces</p>`).join('');
        window.FoodTemplateUI.render(state, el('food-templates'));
        el('rental-components').innerHTML = state.assets.map(asset => `<label>${escape(asset.name)} — pieces per package (0 = not included)<input data-asset="${Number(asset.id)}" type="number" min="0" max="100000" step="1" value="0"></label>`).join('');
        el('rental-package-select').innerHTML = '<option value="">Select a package</option>' + state.packages.map(item => `<option value="${Number(item.id)}">${escape(item.name)} — fee ${money(item.fee_cents)}, deposit ${money(item.deposit_cents)}</option>`).join('');
        for (const name of ['starts_on', 'due_on']) {
            const input = el('rental-book-form').elements[name];
            input.min = state.business_date;
            if (!input.value) {
                input.value = state.business_date;
                input.dispatchEvent(new Event('change', {bubbles: true}));
            }
        }
        el('rental-bookings').innerHTML = state.bookings.map(booking => {
            const parts = JSON.parse(booking.components_json), returned = JSON.parse(booking.returned_json);
            const paid = Number(booking.transaction_id) > 0;
            const deposit = Number(booking.collected_cents) - Number(booking.refunded_cents);
            const overdue = booking.status === 'issued' && booking.due_on < state.business_date;
            const rows = Object.entries(parts).map(([id, qty]) => {
                const name = state.assets.find(asset => Number(asset.id) === Number(id))?.name || `Equipment #${id}`;
                return `<p>${escape(name)}: ${Number(qty) - Number(returned[id] || 0)} outstanding / ${Number(qty)} booked</p>`;
            }).join('');
            let controls = '';
            if (booking.status === 'reserved' && !paid) {
                if (cashier) controls += `<form data-pay="${Number(booking.id)}"><label>Cash received for fee only <input name="cash_received" type="number" min="${money(booking.fee_cents)}" step="0.01" required></label><button class="primary-btn" type="submit">Collect rental fee</button></form>`;
                controls += `<button class="secondary-btn" type="button" data-action="cancel" data-id="${Number(booking.id)}">Cancel unpaid reservation</button>`;
            }
            if (booking.status === 'reserved' && paid && cashier) controls += `<button class="primary-btn" type="button" data-action="issue" data-id="${Number(booking.id)}">Issue equipment & collect ${money(booking.deposit_cents)} cash deposit</button>`;
            if (booking.status === 'issued') controls += `<form data-return="${Number(booking.id)}">${Object.entries(parts).filter(([id, qty]) => qty > Number(returned[id] || 0)).map(([id, qty]) => `<label>Reusable return: ${escape(state.assets.find(asset => Number(asset.id) === Number(id))?.name || id)}<input data-asset="${Number(id)}" type="number" min="0" max="${qty - Number(returned[id] || 0)}" step="1" value="0"></label>`).join('')}<button class="primary-btn" type="submit">Confirm physical return</button></form>`;
            if (booking.status === 'returned' && deposit > 0 && cashier) controls += `<button class="primary-btn" type="button" data-action="refund" data-id="${Number(booking.id)}">Refund ${money(deposit)} cash deposit</button>`;
            return `<article class="offering-panel"><h3>Booking #${Number(booking.id)} — ${escape(booking.borrower)}</h3><p>${escape(booking.contact)}</p><p>${escape(booking.starts_on)} → ${escape(booking.due_on)} · ${escape(booking.status)}${overdue ? ' — OVERDUE' : ''}</p><p>Fee: ${money(booking.fee_cents)} (${paid ? 'paid' : 'unpaid'}) · Deposit held: ${money(deposit)}</p>${paid ? `<a href="/store/receipt/${Number(booking.transaction_id)}">View fee receipt</a>` : ''}${rows}${controls}</article>`;
        }).join('') || '<p>No bookings yet.</p>';
    }
    let loadRequestId = 0;
    async function load() {
        const requestId = ++loadRequestId;
        const data = await request('/store/offerings/data?store_id=' + storeId);
        if (!isActive() || requestId !== loadRequestId) return;
        state = data;
        render();
    }
    root.addEventListener('submit', event => {
        event.preventDefault();
        const form = event.target;
        if (form.id === 'food-template-form' || form.hasAttribute('data-edit-food-template')) action('template', {}, form);
        else if (form.dataset.foodBatch) action('batch', {...values(form),family_id:Number(form.dataset.foodBatch)});
        else if (form.hasAttribute('data-food-close')) action('food-close',values(form));
        else if (form.id === 'rental-asset-form') action('asset', values(form), form);
        else if (form.id === 'rental-package-form') action('package', {...values(form), components:components(form)}, form);
        else if (form.id === 'rental-book-form') action('book', {...values(form), request_key:bookingKey}, form);
        else if (form.dataset.food) action('food', {...values(form), product_id:Number(form.dataset.food), enabled:form.elements.enabled.checked});
        else if (form.dataset.pay) action('pay', {...values(form), booking_id:Number(form.dataset.pay)});
        else if (form.dataset.return) action('return', {booking_id:Number(form.dataset.return), components:components(form)});
    });
    root.addEventListener('click', async event => {
        const addVariant=event.target.closest('[data-add-food-variant]');
        if (addVariant && !busy) {
            const rows = addVariant.closest('form').querySelector('[data-variant-rows]');
            rows.insertAdjacentHTML('beforeend', window.FoodTemplateUI.variant({variant_label:'New variant'}));
            const name = rows.lastElementChild.querySelector('input[name="variant-label"]');
            name?.focus();
            name?.select();
            return;
        }
        const button = event.target.closest('[data-action]');
        if (!button || busy) return;
        const confirmed = await window.IbemsDialog.confirm(button.textContent + '? Confirm only after checking the booking and the physical cash/equipment.', {title:'Confirm rental operation', confirmLabel:'Confirm'});
        if (confirmed && isActive()) action(button.dataset.action, {booking_id:Number(button.dataset.id)});
    });
    el('offering-store').addEventListener('change', event => {
        if (busy) return;
        const url = new URL(window.location.href); url.searchParams.set('store_id', event.target.value); window.location.assign(url.href);
    });
    (async () => {
        try {
            const data = await request('/store/my-stores');
            if (!isActive()) return;
            const requested = Number(new URL(window.location.href).searchParams.get('store_id') || data.default_store_id);
            const selected = data.stores.find(store => Number(store.id) === requested);
            if (!selected) throw new Error('No accessible store selected.');
            storeId = Number(selected.id);
            el('offering-store').innerHTML = data.stores.map(store => `<option value="${Number(store.id)}" ${Number(store.id) === storeId ? 'selected' : ''}>${escape(store.store_name)}</option>`).join('');
            await load();
            if (!isActive()) return;
            el('offering-store').disabled = false;
            result(el('food-section').hidden && el('rental-section').hidden
                ? 'No food or rental workflows are available in this view for the selected store.'
                : 'Ready. Changes apply only to the selected store.');
        } catch (error) { result(error.message, true); }
    })();
})();
