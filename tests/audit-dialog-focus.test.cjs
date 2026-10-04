const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-audit.js', 'utf8');
const start = source.indexOf('function auditOpenModal()');
const helpers = source.slice(start, source.indexOf('function auditOpenDetail', start));
const keyStart = source.indexOf('document.addEventListener("keydown",');
const keys = source.slice(keyStart, source.indexOf('\nauditLoad();', keyStart));
test('Audit dialog enters, traps and restores keyboard focus', () => {
    const hidden = new Set(['is-hidden']);
    let handler;
    const document = { activeElement: null, body: { classList: { add() {}, remove() {} } },
        getElementById: id => id === 'audit-view-modal' ? modal : id === 'audit-view-content' ? content : close,
        addEventListener: (name, fn) => { handler = fn; } };
    const control = () => ({ tabIndex: 0, getClientRects: () => [1], focus() { document.activeElement = this; } });
    const close = control(), content = control(), opener = { ...control(), isConnected: true };
    content.scrollTop = 150;
    const modal = { classList: { add: value => hidden.add(value), remove: value => hidden.delete(value), contains: value => hidden.has(value) },
        querySelectorAll: () => [close, content] };
    document.activeElement = opener;
    const context = vm.createContext({ document, auditModalOpener: null });
    vm.runInContext(helpers + keys, context);
    vm.runInContext('auditOpenModal()', context);
    assert.equal(document.activeElement, close);
    assert.equal(content.scrollTop, 0);
    assert.ok(!hidden.has('is-hidden'));
    const key = (value, shiftKey = false) => { let prevented = false; handler({ key: value, shiftKey, preventDefault() { prevented = true; } }); return prevented; };
    assert.ok(key('Tab', true));
    assert.equal(document.activeElement, content);
    assert.ok(key('Tab'));
    assert.equal(document.activeElement, close);
    assert.ok(key('Escape'));
    assert.equal(document.activeElement, opener);
    assert.ok(hidden.has('is-hidden'));
    assert.equal(key('Tab'), false);
});
