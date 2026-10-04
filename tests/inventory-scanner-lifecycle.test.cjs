const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const main = fs.readFileSync('public/assets/js/store-inventory.js', 'utf8');
const scanner = main.slice(main.indexOf('function invSetBarcodeCameraStatus('), main.indexOf('function invProductNumbersValid('));
const lock = main.slice(main.indexOf('function invSyncModalBodyLock('), main.indexOf('function invProductFamilyKey('));
const part3 = fs.readFileSync('public/assets/js/store-inventory.part3.js', 'utf8');
const keyboard = part3.slice(part3.indexOf('document.addEventListener("keydown"'), part3.indexOf('document.getElementById("inventory-product-action-modal").addEventListener("click"'));
function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}
const tick = () => new Promise(resolve => setImmediate(resolve));
function fixture(mode = 'create') {
    const nodes = {}, engines = [], results = [], signal = { aborted: false };
    let active, keydown, locked = false, constructorFails = false, inputHook;
    const node = id => nodes[id] ||= {
        id, isConnected: true, disabled: false, hidden: false, tabIndex: 0, attrs: {}, dataset: {}, style: { display: 'none' }, value: '', inputCount: 0,
        focus() { active = this; },
        setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return this.attrs[k] ?? null; }, removeAttribute(k) { delete this.attrs[k]; },
        closest() { return this.owner ? node(this.owner) : null; },
        dispatchEvent() { this.inputCount++; inputHook?.(); },
        contains(el) { return el === this || el.owner === this.id; },
        querySelectorAll() { return Object.values(nodes).filter(n => n.owner === this.id); },
        getClientRects() { return this.hidden ? [] : [{}]; },
    };
    const parent = node(mode === 'create' ? 'inventory-product-modal' : 'inventory-product-action-modal');
    parent.style.display = 'grid';
    node(mode === 'create' ? 'inventory-product-action-modal' : 'inventory-product-modal');
    const modal = node('inventory-barcode-camera-modal');
    for (const id of ['inventory-barcode-camera-close', 'preview-region', 'inventory-barcode-camera-reader', 'inventory-barcode-camera-status', 'inventory-barcode-camera-retry', 'inventory-barcode-camera-cancel']) {
        node(id).owner = modal.id;
    }
    node('inventory-barcode-camera-reader').tabIndex = -1;
    node('inventory-barcode-camera-status').tabIndex = -1;
    const target = node('barcode'), opener = node('camera-opener');
    target.owner = opener.owner = parent.id;
    opener.focus();
    class Engine {
        constructor() {
            if (constructorFails) throw Error('Synthetic constructor failure');
            this.startGate = deferred(); this.stopCount = this.clearCount = 0; engines.push(this);
        }
        start(camera, config, onCode) { this.camera = camera; this.config = config; this.onCode = onCode; return this.startGate.promise; }
        async stop() { this.stopCount++; }
        async clear() { this.clearCount++; }
    }
    const context = vm.createContext({
        invActiveStoreId: 1, invIsCreatingProduct: false, invIsUpdatingProduct: false, invStockSavePending: false,
        invBarcodeCamera: null, invBarcodeCameraTarget: null, invBarcodeSession: null, invBarcodeVersion: 0, invBarcodeCleanupPromise: Promise.resolve(),
        navigator: { mediaDevices: { getUserMedia() {} } }, window: { Html5Qrcode: Engine, IbemsPortalNavigation: { currentSignal: signal } },
        Event: function () {},
        document: { getElementById: node, get activeElement() { return active; }, querySelectorAll: () => [parent, modal],
            body: { classList: { toggle(_name, value) { locked = value; } } }, addEventListener(_type, fn) { keydown = fn; } },
        invSetProductResult: (mode, message, type) => { results.push({ mode, message, type }); node('product-result').focus(); },
        invSetResult: (message, type) => results.push({ message, type }),
        invCloseProductActionModal: () => { parent.style.display = 'none'; },
        invRequestCloseProductModal: () => { parent.style.display = 'none'; },
    });
    vm.runInContext(lock + scanner + keyboard, context);
    const key = (value, shiftKey = false, defaultPrevented = false) => {
        const event = { key: value, target: active, shiftKey, defaultPrevented, preventDefault() { this.defaultPrevented = true; } };
        keydown(event); return event;
    };
    return { context, node, target, opener, parent, modal, engines, results, signal, key,
        open: () => context.invOpenBarcodeCamera(target), close: manual => context.invCloseBarcodeCamera(manual),
        get active() { return active; }, get locked() { return locked; },
        failConstructor() { constructorFails = true; }, onInput(fn) { inputHook = fn; },
    };
}
async function ready(f) {
    const opening = f.open(); await tick(); f.engines.at(-1).startGate.resolve(); await opening;
}

