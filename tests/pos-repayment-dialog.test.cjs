const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm'), crypto = require('node:crypto');
const part3 = fs.readFileSync('public/assets/js/store-pos.part3.js', 'utf8');
const part4 = fs.readFileSync('public/assets/js/store-pos.part4.js', 'utf8');
const main = fs.readFileSync('public/assets/js/store-pos.js', 'utf8');
function extract(source, name) {
    let start = source.indexOf(`function ${name}(`);
    if (source.slice(start - 6, start) === 'async ') start -= 6;
    const end = source.slice(start + 1).search(/\n(?:async )?function /);
    return source.slice(start, end < 0 ? undefined : start + 1 + end);
}
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
function fixture() {
    const nodes = {}, frames = [], requests = [], tones = [], toasts = [], storage = new Map();
    const document = { activeElement: null, getElementById: id => nodes[id] || null, querySelector: () => ({ dataset: { userId: 7 } }), createElement: () => ({ setAttribute() {}, remove() {} }) };
    function node(id, extra = {}) {
        const classes = new Set();
        return nodes[id] = { id, value: '', disabled: false, isConnected: true, tabIndex: 0, attrs: {}, dataset: {}, style: {}, handlers: {}, innerHTML: '', textContent: '',
            focus() { if (!this.disabled) document.activeElement = this; }, getClientRects() { return modal.style.display === 'none' && id.startsWith('debt-payment') ? [] : [{}]; },
            setAttribute(k, v) { this.attrs[k] = String(v); }, addEventListener(k, fn) { this.handlers[k] = fn; },
            classList: { contains: c => classes.has(c), add: c => classes.add(c), remove: c => classes.delete(c), toggle(c, force) { if (force ?? !classes.has(c)) classes.add(c); else classes.delete(c); } }, ...extra };
    }
    const opener = node('opener'); document.activeElement = opener; node('product-search'); node('result');
    const close = node('debt-payment-close'); close.classList.add('receipt-close');
    const search = node('debt-payment-search'), amount = node('debt-payment-amount'), channel = node('debt-payment-channel', { value: 'cash' });
    const destination = node('debt-payment-destination'); node('debt-payment-destination-wrap');
    const reference = node('debt-payment-reference'), remarks = node('debt-payment-remarks');
    const result = node('debt-payment-result', { tabIndex: -1 }), cancel = node('debt-payment-cancel'), save = node('debt-payment-save');
    node('debt-payment-profile'); node('debt-payment-suggestions');
    const controls = [close, search, amount, channel, destination, reference, remarks, cancel, save];
    const modal = node('debt-payment-modal', { style: { display: 'none' }, querySelector: selector => selector === '.receipt-card' ? { scrollTop: 50 } : close, querySelectorAll: () => controls });
    for (const id of ['confirm-transaction-modal', 'receipt-modal', 'barcode-scanner-modal']) node(id);
    const storageApi = { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) };
    const context = vm.createContext({ document, crypto, activeStoreId: 1, openingBalanceReady: true, repaymentCustomers: [], selectedRepaymentCustomer: null,
        paymentMethodsCache: [{ code: 'cash', label: 'Cash' }, { code: 'gcash', label: 'GCash', destination_accounts: [{ id: 3, account_name: 'Fictional account', masked_number: '***001' }] }],
        localStorage: storageApi, sessionStorage: { getItem: () => null, removeItem() {} }, navigator: { locks: { request: async (_key, _options, callback) => callback({}) } },
        window: { requestAnimationFrame: fn => frames.push(fn), setTimeout() {}, IbemsFeedbackSound: { play: tone => tones.push(tone) }, IbemsAvatar: { html: () => '<span>Avatar</span>' } },
        setTimeout: fn => frames.push(fn), formatMoney: value => `PHP ${Number(value).toFixed(2)}`, escapeHtml: value => String(value).replace(/</g, '&lt;'),
        ensureToastHost: () => ({ appendChild: node => toasts.push(node) }),
        requestJson: (url, options = {}) => { const d = deferred(); requests.push({ url, options, ...d }); return d.promise; },
        loadOpeningBalanceStatus: async () => true, loadDebtCustomers: async () => {},
    });
    vm.runInContext(part3.slice(part3.indexOf('const posCheckoutDialogStates'), part3.indexOf('\nfunction openReceiptModal')) + '\n' + part4, context);
    vm.runInContext(['showToast', 'setResult'].map(name => extract(main, name)).join('\n'), context);
    context.loadDebtCustomers = async () => true;
    context.bindPosCheckoutDialogKeys();
    const flush = () => { while (frames.length) frames.shift()(); };
    const key = (key, shiftKey = false) => modal.handlers.keydown?.({ key, shiftKey, currentTarget: modal, preventDefault() {}, stopPropagation() {} });
    const debtor = () => { context.selectedRepaymentCustomer = { id: 501, name: 'Fictional customer', current_debt: 100, credit_limit: 500 }; amount.value = '20'; };
    const recovery = () => { const payload = { store_id: 1, user_id: 501, amount: 20, payment_method: 'gcash', destination_account_id: 3, reference_no: 'DEMO', remarks: 'Fictional recovery' }; storage.set('ibems.repayment.7.1', JSON.stringify({ key: crypto.randomUUID(), payload, fingerprint: JSON.stringify(payload) })); };
    return { context, nodes, document, opener, modal, close, search, amount, channel, destination, result, save, requests, tones, toasts, storage, flush, key, debtor, recovery };
}

