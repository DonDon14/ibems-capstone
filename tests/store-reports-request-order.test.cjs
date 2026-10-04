const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/store-reports.js', 'utf8');
function functionSource(name, next) {
    return source.slice(source.indexOf(name), source.indexOf(next, source.indexOf(name)));
}
function setup() {
    const requests = [], renders = [], nodes = new Map();
    const context = vm.createContext({
        reportsRequestSequence: 0, reportsActiveStoreId: 3, reportsPeriod: 'today', reportsSummaryData: null,
        URLSearchParams,
        fetch: () => new Promise((resolve, reject) => requests.push({ resolve, reject })),
        document: { getElementById(id) { if (!nodes.has(id)) nodes.set(id, { textContent: '', innerHTML: '' }); return nodes.get(id); } },
        rSetResult: (message) => renders.push(['result', message]),
        rRenderTableStates: (type) => renders.push(['tables', type]),
        rSetChartState() {}, rRenderTrendBars() {}, rRenderPaymentMix() {},
        rRenderSummary: (data) => renders.push(['summary', data.summary.total_sales]),
        rRenderPaymentRows() {}, rRenderPaymentAccountRows() {}, rRenderPaymentRecords() {},
        rRenderCashMovements() {}, rRenderProductRows() {}, rRenderTrendRows() {},
        rRenderTransactions: (rows) => renders.push(['receipts', rows[0].id]),
        rDataState: (type, message) => `${type}:${message}`,
    });
    vm.runInContext(functionSource('function rClearMetrics(', 'function rSetResult('), context);
    vm.runInContext(functionSource('async function rLoadTransactions(', 'async function rOpenReceipt('), context);
    vm.runInContext(functionSource('async function rLoadSummary(', 'document.querySelectorAll(".period-chip")'), context);
    return { requests, renders, nodes, context, start: () => vm.runInContext('rLoadSummary()', context) };
}
const summary = total => ({ ok: true, json: async () => ({ status: 'success', summary: { total_sales: total }, range: { from: '2026-09-01', to: '2026-09-30' } }) });
const receipts = id => ({ ok: true, json: async () => ({ status: 'success', transactions: [{ id }] }) });
const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
for (const outcome of ['network failure', 'API failure', 'success']) test(`old summary ${outcome} cannot replace new report`, async () => {
    const qa = setup(), old = qa.start(), current = qa.start();
    qa.requests[1].resolve(summary(330)); await settle();
    qa.requests[2].resolve(receipts(2)); await current;
    const before = qa.renders.slice();
    if (outcome === 'network failure') qa.requests[0].reject(new Error('Old failure'));
    else if (outcome === 'API failure') qa.requests[0].resolve({ ok: true, json: async () => ({ status: 'error', message: 'Old failure' }) });
    else qa.requests[0].resolve(summary(999));
    await old;
    assert.deepEqual(qa.renders, before);
});
for (const outcome of ['success', 'failure']) test(`old receipt ${outcome} cannot replace current receipt state`, async () => {
    const qa = setup(), old = qa.start(); qa.requests[0].resolve(summary(100)); await settle();
    const current = qa.start(); qa.requests[2].resolve(summary(330)); await settle();
    qa.requests[3].resolve(receipts(3)); await current;
    const before = qa.renders.slice();
    if (outcome === 'success') qa.requests[1].resolve(receipts(1)); else qa.requests[1].reject(new Error('Old receipts'));
    await old;
    assert.deepEqual(qa.renders, before);
    assert.equal(qa.nodes.get('reports-transactions-body')?.innerHTML || '', '');
});
test('HTTP failure clears stale metrics instead of rendering a success payload', async () => {
    const qa = setup(); qa.nodes.set('sum-sales', { textContent: 'PHP 999.00' });
    const pending = qa.start(); assert.equal(qa.nodes.get('sum-sales').textContent, 'Loading…');
    qa.requests[0].resolve({ ok: false, json: async () => ({ status: 'success', summary: { total_sales: 999 } }) });
    await pending;
    assert.equal(qa.nodes.get('sum-sales').textContent, 'Unavailable');
    assert.equal(qa.context.reportsSummaryData, null);
    assert.equal(qa.renders.some(row => row[0] === 'summary'), false);
});
test('selecting Custom invalidates pending summary and clears the previous totals', async () => {
    const qa = setup(), pending = qa.start();
    qa.context.document.querySelectorAll = () => [];
    qa.nodes.set('reports-custom-range', { classList: { toggle() {} } });
    vm.runInContext(functionSource('function rSetPeriod(', 'function rSetChartState('), qa.context);
    vm.runInContext('rSetPeriod("custom")', qa.context);
    const before = qa.renders.slice();
    qa.requests[0].resolve(summary(999)); await pending;
    assert.deepEqual(qa.renders, before);
    assert.equal(qa.nodes.get('sum-sales').textContent, 'Select dates');
    assert.equal(qa.context.reportsSummaryData, null);
});
