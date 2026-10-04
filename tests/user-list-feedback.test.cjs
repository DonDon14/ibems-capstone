const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-user-view.js', 'utf8');
const loader = source.slice(source.indexOf('async function loadUserView()'), source.indexOf('async function openEditUser('));
function setup() {
    const elements = {}, pending = [], renders = [];
    const element = id => elements[id] ||= { value: '', attrs: {}, setAttribute(name, value) { this.attrs[name] = value; }, removeAttribute(name) { delete this.attrs[name]; } };
    const signal = { aborted: false };
    const context = vm.createContext({ uvListRequestId: 0, uvListState: 'ready', uvRows: [], URLSearchParams,
        window: { IbemsPortalNavigation: { currentSignal: signal } }, document: { getElementById: element },
        fetch: () => new Promise((resolve, reject) => pending.push({ resolve, reject })),
        renderUvDataState: (type, message) => `${type}:${message}`, applyUserFiltersAndRender: () => renders.push(context.uvRows) });
    vm.runInContext(loader, context);
    return { context, element, pending, renders, signal, load: () => vm.runInContext('loadUserView()', context) };
}
for (const failure of [false, true]) test(`Employee list ignores stale ${failure ? 'failure' : 'success'}`, async () => {
    const f = setup(); const first = f.load(), second = f.load();
    f.pending[1].resolve({ ok: true, json: async () => ({ status: 'success', data: [{ id: 2 }] }) }); await second;
    if (failure) f.pending[0].reject(Error('old error'));
    else f.pending[0].resolve({ ok: true, json: async () => ({ status: 'success', data: [{ id: 1 }] }) });
    await first;
    assert.equal(f.renders.length, 1); assert.equal(f.renders[0][0].id, 2);
    assert.equal(f.context.uvListState, 'ready');
});
test('Employee list shows unavailable on network failure and recovers', async () => {
    const f = setup(); const first = f.load();
    assert.equal(f.element('uv-body').attrs['aria-busy'], 'true');
    f.pending[0].reject(Error('offline')); await first;
    assert.equal(f.context.uvListState, 'error');
    assert.equal(f.element('uv-total-users').textContent, 'Unavailable');
    assert.match(f.element('uv-body').innerHTML, /error:.*Refresh/);
    assert.equal(f.element('uv-body').attrs['aria-busy'], undefined);
    const second = f.load(); f.pending[1].resolve({ ok: true, json: async () => ({ status: 'success', data: [] }) }); await second;
    assert.equal(f.context.uvListState, 'ready'); assert.equal(f.renders.length, 1);
});
test('Employee list ignores a response after leaving the page', async () => {
    const f = setup(); const pending = f.load(); f.signal.aborted = true;
    f.pending[0].resolve({ ok: true, json: async () => ({ status: 'success', data: [] }) }); await pending;
    assert.equal(f.renders.length, 0);
});
