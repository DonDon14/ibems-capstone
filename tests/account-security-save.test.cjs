const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/account-security.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function setup() {
    let document;
    class Element {
        hidden = false; disabled = false; value = ''; innerHTML = 'Original button'; textContent = ''; attributes = {}; listeners = {}; classes = new Set();
        classList = {add: n => this.classes.add(n), remove: n => this.classes.delete(n), contains: n => this.classes.has(n), toggle: (n, on) => on ? this.classes.add(n) : this.classes.delete(n)};
        setAttribute(n, v) {this.attributes[n] = v;}
        addEventListener(n, handler) {this.listeners[n] = handler;}
        focus() {document.activeElement = this;}
        matches(selector) {return selector === '[data-account-security-close]' && this === get('close');}
        contains(el) {return [...elements.values()].includes(el) && el !== get('opener') && el !== get('outside');}
        querySelectorAll() {return controls;}
        reset() {this.resets = (this.resets || 0) + 1; for (const id of this === get('account-password-form') ? passwordIds : pinIds) get(id).value = '';}
    }
    const elements = new Map();
    const get = id => {if (!elements.has(id)) elements.set(id, new Element()); return elements.get(id);};
    const passwordIds = ['account-current-password', 'account-new-password', 'account-new-password-confirm'];
    const pinIds = ['account-pin-current-password', 'account-new-pin', 'account-new-pin-confirm'];
    const controls = [...passwordIds, ...pinIds, 'account-password-save', 'account-pin-save', 'account-security-retry', 'close'].map(get);
    const opener = get('opener'), requests = [], frames = [], events = {}, controller = new AbortController();
    document = {getElementById: get, querySelector: () => opener, querySelectorAll: () => [get('close')], body: get('body'), activeElement: opener};
    vm.runInNewContext(source, {document, HTMLElement: Element, window: {IbemsPortalNavigation: {currentSignal: controller.signal}, requestAnimationFrame: fn => frames.push(fn), addEventListener: (name, fn) => {events[name] = fn;}},
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({url, options, resolve, reject}))});
    const submit = kind => get(`account-${kind}-form`).listeners.submit({preventDefault() {}});
    return {get, requests, controls, document, frames, unload: () => events['ibems:portal-page-unload'](), open: () => opener.listeners.click(), close: () => get('close').listeners.click(), submit, abort: () => controller.abort(),
        draft(kind = 'password') {
            if (kind === 'password') {get(passwordIds[0]).value = 'synthetic-current'; get(passwordIds[1]).value = get(passwordIds[2]).value = 'synthetic-new';}
            else {get(pinIds[0]).value = 'synthetic-current'; get(pinIds[1]).value = get(pinIds[2]).value = '12345';}
        },
        async ready(hasPin = false, eligible = true) {this.open(); requests[0].resolve({ok: true, json: async () => ({status: 'success', has_debt_pin: hasPin, can_manage_debt_pin: eligible})}); await flush();}
    };
}
const success = message => ({ok: true, json: async () => ({status: 'success', message})});

test('security save blocks duplicate and cross-form requests, freezes fields and focuses progress', async () => {
    const qa = setup(); await qa.ready(); qa.draft(); qa.draft('pin'); qa.get('account-password-save').focus();
    const pending = qa.submit('password'); qa.submit('password'); qa.submit('pin');
    assert.equal(qa.requests.length, 2);
    assert.equal(qa.requests[1].url, '/profile/password');
    assert.equal(qa.requests[1].options.method, 'POST');
    assert.equal(JSON.parse(qa.requests[1].options.body).new_password_confirm, 'synthetic-new');
    assert.equal(qa.get('account-new-password').disabled, true);
    assert.equal(qa.get('account-pin-save').disabled, true);
    assert.equal(qa.document.activeElement, qa.get('account-password-result'));
    assert.equal(qa.get('account-password-result').attributes.role, 'status');
    assert.equal(qa.get('account-password-form').attributes['aria-busy'], 'true');
    qa.requests[1].resolve(success('Saved')); await pending;
    assert.equal(qa.get('account-password-result').textContent, 'Saved');
    assert.equal(qa.get('account-password-result').classes.has('is-success'), true);
    assert.equal(qa.get('account-password-form').resets, 2);
    assert.equal(qa.get('account-new-password').value, '');
    assert.equal(qa.get('account-new-password').disabled, false);
    assert.equal(qa.get('account-password-save').innerHTML, 'Original button');
});

test('pending save retains the dialog on close and ignores repeated open/status retry', async () => {
    const qa = setup(); await qa.ready(); qa.draft(); const pending = qa.submit('password');
    qa.close(); qa.open(); qa.get('account-security-retry').listeners.click();
    assert.equal(qa.get('account-security-modal').classes.has('is-hidden'), false);
    assert.equal(qa.get('account-password-form').resets, 1);
    assert.equal(qa.requests.length, 2);
    assert.equal(qa.document.activeElement, qa.get('account-password-result'));
    qa.requests[1].resolve({ok: false, json: async () => ({status: 'error', message: 'Rejected'})}); await pending;
    qa.close(); assert.equal(qa.get('account-security-modal').classes.has('is-hidden'), true);
    assert.equal(qa.document.activeElement, qa.get('opener'));
});

