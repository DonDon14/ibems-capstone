const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm');
const main = fs.readFileSync('public/assets/js/store-pos.js', 'utf8');
const part2 = fs.readFileSync('public/assets/js/store-pos.part2.js', 'utf8');
const part3 = fs.readFileSync('public/assets/js/store-pos.part3.js', 'utf8');
function extract(source, name) {
    const match = new RegExp('(?:async )?function ' + name + '\\(').exec(source);
    const end = source.slice(match.index + 1).search(/\n(?:async )?function /);
    return source.slice(match.index, end < 0 ? undefined : match.index + 1 + end);
}
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
function fixture() {
    const frames = [], nodes = {}, requests = [], outcomes = [];
    const document = { activeElement: null, getElementById: id => nodes[id], querySelectorAll: selector => selector.includes('data-opening') ? [openingAccount] : selector.includes('data-closing-account') ? [closingAccount] : [] };
    function node(id, extra = {}) {
        const classes = new Set();
        return nodes[id] = { id, disabled: false, isConnected: true, tabIndex: 0, value: '0', dataset: {}, style: {}, attrs: {}, handlers: {}, scrollTop: 80,
            focus() { if (!this.disabled) document.activeElement = this; }, getClientRects: () => [{}],
            setAttribute(k, v) { this.attrs[k] = String(v); }, addEventListener(k, fn) { this.handlers[k] = fn; },
            classList: { contains: c => classes.has(c), add: c => classes.add(c), remove: c => classes.delete(c) }, ...extra };
    }
    const opener = node('store-day-opener'), other = node('other-opener'); node('product-search'); document.activeElement = opener;
    const openingCash = node('opening-balance-input', { value: '100' }), note = node('opening-balance-note', { value: '' });
    const openingAccount = node('opening-account', { value: '50', dataset: { openingAccountId: '1' } });
    const openingToggle = node('opening-count-toggle'); node('opening-denomination-counter');
    const closingCash = node('closing-cash-input', { value: '100' }), closingNote = node('closing-note-input', { value: '' });
    const closingAccount = node('closing-account', { value: '50', dataset: { closingAccountId: '1' } });
    const openSave = node('opening-balance-save'), closeSave = node('store-day-close-save');
    const openClose = node('opening-balance-close'), closeClose = node('store-day-close-x');
    openClose.classList.add('receipt-close'); closeClose.classList.add('receipt-close');
    const openCancel = node('opening-balance-cancel'), closeCancel = node('store-day-close-cancel');
    const openResult = node('opening-balance-result', { tabIndex: -1 }), closeResult = node('store-day-close-result', { tabIndex: -1 });
    for (const id of ['opening-balance-title', 'opening-balance-description', 'opening-balance-label', 'store-day-reopen-preview', 'opening-balance-note-label', 'opening-payment-account-balances', 'store-day-close-summary', 'closing-ecash-input']) node(id);
    function modal(id, close, controls) {
        const card = { scrollTop: 80 }, body = { scrollTop: 80 };
        return node(id, { card, body, style: { display: 'none' }, querySelector: selector => selector === '.receipt-card' ? card : selector.includes('#confirm-transaction-content') ? body : selector.includes('data-denomination-target') ? openingToggle : close, querySelectorAll: () => controls });
    }
    const opening = modal('opening-balance-modal', openClose, [openClose, openingCash, openingToggle, openingAccount, note, openCancel, openSave]);
    const closing = modal('store-day-close-modal', closeClose, [closeClose, closingCash, closingAccount, closingNote, closeCancel, closeSave]);
    for (const id of ['confirm-transaction-modal', 'receipt-modal', 'barcode-scanner-modal']) node(id);
    const context = vm.createContext({
        document, window: { requestAnimationFrame: fn => frames.push(fn) },
        openingBalanceMode: 'create', openingBalanceReady: false, openingBalanceSubmitting: false, storeDayCloseSubmitting: false, storeDayCloseOpenVersion: 0, activeStoreId: 1,
        currentDaySession: { status: 'open', expected_cash: 100, expected_ecash: 50 }, paymentMethodsCache: [],
        escapeHtml: String, formatMoney: v => `PHP ${v}`, formatDateTime: String,
        showToast() {}, setResult: (message, tone) => outcomes.push({ message, tone }), setPosTransactionEnabled() {}, updateOpeningBalanceDisplay() {},
        loadOpeningBalanceStatus: async () => true, renderStoreDayCloseReconciliation() {}, updateStoreDayCloseVariance() {},
        requestJson(url, options) { const request = deferred(); requests.push({ ...request, url, options }); return request.promise; },
    });
    const helpers = part3.slice(part3.indexOf('const posCheckoutDialogStates'), part3.indexOf('function openReceiptModal('));
    vm.runInContext(helpers + ['setOpeningBalanceResult', 'openOpeningBalanceModal', 'closeOpeningBalanceModal'].map(n => extract(main, n)).join('\n') + ['saveOpeningBalance', 'setStoreDayCloseResult', 'closeDayValue', 'openStoreDayCloseModal', 'closeStoreDayCloseModal', 'saveStoreDayClose'].map(n => extract(part2, n)).join('\n'), context);
    context.bindPosCheckoutDialogKeys();
    function flush() { while (frames.length) frames.shift()(); }
    function key(modal, key, shiftKey = false) { modal.handlers.keydown({ currentTarget: modal, key, shiftKey, preventDefault() {}, stopPropagation() {} }); }
    return { context, document, opener, other, opening, closing, openingCash, openingToggle, openingAccount, note, closingCash, closingNote, openSave, closeSave, openClose, closeClose, openCancel, closeCancel, openResult, closeResult, requests, outcomes, flush, key };
}

