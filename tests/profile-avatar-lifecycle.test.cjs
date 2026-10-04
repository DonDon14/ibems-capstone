const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/profile-avatar.js', 'utf8');

function setup() {
    const document = { activeElement: null };
    function element() {
        const classes = new Set();
        return { listeners: {}, attrs: {}, disabled: false, textContent: '', dataset: {},
            classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c), toggle: (c, on) => on ? classes.add(c) : classes.delete(c) },
            addEventListener(type, fn) { this.listeners[type] = fn; },
            setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return this.attrs[k]; },
            focus() { this.focused = true; document.activeElement = this; }, click() { this.clicked = true; }, getClientRects() { return [1]; } };
    }
    const ids = Object.fromEntries(['modal', 'form', 'file', 'picker', 'preview', 'file-name', 'result', 'save'].map(k => [`profile-image-${k}`, element()]));
    const opener = element(), closer = element(), avatar = element();
    const modal = ids['profile-image-modal'], form = ids['profile-image-form'], file = ids['profile-image-file'];
    modal.classList.add('is-hidden'); modal.querySelector = () => closer;
    modal.contains = el => Object.values(ids).includes(el) || el === closer;
    form.reset = () => { file.files = []; };
    let resolveRequest, rejectRequest, requests = 0, previews = 0, revoked = 0, updates = 0;
    const pending = new Promise((resolve, reject) => { resolveRequest = resolve; rejectRequest = reject; });
    Object.assign(document, { body: element(), getElementById: id => ids[id], addEventListener() {},
        querySelector: s => s.includes('meta') ? null : s === '[data-profile-image-open]' ? opener : avatar,
        querySelectorAll: s => s === '[data-profile-image-close]' ? [closer] : [avatar] });
    vm.runInNewContext(source, {
        document, window: { dispatchEvent() { updates++; } }, CustomEvent: function () {},
        URL: { revokeObjectURL() { revoked++; }, createObjectURL: () => { previews++; return 'blob:test'; } },
        FormData: class { append() {} }, fetch: () => { requests++; return pending; }
    });
    const open = () => opener.listeners.click({ currentTarget: opener });
    const submit = () => form.listeners.submit({ preventDefault() {} });
    return { ids, modal, form, file, open, submit, avatar, document, close: () => closer.listeners.click(), requests: () => requests,
        change: () => file.listeners.change(), previews: () => previews, revoked: () => revoked, updates: () => updates,
        fail: rejectRequest, respond: resolveRequest,
        finish: data => resolveRequest({ ok: data.status === 'success', json: async () => data }) };
}

test('pending upload prevents duplicate requests and keeps a reopened dialog open', async () => {
    const ui = setup(); ui.open(); ui.file.files = [{ name: 'synthetic.png' }];
    const first = ui.submit(); await ui.submit();
    assert.equal(ui.requests(), 1); assert.equal(ui.file.disabled, true);
    assert.equal(ui.form.attrs['aria-busy'], 'true');
    ui.close(); ui.open(); ui.finish({ status: 'success', profile_image_url: '/test.png' }); await first;
    assert.equal(ui.modal.classList.contains('is-hidden'), false);
    assert.equal(ui.ids['profile-image-result'].textContent, 'Profile picture updated.');
    assert.equal(ui.file.disabled, false); assert.equal(ui.form.attrs['aria-busy'], 'false');
});

test('failed upload releases controls without reopening a dismissed dialog', async () => {
    const ui = setup(); ui.open(); ui.file.files = [{ name: 'synthetic.png' }];
    const first = ui.submit(); ui.close(); ui.finish({ status: 'error', message: 'Rejected' }); await first;
    assert.equal(ui.modal.classList.contains('is-hidden'), true);
    assert.equal(ui.ids['profile-image-result'].textContent, '');
    assert.equal(ui.ids['profile-image-save'].disabled, false);
});

test('missing file validates locally without a request', async () => {
    const ui = setup(); ui.open(); await ui.submit();
    assert.equal(ui.requests(), 0);
    assert.equal(ui.ids['profile-image-result'].attrs.role, 'alert');
    assert.equal(ui.ids['profile-image-picker'].focused, true);
});

