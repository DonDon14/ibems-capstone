const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function checkoutContext() {
    const elements = new Map([
        ['submit-transaction', { disabled: false, innerHTML: '' }],
        ['payment-method', { value: 'cash' }],
        ['department-requester-name', { value: '' }],
    ]);
    const context = vm.createContext({
        document: { getElementById: (id) => elements.get(id) || null },
        updateDebtCreditMeter() {},
        openingBalanceReady: true,
        cart: [{ product_id: 101, qty: 1 }],
        splitTenderEnabled: false,
        splitIncludesDebt: () => false,
        getDebtAccountType: () => 'employee',
        getSelectedDepartmentDebtAccount: () => null,
        getSelectedDepartmentApprover: () => null,
        requiresCheckoutCustomer: (method) => method === 'debt',
        waterStationInternalOnly: true,
        selectedDebtCustomerId: null,
    });
    vm.runInContext(fs.readFileSync('public/assets/js/store-pos.part4.js', 'utf8'), context);
    return { context, submit: elements.get('submit-transaction') };
}

test('water-station cash checkout requires a selected university account', () => {
    const { context, submit } = checkoutContext();
    vm.runInContext('updateCheckoutState()', context);
    assert.equal(submit.disabled, true);
    assert.match(submit.innerHTML, /Select University Customer/);

    vm.runInContext('selectedDebtCustomerId = 502; updateCheckoutState()', context);
    assert.equal(submit.disabled, false);
    assert.match(submit.innerHTML, /Complete Transaction/);

    vm.runInContext('waterStationInternalOnly = false; selectedDebtCustomerId = null; updateCheckoutState()', context);
    assert.equal(submit.disabled, false);
});

test('store capability response activates the water-station POS rule', async () => {
    let refreshed = 0;
    const guidance = { textContent: '' };
    const context = vm.createContext({
        activeStoreId: 91,
        waterStationInternalOnly: false,
        document: { getElementById: (id) => id === 'payment-method-help' ? guidance : id === 'payment-method' ? { value: 'cash' } : null },
        getPaymentMethodGuidance: () => 'Select a registered customer.',
        requestJson: async (url) => {
            assert.equal(url, '/store/capabilities?store_id=91');
            return { status: 'success', capabilities: ['retail', 'refill_service'] };
        },
        updateDebtCustomerVisibility: () => { refreshed += 1; },
        updateCheckoutState: () => { refreshed += 1; },
    });
    vm.runInContext(fs.readFileSync('public/assets/js/store-pos.part5.js', 'utf8'), context);
    await vm.runInContext('loadStoreCapabilities()', context);
    assert.equal(vm.runInContext('waterStationInternalOnly', context), true);
    assert.equal(refreshed, 2);
    assert.equal(guidance.textContent, 'Select a registered customer.');
});

test('water-station payment guidance does not invite walk-in sales', () => {
    const context = vm.createContext({ waterStationInternalOnly: true });
    vm.runInContext(fs.readFileSync('public/assets/js/store-pos.part2.js', 'utf8'), context);
    assert.match(vm.runInContext('getPaymentMethodGuidance("cash")', context), /registered faculty, staff, or student account/);
    assert.doesNotMatch(vm.runInContext('getPaymentMethodGuidance("cash")', context), /optional|Leave it blank/);
    vm.runInContext('waterStationInternalOnly = false', context);
    assert.match(vm.runInContext('getPaymentMethodGuidance("cash")', context), /optional/);
});
