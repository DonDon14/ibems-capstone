const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
function resolve(url, stores, defaultId) {
    const context = vm.createContext({URL, window:{location:{href:url}}});
    vm.runInContext(fs.readFileSync('public/assets/js/store-scope.js','utf8'), context);
    return context.window.IbemsStoreScope.resolve(stores, defaultId);
}
test('explicit second store overrides first-store defaults', () => {
    assert.equal(resolve('http://localhost/store/inventory?store_id=2', [{id:1},{id:2}], 1), 2);
});
test('unassigned and malformed stores fail closed', () => {
    for (const id of ['9','0','abc','1.5','']) assert.throws(() => resolve('http://localhost/store/settings?store_id='+id, [{id:1}], 1));
});
test('unscoped entry uses accessible default', () => {
    assert.equal(resolve('http://localhost/store/inventory', [{id:1},{id:2}], 2), 2);
});
