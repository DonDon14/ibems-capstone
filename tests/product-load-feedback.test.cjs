const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-products.js', 'utf8');
const loader = source.slice(source.indexOf('async function apLoad()'), source.indexOf('let apModalOpener'));
for (const scenario of ['stale', 'failure', 'departed']) test(`Product load protects ${scenario} state`, async () => {
    const elements = {}, requests = [], rendered = [];
    const element = id => elements[id] ||= { value: '', checked: false, attrs: {}, classList: {toggle() {}}, querySelector: () => null, contains: () => false, setAttribute(name, value) { this.attrs[name] = value; }, removeAttribute(name) { delete this.attrs[name]; } };
    const signal = { aborted: false };
    const context = vm.createContext({ apRequestId: 0, apPage: 1, apRows: [], URLSearchParams,
        window: { IbemsPortalNavigation: { currentSignal: signal } }, document: { getElementById: element },
        fetch: () => new Promise((resolve, reject) => requests.push({ resolve, reject })),
        apEscape: value => String(value), apSetResult() {}, apRenderFilters() {}, apRenderSummary() {}, apRenderPager() {}, apRenderTable: rows => rendered.push(rows) });
    vm.runInContext(source.slice(source.indexOf('function apSetListState'),source.indexOf('function apRenderTable')) + loader, context);
    const first = vm.runInContext('apLoad()', context);
    if (scenario === 'stale') {
        const second = vm.runInContext('apLoad()', context);
        requests[1].resolve({ ok: true, json: async () => ({ status: 'success', data: [{ id: 2 }] }) }); await second;
    }
    if (scenario === 'departed') signal.aborted = true;
    if (scenario === 'failure') requests[0].reject(Error('offline'));
    else requests[0].resolve({ ok: true, json: async () => ({ status: 'success', data: [{ id: 1 }] }) });
    await first;
    if (scenario === 'stale') { assert.equal(rendered.length, 1); assert.equal(rendered[0][0].id, 2); }
    else if (scenario === 'failure') { assert.match(element('ap-body').innerHTML, /unavailable.*Retry/); assert.equal(element('ap-visible-products').textContent, 'Unavailable'); }
    else assert.equal(rendered.length, 0);
});
