const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/department-debt-pages.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
const reply = data => ({ ok: true, json: async () => ({ status: 'success', ...data }) });

function setup() {
    const elements = new Map();
    const controller = new AbortController();
    const requests = [];
    const masked = [];
    let focused;
    for (const id of ['department-user-page', 'department-pin-form', 'department-pin', 'department-pin-confirmation', 'department-current-password', 'department-pin-heading', 'department-current-password-wrap', 'department-user-assignments', 'department-pin-result', 'department-pin-status', 'department-pin-retry', 'department-pin-save-label', 'save']) {
        const element = { value: '', textContent: '', innerHTML: '', disabled: false, hidden: false, required: false, attrs: {}, listeners: {},
            addEventListener(type, handler) { this.listeners[type] = handler; },
            setAttribute(name, value) { this.attrs[name] = value; },
            focus() { focused = id; },
            querySelector() { return elements.get('save'); }
        };
        elements.set(id, element);
    }
    const context = {
        AbortController,
        document: { getElementById: id => elements.get(id) || null, querySelector: () => elements.get('save'), createElement: () => ({ set textContent(value) { this.innerHTML = String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'); } }) },
        window: { IbemsPortalNavigation: { currentSignal: controller.signal }, IbemsPasswordVisibility: {mask: root => masked.push(root)} },
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }))
    };
    vm.runInNewContext(source, context);
    return { elements, requests, masked, abort: () => controller.abort(), focus: () => focused,
        submit: () => elements.get('department-pin-form').listeners.submit({ preventDefault() {}, currentTarget: elements.get('department-pin-form') }),
        retry: () => elements.get('department-pin-retry').listeners.click(),
        draft() { elements.get('department-pin').value = '2468'; elements.get('department-pin-confirmation').value = '2468'; elements.get('department-current-password').value = 'fictional-only'; },
        async ready(pinSet = true) { requests[0].resolve(reply({ assignments: [{ name: '<Fictional>', code: 'DEMO' }], pin: { is_set: pinSet } })); await flush(); }
    };
}

test('replacement status exposes password requirement and escaped authority without enabling hidden password on initial setup', async () => {
    for (const pinSet of [true, false]) {
        const qa = setup(); await qa.ready(pinSet);
        assert.equal(qa.elements.get('department-current-password-wrap').hidden, !pinSet);
        assert.equal(qa.elements.get('department-current-password').required, pinSet);
        assert.equal(qa.elements.get('department-current-password').disabled, !pinSet);
        assert.match(qa.elements.get('department-user-assignments').innerHTML, /&lt;Fictional&gt;/);
    }
});

test('pending replacement freezes credentials and prevents repeated writes', async () => {
    const qa = setup(); await qa.ready(); qa.draft();
    const pending = qa.submit(); const repeated = qa.submit();
    assert.equal(qa.requests.length, 2);
    assert.equal(qa.elements.get('department-pin-form').attrs['aria-busy'], 'true');
    for (const id of ['department-pin', 'department-pin-confirmation', 'department-current-password', 'save']) assert.equal(qa.elements.get(id).disabled, true);
    assert.equal(qa.focus(), 'department-pin-result');
    qa.requests[1].reject(new Error('<Rejected>')); await pending; await repeated;
    assert.equal(qa.elements.get('department-pin-result').textContent, '<Rejected>');
    assert.equal(qa.elements.get('department-pin-result').attrs.role, 'alert');
    assert.equal(qa.elements.get('department-pin-form').attrs['aria-busy'], 'false');
    assert.equal(qa.elements.get('save').disabled, false);
    assert.equal(qa.masked.length, 0);
});

test('confirmed PIN save survives failed assignment refresh and keeps unavailable form disabled', async () => {
    const qa = setup(); await qa.ready(); qa.draft();
    const pending = qa.submit(); qa.requests[1].resolve(reply({})); await flush();
    qa.requests[2].reject(new Error('Refresh unavailable')); await pending;
    assert.match(qa.elements.get('department-pin-result').textContent, /^Department approval PIN saved securely\./);
    assert.match(qa.elements.get('department-pin-result').className, /is-warning/);
    assert.equal(qa.elements.get('department-pin-retry').hidden, false);
    assert.equal(qa.elements.get('save').disabled, true);
    for (const id of ['department-pin', 'department-pin-confirmation', 'department-current-password']) assert.equal(qa.elements.get(id).value, '');
    assert.deepEqual(qa.masked, [qa.elements.get('department-pin-form')]);
});

test('lost assignment after successful save stays disabled and blocks another submission', async () => {
    const qa = setup(); await qa.ready(); qa.draft();
    const pending = qa.submit(); qa.requests[1].resolve(reply({})); await flush();
    qa.requests[2].resolve(reply({ assignments: [], pin: { is_set: true } })); await pending;
    assert.equal(qa.elements.get('save').disabled, true);
    qa.draft(); const repeated = qa.submit(); assert.equal(qa.requests.length, 3); await repeated;
});

test('late successful save after navigation cannot clear drafts or start another load', async () => {
    const qa = setup(); await qa.ready(); qa.draft();
    const pending = qa.submit(); qa.abort(); qa.requests[1].resolve(reply({})); await flush();
    assert.equal(qa.requests.length, 2);
    assert.equal(qa.elements.get('department-pin').value, '2468');
    assert.equal(qa.masked.length, 0);
    await pending;
});

test('mismatch and missing replacement password focus correction without writes', async () => {
    const qa = setup(); await qa.ready(); qa.draft();
    qa.elements.get('department-pin-confirmation').value = '1234'; await qa.submit();
    assert.equal(qa.focus(), 'department-pin-result');
    assert.match(qa.elements.get('department-pin-result').textContent, /matching/);
    qa.draft(); qa.elements.get('department-current-password').value = ''; await qa.submit();
    assert.match(qa.elements.get('department-pin-result').textContent, /current account password/);
    assert.equal(qa.requests.length, 1);
});

test('assignment failure has safe local retry, prevents overlapping reload, and clears obsolete error on recovery', async () => {
    const qa = setup(); qa.requests[0].reject(new Error('Unavailable')); await flush();
    assert.equal(qa.elements.get('save').disabled, true);
    assert.equal(qa.elements.get('department-pin-retry').hidden, false);
    const retry = qa.retry(); await qa.retry(); assert.equal(qa.requests.length, 2);
    qa.requests[1].resolve(reply({ assignments: [{ name: 'Restored', code: 'R' }], pin: { is_set: false } })); await retry;
    assert.equal(qa.elements.get('save').disabled, false);
    assert.equal(qa.elements.get('department-pin-retry').hidden, true);
    assert.doesNotMatch(qa.elements.get('department-pin-result').className, /is-error/);
    assert.equal(qa.elements.get('department-pin-result').textContent, 'Department assignments refreshed.');
    assert.equal(qa.focus(), 'department-pin-result');
});
