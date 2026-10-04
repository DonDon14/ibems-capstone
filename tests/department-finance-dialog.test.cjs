const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/department-debt-pages.js', 'utf8');
const helpers = source.slice(source.indexOf('const departmentModalOpeners'), source.indexOf('function initAdmin()'));
test('Finance dialog enters, contains, closes through its handler and restores focus', () => {
    const document = { activeElement: null, body: { classList: { toggle() {} } }, getElementById: () => modal };
    const control = () => ({ tabIndex: 0, isConnected: true, getClientRects: () => [1], focus() { document.activeElement = this; } });
    const opener = control(), close = control(), save = control(); let keydown;
    const modal = { parentElement: document.body, classList: { toggle() {} },
        querySelector: () => close, querySelectorAll: () => [close, save], addEventListener: (name, fn) => { keydown = fn; } };
    const context = vm.createContext({ document, window: {} });
    vm.runInContext(helpers, context);
    close.click = () => vm.runInContext('setDepartmentModalOpen(document.getElementById(), false)', context);
    document.activeElement = opener;
    vm.runInContext('mountDepartmentModal("department-finance-modal");setDepartmentModalOpen(document.getElementById(), true)', context);
    assert.equal(document.activeElement, close);
    keydown({ key: 'Tab', shiftKey: true, preventDefault() {} }); assert.equal(document.activeElement, save);
    keydown({ key: 'Tab', preventDefault() {} }); assert.equal(document.activeElement, close);
    keydown({ key: 'Escape', preventDefault() {} }); assert.equal(document.activeElement, opener);
});
