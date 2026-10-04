const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/admin-store-details.js', 'utf8');
const start = source.indexOf('document.addEventListener("click", (event) => {');
const handler = source.slice(start, source.indexOf('document.addEventListener("submit"', start));

for (const lastPage of [false, true]) {
    test(`Store detail pager restores focus${lastPage ? ' at the last page' : ' while Next remains available'}`, () => {
        let listener, focused, rendered = 0;
        const controls = ['Previous', 'Next'].map(direction => ({
            disabled: direction === 'Next' && lastPage,
            getAttribute: () => `${direction} sessions page`,
            focus: options => { focused = { direction, options }; }
        }));
        const context = vm.createContext({
            sdPages: { sessions: 1 },
            document: {
                addEventListener: (_type, callback) => { listener = callback; },
                getElementById: () => ({ querySelectorAll: () => controls })
            },
            sdRenderSessionHistory: () => { rendered++; },
            sdRenderInventory() {}, sdRenderTransactions() {}
        });
        vm.runInContext(handler, context);
        const button = {
            disabled: false,
            getAttribute: name => ({ 'data-sd-page-section': 'sessions', 'aria-label': 'Next sessions page', 'data-sd-page': '2' })[name]
        };
        listener({ target: { closest: selector => selector === '[data-sd-page-section]' ? button : null } });
        assert.equal(rendered, 1);
        assert.equal(context.sdPages.sessions, 2);
        assert.equal(focused.direction, lastPage ? 'Previous' : 'Next');
        assert.equal(focused.options, undefined, 'Native focus keeps the rebuilt pager visible');
        button.disabled = true;
        listener({ target: { closest: selector => selector === '[data-sd-page-section]' ? button : null } });
        assert.equal(rendered, 1, 'Disabled controls cannot advance the page');
    });
}
