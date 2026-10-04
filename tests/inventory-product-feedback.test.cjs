const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/store-inventory.part2.js', 'utf8');
const main = fs.readFileSync('public/assets/js/store-inventory.js', 'utf8');
function fixture(signal) {
    const requests = [], nodes = new Map();
    const node = id => {
        if (!nodes.has(id)) nodes.set(id, {isConnected: true, innerHTML:'old', textContent:'old', value:'', dataset:{}, attrs:{}, disabled:false,
            setAttribute(k,v){this.attrs[k]=v;}, classList:{add() {},toggle(){}}});
        return nodes.get(id);
    };
    const context = vm.createContext({
        document:{getElementById: node}, window:{IbemsPortalNavigation:{currentSignal:signal}},
        invDataState: (type, message) => `${type}:${message}`,
        fetch() {return new Promise((resolve,reject) => requests.push({resolve,reject}));},
        invRenderProductTable() {node('inventory-product-body').innerHTML='new products';},
    });
    vm.runInContext('let invProductSequence=0, invActiveStoreId=1, invProducts=["old"], invAllowedItemTypes=[], invProductLoadState="loading";\n' +
        source.slice(source.indexOf('function invSetProductLoadState('), source.indexOf('async function invLoadCategories()')), context);
    return {context,requests,node,load:()=>vm.runInContext('invLoadProducts()',context)};
}
test('product loading and current failure clear stale result indicators', async () => {
    const f=fixture(), pending=f.load();
    assert.equal(f.node('inventory-pager').innerHTML,'');
    assert.match(f.node('inventory-results-count').textContent,/Loading/);
    assert.equal(vm.runInContext('invProducts.length',f.context),0);
    f.requests[0].reject(new Error('offline'));
    await assert.rejects(pending,/offline/);
    assert.match(f.node('inventory-product-body').innerHTML,/error:offline/);
    assert.match(f.node('inventory-results-count').textContent,/unavailable/);
});

test('filters cannot replace pending or failed products with a false empty state; Retry retains selected filters', async () => {
    const f=fixture(), originalRender=f.context.invRenderProductTable;
    const pending=f.load();
    vm.runInContext(main.slice(main.indexOf('function invRenderProductTable('),main.indexOf('function invRenderStockSummary(')),f.context);
    f.node('inventory-search').value='notebook';f.context.invRenderProductTable();
    assert.match(f.node('inventory-product-body').innerHTML,/loading/);
    assert.equal(f.node('inventory-products-region').attrs['aria-busy'],'true');
    assert.equal(f.node('refresh-inventory-products').disabled,true);
    f.requests[0].reject(Error('offline'));await assert.rejects(pending,/offline/);
    f.context.invRenderProductTable();
    assert.equal(f.node('inventory-product-body').innerHTML,'error:offline');
    assert.equal(f.node('refresh-inventory-products').disabled,false);
    assert.equal(f.node('refresh-inventory-products').textContent,'Retry products');
    f.context.invRenderProductTable=originalRender;
    const retry=f.context.invRefreshProducts();await f.context.invRefreshProducts();
    assert.equal(f.requests.length,2);
    f.requests[1].resolve({ok:true,json:async()=>({status:'success',products:[]})});await retry;
    assert.equal(f.node('inventory-search').value,'notebook');
    assert.equal(f.node('inventory-products-region').attrs['aria-busy'],'false');
    assert.equal(f.node('refresh-inventory-products').textContent,'Refresh products');
});

test('malformed successful product data remains an error and Retry rejection is handled locally',async()=>{
    const f=fixture(), pending=f.load();f.requests[0].resolve({ok:true,json:async()=>({status:'success',products:null})});
    await assert.rejects(pending,/Unable to load products/);
    assert.match(f.node('inventory-product-body').innerHTML,/error/);
    const retry=f.context.invRefreshProducts();f.requests[1].reject(Error('retry offline'));
    assert.equal(await retry,false);assert.equal(f.node('refresh-inventory-products').disabled,false);
    assert.match(f.node('inventory-product-body').innerHTML,/retry offline/);
});

test('empty store and filtered empty results have distinct accurate messages',()=>{
    const f=fixture();
    vm.runInContext(main.slice(main.indexOf('function invProductFamilyKey('),main.indexOf('function invSkuToken('))+
        main.slice(main.indexOf('function invRenderProductTable('),main.indexOf('function invMovementMeta(')),f.context);
    vm.runInContext('invProductLoadState="ready";invProducts=[];',f.context);
    f.context.invPage=1;f.context.invExpandedFamilies=new Set();f.node('inventory-sort').value='name:asc';f.node('inventory-page-size').value='25';
    f.context.invRenderProductTable();assert.match(f.node('inventory-product-body').innerHTML,/No products have been added/);
    vm.runInContext('invProducts=[{id:1,name:"Notebook",category:"General"}]',f.context);f.node('inventory-search').value='missing';
    f.context.invRenderProductTable();assert.match(f.node('inventory-product-body').innerHTML,/No products match/);
});
test('older product success and failure cannot replace newer results', async () => {
    for (const fail of [false,true]) {
        const f=fixture(), older=f.load(), newer=f.load();
        f.requests[1].resolve({ok:true,json:async()=>({status:'success',products:['new']})});
        await newer;
        if(fail) f.requests[0].reject(new Error('late error'));
        else f.requests[0].resolve({ok:true,json:async()=>({status:'success',products:['old']})});
        assert.equal(await older,false);
        assert.equal(vm.runInContext('invProducts[0]',f.context),'new');
        assert.equal(f.node('inventory-product-body').innerHTML,'new products');
    }
});
