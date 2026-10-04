const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/store-pos.part2.js', 'utf8');
const scannerSource = source.slice(source.indexOf('function setScannerStatus('), source.indexOf('function autoSelectDebtCustomerByCode('));
function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}
function fixture() {
    const nodes = {}, engines = [], handled = [];
    const document = { activeElement: null, getElementById: id => nodes[id] };
    for (const id of ['scanner-title', 'scanner-status', 'scanner-reader', 'scanner-start', 'scanner-stop', 'open-scanner-btn', 'open-debt-scanner-btn', 'barcode-scanner-modal', 'scanner-close']) {
        nodes[id] = { id, style: { display: 'none' }, dataset: {}, disabled: false, attrs: {}, focus() { document.activeElement = this; }, setAttribute(key, value) { this.attrs[key] = value; } };
    }
    class Engine {
        constructor() { this.startGate = deferred(); this.stopCount = 0; this.clearCount = 0; engines.push(this); }
        start(_camera, _config, onCode) { this.onCode = onCode; return this.startGate.promise; }
        async stop() { this.stopCount++; }
        async clear() { this.clearCount++; }
    }
    const context = vm.createContext({
        scannerMode: 'product', scannerRunning: false, scannerEngine: null, scannerSession: null,
        scannerCleanupPromise: Promise.resolve(), scannerLastDetected: '', scannerLastDetectedAt: 0,
        document, navigator: { mediaDevices: { getUserMedia() {} } },
        window: { Html5Qrcode: Engine },
        focusPosCheckoutDialog() {}, releasePosCheckoutDialogFocus() {},
        handleDetectedScannerCode: async code => handled.push(code),
    });
    vm.runInContext(scannerSource, context);
    return { context, nodes, engines, handled };
}
const tick = () => new Promise(resolve => setImmediate(resolve));

test('scanner closed during pending start cleans up the late engine without reviving hidden UI', async () => {
    const f = fixture();
    const opening = f.context.openScannerModal();
    await tick();
    const closing = f.context.closeScannerModal();
    await tick();
    assert.equal(f.nodes['barcode-scanner-modal'].style.display, 'none');
    f.engines[0].startGate.resolve();
    await Promise.all([opening, closing]);
    assert.equal(f.context.scannerRunning, false);
    assert.equal(f.engines[0].stopCount, 1);
    assert.equal(f.engines[0].clearCount, 1);
    await f.engines[0].onCode('OLD-CODE');
    assert.deepEqual(f.handled, []);
});

test('repeated scanner open while startup is pending cannot create duplicate engines', async () => {
    const f = fixture();
    const first = f.context.openScannerModal();
    await tick();
    const second = f.context.openScannerModal('debt');
    await tick();
    assert.equal(f.engines.length, 1);
    f.engines[0].startGate.resolve();
    await Promise.all([first, second]);
    assert.equal(f.context.scannerMode, 'product');
    await f.context.closeScannerModal();
});

test('reopen waits for cancelled startup cleanup and ignores its late rejection and codes', async () => {
    const f = fixture();
    const first = f.context.openScannerModal();
    await tick();
    const closing = f.context.closeScannerModal();
    const second = f.context.openScannerModal('debt');
    await tick();
    assert.equal(f.engines.length, 1);
    f.engines[0].startGate.reject(new Error('Old camera request denied'));
    await tick();
    assert.equal(f.engines.length, 2);
    assert.equal(f.engines[0].stopCount, 1);
    assert.equal(f.engines[0].clearCount, 1);
    f.engines[1].startGate.resolve();
    await Promise.all([first, closing, second]);
    assert.equal(f.nodes['barcode-scanner-modal'].style.display, 'grid');
    assert.equal(f.context.scannerRunning, true);
    assert.match(f.nodes['scanner-status'].textContent, /Scanning/);
    await f.engines[0].onCode('STALE');
    await f.engines[1].onCode('CURRENT');
    await f.engines[1].onCode('CURRENT');
    assert.deepEqual(f.handled, ['CURRENT']);
    await f.context.closeScannerModal();
});

