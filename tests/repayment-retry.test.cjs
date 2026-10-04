const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const crypto = require('node:crypto');

const lockSets = new WeakMap();
test('confirmed repayment with failed balance refresh keeps success tone and saved guidance', async () => {
    const f = fixture(async () => ({ status: 'success', payment: { new_debt: 80 } }));
    f.context.loadOpeningBalanceStatus = async () => { throw new Error('Synthetic refresh failure'); };
    await f.submit();
    assert.deepEqual(f.tones, ['success']);
    assert.match(f.errors.at(-1), /Debt payment recorded\. Balance refresh failed/);
    assert.equal(f.controls.get('debt-payment-save').disabled, false);
});
test('rejected repayment emits error without a success tone', async () => {
    const f = fixture(async () => ({ status: 'error', message: 'Synthetic rejection' }));
    await f.submit();
    assert.deepEqual(f.tones, ['error']);
    assert.match(f.errors.at(-1), /Synthetic rejection/);
});
function sharedLocks(storage) {
    if (!lockSets.has(storage)) lockSets.set(storage, new Set());
    const held = lockSets.get(storage);
    return { async request(name, options, callback) {
        assert.equal(options.mode, 'exclusive');
        assert.equal(options.ifAvailable, true);
        if (held.has(name)) return callback(null);
        held.add(name);
        try { return await callback({ name }); }
        finally { held.delete(name); }
    } };
}

function fixture(requestJson, storage = new Map(), actor = 7, store = 1, session = new Map()) {
    const controls = new Map([
        ['debt-payment-amount', { value: '20' }],
        ['debt-payment-channel', { value: 'cash' }],
        ['debt-payment-save', { disabled: false, innerHTML: '' }],
    ]);
    const errors = [], tones = [];
    const storageApi = map => ({ getItem: key => map.get(key) || null, setItem: (key, value) => map.set(key, value), removeItem: key => map.delete(key) });
    const context = vm.createContext({ crypto, activeStoreId: store, paymentMethodsCache: [],
        focusPosCheckoutDialog() {}, setPosStoreDayDialogBusy() {},
        window: { IbemsFeedbackSound: { play: type => tones.push(type) } },
        localStorage: storageApi(storage), sessionStorage: storageApi(session),
        navigator: { locks: sharedLocks(storage) },
        selectedRepaymentCustomer: { id: 501, current_debt: 100, name: 'Synthetic customer' },
        document: { getElementById: id => controls.get(id) || null, querySelector: () => ({ dataset: { userId: actor } }) }, requestJson,
        formatMoney: String, showToast() {}, setResult: message => errors.push(message),
        escapeHtml: String,
        loadOpeningBalanceStatus: async () => {}, loadDebtCustomers: async () => {},
    });
    vm.runInContext(fs.readFileSync('public/assets/js/store-pos.part4.js', 'utf8'), context);
    context.setDebtPaymentResult = message => errors.push(message);
    context.renderDebtPaymentProfile = () => {};
    context.closeDebtPaymentModal = () => {};
    return { context, controls, errors, tones, session, open: () => vm.runInContext('openDebtPaymentModal()', context), submit: () => vm.runInContext('submitDebtPayment()', context) };
}

test('lost repayment response retains its key; a new acknowledged payment gets a new key', async () => {
    const requests = [];
    const f = fixture(async (_url, options) => {
        requests.push(JSON.parse(options.body));
        if (requests.length === 1) throw new Error('Synthetic lost response');
        return { status: 'success', payment: { new_debt: 80 } };
    });
    await f.submit();
    assert.equal(f.controls.get('debt-payment-save').disabled, false);
    await f.submit();
    assert.equal(requests[0].request_key, requests[1].request_key);
    assert.match(requests[0].request_key, /^[a-f0-9-]{36}$/);
    await f.submit();
    assert.notEqual(requests[2].request_key, requests[1].request_key);
});

test('a second repayment click during an unresolved request does not send another request', async () => {
    let finish;
    let count = 0;
    const f = fixture(() => { count++; return new Promise(resolve => { finish = resolve; }); });
    const pending = f.submit();
    await f.submit();
    assert.equal(count, 1);
    assert.equal(f.controls.get('debt-payment-save').disabled, true);
    finish({ status: 'success', payment: { new_debt: 80 } });
    await pending;
    assert.equal(f.controls.get('debt-payment-save').disabled, false);
});

