const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/auth-role-selection.js', 'utf8');
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
function fixture() {
    const calls = [], redirects = [], events = {}, nodes = {};
    const document = { activeElement: null, querySelectorAll: () => forms, getElementById: id => nodes[id] };
    const node = (extra = {}) => { const classes = new Set(); return { textContent: '', className: '', attrs: {}, disabled: false, handlers: {},
        setAttribute(k, v) { this.attrs[k] = String(v); }, removeAttribute(k) { delete this.attrs[k]; }, focus() { document.activeElement = this; },
        classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c) }, ...extra }; };
    const status = nodes.status = node(), group = nodes['role-options'] = node();
    const buttons = ['ADMIN', 'USER'].map(value => node({ value, querySelector: () => ({ textContent: value === 'ADMIN' ? 'Admin' : 'User' }) }));
    const forms = buttons.map(button => { const form = { action: 'https://ibems.test/index.php/auth/select-role', querySelector: () => button, addEventListener: (event, fn) => { form[event] = fn; } }; button.form = form; return form; });
    const context = vm.createContext({ document, URL, FormData: class { entries() { return [['csrf_name', 'fictional-token']][Symbol.iterator](); } },
        window: { location: { href: 'https://ibems.test/index.php/auth/select-role', origin: 'https://ibems.test', assign: href => redirects.push(href) }, addEventListener: (event, fn) => { events[event] = fn; } },
        fetch: (url, options) => { const d = deferred(); calls.push({ url, options, ...d }); return d.promise; },
    });
    vm.runInContext(source, context);
    const submit = (index = 0, implicit = false) => forms[index].submit({ submitter: implicit ? undefined : buttons[index], preventDefault() {}, defaultPrevented: false });
    return { context, document, forms, buttons, status, group, calls, events, redirects, submit };
}

test('portal selection posts original CSRF fields and role once while holding other choices', async () => {
    const f = fixture(); f.buttons[1].disabled = true;
    const pending = f.submit(0, true); await f.submit();
    assert.equal(f.calls.length, 1); assert.equal(f.calls[0].url, f.forms[0].action);
    assert.deepEqual(JSON.parse(f.calls[0].options.body), { csrf_name: 'fictional-token', role: 'ADMIN' });
    assert.equal(f.calls[0].options.headers['Content-Type'], 'application/json');
    assert.ok(f.buttons.every(button => button.disabled)); assert.equal(f.group.attrs['aria-busy'], 'true');
    assert.equal(f.status.textContent, 'Opening Admin…'); assert.equal(f.document.activeElement, f.status);
    f.calls[0].resolve({ ok: false, json: async () => ({ status: 'error', message: 'Assignment changed' }) }); await pending;
    assert.equal(f.buttons[0].disabled, false); assert.equal(f.buttons[1].disabled, true); assert.equal(f.group.attrs['aria-busy'], 'false');
});

test('rejected selection gives plain-text focused alert and permits a corrected choice', async () => {
    const f = fixture(); const pending = f.submit();
    f.calls[0].resolve({ ok: false, json: async () => ({ status: 'error', message: '<img src=x> Assignment changed' }) }); await pending;
    assert.equal(f.status.textContent, '<img src=x> Assignment changed'); assert.equal(f.status.attrs.role, 'alert');
    assert.equal(f.status.attrs['aria-live'], 'assertive'); assert.equal(f.document.activeElement, f.status); assert.equal(f.redirects.length, 0);
    const retry = f.submit(1); assert.equal(f.calls.length, 2); assert.equal(JSON.parse(f.calls[1].options.body).role, 'USER');
    f.calls[1].resolve({ ok: true, json: async () => ({ status: 'success', redirect_to: '/user/dashboard' }) }); await retry;
    assert.deepEqual(f.redirects, ['https://ibems.test/user/dashboard']);
});

test('network and unreadable response failures restore usable choices and readable guidance', async () => {
    for (const scenario of ['network', 'json']) {
        const f = fixture(); const pending = f.submit();
        if (scenario === 'network') f.calls[0].reject(new Error('Failed to fetch'));
        else f.calls[0].resolve({ ok: true, json: async () => { throw new Error('Unexpected HTML'); } });
        await pending; assert.ok(f.buttons.every(button => !button.disabled)); assert.equal(f.status.attrs.role, 'alert');
        assert.match(f.status.textContent, scenario === 'network' ? /connection|confirm/i : /response could not be read/);
    }
});

test('successful-looking HTTP failure cannot navigate', async () => {
    const f = fixture(); const pending = f.submit();
    f.calls[0].resolve({ ok: false, json: async () => ({ status: 'success', redirect_to: '/admin/dashboard' }) }); await pending;
    assert.equal(f.redirects.length, 0); assert.equal(f.status.attrs.role, 'alert');
});

test('confirmed selection navigates once and retains busy state until navigation or history restore', async () => {
    const f = fixture(); const pending = f.submit();
    f.calls[0].resolve({ ok: true, json: async () => ({ status: 'success', redirect_to: '/index.php/admin/dashboard' }) }); await pending; await f.submit(1);
    assert.deepEqual(f.redirects, ['https://ibems.test/index.php/admin/dashboard']); assert.equal(f.calls.length, 1);
    assert.equal(f.group.attrs['aria-busy'], 'true');
    f.events.pageshow(); assert.ok(f.buttons.every(button => !button.disabled)); assert.equal(f.status.textContent, '');
});

test('late response after leaving and returning cannot redirect or overwrite a new choice', async () => {
    const f = fixture(); const first = f.submit(); f.events.pagehide(); f.events.pageshow();
    const second = f.submit(1);
    f.calls[0].resolve({ ok: true, json: async () => ({ status: 'success', redirect_to: '/admin/dashboard' }) }); await first;
    assert.equal(f.status.textContent, 'Opening User…'); assert.equal(f.redirects.length, 0); assert.equal(f.group.attrs['aria-busy'], 'true');
    f.calls[1].resolve({ ok: false, json: async () => ({ status: 'error', message: 'New choice rejected' }) }); await second;
    assert.equal(f.status.textContent, 'New choice rejected');
});

test('missing, malformed and external destinations keep acknowledged selection guidance inline', async () => {
    for (const redirect_to of [undefined, 'https://other.test/admin', 'javascript:alert(1)', 'http://[invalid']) {
        const f = fixture(); const pending = f.submit();
        f.calls[0].resolve({ ok: true, json: async () => ({ status: 'success', redirect_to }) }); await pending;
        assert.equal(f.redirects.length, 0); assert.match(f.status.textContent, /Portal selected.*Reload/); assert.ok(f.buttons.every(button => !button.disabled));
    }
});

test('unavailable browser enhancement leaves native form fallback untouched', () => {
    const f = fixture(); let listeners = 0;
    const forms = [{ addEventListener: () => listeners++ }];
    f.context.document.querySelectorAll = () => forms; f.context.fetch = undefined;
    vm.runInContext(source, f.context); assert.equal(listeners, 0);
});