test('repayment dialog enters search, traps keyboard and restores opener without late hidden focus', () => {
    const f = fixture(); f.context.openDebtPaymentModal(); f.flush(); assert.equal(f.document.activeElement, f.search);
    f.save.focus(); f.key('Tab'); assert.equal(f.document.activeElement, f.close);
    f.close.focus(); f.key('Tab', true); assert.equal(f.document.activeElement, f.save);
    f.key('Escape'); assert.equal(f.modal.style.display, 'none'); assert.equal(f.document.activeElement, f.opener);
    f.context.openDebtPaymentModal(); f.context.closeDebtPaymentModal(); f.flush(); assert.equal(f.document.activeElement, f.opener);
});

test('recovery shows original destination, enters Close and resets action label on next normal entry', () => {
    const f = fixture(); f.recovery(); f.context.openDebtPaymentModal(); f.flush();
    assert.equal(f.document.activeElement, f.close); assert.equal(f.amount.disabled, true);
    assert.match(f.destination.innerHTML, /Fictional account/); assert.equal(f.destination.value, '3');
    assert.equal(f.save.textContent, 'Check unfinished payment');
    f.context.closeDebtPaymentModal(); f.storage.clear(); f.context.openDebtPaymentModal(); f.flush();
    assert.match(f.save.innerHTML, /Record Payment/); assert.equal(f.amount.disabled, false);
});

test('superseded customer search and completion after close cannot overwrite or announce current state', async () => {
    const f = fixture(); f.context.openDebtPaymentModal(); f.search.value = 'new';
    const old = f.context.loadDebtPaymentCustomers('old'), current = f.context.loadDebtPaymentCustomers('new');
    f.requests[2].resolve({ customers: [{ id: 2, name: 'Newest', current_debt: 50 }] }); await current;
    f.requests[1].reject(new Error('Obsolete failure')); await old;
    assert.match(f.nodes['debt-payment-suggestions'].innerHTML, /Newest/); assert.equal(f.result.textContent, '');
    f.context.closeDebtPaymentModal(); f.requests[0].reject(new Error('Closed dialog failure')); await Promise.resolve(); await Promise.resolve();
    assert.equal(f.result.textContent, ''); assert.equal(f.modal.style.display, 'none');
});

