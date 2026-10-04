const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/user-navigation.js', 'utf8');
const handler = source.slice(source.indexOf('    document.addEventListener("submit"'), source.indexOf('    window.addEventListener("popstate"'));
function run({ method = 'get', opted = true, override = false, fields = [['date', '2026-09-30']], supported = true } = {}) {
    let listener, prevented = false, visited;
    const form = { method, target: '', action: 'http://localhost/cashier/dashboard?old=1', matches: () => opted };
    vm.runInNewContext(handler, { document: { addEventListener: (k, fn) => { listener = fn; } },
        window: { location: { href: 'http://localhost/cashier/dashboard' } }, URL,
        FormData: class { constructor() { this.fields = [...fields]; } append(k, v) { this.fields.push([k, v]); } [Symbol.iterator]() { return this.fields[Symbol.iterator](); } },
        isSupportedUrl: () => supported, visit: url => { visited = url.href; } });
    listener({ target: form, defaultPrevented: false, submitter: { name: 'view', value: '1', hasAttribute: () => override }, preventDefault: () => { prevented = true; } });
    return { prevented, visited };
}
test('opted GET filters preserve successful fields and submitter with fresh query', () => {
    assert.deepEqual(run(), { prevented: true, visited: 'http://localhost/cashier/dashboard?date=2026-09-30&view=1' });
});
test('POST, unmarked and overridden forms retain native submission', () => {
    for (const options of [{ method: 'post' }, { opted: false }, { override: true }, { supported: false }, { fields: [['file', {}]] }]) {
        assert.equal(run(options).prevented, false);
    }
});
