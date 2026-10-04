const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const main = fs.readFileSync('public/assets/js/store-inventory.js', 'utf8');
const part2 = fs.readFileSync('public/assets/js/store-inventory.part2.js', 'utf8');
const part3 = fs.readFileSync('public/assets/js/store-inventory.part3.js', 'utf8');
function fixture(mode='create') {
    const nodes = new Map(), requests=[], reads=[], frames=[], confirms=[], variants=[];
    const signal={aborted:false};let active;
    const node = id => {
        if (!nodes.has(id)) {
        let domValue='';
        nodes.set(id,{id,isConnected:true,get value(){return domValue;},set value(value){domValue=String(value);},files:[],textContent:id,innerHTML:'',dataset:{},style:{},disabled:false,hidden:false,attributes:{},options:[],
            focus(){active=this;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},dispatchEvent(){},
            add(option){this.options.push(option);},classList:{add(){},remove(){},toggle(){}},
            appendChild(option){this.options.push(option);},
            querySelector(selector){if(selector==='option[value="rental"]')return this.options.find(o=>o.value==='rental'); return node(selector.includes('create-product-layout')?'create-body':'action-body');},
            querySelectorAll(){return [...nodes.values()].filter(n=>n.control && n.owner===this.id);},
        });
        }
        return nodes.get(id);
    };
    const create='inventory-product-modal',edit='inventory-product-action-modal';
    for(const id of [create,edit]) node(id).style.display='none';
    node(mode==='create'?create:edit).style.display='grid';
    const initial={'new-product-sku':'NEW','new-product-name':'New product','new-product-variant-label':'Small','new-product-category':'General',
        'new-product-image-source':'url','new-product-image-url':'/preview-image.png','new-product-image-mode':'shared','new-product-sell-price':'15','new-product-unit-cost':'10',
        'new-product-initial-stock':'2','new-product-low-stock':'0','new-product-reason':'Initial stock','new-product-item-type':'stock_item','new-product-stock-policy':'tracked','new-product-unit-code':'piece',
        'modal-product-sku':'OLD','modal-product-name':'Edited product','modal-product-price':'20','modal-product-low-stock':'0','modal-product-image-source':'upload',
        'modal-product-item-type':'stock_item','modal-product-stock-policy':'tracked','modal-product-unit-code':'piece','modal-actual-stock':'5'};
    for(const [id,value] of Object.entries(initial)){node(id).value=value;node(id).control=true;node(id).owner=id.startsWith('new')?create:edit;}
    for(const [owner,ids] of [[create,['close-product-modal','new-product-submit','new-product-cancel','create-protected','add-product-variant']],
        [edit,['close-product-action-modal','modal-save-product','modal-save-adjustment','modal-start-edit-product','modal-restock-submit','edit-protected']]]) {
        for(const id of ids){node(id).control=true;node(id).owner=owner;}
    }
    node('create-protected').disabled=true;node('edit-protected').disabled=true;
    active=node('opener');
    class FormData {constructor(){this.data=new Map();}append(k,v){this.data.set(k,v);}get(k){return this.data.get(k)??null;}}
    const context=vm.createContext({FormData,Event:function(){},Option:function(text,value){return{text,value};},URL:{revokeObjectURL(){}},
        document:{getElementById:node,createElement:()=>({}),get activeElement(){return active;},body:{classList:{toggle(){}}},querySelector(){return null;},
            querySelectorAll:selector=>selector==='[data-variant-row]'?variants:selector==='.inv-modal'?[node(create),node(edit)]:[]},
        window:{requestAnimationFrame:fn=>frames.push(fn),IbemsPortalNavigation:{currentSignal:signal},IbemsDialog:{confirm:()=>new Promise(resolve=>confirms.push(resolve))}},
        fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),
    });
    vm.runInContext(main+part2+part3.slice(0,part3.indexOf('document.getElementById("inventory-search")')),context);
    const run=code=>vm.runInContext(code,context);
    run('invActiveStoreId=1;invProductCreateVersion=1;invProductActionVersion=1;invModalProductId=4;invProductEditMode=true;invProductCreateOpener=document.getElementById("opener");invProductActionOpener=document.getElementById("opener"); invAllowedItemTypes=["stock_item"];invProducts=[{id:4,sku:"OLD",name:"Old product",stock_qty:5,price:15,stock_policy:"tracked"}];');
    context.invLoadCategories=async()=>{reads.push('categories');return true;};
    context.invLoadProducts=async()=>{reads.push('products');return true;};
    context.invLoadMovements=async()=>{reads.push('movements');return true;};
    function variant(fields={}) {
        const values={label:'Large',sku:'NEW-LARGE',barcode:'',cost:'10',price:'20',stock:'2',low:'0',...fields};
        const image=values.image;delete values.image;
        const row={querySelector:selector=>{const key=selector.match(/data-v="(.*?)"/)[1];return key==='image'?{files:image?[image]:[]}:{value:values[key]};}};
        variants.push(row);return values;
    }
    const save=()=>run(mode==='create'?'invCreateProduct()':'invUpdateProduct(4)');
    const result=()=>node(mode==='create'?'modal-product-result':'modal-stock-result');
    return {mode,node,run,context,requests,reads,frames,confirms,signal,variant,save,result,get active(){return active;}};
}
const reply=(r,data={status:'success',variant_count:2,product:{name:'Saved product'}},ok=true)=>r.resolve({ok,json:async()=>data});
for(const mode of ['create','edit']) {
    test(`${mode} pending save freezes drafts and cross actions, preserves protected controls, and ignores ordinary close events`,async()=>{
        const f=fixture(mode),p=f.save(),modal=f.node(mode==='create'?'inventory-product-modal':'inventory-product-action-modal');
        const close=mode==='create'?'close-product-modal':'close-product-action-modal';
        assert.equal(f.requests.length,1);assert.equal(modal.attributes['aria-busy'],'true');assert.equal(f.active.id,close);
        assert.equal(f.node(mode==='create'?'new-product-name':'modal-product-name').disabled,true);assert.equal(f.node(close).disabled,false);
        await f.save();await f.run('invSubmitModalRestock();');await f.run('invAdjustStock(4)');
        f.run(mode==='create'?'invCloseProductModal({type:"click"});':'invCloseProductActionModal({type:"click"});invSetProductEditMode(false);invSetModalPanel("restock");');
        assert.equal(f.requests.length,1);assert.equal(modal.style.display,'grid');
        reply(f.requests[0],{status:'error',message:'A conflicting SKU was found.'});await p;
        assert.equal(f.active.id,f.result().id);assert.equal(f.result().dataset.type,'error');assert.equal(modal.attributes['aria-busy'],'false');
        assert.equal(f.node(mode==='create'?'new-product-name':'modal-product-name').disabled,false);
        assert.equal(f.node(`${mode}-protected`).disabled,true);
    });
    test(`${mode} HTTP rejection and unconfirmed transport failures remain modal errors`,async()=>{
        for(const transport of [false,true]) {
            const f=fixture(mode),p=f.save();
            if(transport)f.requests[0].reject(Error('offline'));else reply(f.requests[0],{status:'success',message:'Rejected'},false);
            await p;assert.equal(f.result().dataset.type,'error');assert.equal(f.reads.length,0);
            if(transport)assert.match(f.result().textContent,/Check inventory before trying again/);
        }
    });
    test(`${mode} confirmed save survives any follow-up refresh failure without retrying POST`,async()=>{
        for(const failed of mode==='create'?['invLoadCategories','invLoadProducts','invLoadMovements']:['invLoadCategories','invLoadProducts']) {
            const f=fixture(mode);f.context[failed]=async()=>{throw Error('Read failed');};const p=f.save();reply(f.requests[0]);await p;
            assert.equal(f.requests.length,1);assert.match(f.node('inventory-result').textContent,/Reload this page before/);
            assert.equal(f.active.id,'inventory-result');assert.match(f.node('inventory-result').style.color,/feedback-warning/);
            assert.equal(f.node(mode==='create'?'inventory-product-modal':'inventory-product-action-modal').style.display,'none');
        }
    });
    test(`${mode} stale response cannot close or update a departed or replacement dialog`,async()=>{
        for(const depart of [f=>{f.signal.aborted=true;},f=>f.run('invActiveStoreId=2'),f=>f.run(mode==='create'?'++invProductCreateVersion':'++invProductActionVersion'),f=>{f.node(mode==='create'?'inventory-product-modal':'inventory-product-action-modal').isConnected=false;}]) {
            const f=fixture(mode),p=f.save();depart(f);reply(f.requests[0]);await p;
            assert.equal(f.node('inventory-result').textContent,'inventory-result');assert.equal(f.reads.length,0);
        }
    });
}
test('family payload preserves variant numbers, shared fields, zero threshold and per-variant image keys',async()=>{
    const f=fixture();const image={name:'large.png',size:200};f.variant({image});f.node('new-product-image-mode').value='per_variant';
    const p=f.save(),data=f.requests[0].options.body;
    assert.equal(f.requests[0].url,'/store/inventory/add-product-family');assert.equal(data.get('store_id'),'1');assert.equal(data.get('variant_image_1'),image);
    assert.deepEqual(JSON.parse(data.get('variants')),[{sku:'NEW',label:'Small',barcode:'',price:15,stock:2,cost:10,low:0},{label:'Large',sku:'NEW-LARGE',barcode:'',cost:10,price:20,stock:2,low:0}]);
    reply(f.requests[0]);await p;assert.match(f.node('inventory-result').textContent,/2 variants created for New product/);
});
test('editing preserves product/store/image fields and captures draft name before async save',async()=>{
    const f=fixture('edit');f.node('modal-product-image-source').value='url';f.node('modal-product-image-url').value='/new-image.png';
    const p=f.save(),data=f.requests[0].options.body;f.node('modal-product-name').value='Changed after submit';
    assert.equal(f.requests[0].url,'/store/inventory/update-product');assert.equal(data.get('product_id'),'4');assert.equal(data.get('sell_price'),'20');assert.equal(data.get('low_stock_threshold'),'0');assert.equal(data.get('image_url'),'/new-image.png');
    reply(f.requests[0],{status:'success'});await p;assert.equal(f.node('inventory-result').textContent,'Product updated: Edited product');
});
test('nonfinite and fractional creation numbers, conflicting variants and invalid images never post',async()=>{
    for(const fields of [{'new-product-unit-cost':'Infinity'},{'new-product-initial-stock':'1.5'},{'new-product-low-stock':'0.5'},{'new-product-name':''},{'new-product-reason':''}]) {
        const f=fixture();for(const [id,value] of Object.entries(fields))f.node(id).value=value;await f.save();assert.equal(f.requests.length,0);assert.equal(f.active.id,'modal-product-result');
    }
    for(const fields of [{price:'Infinity'},{stock:'1.5'},{low:'0.5'},{label:''},{sku:'OLD'},{sku:'new'},{image:{size:0}}]) {
        const f=fixture();f.variant(fields);await f.save();assert.equal(f.requests.length,0);assert.equal(f.active.id,'modal-product-result');
    }
});
test('readiness follows additional variant corrections and never enables Create during a save',()=>{
    const f=fixture(),variant=f.variant({stock:'1.5'});f.run('invUpdateCreateProductProjection()');assert.equal(f.node('new-product-submit').disabled,true);
    variant.stock='2';f.run('invUpdateCreateProductProjection()');assert.equal(f.node('new-product-submit').disabled,false);
    f.run('invIsCreatingProduct=true;invUpdateCreateProductProjection();');assert.equal(f.node('new-product-submit').disabled,true);
});
test('editing nonfinite/fractional price/threshold and conflicting SKU/barcode stay inside the modal',async()=>{
    for(const [id,value] of [['modal-product-price','Infinity'],['modal-product-low-stock','1.5'],['modal-product-name',''],['modal-product-sku','TAKEN'],['modal-product-barcode','TAKEN']]) {
        const f=fixture('edit');f.run('invProducts.push({id:5,sku:"TAKEN",barcode:"TAKEN"})');f.node(id).value=value;
        await f.save();assert.equal(f.requests.length,0);assert.equal(f.active.id,'modal-stock-result');
    }
});
test('only latest product opening may reset drafts and capture its opener',async()=>{
    const f=fixture();f.node('inventory-product-modal').style.display='none';const opens=[];
    f.context.invLoadCategories=()=>new Promise(resolve=>opens.push(resolve));
    const first=f.run('invOpenProductModal()');f.node('second-opener').focus();const second=f.run('invOpenProductModal()');
    opens[1](true);await second;f.node('new-product-name').value='Keep this draft';opens[0](true);await first;
    assert.equal(f.node('new-product-name').value,'Keep this draft');assert.equal(f.run('invProductCreateOpener.id'),'second-opener');
    f.run('invCloseProductModal(); invCloseProductModal();');assert.equal(f.active.id,'second-opener');
});
test('dirty-form discard asks once, respects cancellation and ignores stale approval',async()=>{
    for(const cancel of [false,true]) {
        const f=fixture();f.run('invCreateSnapshot=invCaptureCreateFormState()');f.node('new-product-name').value='Dirty';
        const pending=f.run('invRequestCloseProductModal()');await f.run('invRequestCloseProductModal()');assert.equal(f.confirms.length,1);
        if(!cancel)f.run('++invProductCreateVersion');f.confirms[0](!cancel);await pending;
        assert.equal(f.node('inventory-product-modal').style.display,'grid');assert.equal(f.node('new-product-name').value,'Dirty');
        assert.equal(f.run('invCreateClosePending'),false);
    }
});
test('opening animation cannot steal immediate edit focus or focus a closed dialog',()=>{
    const f=fixture('edit');f.run('invOpenProductActionModal(4);invSetProductEditMode(true);');f.frames.shift()();
    assert.equal(f.active.id,'modal-product-sku');
    f.run('invSetProductEditMode(false);invOpenProductActionModal(4);invCloseProductActionModal();');f.frames.shift()();
    assert.equal(f.active.id,'opener');
});
test('editing shows only product save/cancel and returns to the selected stock panel on cancellation',()=>{
    const f=fixture('edit');f.run('invProductEditMode=false;invSetModalPanel("restock");invSetProductEditMode(true);');
    assert.equal(f.node('modal-restock-submit').style.display,'none');assert.equal(f.node('modal-save-adjustment').style.display,'none');
    assert.equal(f.node('modal-restock-panel').attributes['aria-hidden'],'true');assert.equal(f.node('modal-save-product').style.display,'inline-flex');
    f.run('invSetProductEditMode(false)');assert.equal(f.node('modal-restock-submit').style.display,'inline-flex');
    assert.equal(f.node('modal-restock-panel').attributes['aria-hidden'],'false');
    f.run('invProducts[0].stock_policy="untracked";invSetModalPanel("restock");');assert.equal(f.node('modal-restock-submit').style.display,'none');
});
