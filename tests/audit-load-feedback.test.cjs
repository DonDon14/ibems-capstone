const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-audit.js', 'utf8');
const loader = source.slice(source.indexOf('async function auditLoad()'), source.indexOf('function auditDetailItem'));
function setup() {
    const elements = new Map();
    const element = id => {
        if (!elements.has(id)) elements.set(id, { value: '', textContent: '', innerHTML: '', attrs: {}, contains: () => false,
            querySelector:()=>null,setAttribute(name, value) { this.attrs[name] = value; }, removeAttribute(name) { delete this.attrs[name]; } });
        return elements.get(id);
    };
    const requests = [], renders = [], messages = [];
    const signal = { aborted: false };
    const context = vm.createContext({ auditRequestId: 0, auditPage: 1, auditRows: [], URLSearchParams,
        window: { IbemsPortalNavigation: { currentSignal: signal } }, document: { getElementById: element },
        fetch: () => new Promise((resolve, reject) => requests.push({ resolve, reject })),
        auditSetResult: message => messages.push(message), auditSetOptions() {}, auditRenderSummary() {},
        auditSetDateValidity(){},auditSetSummaryState(value){for(const id of ['audit-total-events','audit-today-events','audit-actor-count','audit-action-count'])element(id).textContent=value;},
        auditSetListState(type,message,detail=''){element('audit-body').innerHTML=`${type}:${message} ${detail} Retry`;},
        auditRenderRows: rows => renders.push(rows), auditRenderPager() {} });
    vm.runInContext(loader, context);
    return { context, element, requests, renders, messages, signal, load: () => vm.runInContext('auditLoad()', context) };
}
for (const staleFailure of [false, true]) test(`Audit latest request wins over stale ${staleFailure ? 'failure' : 'success'}`, async () => {
    const f = setup(); const first = f.load(), second = f.load();
    f.requests[1].resolve({ ok: true, json: async () => ({ status: 'success', data: [{ id: 2 }] }) }); await second;
    if (staleFailure) f.requests[0].reject(Error('old error'));
    else f.requests[0].resolve({ ok: true, json: async () => ({ status: 'success', data: [{ id: 1 }] }) });
    await first;
    assert.equal(f.renders.length, 1); assert.equal(f.renders[0][0].id, 2);
    assert.ok(!f.messages.includes('old error'));
});
test('Audit failure shows unavailable rather than a successful zero', async () => {
    const f = setup(); const pending = f.load();
    assert.equal(f.element('audit-body').attrs['aria-busy'], 'true');
    f.requests[0].reject(Error('offline')); await pending;
    assert.match(f.element('audit-body').innerHTML, /unavailable.*Retry/);
    assert.equal(f.element('audit-total-events').textContent, 'Unavailable');
    assert.equal(f.element('audit-body').attrs['aria-busy'], undefined);
    assert.equal(f.renders.length, 0);
});
test('Invalid date range invalidates an earlier pending result', async () => {
    const f = setup(); const pending = f.load();
    f.element('audit-date-from').value = '2026-10-03'; f.element('audit-date-to').value = '2026-10-01';
    await f.load();
    f.requests[0].resolve({ ok: true, json: async () => ({ status: 'success', data: [{ id: 1 }] }) }); await pending;
    assert.equal(f.renders.length, 0); assert.match(f.element('audit-body').innerHTML, /start date/);
});
test('Departed audit page ignores its pending response', async () => {
    const f = setup(); const pending = f.load(); f.signal.aborted = true;
    f.requests[0].resolve({ ok: true, json: async () => ({ status: 'success', data: [] }) }); await pending;
    assert.equal(f.renders.length, 0);
});

for (const failure of [false, true]) test(`Audit pagination returns focus after ${failure ? 'failure' : 'success'}`, async () => {
    const f = setup();
    const document = f.context.document;
    document.body = {};
    document.activeElement = { getAttribute: () => 'Next audit page' };
    const pager = f.element('audit-pager');
    pager.contains = () => true;
    const next = { disabled: false, getAttribute: () => 'Next audit page', focus() { document.activeElement = this; } };
    pager.querySelectorAll = () => failure ? [] : [next];
    const refresh = f.element('audit-refresh-btn');
    refresh.focus = () => { document.activeElement = refresh; };
    const pending = f.load();
    document.activeElement = document.body;
    if (failure) f.requests[0].reject(Error('offline'));
    else f.requests[0].resolve({ ok: true, json: async () => ({ status: 'success', data: [] }) });
    await pending;
    assert.equal(document.activeElement, failure ? refresh : next);
});

test('Audit reset synchronizes controls and makes one load request', () => {
    const controls = new Map(); let loads = 0;
    const context = vm.createContext({ auditResetting: false, auditPage: 3, Event,
        document: { getElementById(id) {
            if (!controls.has(id)) controls.set(id, { value: 'old', dispatchEvent() {
                assert.equal(context.auditResetting, true); this.displayed = this.value;
            } });
            return controls.get(id);
        } }, auditLoad() { loads++; } });
    const start = source.indexOf('function auditReset()');
    vm.runInContext(source.slice(start, source.indexOf('document.getElementById("audit-search-btn")', start)), context);
    vm.runInContext('auditReset()', context);
    assert.equal(controls.get('audit-sort').displayed, 'date:desc');
    assert.equal(controls.get('audit-limit').displayed, '100');
    assert.equal(context.auditResetting, false);
    assert.equal(context.auditPage, 1);
    assert.equal(loads, 1);
});