test('reload recovers the original full repayment despite no customer, a lower debt and a closed day', async () => {
    const storage = new Map(); const sent = [];
    const first = fixture(async (_url, options) => { sent.push(JSON.parse(options.body)); throw new Error('Lost response'); }, storage);
    first.controls.get('debt-payment-amount').value = '100';
    await first.submit();
    const reloaded = fixture(async (_url, options) => { sent.push(JSON.parse(options.body)); return { status: 'success', payment: { new_debt: 0 } }; }, storage);
    reloaded.context.selectedRepaymentCustomer = null;
    reloaded.context.openingBalanceReady = false;
    reloaded.open();
    await reloaded.submit();
    assert.deepEqual(sent[1], sent[0]);
    assert.equal(storage.size, 0);
});

test('pending repayment recovery is isolated to its actor and store', async () => {
    const storage = new Map();
    const first = fixture(async () => { throw new Error('Lost response'); }, storage);
    await first.submit();
    for (const [actor, store] of [[8, 1], [7, 2]]) {
        const other = fixture(async () => { throw new Error('Unexpected post'); }, storage, actor, store);
        assert.equal(vm.runInContext('readDebtPaymentIntent()', other.context), null);
    }
    assert.equal(storage.size, 1);
});

test('unavailable recovery storage prevents posting a repayment', async () => {
    let sent = 0;
    const f = fixture(async () => { sent++; });
    f.context.localStorage.setItem = () => { throw new Error('Storage unavailable'); };
    await f.submit();
    assert.equal(sent, 0);
    assert.equal(f.controls.get('debt-payment-save').disabled, false);
});

test('closing a tab loses its session but a new tab shows and recovers the durable original payment', async () => {
    const storage = new Map(); const sent = [];
    const oldTab = fixture(async (_url, options) => { sent.push(JSON.parse(options.body)); throw new Error('Lost response'); }, storage);
    oldTab.controls.set('debt-payment-reference', { value: 'SYNTHETIC-879' });
    oldTab.controls.set('debt-payment-remarks', { value: 'Full recovery details' });
    oldTab.controls.get('debt-payment-amount').value = '100';
    await oldTab.submit();
    oldTab.session.clear();
    const newTab = fixture(async (_url, options) => { sent.push(JSON.parse(options.body)); return { status: 'success', payment: { new_debt: 0 } }; }, storage);
    newTab.context.selectedRepaymentCustomer = null;
    newTab.context.openingBalanceReady = false;
    // A click without first seeing recovery details only opens the original intent.
    await newTab.submit();
    assert.equal(sent.length, 1);
    assert.equal(newTab.controls.get('debt-payment-amount').value, 100);
    assert.equal(newTab.controls.get('debt-payment-amount').disabled, true);
    await newTab.submit();
    assert.deepEqual(sent[1], sent[0]);
    assert.equal(storage.size, 0);
});

test('another tab cannot post or overwrite an intent while the first tab holds the payment lock', async () => {
    const storage = new Map(); let finish; let secondPosts = 0;
    const first = fixture(() => new Promise(resolve => { finish = resolve; }), storage);
    const second = fixture(async () => { secondPosts++; }, storage);
    second.controls.get('debt-payment-amount').value = '30';
    const inFlight = first.submit();
    const original = storage.get('ibems.repayment.7.1');
    await second.submit();
    assert.equal(secondPosts, 0);
    assert.equal(storage.get('ibems.repayment.7.1'), original);
    assert.match(second.errors.at(-1), /another tab/);
    finish({ status: 'success', payment: { new_debt: 80 } });
    await inFlight;
});

test('another tab must display an unresolved payment before retrying and never replaces it with its own form', async () => {
    const storage = new Map(); const sent = [];
    const first = fixture(async (_url, options) => { sent.push(JSON.parse(options.body)); throw new Error('Lost response'); }, storage);
    const second = fixture(async (_url, options) => { sent.push(JSON.parse(options.body)); throw new Error('Lost response again'); }, storage);
    second.controls.get('debt-payment-amount').value = '30';
    await first.submit();
    const original = storage.get('ibems.repayment.7.1');
    await second.submit();
    assert.equal(sent.length, 1);
    assert.equal(second.controls.get('debt-payment-amount').value, 20);
    assert.equal(storage.get('ibems.repayment.7.1'), original);
    await second.submit();
    assert.deepEqual(sent[1], sent[0]);
});

test('a stale recovery modal cannot create a new payment after another tab resolved its original', async () => {
    const storage = new Map(); let stalePosts = 0;
    const first = fixture(async () => { throw new Error('Lost response'); }, storage);
    await first.submit();
    const stale = fixture(async () => { stalePosts++; }, storage);
    stale.open();
    const resolving = fixture(async () => ({ status: 'success', payment: { new_debt: 80 } }), storage);
    resolving.open();
    await resolving.submit();
    await stale.submit();
    assert.equal(stalePosts, 0);
    assert.equal(storage.size, 0);
    assert.match(stale.errors.at(-1), /resolved in another tab/);
});

