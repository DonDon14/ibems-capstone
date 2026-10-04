const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-stores.part2.js', 'utf8');
function fixture(fetch) {
    const nodes = {'store-search':{value:''},'store-status-filter':{value:''},'stores-gallery':{innerHTML:'old',contains:()=>false,setAttribute(){},removeAttribute(){}},'store-sort':{disabled:false},'store-refresh-btn':{disabled:false,textContent:'Refresh'}};
    const context = vm.createContext({fetch, URLSearchParams, window:{}, document:{getElementById:id=>nodes[id]},renderStoresDataState:(type,message,detail)=>`${type}:${message} ${detail||''}`,renderStores:rows=>nodes['stores-gallery'].innerHTML=JSON.stringify(rows),setStoresResult(){}});
    vm.runInContext('let storePage=1,storesData=[1]; const storesDataUrl="/stores";\n'+source.slice(source.indexOf('let storesRequestSequence'),source.indexOf('function openStoreModal')),context);
    return {nodes,load:()=>vm.runInContext('loadStores()',context)};
}
test('current network and HTTP failures replace old cards with recovery',async()=>{
    for(const fetch of [async()=>{throw Error('offline');},async()=>({ok:false})]){
        const f=fixture(fetch); await f.load();
        assert.match(f.nodes['stores-gallery'].innerHTML,/error:.*Retry/);
        assert.equal(f.nodes['store-sort'].disabled,true);
    }
});
test('obsolete search success cannot replace a newer result',async()=>{
    let finish;let calls=0;
    const f=fixture(()=>++calls===1?new Promise(resolve=>finish=resolve):Promise.resolve({ok:true,json:async()=>({status:'success',data:[{id:2}]})}));
    const old=f.load();await f.load();finish({ok:true,json:async()=>({status:'success',data:[{id:1}]})});await old;
    assert.equal(f.nodes['stores-gallery'].innerHTML,'[{"id":2}]');
    assert.equal(f.nodes['store-sort'].disabled,false);
});
test('API failure provides the same recovery guidance as network failures',async()=>{
    const f=fixture(async()=>({ok:true,json:async()=>({status:'error',message:'Store list unavailable.'})}));
    await f.load();
    assert.match(f.nodes['stores-gallery'].innerHTML,/Store list unavailable\./);
    assert.equal(f.nodes['store-sort'].disabled,true);
});