test('pending payment freezes controls and blocks dismissal; rejection restores controls and focuses error', async () => {
    const f = fixture(); f.context.openDebtPaymentModal(); f.flush(); f.debtor();
    const pending = f.context.submitDebtPayment(); await f.context.submitDebtPayment();
    assert.equal(f.requests.filter(r => r.options.method === 'POST').length, 1); assert.equal(f.modal.attrs['aria-busy'], 'true');
    assert.equal(f.amount.disabled, true); assert.equal(f.document.activeElement, f.close);
    f.context.closeDebtPaymentModal({ type: 'click' }); f.key('Escape'); assert.equal(f.modal.style.display, 'grid');
    f.requests.at(-1).reject(new Error('Simulated lost response')); await pending;
    assert.equal(f.document.activeElement, f.result); assert.equal(f.result.attrs.role, 'alert');
    assert.equal(f.amount.disabled, false); assert.equal(f.destination.disabled, true); assert.equal(f.modal.attrs['aria-busy'], 'false');
    assert.equal(f.storage.size, 1); assert.deepEqual(f.tones, ['error']);
});

test('confirmed payment dismisses with opener focus and emits one success toast and tone', async () => {
    const f = fixture(); f.context.openDebtPaymentModal(); f.flush(); f.debtor();
    const pending = f.context.submitDebtPayment(); f.requests.at(-1).resolve({ status: 'success', payment: { new_debt: 80 } }); await pending;
    assert.equal(f.modal.style.display, 'none'); assert.equal(f.document.activeElement, f.opener); assert.equal(f.storage.size, 0);
    assert.deepEqual(f.tones, ['success']); assert.equal(f.toasts.length, 1);
});

test('confirmed payment with false or thrown quiet refresh remains successful and gives warning guidance', async () => {
    for (const refresh of [false, new Error('Refresh failed')]) {
        const f = fixture(); f.context.openDebtPaymentModal(); f.flush(); f.debtor();
        const refreshCalls = [];
        f.context.loadOpeningBalanceStatus = async reportError => { refreshCalls.push(reportError); if (refresh instanceof Error) throw refresh; return refresh; };
        const pending = f.context.submitDebtPayment(); f.requests.at(-1).resolve({ status: 'success', payment: { new_debt: 80 } }); await pending;
        assert.equal(f.nodes.result.dataset.tone, 'warning'); assert.match(f.nodes.result.textContent, /^Debt payment recorded.*refresh failed/);
        assert.deepEqual(refreshCalls, [false]);
        assert.deepEqual(f.tones, ['success']); assert.equal(f.modal.style.display, 'none'); assert.equal(f.result.textContent, '');
    }
});

test('actual customer refresh supports quiet failure without emitting a rejected-payment alert', async () => {
    for (const reportError of [false, true]) {
        const f = fixture(); f.context.waterStationInternalOnly = false; f.context.debtCustomers = [];
        vm.runInContext(extract(part4, 'loadDebtCustomers'), f.context);
        f.context.renderDebtSuggestions = () => {};
        const pending = f.context.loadDebtCustomers('', reportError);
        f.requests[0].reject(new Error('Synthetic customer refresh failure'));
        assert.equal(await pending, false);
        assert.deepEqual(f.tones, reportError ? ['error'] : []);
        assert.equal(f.toasts.length, reportError ? 1 : 0);
    }
});

test('recovery retains a removed original destination and late responses from another store are ignored', async () => {
    const f = fixture(); f.recovery(); f.context.paymentMethodsCache = [{ code: 'cash', label: 'Cash' }];
    f.context.openDebtPaymentModal(); f.flush();
    assert.match(f.destination.innerHTML, /Receiving account #3 \(original payment\)/);
    assert.equal(f.destination.value, '3'); assert.equal(f.destination.disabled, true);
    f.context.closeDebtPaymentModal(); f.storage.clear(); f.context.openDebtPaymentModal();
    f.context.activeStoreId = 2;
    f.requests[0].resolve({ customers: [{ id: 1, name: 'Obsolete store', current_debt: 100 }] });
    await Promise.resolve(); await Promise.resolve();
    assert.equal(f.nodes['debt-payment-suggestions'].innerHTML, ''); assert.equal(f.result.textContent, '');
});
