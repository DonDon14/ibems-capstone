const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = vm.createContext({ window: {}, Date });
vm.runInContext(fs.readFileSync('public/assets/js/ibems-format.js', 'utf8'), context);
const format = context.window.IbemsFormat;

test('UTC database timestamp and explicit timezone instants display the same Manila confirmation time', () => {
    const options = { hour: 'numeric', minute: '2-digit', hour12: true };
    assert.equal(format.dateTime('2026-10-31 04:00:00', options), '12:00 PM');
    assert.equal(format.dateTime('2026-10-31T04:00:00Z', options), '12:00 PM');
    assert.equal(format.dateTime('2026-10-31T12:00:00+08:00', options), '12:00 PM');
});

test('UTC timestamp across Manila midnight advances the date while a business date remains unchanged', () => {
    const options = { year: 'numeric', month: '2-digit', day: '2-digit' };
    assert.equal(format.date('2026-10-31 20:00:00', options), '11/01/2026');
    assert.equal(format.date('2026-10-31', options), '10/31/2026');
    assert.equal(format.dateTime('invalid timestamp'), 'invalid timestamp');
});