test('opening and reopening choose enabled entry, wrap focus and cancel stale frames', () => {
    const f = fixture(); f.context.openOpeningBalanceModal(); f.flush();
    assert.equal(f.document.activeElement, f.openingCash); assert.equal(f.opening.body.scrollTop, 0);
    f.openSave.focus(); f.key(f.opening, 'Tab'); assert.equal(f.document.activeElement, f.openClose);
    f.key(f.opening, 'Tab', true); assert.equal(f.document.activeElement, f.openSave);
    f.key(f.opening, 'Escape'); assert.equal(f.document.activeElement, f.opener);
    f.context.openingBalanceMode = 'reopen'; f.context.openOpeningBalanceModal({ opening_cash: 100 }); f.flush();
    assert.equal(f.document.activeElement, f.openClose); assert.equal(f.openingCash.disabled, true);
    assert.equal(f.openingToggle.disabled, true); assert.equal(f.openingToggle.attrs['aria-expanded'], 'false');
    f.context.closeOpeningBalanceModal(); f.context.openOpeningBalanceModal(); f.context.closeOpeningBalanceModal(); f.flush();
    assert.equal(f.document.activeElement, f.opener);
    f.other.focus(); f.context.closeOpeningBalanceModal(); assert.equal(f.document.activeElement, f.other);
});

test('closing asynchronous entry retains the opener captured before loading', async () => {
    const f = fixture(), load = deferred(); f.context.loadOpeningBalanceStatus = () => load.promise;
    const open = f.context.openStoreDayCloseModal(); f.other.focus(); load.resolve(); await open; f.flush();
    assert.equal(f.document.activeElement, f.closeClose); assert.equal(f.closing.body.scrollTop, 0);
    f.key(f.closing, 'Escape'); assert.equal(f.document.activeElement, f.opener);
});

test('dismissed or superseded closing loads cannot reopen the dialog', async () => {
    const f = fixture(), loads = [deferred(), deferred(), deferred()]; let i = 0;
    f.context.loadOpeningBalanceStatus = () => loads[i++].promise;
    const dismissed = f.context.openStoreDayCloseModal(); f.context.closeStoreDayCloseModal(); loads[0].resolve(); await dismissed; f.flush();
    assert.equal(f.closing.style.display, 'none');
    const old = f.context.openStoreDayCloseModal(); f.other.focus(); const latest = f.context.openStoreDayCloseModal();
    loads[2].resolve(); await latest; f.flush(); loads[1].resolve(); await old;
    f.context.closeStoreDayCloseModal(); assert.equal(f.document.activeElement, f.other);
});

test('pending opening blocks repeated save and dismissal; failure restores controls and focuses its alert', async () => {
    const f = fixture(); f.context.openOpeningBalanceModal(); f.flush();
    const pending = f.context.saveOpeningBalance(); await f.context.saveOpeningBalance();
    assert.equal(f.requests.length, 1); assert.equal(f.opening.attrs['aria-busy'], 'true');
    assert.equal(f.openingCash.disabled, true); assert.equal(f.document.activeElement, f.openClose);
    f.context.closeOpeningBalanceModal({ type: 'click' }); f.key(f.opening, 'Escape');
    assert.equal(f.opening.style.display, 'grid');
    f.requests[0].reject(new Error('Synthetic opening failure')); await pending;
    assert.equal(f.openingCash.disabled, false); assert.equal(f.openSave.disabled, false);
    assert.equal(f.document.activeElement, f.openResult); assert.equal(f.openResult.attrs.role, 'alert');
    assert.equal(f.opening.attrs['aria-busy'], 'false');
});

