const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const crypto = require('node:crypto');

test('confirmed checkout retains receipt and success tone when product refresh fails', async () => {
    const controls = new Map(['submit-transaction', 'confirm-proceed'].map(id => [id, { disabled: false, innerHTML: '' }]));
    const messages = [], receipts = [], tones = [], returnFocus = {};
    const context = vm.createContext({
        crypto, isSubmitting: false, cart: [{ name: 'Test item' }], splitTenderEnabled: false,
        splitTenderAmounts: new Map(), selectedDebtPin: '',
        document: { getElementById: id => controls.get(id) || null },
        window: { location: { origin: 'http://example.test' } },
        updateCheckoutState() {}, closeConfirmTransactionModal: () => returnFocus,
        setResult: (message, type) => messages.push({ message, type }),
        requestJson: async () => ({ status: 'success', transaction_id: 42, total_amount: 10 }),
        openReceiptModal: (receipt, opener) => { receipts.push(receipt); assert.equal(opener, returnFocus); },
        renderSuccessStrip: () => tones.push('success'),
        loadProducts: async () => { throw new Error('Refresh failed'); },
    });
    vm.runInContext(fs.readFileSync('public/assets/js/store-pos.part5.js', 'utf8'), context);
    await context.processConfirmedTransaction({ payload: {}, payments: [], cartSnapshot: [{ name: 'Test item' }], totalAmount: 10 });
    assert.equal(receipts[0].transactionId, '42');
    assert.deepEqual(tones, ['success']);
    assert.deepEqual(messages.map(row => row.type), ['ok', 'warning']);
    assert.match(messages.at(-1).message, /Transaction completed.*refresh failed/);
    assert.equal(context.cart.length, 0);
    assert.equal(context.isSubmitting, false);
    assert.equal(controls.get('confirm-proceed').disabled, false);
});

test('lost checkout response retains the same intent key for a retry and releases the buttons', async () => {
    const controls = new Map(['submit-transaction', 'confirm-proceed'].map(id => [id, { disabled: false, innerHTML: '' }]));
    const requests = [];
    const context = vm.createContext({
        crypto, isSubmitting: false,
        document: { getElementById: id => controls.get(id) || null },
        updateConfirmCashTender: () => true,
        updateCheckoutState() {}, setResult() {},
        requestJson: async (_url, options) => {
            requests.push(JSON.parse(options.body));
            if (requests.length === 1) throw new Error('Synthetic lost response');
            return { status: 'error', message: 'Synthetic response for UI retry test' };
        },
    });
    vm.runInContext(fs.readFileSync('public/assets/js/store-pos.part5.js', 'utf8'), context);
    context.pending = { payload: { store_id: 1 }, payments: [{ payment_method: 'cash' }] };
    await vm.runInContext('processConfirmedTransaction(pending)', context);
    assert.equal(context.isSubmitting, false);
    assert.equal(controls.get('confirm-proceed').disabled, false);
    await vm.runInContext('processConfirmedTransaction(pending)', context);
    assert.equal(requests.length, 2);
    assert.match(requests[0].request_key, /^[a-f0-9-]{36}$/);
    assert.equal(requests[0].request_key, requests[1].request_key);
    context.pending = { payload: { store_id: 1 }, payments: [{ payment_method: 'cash' }] };
    await vm.runInContext('processConfirmedTransaction(pending)', context);
    assert.notEqual(requests[2].request_key, requests[1].request_key);
});
