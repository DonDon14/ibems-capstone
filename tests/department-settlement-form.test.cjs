const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const { create } = require('../public/assets/js/department-settlement-retry.js');

const source = fs.readFileSync('public/assets/js/department-debt-pages.js', 'utf8');
const openStart = source.indexOf('    function openFinance(department, mode) {');
const openFunctions = source.slice(openStart, source.indexOf('    function updateBatchSummary()', openStart));
const submitStart = source.indexOf('    document.getElementById("department-finance-form").addEventListener("submit"');
const submitListener = source.slice(submitStart, source.indexOf('    document.getElementById("department-batch-allocation-open")', submitStart));

function fixture() {
    const values = new Map();
    const storage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) };
    let keys = 0, locked = false, loseResponse = true;
    const requests = [], postings = new Map();
    const exclusive = async task => {
        if (locked) throw new Error('Another tab is submitting');
        locked = true;
        try { return await task(); } finally { locked = false; }
    };
    const api = async (_, options) => {
        const request = JSON.parse(options.body);
        requests.push(request);
        if (!postings.has(request.request_key)) postings.set(request.request_key, { status: 'success', settlement_entry_id: postings.size + 1 });
        if (loseResponse) { loseResponse = false; throw new Error('Response lost after commit'); }
        return postings.get(request.request_key);
    };
    function tab() {
        const elements = new Map(), results = [];
        function element(id) {
            if (!elements.has(id)) elements.set(id, { value: '', disabled: false, classList: { toggle() {} }, removeAttribute(name) { delete this[name]; }, addEventListener(event, callback) { this[event] = callback; } });
            return elements.get(id);
        }
        const context = vm.createContext({
            document: { getElementById: element, querySelector: element, querySelectorAll: () => [] },
            settlementRetry: create(storage, 900, () => `synthetic-form-intent-${++keys}`, exclusive),
            financeModal: {}, pageSignal: { aborted: false }, window: { alert: message => results.push(message) },
            money: String, setDepartmentModalOpen() {}, setResult: (...args) => results.push(args),
            api, jsonOptions: payload => ({ body: JSON.stringify(payload) }), load: async () => {},
        });
        vm.runInContext('let settlementForm = null;\n' + openFunctions + submitListener, context);
        return {
            open: () => vm.runInContext('openFinance({id:1,period_id:10,code:"SYNTHETIC",outstanding_amount:50}, "settlement")', context),
            cancel: () => vm.runInContext('closeFinance()', context),
            fill: () => { element('department-settlement-reference').value = 'SYNTHETIC-REF'; element('department-settlement-remarks').value = 'Synthetic payment'; },
            submit: () => element('department-finance-form').submit({ preventDefault() {} }),
            results,
        };
    }
    return { tab, requests, postings };
}

test('real recovery form retains its original identity after another tab resolves it', async () => {
    const f = fixture();
    const tabA = f.tab();
    tabA.open(); tabA.fill();
    await tabA.submit(); // Posting commits, but response is lost.
    const tabB = f.tab();
    tabB.open(); // Real openFinance captures the pending form/key and frozen fields.
    await tabA.submit(); // Retry acknowledges original posting and clears shared storage.
    await tabB.submit(); // Real handler must use captured form, not rediscover a new intent.
    assert.equal(f.requests.length, 2);
    assert.equal(f.postings.size, 1);
    assert.equal(f.requests[0].request_key, f.requests[1].request_key);
    assert.match(tabB.results.at(-1)[1], /no longer pending/);
    tabB.cancel(); tabB.open(); tabB.fill();
    await tabB.submit(); // Deliberately reopened equal payment remains valid.
    assert.equal(f.postings.size, 2);
    assert.notEqual(f.requests.at(-1).request_key, f.requests[0].request_key);
});

test('real cancellation and page reload preserve unresolved recovery', async () => {
    const f = fixture();
    const tabA = f.tab();
    tabA.open(); tabA.fill(); await tabA.submit();
    tabA.cancel();
    await tabA.submit(); // A closed form cannot send.
    assert.equal(f.requests.length, 1);
    const reloaded = f.tab();
    reloaded.open(); await reloaded.submit();
    assert.equal(f.requests.length, 2);
    assert.equal(f.requests[0].request_key, f.requests[1].request_key);
    assert.equal(f.postings.size, 1);
});
