const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-dashboard.js', 'utf8');
const start = source.indexOf('async function adLoadAlertPage(page)');
const loader = source.slice(start, source.indexOf('\nasync function adLoadDashboard()', start));

for (const scenario of ['success', 'last-page', 'failure', 'focus-moved']) {
    test(`Admin alert pagination restores usable controls: ${scenario}`, async () => {
        const body = {};
        const elsewhere = {};
        const document = { body, activeElement: null, getElementById: id => id === 'ad-alerts-pager' ? pager : container };
        function makeButton(label, disabled = false) {
            const control = { disabled, getAttribute: () => label };
            control.focus = () => { document.activeElement = control; };
            return control;
        }
        let buttons = [makeButton('Previous alert page', true), makeButton('Next alert page')];
        const original = buttons;
        document.activeElement = buttons[1];
        const pager = { contains: element => buttons.includes(element), querySelectorAll: () => buttons };
        const container = { innerHTML: '', setAttribute: () => {}, removeAttribute: () => {} };
        let release;
        const request = new Promise(resolve => { release = resolve; });
        const context = vm.createContext({ document, adAlertsLoading: false, adAlertPage: 1,
            adFetchDashboard: async () => { await request; if (scenario === 'failure') throw Error('offline'); return {}; },
            adRenderAlerts: () => {}, adDataState: () => 'error',
            adRenderAlertPagination: () => { buttons = [makeButton('Previous alert page'), makeButton('Next alert page', scenario === 'last-page')]; },
        });
        vm.runInContext(loader, context);
        const pending = vm.runInContext('adLoadAlertPage(2)', context);
        assert.ok(original.every(control => control.disabled));
        document.activeElement = scenario === 'focus-moved' ? elsewhere : body;
        release();
        await pending;
        assert.equal(context.adAlertsLoading, false);
        if (scenario === 'failure') {
            assert.equal(original[0].disabled, true);
            assert.equal(original[1].disabled, false);
            assert.equal(document.activeElement, original[1]);
            assert.equal(container.innerHTML, 'error');
        } else if (scenario === 'focus-moved') assert.equal(document.activeElement, elsewhere);
        else assert.equal(document.activeElement, buttons[scenario === 'last-page' ? 0 : 1]);
    });
}