test('save rejection preserves drafts, original disabled states and outside focus', async () => {
    const qa = setup(); await qa.ready(); qa.draft('pin'); qa.get('account-current-password').disabled = true;
    const pending = qa.submit('pin'); qa.get('outside').focus();
    qa.requests[1].resolve({ok: false, json: async () => ({status: 'success', message: 'Server rejected'})}); await pending;
    assert.equal(qa.get('account-new-pin').value, '12345');
    assert.equal(qa.get('account-pin-result').textContent, 'Server rejected');
    assert.equal(qa.get('account-pin-result').attributes['aria-live'], 'assertive');
    assert.equal(qa.get('account-current-password').disabled, true);
    assert.equal(qa.get('account-new-pin').disabled, false);
    assert.equal(qa.document.activeElement, qa.get('outside'));
    assert.equal(qa.get('account-pin-form').attributes['aria-busy'], 'false');
});

test('unconfirmed credential replies never clear drafts or imply a successful change', async () => {
    for (const kind of ['password', 'pin']) for (const outcome of ['network', 'json', 'null', 'unknown']) {
        const qa = setup(); await qa.ready(); qa.draft(kind); const pending = qa.submit(kind);
        if (outcome === 'network') qa.requests[1].reject(new Error('Internal network details'));
        else qa.requests[1].resolve({ok: true, json: async () => {if (outcome === 'json') throw new Error('Technical JSON details'); return outcome === 'null' ? null : {status: 'unknown'};}});
        await pending;
        assert.match(qa.get(`account-${kind}-result`).textContent, /not confirmed/);
        assert.doesNotMatch(qa.get(`account-${kind}-result`).textContent, /Technical|Internal/);
        assert.equal(qa.get(`account-${kind}-form`).resets, 1);
        assert.equal(qa.get(`account-${kind}-result`).classes.has('is-success'), false);
    }
});

test('aborted navigation suppresses late credential replies and draft resets', async () => {
    for (const outcome of ['success', 'failure']) {
        const qa = setup(); await qa.ready(); qa.draft('pin'); const pending = qa.submit('pin'); qa.abort(); qa.get('outside').focus();
        const text = qa.get('account-pin-result').textContent;
        if (outcome === 'success') qa.requests[1].resolve(success('Saved')); else qa.requests[1].reject(new Error('Late failure'));
        await pending;
        assert.equal(qa.get('account-pin-result').textContent, text);
        assert.equal(qa.get('account-pin-form').resets, 1);
        assert.equal(qa.get('account-pin-current-wrap').hidden, true);
        assert.equal(qa.document.activeElement, qa.get('outside'));
        assert.equal(qa.get('account-pin-save').disabled, false);
    }
});

test('confirmed PIN change resets only its form and requires current password for replacement', async () => {
    const qa = setup(); await qa.ready(); qa.draft(); qa.draft('pin'); const pending = qa.submit('pin');
    assert.equal(qa.requests[1].url, '/profile/debt-pin');
    assert.deepEqual(JSON.parse(qa.requests[1].options.body), {current_password: 'synthetic-current', pin: '12345', pin_confirm: '12345'});
    qa.requests[1].resolve(success()); await pending;
    assert.equal(qa.get('account-password-form').resets, 1);
    assert.equal(qa.get('account-new-password').value, 'synthetic-new');
    assert.equal(qa.get('account-pin-form').resets, 2);
    assert.equal(qa.get('account-pin-current-wrap').hidden, false);
    assert.equal(qa.get('account-pin-current-password').required, true);
    assert.equal(qa.get('account-pin-result').textContent, 'Purchase PIN saved.');
});

test('local missing/invalid credentials and unavailable PIN state cannot send requests', async () => {
    for (const kind of ['password', 'pin']) {
        const qa = setup(); await qa.ready(true); qa.draft(kind);
        qa.get(kind === 'password' ? 'account-current-password' : 'account-pin-current-password').value = '';
        const pending = qa.submit(kind); assert.equal(qa.requests.length, 1); await pending;
        assert.equal(qa.document.activeElement, qa.get(`account-${kind}-result`));
    }
    for (const [kind, id, value] of [
        ['password', 'account-new-password', 'short'],
        ['password', 'account-new-password', 'x'.repeat(256)],
        ['password', 'account-new-password-confirm', 'different'],
        ['pin', 'account-new-pin', '12ab'],
        ['pin', 'account-new-pin-confirm', '54321']
    ]) {
        const qa = setup(); await qa.ready(); qa.draft(kind); qa.get(id).value = value;
        const pending = qa.submit(kind); assert.equal(qa.requests.length, 1); await pending;
        assert.equal(qa.document.activeElement, qa.get(`account-${kind}-result`));
        assert.equal(qa.get(`account-${kind}-result`).attributes.role, 'alert');
    }
    const qa = setup(); await qa.ready(false, false); qa.draft('pin'); const pending = qa.submit('pin');
    assert.equal(qa.requests.length, 1); await pending;
});

test('queued initial focus cannot steal focus after close and reopen', async () => {
    const qa = setup(); qa.open(); qa.close(); qa.open(); qa.get('outside').focus();
    qa.frames[0](); assert.equal(qa.document.activeElement, qa.get('outside'));
    qa.frames[1](); assert.equal(qa.document.activeElement, qa.get('outside'));
});

test('page unload clears the security dialog and ignores a late save without stealing page focus', async () => {
    const qa = setup(); await qa.ready(); qa.draft(); const pending = qa.submit('password');
    assert.equal(qa.get('opener').disabled, true);
    qa.unload(); qa.abort(); qa.get('outside').focus();
    assert.equal(qa.get('account-security-modal').classes.has('is-hidden'), true);
    assert.equal(qa.get('account-new-password').value, '');
    qa.requests[1].resolve(success('Late save')); await pending;
    assert.equal(qa.get('account-password-result').textContent, '');
    assert.equal(qa.document.activeElement, qa.get('outside'));
    assert.equal(qa.get('opener').disabled, false);
});
