const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const part2 = fs.readFileSync('public/assets/js/store-inventory.part2.js', 'utf8');
const part3 = fs.readFileSync('public/assets/js/store-inventory.part3.js', 'utf8');
function fixture() {
    const nodes = new Map(), requests = [], results = [], reads = [], signal = {aborted:false};
    let active;
    const node = id => {
        if (!nodes.has(id)) nodes.set(id, {id, isConnected:true, style:{display:'grid'}, disabled:false,
            textContent:id, value:'', dataset:{}, hidden:false, attributes:{},
            setAttribute(name,value) {this.attributes[name]=value;}, removeAttribute(name) {delete this.attributes[name];},
            classList:{add(){},remove(){},toggle(){}},
            focus() {active=this;}, querySelectorAll() {return [...nodes.values()].filter(n=>n.control);}});
        return nodes.get(id);
    };
    const controls = ['close-product-action-modal','modal-save-adjustment','modal-restock-submit','modal-start-edit-product',
        'modal-actual-stock','modal-stock-reason','modal-stock-waste-disposition','modal-restock-qty','modal-restock-unit-cost','modal-restock-sell-price','modal-restock-reason','protected'];
    controls.forEach(id=>{node(id).control=true;});
    node('protected').disabled=true;
    node('modal-actual-stock').value='7'; node('modal-stock-reason').value='Count correction';
    node('modal-restock-qty').value='2'; node('modal-restock-unit-cost').value='10';
    node('modal-restock-sell-price').value='15'; node('modal-restock-reason').value='Stock in';
    node('inventory-product-action-modal');
    const context=vm.createContext({document:{getElementById:node,get activeElement(){return active;}},
        window:{IbemsPortalNavigation:{currentSignal:signal}}, URL:{revokeObjectURL(){}},
        invSyncModalBodyLock(){}, invMoney:n=>`PHP ${n}`,
        invSetResult:(...args)=>results.push(args), invLoadProducts:async()=>{reads.push('products');return true;},
        invLoadMovements:async()=>{reads.push('movements');return true;},
        fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),
    });
    vm.runInContext('let invStockSavePending=false, invIsRestocking=false, invIsUpdatingProduct=false, invIsCreatingProduct=false, invActiveStoreId=1, invModalProductId=4, invProductActionVersion=1, invProductActionOpener=null, invModalPreviewObjectUrl=null; let invProducts=[{id:4,stock_qty:5,price:15}];\n'+
        part2.slice(0,part2.indexOf('function invOpenProductActionModal'))+
        part2.slice(part2.indexOf('function invCloseProductActionModal'),part2.indexOf('function invSetProductEditMode'))+
        'function invSetProductEditMode() {}\n'+
        part2.slice(part2.indexOf('function invUpdateAdjustmentPreview'),part2.indexOf('async function invLoadStores'))+
        part2.slice(part2.indexOf('function invSetStockResult'),part2.indexOf('function invToggleProductImageInput'))+
        part3.slice(part3.indexOf('async function invAdjustStock'),part3.indexOf('async function invUpdateProduct')),context);
    const run=code=>vm.runInContext(code,context);
    return {node,context,requests,results,reads,signal,run,get active(){return active;},adjust:()=>run('invAdjustStock(4)'),restock:()=>run('invSubmitModalRestock()')};
}
const reply=(request,data={status:'success',previous_qty:5,actual_qty:7,diff_qty:2},ok=true)=>request.resolve({ok,json:async()=>data});
test('pending stock write freezes both panels, rejects duplicate/cross action and cannot be dismissed by an event',async()=>{
    const f=fixture(), pending=f.adjust();
    assert.equal(f.requests.length,1);
    assert.equal(f.node('modal-restock-qty').disabled,true);
    assert.equal(f.node('close-product-action-modal').disabled,false);
    assert.equal(f.active.id,'close-product-action-modal');
    await f.adjust(); await f.restock();
    f.run('invCloseProductActionModal({type:"click"}); invCloseProductActionModal(); invUpdateAdjustmentPreview();');
    assert.equal(f.requests.length,1);
    assert.equal(f.node('inventory-product-action-modal').style.display,'grid');
    assert.equal(f.node('modal-save-adjustment').disabled,true);
    reply(f.requests[0],{status:'error',message:'Quantity changed. Reload inventory.'}); await pending;
    assert.equal(f.active.id,'modal-stock-result');
    assert.equal(f.node('modal-restock-qty').disabled,false);
    assert.equal(f.node('protected').disabled,true);
    assert.equal(f.node('inventory-product-action-modal').attributes['aria-busy'],'false');
});
test('adjustment preserves expected stock and classification payload',async()=>{
    const f=fixture(); f.node('modal-actual-stock').value='3'; f.node('modal-stock-waste-disposition').value='spoiled';
    const p=f.adjust(), body=JSON.parse(f.requests[0].options.body);
    assert.deepEqual(body,{store_id:1,product_id:4,actual_qty:3,expected_qty:5,reason:'Count correction',waste_disposition:'spoiled'});
    reply(f.requests[0]);await p;
    assert.equal(f.results[0][1],'ok');assert.equal(f.node('inventory-product-action-modal').style.display,'none');
});
test('invalid quantities and incompatible losses stay inside modal without requests',async()=>{
    for(const [value,loss] of [['',''],['-1',''],['1.5',''],['7','spoiled'],['NaN','']]) {
        const f=fixture();f.node('modal-actual-stock').value=value;f.node('modal-stock-waste-disposition').value=loss;
        await f.adjust();assert.equal(f.requests.length,0);assert.equal(f.active.id,'modal-stock-result');
        assert.equal(f.node('modal-stock-result').dataset.type,'error');
    }
});
test('nonfinite stock-in costs are never serialized as null',async()=>{
    for(const id of ['modal-restock-unit-cost','modal-restock-sell-price']) {
        const f=fixture();f.node(id).value='Infinity';await f.restock();
        assert.equal(f.requests.length,0);assert.equal(f.active.id,'modal-stock-result');
    }
});
test('blank reason rejects both stock actions and clearing quantity disables adjustment',async()=>{
    for(const [action,id] of [['adjust','modal-stock-reason'],['restock','modal-restock-reason']]) {
        const f=fixture();f.node(id).value=' ';await f[action]();assert.equal(f.requests.length,0);
    }
    const f=fixture();f.node('modal-actual-stock').value='';f.run('invUpdateAdjustmentPreview()');
    assert.equal(f.node('modal-save-adjustment').disabled,true);
});
test('HTTP rejection cannot show a confirmed save even with success-looking JSON',async()=>{
    const f=fixture(),p=f.restock(); reply(f.requests[0],{status:'success',message:'Rejected'},false);await p;
    assert.equal(f.results.length,0);assert.equal(f.node('modal-stock-result').dataset.type,'error');assert.equal(f.reads.length,0);
});
test('confirmed adjustment and stock-in survive read failure with reload warning and no repeat POST',async()=>{
    for(const action of ['adjust','restock']) {
        const f=fixture();f.context.invLoadProducts=async()=>{throw Error('read failed');};
        const p=f[action]();reply(f.requests[0]);await p;
        assert.equal(f.results[0][1],'warning');assert.match(f.results[0][0],/Reload this page/);
        assert.equal(f.requests.length,1);assert.deepEqual(f.reads,['movements']);
        assert.equal(f.node('inventory-product-action-modal').style.display,'none');
    }
});
test('late responses do not update departed store, aborted navigation or replacement dialog',async()=>{
    for(const depart of [f=>{f.signal.aborted=true;},f=>f.run('invActiveStoreId=2'),f=>f.run('++invProductActionVersion'),f=>{f.node('inventory-product-action-modal').isConnected=false;}]) {
        const f=fixture(),p=f.adjust();depart(f);reply(f.requests[0]);await p;
        assert.equal(f.results.length,0);assert.equal(f.reads.length,0);
    }
});
test('unconfirmed network failure tells the user to check inventory before retrying',async()=>{
    const f=fixture(),p=f.adjust();f.requests[0].reject(Error('offline'));await p;
    assert.match(f.node('modal-stock-result').textContent,/Check inventory before trying again/);
    assert.equal(f.active.id,'modal-stock-result');assert.equal(f.node('modal-save-adjustment').disabled,false);
});
test('close is idempotent and invalidates pending opening focus',()=>{
    const f=fixture();let focuses=0;f.context.opener={isConnected:true,focus(){focuses++;}};
    f.run('invProductActionOpener=opener');
    f.run('invCloseProductActionModal();invCloseProductActionModal();');
    assert.equal(focuses,1);assert.equal(f.run('invProductActionVersion'),2);
});
