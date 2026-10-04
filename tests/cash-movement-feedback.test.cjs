const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/store-reports.js', 'utf8');
const handler = source.slice(source.indexOf('async function rCreateCashMovement()'), source.indexOf('function rRenderProductRows'));

function setup({ rejected = false, refreshFails = false, httpFailure = false } = {}) {
    const fields = new Map(Object.entries({
        'cash-channel': 'cash', 'cash-movement-type': 'cash_in',
        'cash-movement-amount': '10', 'cash-movement-reason': 'Synthetic QA', 'cash-movement-save': ''
    }).map(([id, value]) => [id, { value, disabled: false }]));
    const tones = [], messages = [], types = [];
    let requests = 0;
    const context = vm.createContext({
        reportsActiveStoreId: 3, reportsSummaryData: { cash_drawer: { business_date: '2026-10-02' } },
        document: { getElementById: id => fields.get(id) },
        window: { IbemsFeedbackSound: { play: tone => tones.push(tone) } },
        rSetResult: (message, type) => { messages.push(message); types.push(type); },
        rLoadSummary: async () => { if (refreshFails) throw new Error('Synthetic refresh failure'); },
        fetch: async () => { requests++; return { ok: !httpFailure, json: async () => rejected ? { status: 'error', message: 'Rejected' } : { status: 'success' } }; }
    });
    vm.runInContext(handler, context);
    return { run: () => vm.runInContext('rCreateCashMovement()', context), tones, messages, types, fields, requests: () => requests };
}

test('confirmed cash movement emits success and releases save control', async () => {
    const ui = setup(); await ui.run();
    assert.deepEqual(ui.tones, ['success']);
    assert.equal(ui.messages.at(-1), 'Cash movement saved.');
    assert.equal(ui.fields.get('cash-movement-save').disabled, false);
});
test('refresh failure after confirmed save preserves success tone and saved message', async () => {
    const ui = setup({ refreshFails: true }); await ui.run();
    assert.deepEqual(ui.tones, ['success']);
    assert.match(ui.messages.at(-1), /^Cash movement saved\. Report refresh failed/);
    assert.equal(ui.types.at(-1), 'warning');
});

test('report refresh warning uses polite status and warning color', () => {
    const node = { attrs: {}, style: {}, setAttribute(key, value) { this.attrs[key] = value; } };
    const context = vm.createContext({ document: { getElementById: () => node } });
    vm.runInContext(source.slice(source.indexOf('function rSetResult('), source.indexOf('function rSetPeriod(')), context);
    context.rSetResult('Cash movement saved. Refresh failed.', 'warning');
    assert.equal(node.attrs.role, 'status');
    assert.equal(node.attrs['aria-live'], 'polite');
    assert.match(node.style.color, /feedback-warning/);
});
test('rejected cash movement emits only error and retains entered values', async () => {
    const ui = setup({ rejected: true }); await ui.run();
    assert.deepEqual(ui.tones, ['error']);
    assert.equal(ui.fields.get('cash-movement-amount').value, '10');
    assert.equal(ui.fields.get('cash-movement-save').disabled, false);
});
test('invalid cash movement does not send a request or play a completion tone', async () => {
    const ui = setup(); ui.fields.get('cash-movement-amount').value = '0'; await ui.run();
    assert.equal(ui.requests(), 0); assert.deepEqual(ui.tones, []);
});

test('HTTP failure cannot produce a cash movement success tone or clear the form', async () => {
    const ui = setup({ httpFailure: true }); await ui.run();
    assert.deepEqual(ui.tones, ['error']);
    assert.equal(ui.types.at(-1), 'error');
    assert.equal(ui.fields.get('cash-movement-amount').value, '10');
    assert.equal(ui.fields.get('cash-movement-reason').value, 'Synthetic QA');
    assert.equal(ui.fields.get('cash-movement-save').disabled, false);
});
