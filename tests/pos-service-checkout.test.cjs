const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function preflight(product, qty) {
    const context = vm.createContext({
        activeStoreId: 1, productsCache: [], cart: [{product_id: 103, qty, price: 20, name: 'Refill'}],
        requestJson: async () => ({status: 'success', products: [{...product, is_active: 1}], allowed_item_types: ['stock_item', 'service', 'deposit']}),
        getProductById: id => context.productsCache.find(p => p.id === id),
        getProductDisplayName: p => p.name, buildCategories() {}, renderCategoryTabs() {}, refreshUi() {},
    });
    const stockRule = fs.readFileSync('public/assets/js/store-pos.js', 'utf8').match(/function productTracksStock\(product\) \{[\s\S]*?\n\}/)[0];
    vm.runInContext(stockRule, context);
    vm.runInContext(fs.readFileSync('public/assets/js/store-pos.part5.js', 'utf8'), context);
    context.refreshUi = () => {};
    return {result: await vm.runInContext('preflightCartStock()', context), cart: context.cart};
}

test('untracked water service and refundable deposit survive checkout with zero inventory', async () => {
    for (const item_type of ['service', 'deposit']) {
        const {result, cart} = await preflight({id: 103, name: 'Refill', price: 25, stock_policy: 'untracked', item_type, stock_qty: 0}, 3);
        assert.equal(result.ok, true);
        assert.equal(cart.length, 1);
        assert.equal(cart[0].qty, 3);
        assert.equal(cart[0].price, 25);
    }
});

test('tracked stock still caps quantity and removes sold-out products', async () => {
    const capped = await preflight({id: 103, name: 'Bottle', price: 25, stock_policy: 'tracked', stock_qty: 2}, 3);
    assert.equal(capped.result.ok, false);
    assert.equal(capped.cart[0].qty, 2);
    const empty = await preflight({id: 103, name: 'Bottle', price: 25, stock_policy: 'tracked', stock_qty: 0}, 1);
    assert.equal(empty.result.ok, false);
    assert.equal(empty.cart.length, 0);
});

test('untracked food still respects its daily prepared capacity', async () => {
    const {result, cart} = await preflight({id: 103, name: 'Meal', price: 25, daily_price: 25, stock_policy: 'untracked', stock_qty: 0, daily_remaining: 2}, 3);
    assert.equal(result.ok, false);
    assert.equal(cart[0].qty, 2);
});