test('image chooser opens the file control and is unavailable during upload', async () => {
    const ui = setup(); ui.open();
    const picker = ui.ids['profile-image-picker'];
    picker.listeners.click(); assert.equal(ui.file.clicked, true);
    ui.file.clicked = false; ui.file.files = [{ name: 'synthetic.png' }];
    const pending = ui.submit();
    assert.equal(picker.disabled, true);
    picker.listeners.click(); assert.equal(ui.file.clicked, false);
    ui.finish({ status: 'error', message: 'Rejected' }); await pending;
    assert.equal(picker.disabled, false);
});

test('oversized and unsupported files are rejected before preview or upload', async () => {
    for (const file of [{ name: 'large.png', type: 'image/png', size: 2 * 1024 * 1024 + 1 }, { name: 'fake.png', type: 'text/plain', size: 20 }]) {
        const ui = setup(); ui.open(); ui.file.files = [file]; ui.change();
        assert.equal(ui.file.value, '');
        assert.equal(ui.previews(), 0);
        await ui.submit(); assert.equal(ui.requests(), 0);
        assert.equal(ui.ids['profile-image-result'].attrs['aria-live'], 'assertive');
        assert.equal(ui.document.activeElement, ui.ids['profile-image-picker']);
    }
});

test('replacing a valid preview with an invalid file releases its object URL', () => {
    const ui = setup(); ui.open(); ui.file.files = [{ name: 'valid.webp', type: '', size: 2 * 1024 * 1024 }]; ui.change();
    assert.equal(ui.previews(), 1);
    ui.file.files = [{ name: 'document.pdf', type: 'application/pdf', size: 20 }]; ui.change();
    assert.equal(ui.revoked(), 1);
    assert.equal(ui.ids['profile-image-preview'].src, '/assets/images/default-profile.svg');
});

test('pending status owns focus before the initiating button is disabled', async () => {
    const ui = setup(); ui.open(); ui.file.files = [{ name: 'synthetic.png' }];
    ui.ids['profile-image-save'].focus();
    const pending = ui.submit();
    assert.equal(ui.document.activeElement, ui.ids['profile-image-result']);
    assert.equal(ui.ids['profile-image-result'].attrs['aria-live'], 'polite');
    ui.finish({ status: 'error', message: 'Rejected' }); await pending;
    assert.equal(ui.document.activeElement, ui.ids['profile-image-result']);
    assert.equal(ui.ids['profile-image-result'].textContent, 'Rejected');
});

test('upload retains focus outside the dialog and restores previous control availability', async () => {
    const ui = setup(); ui.open(); ui.document.activeElement = {};
    ui.ids['profile-image-save'].disabled = true;
    ui.file.files = [{ name: 'synthetic.png' }]; const outside = ui.document.activeElement;
    const pending = ui.submit();
    assert.equal(ui.document.activeElement, outside);
    ui.finish({ status: 'error' }); await pending;
    assert.equal(ui.ids['profile-image-save'].disabled, true);
    assert.equal(ui.file.disabled, false);
});

test('transport and malformed replies explain that the upload is unconfirmed', async () => {
    for (const outcome of ['transport', 'json', 'null', 'unknown']) {
        const ui = setup(); ui.open(); ui.file.files = [{ name: 'synthetic.png' }];
        const pending = ui.submit();
        if (outcome === 'transport') ui.fail(new Error('Internal network detail'));
        else ui.respond({ ok: true, json: async () => { if (outcome === 'json') throw new Error('JSON detail'); return outcome === 'null' ? null : { status: 'unknown' }; } });
        await pending;
        assert.equal(ui.ids['profile-image-result'].textContent, 'The upload was not confirmed. Check your profile picture before retrying.');
        assert.equal(ui.updates(), 0); assert.equal(ui.file.disabled, false);
    }
});

test('confirmed reply without an image URL keeps the last avatar and prevents selected-file resubmission', async () => {
    const ui = setup(); ui.open(); ui.file.files = [{ name: 'synthetic.png' }]; ui.change();
    ui.avatar.src = '/existing.png'; ui.avatar.attrs.src = '/existing.png';
    const pending = ui.submit(); ui.finish({ status: 'success' }); await pending;
    assert.equal(ui.avatar.src, '/existing.png'); assert.equal(ui.updates(), 0);
    assert.equal(ui.modal.classList.contains('is-hidden'), false);
    assert.equal(ui.file.value, ''); assert.equal(ui.revoked(), 1);
    assert.equal(ui.ids['profile-image-preview'].src, '/existing.png');
    assert.equal(ui.ids['profile-image-result'].classList.contains('is-warning'), true);
    assert.match(ui.ids['profile-image-result'].textContent, /updated.*Reload/);
});
