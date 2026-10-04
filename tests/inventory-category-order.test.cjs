const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/store-inventory.part2.js', 'utf8');
function fixture(signal) {
    const requests = [];
    let renders = 0;
    const context = vm.createContext({
        window: {IbemsPortalNavigation: {currentSignal: signal}},
        fetch(url, options) { return new Promise((resolve, reject) => requests.push({url, options, resolve, reject})); },
        invRenderCategorySelects() {renders++;},
    });
    vm.runInContext('let invCategorySequence=0, invActiveStoreId=4, invCategories=[];\n' +
        source.slice(source.indexOf('async function invLoadCategories()'), source.indexOf('async function invLoadMovements()')), context);
    return {context, requests, load: () => vm.runInContext('invLoadCategories()', context), renders: () => renders};
}
const response = categories => ({ok: true, json: async () => ({status: 'success', categories})});
test('newest inventory category response wins', async () => {
    const f = fixture();
    const older = f.load(), newer = f.load();
    f.requests[1].resolve(response(['new']));
    assert.equal(await newer, true);
    f.requests[0].resolve(response(['old']));
    assert.equal(await older, false);
    assert.equal(f.renders(), 1);
    assert.equal(vm.runInContext('invCategories[0]', f.context), 'new');
});
test('navigation abort ignores late category success and failure', async () => {
    for (const failure of [false, true]) {
        const controller = new AbortController(), f = fixture(controller.signal);
        const pending = f.load();
        assert.equal(f.requests[0].options.signal, controller.signal);
        controller.abort();
        if (failure) f.requests[0].reject(new Error('late failure'));
        else f.requests[0].resolve(response(['old']));
        assert.equal(await pending, false);
        assert.equal(f.renders(), 0);
    }
});
test('HTTP category failure does not replace valid categories', async () => {
    const f = fixture(), pending = f.load();
    f.requests[0].resolve({ok: false, json: async () => ({status: 'success', categories: ['invalid']})});
    await assert.rejects(pending, /Unable to load categories/);
    assert.equal(f.renders(), 0);
});