test('closing during slow stop hides immediately and cannot hide a later reopen', async () => {
    const f = fixture();
    const first = f.context.openScannerModal();
    await tick();
    f.engines[0].startGate.resolve();
    await first;
    const stopped = deferred();
    f.engines[0].stop = async () => { f.engines[0].stopCount++; await stopped.promise; };
    const closing = f.context.closeScannerModal();
    assert.equal(f.nodes['barcode-scanner-modal'].style.display, 'none');
    const second = f.context.openScannerModal();
    await tick();
    assert.equal(f.engines.length, 1);
    stopped.resolve();
    await tick();
    f.engines[1].startGate.resolve();
    await Promise.all([closing, second]);
    assert.equal(f.nodes['barcode-scanner-modal'].style.display, 'grid');
    assert.equal(f.context.scannerRunning, true);
    await f.context.closeScannerModal();
});

test('failed start shows an alert, releases controls and permits retry', async () => {
    const f = fixture();
    const opening = f.context.openScannerModal();
    await tick();
    f.nodes['scanner-stop'].focus();
    f.engines[0].startGate.reject(new Error('Synthetic denied permission'));
    await opening;
    assert.equal(f.nodes['scanner-status'].attrs.role, 'alert');
    assert.equal(f.nodes['scanner-status'].attrs['aria-live'], 'assertive');
    assert.equal(f.nodes['scanner-reader'].attrs['aria-busy'], 'false');
    assert.equal(f.nodes['scanner-start'].disabled, false);
    assert.equal(f.nodes['scanner-stop'].disabled, true);
    assert.equal(f.context.document.activeElement, f.nodes['scanner-start']);
    const retry = f.context.startScanner();
    await tick();
    assert.equal(f.engines.length, 2);
    f.engines[1].startGate.resolve();
    await retry;
    assert.equal(f.nodes['scanner-status'].attrs.role, 'status');
    assert.equal(f.context.scannerRunning, true);
    await f.context.closeScannerModal();
});

test('unavailable scanner library and camera API expose recoverable feedback', async () => {
    for (const unavailable of ['library', 'camera']) {
        const f = fixture();
        if (unavailable === 'library') delete f.context.window.Html5Qrcode;
        else f.context.navigator.mediaDevices = undefined;
        await f.context.openScannerModal();
        assert.equal(f.engines.length, 0);
        assert.equal(f.nodes['scanner-status'].attrs.role, 'alert');
        assert.equal(f.nodes['scanner-start'].disabled, false);
        assert.equal(f.nodes['open-scanner-btn'].disabled, false);
        assert.equal(f.context.scannerSession, null);
        assert.ok(f.nodes['scanner-status'].textContent.length > 0);
    }
});

test('cleanup failures cannot leave the dialog open or prevent a later start', async () => {
    const f = fixture();
    const opening = f.context.openScannerModal();
    await tick();
    f.engines[0].startGate.resolve();
    await opening;
    f.engines[0].stop = async () => { throw new Error('Already stopped'); };
    f.engines[0].clear = async () => { throw new Error('Synthetic clear failure'); };
    await f.context.closeScannerModal();
    assert.equal(f.nodes['barcode-scanner-modal'].style.display, 'none');
    const next = f.context.openScannerModal();
    await tick();
    f.engines[1].startGate.resolve();
    await next;
    assert.equal(f.context.scannerRunning, true);
    await f.context.closeScannerModal();
});

test('late Stop completion cannot overwrite the status of a restarted scanner', async () => {
    const f = fixture();
    const opening = f.context.openScannerModal();
    await tick();
    f.engines[0].startGate.resolve();
    await opening;
    const stopped = deferred();
    f.engines[0].stop = async () => { await stopped.promise; };
    const stoppingUi = f.context.stopScannerFromUi();
    const restarting = f.context.startScanner();
    await tick();
    assert.equal(f.engines.length, 1);
    stopped.resolve();
    await tick();
    f.engines[1].startGate.resolve();
    await Promise.all([stoppingUi, restarting]);
    assert.match(f.nodes['scanner-status'].textContent, /^Scanning/);
    assert.equal(f.nodes['scanner-start'].disabled, true);
    await f.context.closeScannerModal();
});