test('legacy same-tab intent is migrated before posting and conflicting legacy state is preserved without posting', async () => {
    const storage = new Map(); const initial = fixture(async () => { throw new Error('Lost response'); }, storage);
    await initial.submit();
    const key = 'ibems.repayment.7.1'; const saved = storage.get(key);
    storage.clear();
    const legacySession = new Map([[key, saved]]);
    let posts = 0;
    const migrating = fixture(async () => {
        posts++;
        assert.equal(storage.get(key), saved);
        assert.equal(legacySession.size, 0);
        throw new Error('Lost response');
    }, storage, 7, 1, legacySession);
    migrating.open();
    await migrating.submit();
    assert.equal(posts, 1);
    const otherIntent = JSON.parse(saved); otherIntent.key = crypto.randomUUID();
    const conflictingSession = new Map([[key, JSON.stringify(otherIntent)]]);
    const conflict = fixture(async () => { posts++; }, storage, 7, 1, conflictingSession);
    await conflict.submit();
    assert.equal(posts, 1);
    assert.equal(storage.get(key), saved);
    assert.equal(conflictingSession.size, 1);
    assert.match(conflict.errors.at(-1), /conflict/);
});

test('missing or denied Web Locks prevents any repayment request', async () => {
    for (const locks of [undefined, { request: async () => { throw new Error('Locks denied'); } }]) {
        let posts = 0;
        const f = fixture(async () => { posts++; });
        f.context.navigator.locks = locks;
        await f.submit();
        assert.equal(posts, 0);
        assert.equal(f.controls.get('debt-payment-save').disabled, false);
        assert.ok(f.errors.at(-1));
    }
});

test('tampered pending payload cannot be retried with its original key', async () => {
    const storage = new Map(); const first = fixture(async () => { throw new Error('Lost response'); }, storage);
    await first.submit();
    const key = 'ibems.repayment.7.1'; const altered = JSON.parse(storage.get(key));
    altered.payload.amount = 99;
    storage.set(key, JSON.stringify(altered));
    let posts = 0;
    const recovering = fixture(async () => { posts++; }, storage);
    await recovering.submit();
    assert.equal(posts, 0);
    assert.match(recovering.errors.at(-1), /cannot be read/);
});

test('reloaded recovery modal shows the original amount and disables edits even after closing', async () => {
    const storage = new Map();
    const first = fixture(async () => { throw new Error('Lost response'); }, storage);
    await first.submit();
    const recovered = fixture(async () => {}, storage);
    recovered.context.openingBalanceReady = false;
    recovered.context.updateDebtCreditMeter = () => {};
    recovered.controls.set('submit-transaction', {});
    recovered.controls.set('open-debt-payment-modal', {});
    vm.runInContext('updateCheckoutState()', recovered.context);
    assert.equal(recovered.controls.get('open-debt-payment-modal').disabled, false);
    assert.match(recovered.controls.get('open-debt-payment-modal').innerHTML, /Check unfinished payment/);
    assert.match(recovered.controls.get('open-debt-payment-modal').title, /even when today's store day is closed/);
    assert.equal(recovered.controls.get('submit-transaction').disabled, true);
    recovered.controls.set('debt-payment-modal', { style: {} });
    recovered.context.selectedRepaymentCustomer = null;
    vm.runInContext('openDebtPaymentModal()', recovered.context);
    assert.equal(recovered.controls.get('debt-payment-modal').style.display, 'grid');
    assert.equal(recovered.controls.get('debt-payment-amount').value, 20);
    assert.equal(recovered.controls.get('debt-payment-amount').disabled, true);
    assert.equal(recovered.controls.get('debt-payment-channel').disabled, true);
    assert.equal(recovered.controls.get('debt-payment-save').textContent, 'Check unfinished payment');
});

test('corrupted recovery state fails closed without sending a payment', async () => {
    const storage = new Map([['ibems.repayment.7.1', '{invalid']]);
    let posted = 0;
    const f = fixture(async () => { posted++; }, storage);
    await f.submit();
    assert.equal(posted, 0);
    assert.equal(storage.size, 1);
});

test('explicit rejected payment clears its intent; an unknown server failure retains it', async () => {
    for (const [status, expectedSize] of [[400, 0], [500, 1]]) {
        const storage = new Map();
        const f = fixture(async () => { const error = new Error('Synthetic rejection'); error.status = status; error.data = { status: 'error' }; throw error; }, storage);
        await f.submit();
        assert.equal(storage.size, expectedSize);
    }
});