test('closing pending startup hides immediately, retains the parent lock, restores opener and cleans the late engine once', async () => {
    const f = fixture(), opening = f.open(); await tick();
    assert.equal(f.parent.attrs['aria-hidden'], 'true');
    const closing = f.close();
    assert.equal(f.modal.style.display, 'none'); assert.equal(f.locked, true); assert.equal(f.active, f.opener);
    assert.equal(f.parent.getAttribute('aria-hidden'), null);
    f.engines[0].startGate.resolve(); await Promise.all([opening, closing]);
    f.engines[0].onCode('STALE');
    assert.equal(f.target.value, ''); assert.equal(f.engines[0].stopCount, 1); assert.equal(f.engines[0].clearCount, 1);
    assert.equal(f.modal.style.display, 'none'); assert.equal(f.modal.attrs['aria-busy'], 'false');
    await f.close(); assert.equal(f.active, f.opener);
});

test('repeated open cannot create a second engine or change the target while starting', async () => {
    const f = fixture(), opening = f.open(); await tick();
    await f.context.invOpenBarcodeCamera(f.node('different-barcode'));
    assert.equal(f.engines.length, 1); assert.equal(f.context.invBarcodeCameraTarget, f.target);
    f.engines[0].startGate.resolve(); await opening; await f.close();
});

test('reopen waits for old cleanup and ignores old rejection, decoded values and status', async () => {
    const f = fixture(), first = f.open(); await tick();
    const closing = f.close(), second = f.open(); await tick(); assert.equal(f.engines.length, 1);
    f.engines[0].startGate.reject(Error('Old permission denied')); await tick();
    assert.equal(f.engines.length, 2); f.engines[1].startGate.resolve(); await Promise.all([first, closing, second]);
    f.engines[0].onCode('OLD');
    assert.equal(f.target.value, ''); assert.equal(f.modal.style.display, 'grid');
    assert.equal(f.node('inventory-barcode-camera-status').dataset.type, 'ready');
    assert.equal(f.parent.attrs['aria-hidden'], 'true'); await f.close();
});

test('slow stop cannot hide a reopened scanner and serializes the next camera', async () => {
    const f = fixture(); await ready(f); const gate = deferred();
    f.engines[0].stop = async () => { f.engines[0].stopCount++; await gate.promise; };
    const closing = f.close(), opening = f.open(); await tick();
    assert.equal(f.engines.length, 1); assert.equal(f.modal.style.display, 'grid');
    gate.resolve(); await tick(); f.engines[1].startGate.resolve(); await Promise.all([opening, closing]);
    assert.equal(f.modal.style.display, 'grid'); assert.equal(f.node('inventory-barcode-camera-status').dataset.type, 'ready');
    await f.close();
});

test('failed startup gives focused local alert, cleanup and Retry; retry restores the original parent and opener', async () => {
    const f = fixture(); f.parent.setAttribute('aria-hidden', 'false');
    const opening = f.open(); await tick(); f.engines[0].startGate.reject(Error('Denied')); await opening;
    const status = f.node('inventory-barcode-camera-status');
    assert.equal(status.attrs.role, 'alert'); assert.equal(status.attrs['aria-live'], 'assertive'); assert.equal(f.active, status);
    assert.equal(f.node('inventory-barcode-camera-retry').hidden, false); assert.equal(f.modal.attrs['aria-busy'], 'false');
    assert.equal(f.engines[0].stopCount, 1); assert.equal(f.engines[0].clearCount, 1);
    f.node('inventory-barcode-camera-retry').focus(); const retry = f.context.invRetryBarcodeCamera(); await tick();
    f.engines[1].startGate.resolve(); await retry;
    assert.equal(status.attrs.role, 'status'); assert.equal(f.node('inventory-barcode-camera-retry').hidden, true);
    await f.close(); assert.equal(f.parent.attrs['aria-hidden'], 'false'); assert.equal(f.active, f.opener);
});

test('unavailable camera API or library and constructor failure remain recoverable inside the scanner', async () => {
    for (const missing of ['library', 'api', 'constructor']) {
        const f = fixture();
        if (missing === 'library') delete f.context.window.Html5Qrcode;
        if (missing === 'api') f.context.navigator.mediaDevices = undefined;
        if (missing === 'constructor') f.failConstructor();
        await f.open(); assert.equal(f.engines.length, 0); assert.equal(f.modal.style.display, 'grid');
        assert.equal(f.node('inventory-barcode-camera-status').dataset.type, 'error'); assert.equal(f.results.length, 0);
        await f.close(true); assert.equal(f.active, f.target); assert.equal(f.parent.style.display, 'grid');
    }
});

