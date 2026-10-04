const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/user-history.js', 'utf8');
function setup() {
    const nodes = new Map();
    const node = id => {
        if (!nodes.has(id)) nodes.set(id, {value: '', innerHTML: 'old', textContent: 'old'});
        return nodes.get(id);
    };
    const pending = [];
    const context = vm.createContext({URLSearchParams, CSS: {escape: x => x},
        document: {getElementById: node}, window: {},
        fetch: () => new Promise((resolve, reject) => pending.push({resolve, reject}))});
    vm.runInContext('const uhPageSignal={aborted:false}; let uhTransactionPage=1, uhCashbookPage=1, uhTransactionRequestId=0, uhCashbookRequestId=0, uhSummaryRequestId=0;', context);
    vm.runInContext(source.slice(source.indexOf('function uhMoney'), source.indexOf('function uhApplyDebtStatus')), context);
    vm.runInContext(source.slice(source.indexOf('async function loadUserTransactions'), source.indexOf('async function openReceipt')), context);
    vm.runInContext('uhSetStoreOptions=()=>{};', context);
    vm.runInContext('uhApplyDebtStatus=()=>{};', context);
    vm.runInContext(source.slice(source.indexOf('async function loadUserSummaryCards'), source.indexOf('async function loadUserTransactions')), context);
    return {node, pending, load: name => vm.runInContext(`${name}()`, context)};
}
test('reversed history dates show correction feedback without list requests', async () => {
    const qa = setup();
    qa.node('uh-date-from').value = '2026-10-03';
    qa.node('uh-date-to').value = '2026-10-01';
    await qa.load('loadUserTransactions');
    await qa.load('loadUserCashbook');
    assert.equal(qa.pending.length, 0);
    assert.match(qa.node('uh-body').innerHTML, /From date must be on or before To date/);
    assert.match(qa.node('uh-cashbook-body').innerHTML, /Adjust the date range/);
    assert.match(qa.node('uh-store-totals').innerHTML, /From date must be on or before To date/);
    assert.equal(qa.node('uh-transactions-pager').innerHTML, '');
});

test('summary clears figures on failure and ignores earlier success', async () => {
    const s = setup();
    const old = s.load('loadUserSummaryCards');
    assert.equal(s.node('uh-credit-limit').textContent, 'Loading…');
    const latest = s.load('loadUserSummaryCards');
    s.pending[1].resolve({ok: false});
    await latest;
    s.pending[0].resolve({ok: true, json: async () => ({status: 'success', summary: {credit_limit: 900}})});
    await old;
    for (const id of ['uh-credit-limit', 'uh-current-debt', 'uh-available-credit', 'uh-total-spent']) {
        assert.equal(s.node(id).textContent, 'Unavailable');
    }
});

test('invalid range prevents a pending valid-range response from restoring records', async () => {
    const qa = setup();
    const pending = qa.load('loadUserTransactions');
    qa.node('uh-date-from').value = '2026-10-03';
    qa.node('uh-date-to').value = '2026-10-01';
    await qa.load('loadUserTransactions');
    const correction = qa.node('uh-body').innerHTML;
    qa.pending[0].resolve({ok:true,json:async()=>({status:'success',data:[{id:1}],pagination:{total:1}})});
    await pending;
    assert.equal(qa.node('uh-body').innerHTML, correction);
    assert.match(correction, /From date must be on or before To date/);
    assert.equal(qa.node('uh-transactions-pager').innerHTML, '');
});
for (const [loader, body, pager] of [
    ['loadUserTransactions', 'uh-body', 'uh-transactions-pager'],
    ['loadUserCashbook', 'uh-cashbook-body', 'uh-cashbook-pager']
]) {
    test(`${loader} clears pagination and ignores obsolete failure`, async () => {
        const s = setup();
        const old = s.load(loader);
        assert.equal(s.node(pager).innerHTML, '');
        const latest = s.load(loader);
        s.pending[1].resolve({ok: true, json: async () => ({status: 'success', data: [], pagination: {total: 0}})});
        await latest;
        const html = s.node(body).innerHTML;
        s.pending[0].reject(new Error('old failure'));
        await old;
        assert.equal(s.node(body).innerHTML, html);
        assert.match(html, /data-state--empty/);
    });
    test(`${loader} rejects successful-looking HTTP error`, async () => {
        const s = setup();
        const load = s.load(loader);
        s.pending[0].resolve({ok: false, json: async () => ({status: 'success', data: []})});
        await load;
        assert.match(s.node(body).innerHTML, /role="alert" aria-live="assertive" aria-atomic="true"/);
        assert.equal(s.node(pager).innerHTML, '');
        if (loader === 'loadUserTransactions') assert.equal(s.node('uh-grand-total').textContent, 'Unavailable');
    });
}
