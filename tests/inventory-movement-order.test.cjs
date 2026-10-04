const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/store-inventory.part2.js', 'utf8');
function fixture(signal) {
    const requests = [], list = {isConnected: true, innerHTML: ''};
    const context = vm.createContext({
        window: {IbemsPortalNavigation: {currentSignal: signal}},
        document: {getElementById() {return list;}},
        invGetElementValue() {return '';}, invDataState: (type, message) => `${type}:${message}`,
        fetch(url, options) {return new Promise((resolve, reject) => requests.push({options, resolve, reject}));},
        invRenderMovements() {list.innerHTML = 'rendered';},
    });
    vm.runInContext('let invActiveStoreId=4, invMovementSequence=0, invMovements=[];\n' +
        source.slice(source.indexOf('async function invLoadMovements()'), source.indexOf('async function invSubmitModalRestock()')), context);
    return {requests, list, context, load: () => vm.runInContext('invLoadMovements()', context)};
}
const response = movements => ({ok: true, json: async () => ({status: 'success', movements})});
test('older movement success and failure cannot replace newer results', async () => {
    for (const fail of [false, true]) {
        const f = fixture(), older = f.load(), newer = f.load();
        f.requests[1].resolve(response(['new']));
        assert.equal(await newer, true);
        if (fail) f.requests[0].reject(new Error('old error'));
        else f.requests[0].resolve(response(['old']));
        assert.equal(await older, false);
        assert.equal(f.list.innerHTML, 'rendered');
        assert.equal(vm.runInContext('invMovements[0]', f.context), 'new');
    }
});
test('aborted navigation ignores late stock-activity response', async () => {
    const controller = new AbortController(), f = fixture(controller.signal), pending = f.load();
    controller.abort();
    f.list.innerHTML = 'new page';
    f.requests[0].resolve(response(['old']));
    assert.equal(await pending, false);
    assert.equal(f.list.innerHTML, 'new page');
});
test('HTTP failure clears obsolete movement data and renders error', async () => {
    const f = fixture();
    vm.runInContext('invMovements=["old"]', f.context);
    const pending = f.load();
    f.requests[0].resolve({ok: false, json: async () => ({status:'success', movements:['invalid']})});
    await assert.rejects(pending, /Unable to load stock activity/);
    assert.match(f.list.innerHTML, /^error:/);
    assert.equal(vm.runInContext('invMovements.length', f.context), 0);
});