test('cleanup failures retain dismissal and allow another camera', async () => {
    const f = fixture(); await ready(f);
    f.engines[0].stop = async () => { throw Error('Already stopped'); };
    f.engines[0].clear = async () => { throw Error('Clear failed'); };
    await f.close(); await ready(f); assert.equal(f.engines.length, 2); await f.close();
});

for (const mode of ['create', 'edit']) {
    test(`${mode} capture trims once, updates the field, dispatches input, preserves the form and focuses the barcode`, async () => {
        const f = fixture(mode); await ready(f);
        f.engines[0].onCode('  '); assert.equal(f.target.inputCount, 0);
        f.engines[0].onCode('  0123456789  '); f.engines[0].onCode('OTHER'); await tick();
        assert.equal(f.target.value, '0123456789'); assert.equal(f.target.inputCount, 1);
        assert.deepEqual(f.results, [{ mode, message: 'Barcode captured: 0123456789', type: 'ok' }]);
        assert.equal(f.active, f.target); assert.equal(f.parent.style.display, 'grid'); assert.equal(f.modal.style.display, 'none');
        assert.equal(f.engines[0].stopCount, 1); assert.equal(f.engines[0].clearCount, 1);
    });
}

test('late code cannot mutate a disabled/disconnected/moved target, changed store, aborted page or closed parent', async () => {
    for (const invalid of ['disabled', 'disconnected', 'moved', 'store', 'aborted', 'closed']) {
        const f = fixture(); await ready(f);
        if (invalid === 'disabled') f.target.disabled = true;
        if (invalid === 'disconnected') f.target.isConnected = false;
        if (invalid === 'moved') f.target.owner = 'other-parent';
        if (invalid === 'store') f.context.invActiveStoreId = 2;
        if (invalid === 'aborted') f.signal.aborted = true;
        if (invalid === 'closed') f.parent.style.display = 'none';
        f.engines[0].onCode('LATE'); assert.equal(f.target.value, ''); assert.equal(f.results.length, 0); await f.close();
    }
});

test('input-driven navigation suppresses stale result and cannot close a newer scanner', async () => {
    const f = fixture(); await ready(f); f.onInput(() => { f.context.invActiveStoreId = 2; });
    f.engines[0].onCode('0123'); assert.equal(f.results.length, 0); assert.equal(f.modal.style.display, 'grid'); await f.close();
});

test('unavailable targets, hidden parents, aborted pages and pending product/stock saves cannot open a camera', async () => {
    for (const blocked of ['disabled', 'disconnected', 'hidden', 'aborted', 'create', 'edit', 'stock']) {
        const f = fixture();
        if (blocked === 'disabled') f.target.disabled = true;
        if (blocked === 'disconnected') f.target.isConnected = false;
        if (blocked === 'hidden') f.parent.style.display = 'none';
        if (blocked === 'aborted') f.signal.aborted = true;
        if (blocked === 'create') f.context.invIsCreatingProduct = true;
        if (blocked === 'edit') f.context.invIsUpdatingProduct = true;
        if (blocked === 'stock') f.context.invStockSavePending = true;
        await f.open(); assert.equal(f.modal.style.display, 'none'); assert.equal(f.engines.length, 0);
    }
});

test('responsive barcode frame fits a narrow reader and remains bounded on a tablet', async () => {
    const f = fixture(); await ready(f); const box = f.engines[0].config.qrbox;
    assert.equal(f.engines[0].camera.facingMode, 'environment');
    assert.deepEqual({ ...box(234, 200) }, { width: 210, height: 140 });
    assert.deepEqual({ ...box(586, 400) }, { width: 280, height: 140 }); await f.close();
});

test('scanner Tab wraps its own controls and focused status; Escape keeps the parent open and honors consumed keys', async () => {
    const f = fixture(); await ready(f);
    f.node('inventory-barcode-camera-cancel').focus(); assert.equal(f.key('Tab').defaultPrevented, true);
    assert.equal(f.active.id, 'inventory-barcode-camera-close'); f.key('Tab', true);
    assert.equal(f.active.id, 'inventory-barcode-camera-cancel');
    f.node('inventory-barcode-camera-status').focus(); f.key('Tab'); assert.equal(f.active.id, 'inventory-barcode-camera-close');
    f.key('Escape', false, true); assert.equal(f.modal.style.display, 'grid');
    assert.equal(f.key('Escape').defaultPrevented, true); assert.equal(f.modal.style.display, 'none');
    assert.equal(f.parent.style.display, 'grid'); assert.equal(f.active, f.opener); await f.close();
});

test('click event arguments restore the camera opener; only explicit manual entry focuses the field', async () => {
    const f = fixture(); await ready(f); await f.context.invCloseBarcodeCamera({ type: 'click' }); assert.equal(f.active, f.opener);
    await ready(f); await f.close(true); assert.equal(f.active, f.target);
});