test('reopening failure preserves original disabled balances and reason validation sends no request', async () => {
    const f = fixture(); f.context.openingBalanceMode = 'reopen'; f.context.openOpeningBalanceModal({ opening_cash: 100 }); f.flush();
    await f.context.saveOpeningBalance(); assert.equal(f.requests.length, 0); assert.equal(f.document.activeElement, f.note);
    f.note.value = 'Fictional review reason'; const pending = f.context.saveOpeningBalance();
    f.requests[0].reject(new Error('Synthetic reopen failure')); await pending;
    assert.equal(f.openingCash.disabled, true); assert.equal(f.openingAccount.disabled, true); assert.equal(f.openingToggle.disabled, true); assert.equal(f.note.disabled, false);
});

test('pending close blocks repeated save and dismissal; failed close remains visible with actionable error', async () => {
    const f = fixture(); await f.context.openStoreDayCloseModal(); f.flush();
    const pending = f.context.saveStoreDayClose(); await f.context.saveStoreDayClose(); assert.equal(f.requests.length, 1);
    f.context.closeStoreDayCloseModal({ type: 'click' }); f.key(f.closing, 'Escape'); assert.equal(f.closing.style.display, 'grid');
    f.requests[0].reject(new Error('Synthetic close failure')); await pending;
    assert.equal(f.document.activeElement, f.closeResult); assert.equal(f.closeSave.disabled, false); assert.equal(f.closingCash.disabled, false);
    f.context.closeStoreDayCloseModal(); assert.equal(f.document.activeElement, f.opener);
});

test('confirmed opening and closing dismiss pending dialogs without losing original focus', async () => {
    const f = fixture(); f.context.openOpeningBalanceModal(); f.flush();
    const opening = f.context.saveOpeningBalance(); f.requests[0].resolve({ status: 'success', session: { status: 'open', expected_cash: 100, expected_ecash: 50 } }); await opening;
    assert.equal(f.opening.style.display, 'none'); assert.equal(f.document.activeElement, f.opener);
    await f.context.openStoreDayCloseModal(); f.flush();
    const closing = f.context.saveStoreDayClose(); f.requests[1].resolve({ status: 'success', session: { status: 'closed' } }); await closing;
    assert.equal(f.closing.style.display, 'none'); assert.equal(f.document.activeElement, f.opener);
    assert.equal(f.outcomes.length, 2); assert.ok(f.outcomes.every(row => row.tone === 'ok'));
});

test('confirmed reopen retains its action label after display refresh changes the global mode', async () => {
    const f = fixture(); f.context.openingBalanceMode = 'reopen'; f.context.openOpeningBalanceModal({ opening_cash: 100 }); f.flush();
    f.note.value = 'Fictional reason'; f.context.updateOpeningBalanceDisplay = () => { f.context.openingBalanceMode = 'locked'; };
    const pending = f.context.saveOpeningBalance(); f.requests[0].resolve({ status: 'success', session: {} }); await pending;
    assert.match(f.outcomes[0].message, /^Store day reopened:/);
});

test('confirmed close with failed status refresh reports success plus a warning, never a failed close', async () => {
    for (const result of [false, new Error('Synthetic refresh failure')]) {
        const f = fixture(); await f.context.openStoreDayCloseModal(); f.flush();
        f.context.loadOpeningBalanceStatus = async reportError => { assert.equal(reportError, false); if (result instanceof Error) throw result; return result; };
        const pending = f.context.saveStoreDayClose(); f.requests[0].resolve({ status: 'success', session: { variance_cash: -5 } }); await pending;
        assert.equal(f.closing.style.display, 'none'); assert.equal(f.document.activeElement, f.opener);
        assert.equal(f.outcomes[0].tone, 'warning'); assert.match(f.outcomes[0].message, /^Store day closed/);
        assert.match(f.outcomes[0].message, /refresh failed/); assert.equal(f.closeResult.textContent, '');
    }
});

test('actual status loader suppresses error feedback only when a confirmed action requests quiet refresh', async () => {
    for (const reportError of [false, true]) {
        const f = fixture(); let toasts = 0;
        f.context.setOpeningReadinessState = () => {};
        f.context.showToast = () => { ++toasts; };
        vm.runInContext(extract(main, 'loadOpeningBalanceStatus'), f.context);
        const loading = f.context.loadOpeningBalanceStatus(reportError);
        f.requests[0].reject(new Error('Synthetic status failure'));
        assert.equal(await loading, false);
        assert.equal(toasts, reportError ? 1 : 0);
        assert.equal(Boolean(f.openResult.textContent), reportError);
        assert.equal(f.context.openingBalanceReady, false);
    }
});
