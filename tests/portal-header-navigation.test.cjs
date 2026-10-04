const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/user-navigation.js', 'utf8');
const replacement = source.slice(source.indexOf('    async function replacePage('), source.indexOf('\n    async function ', source.indexOf('    async function replacePage(') + 1));

function fixture(header = { innerHTML: '<h2>Cashier Office</h2><p>Collecting Officer</p>' }) {
    const title = { innerHTML: '<h2>Cashier Office</h2><p>Finance Reports</p>' };
    const main = { className: 'old', innerHTML: 'old' };
    const calls = [];
    const nextDocument = { title: 'Deductions', querySelector: selector => selector === '.topbar-title' ? header : selector === '[data-portal-page-content]' ? { className: 'new', innerHTML: 'Salary deductions' } : { dataset: { portalNavigationKey: 'cashier' } } };
    const context = vm.createContext({
        main, navigationKey: 'cashier', pageRuntime: {},
        document: { title: 'Reports', querySelector: () => title, dispatchEvent() {} },
        CustomEvent: function () {},
        ensurePageStyles: async () => () => calls.push('styles released'),
        disposePageRuntime: async () => calls.push('old page disposed'),
        createPageRuntime: () => ({}), updateBodyPageClasses() {}, updateCsrfMetadata() {},
        updatePortalContext() {}, updateNavigationState() {}, executePageScripts: async () => calls.push('destination initialized'),
    });
    vm.runInContext(replacement, context);
    return { title, main, context, calls, run: () => context.replacePage(nextDocument, new URL('http://example.test/deductions/workspace')) };
}

test('partial portal navigation adopts destination header and initializes new page', async () => {
    const ui = fixture(); await ui.run();
    assert.match(ui.title.innerHTML, /Collecting Officer/);
    assert.doesNotMatch(ui.title.innerHTML, /Finance Reports/);
    assert.equal(ui.context.document.title, 'Deductions');
    assert.equal(ui.main.innerHTML, 'Salary deductions');
    assert.deepEqual(ui.calls, ['old page disposed', 'destination initialized', 'styles released']);
});

test('destination without a header preserves the current header without interrupting navigation', async () => {
    const ui = fixture(null); await ui.run();
    assert.match(ui.title.innerHTML, /Finance Reports/);
    assert.equal(ui.main.innerHTML, 'Salary deductions');
});
