const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-user-view.js', 'utf8');
const method = source.slice(source.indexOf('async function importUsersCsv()'), source.indexOf('function exportFilteredRowsToCsv()'));
for (const scenario of ['missing file', 'partial success', 'refresh failure', 'http failure']) test(`Employee CSV feedback: ${scenario}`, async () => {
    const button = { disabled: false, setAttribute() {}, removeAttribute() {} };
    const file = { files: scenario === 'missing file' ? [] : [{}], focus() { this.focused = true; } };
    const messages = [];
    let requests = 0, closed = 0;
    const context = vm.createContext({
        document: { getElementById: id => id === 'uv-import-file' ? file : button },
        FormData: class { append() {} },
        setUvImportResult: (message, type) => messages.push({ message, type }),
        setUvResult: (message, type) => messages.push({ message, type }),
        closeModal: () => { closed++; }, loadUserView: async () => scenario !== 'refresh failure',
        fetch: async () => { requests++; return { ok: scenario !== 'http failure', json: async () => ({ status: 'success', created: 2, updated: 1, invalid: scenario === 'partial success' ? 1 : 0 }) }; }
    });
    vm.runInContext(method, context);
    const pending = vm.runInContext('importUsersCsv()', context);
    if (scenario !== 'missing file') await vm.runInContext('importUsersCsv()', context);
    await pending;
    assert.equal(requests, scenario === 'missing file' ? 0 : 1);
    assert.equal(button.disabled, false);
    if (scenario === 'missing file' || scenario === 'http failure') {
        assert.equal(closed, 0); assert.equal(messages.at(-1).type, 'error');
        if (scenario === 'missing file') assert.equal(file.focused, true);
    } else {
        assert.equal(closed, 1); assert.match(messages.at(-1).message, /^Import complete/);
        assert.equal(messages.at(-1).type, 'warning');
        assert.equal(messages.some(item => item.type === 'error'), false);
    }
});
