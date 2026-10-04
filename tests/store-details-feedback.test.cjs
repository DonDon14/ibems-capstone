const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/admin-store-details.js', 'utf8');
const success = () => ({ status:'success', store:{store_name:'A & B', is_active:true}, summary:{sales_total:42}, day_session:null, day_sessions:[], officers:[], inventory:[], recent_transactions:[] });
const response = data => ({ok:true, json:async()=>data});
function fixture(fetch, signal) {
    let context; const nodes = new Map();
    class Node {
        constructor(id) { this.id=id; this.textContent='old'; this.disabled=false; this.isConnected=true; this.value='10'; this.attrs={}; this.classes=new Set(); this.children=[]; this.classList={toggle:(name,on)=>on?this.classes.add(name):this.classes.delete(name)}; }
        set innerHTML(value) { this.html=value; this.children=[]; if(this.id==='sd-load-feedback' && value) { this.children.push(new Node('progress')); if(value.includes('data-sd-retry')) this.children.unshift(new Node('retry')); } }
        get innerHTML() { return this.html || ''; }
        setAttribute(name,value) { this.attrs[name]=value; }
        removeAttribute(name) { delete this.attrs[name]; }
        getAttribute(name) { return this.attrs[name] || null; }
        contains(element) { return this===element || this.children.includes(element); }
        closest(selector) { if (selector.startsWith('#sd-day-session') && this.attrs.panelOwned) return node('sd-day-session'); return selector==='.table-standard-wrap' && this.id.endsWith('-body') ? node(`${this.id}-region`) : null; }
        querySelector(selector) { return this.children.find(child => selector === '[data-sd-retry]' ? child.id === 'retry' : child.id === 'progress') || null; }
        focus() { context.document.activeElement=this; }
    }
    const node = id => { if(!nodes.has(id)) nodes.set(id,new Node(id)); return nodes.get(id); };
    const root=node('root'); root.attrs={'data-store-id':'3','data-details-url':'/supervision/stores/3/data'};
    const document={getElementById:node, querySelector:()=>root, querySelectorAll:()=>['sd-day-session','sd-officers','sd-session-history-body-region','sd-inventory-body-region','sd-transactions-body-region'].map(node), body:node('body'), activeElement:node('outside')};
    context=vm.createContext({fetch, document, window:{IbemsPortalNavigation:{currentSignal:signal},IbemsAvatar:{html:()=>'<span>Avatar</span>'}}, sdRenderDaySession() {}, sdCaseSummary:()=>'',sdVarianceClass:()=>'',sdVarianceStatusLabel:()=>'',sdReviewClass:()=>'',sdReviewLabel:()=>''});
    vm.runInContext(source.slice(0,source.indexOf('function sdReviewLabel(')) + source.slice(source.indexOf('function sdRenderOfficers('),source.indexOf('document.addEventListener("click"')),context);
    node('sd-session-filter').value='all';
    return {context,node,root,load:options=>vm.runInContext(`loadStoreDetails(${JSON.stringify(options || {})})`,context)};
}
test('network, HTTP and parser failures clear stale totals/records and offer one announced retry', async()=>{
    for(const fetch of [async()=>{throw Error('offline');},async()=>({ok:false,json:async()=>({message:'<script>unavailable</script>'})}),async()=>({ok:true,json:async()=>{throw Error('json');}})]) {
        const f=fixture(fetch); assert.equal(await f.load(),false);
        assert.equal(f.node('sd-sales-total').textContent,'Unavailable');
        assert.match(f.node('sd-load-feedback').innerHTML,/role="alert"/);
        assert.match(f.node('sd-load-feedback').innerHTML,/Retry store details/);
        assert.doesNotMatch(f.node('sd-load-feedback').innerHTML,/<script>/);
        assert.match(f.node('sd-session-history-body').innerHTML,/aria-live="off"/);
        assert.equal(f.node('sd-session-pager').innerHTML,''); assert.equal(f.node('sd-session-filter').disabled,true);
        assert.equal(f.node('sd-refresh').disabled,false); assert.equal(f.node('sd-day-session').attrs['aria-busy'],undefined);
        assert.equal(vm.runInContext('sdInventoryRows.length',f.context),0);
        assert.equal(f.node('sd-session-history-body-region').attrs.tabindex,undefined);
    }
});
test('pending retry announces progress, exposes busy state, suppresses duplicate manual reads and retains scope',async()=>{
    let settle,calls=0; const f=fixture((url)=>{assert.equal(url,'/supervision/stores/3/data');calls++;return new Promise(resolve=>settle=resolve);});
    f.node('sd-session-filter').value='unresolved'; f.node('sd-inventory-page-size').value='25';
    vm.runInContext('sdPages.inventory=2',f.context); f.node('sd-refresh').focus(); const pending=f.load({retry:true});
    assert.equal(f.node('sd-refresh').disabled,true); assert.match(f.node('sd-refresh').textContent,/Refreshing/);
    assert.equal(f.node('sd-day-session').attrs['aria-busy'],'true'); assert.equal(f.context.document.activeElement.id,'progress');
    assert.equal(await f.load({retry:true}),false); assert.equal(calls,1);
    const data=success(); data.inventory=Array.from({length:60},(_,id)=>({id,name:'Product',price:5,stock_qty:10})); settle(response(data));
    assert.equal(await pending,true); assert.equal(f.node('sd-session-filter').value,'unresolved'); assert.equal(f.node('sd-inventory-page-size').value,'25');
    assert.equal(vm.runInContext('sdPages.inventory',f.context),2); assert.equal(f.context.document.activeElement.id,'sd-refresh');
    assert.equal(f.node('sd-load-feedback').innerHTML,''); assert.equal(f.node('sd-inventory-body-region').attrs.tabindex,'0');
});
test('retry failure restores the current retry button',async()=>{
    let settle; const f=fixture(()=>new Promise(resolve=>settle=resolve)); f.node('sd-load-feedback').innerHTML='<div data-sd-retry></div>'; f.node('sd-load-feedback').children[0].focus();
    const pending=f.load({retry:true}); settle({ok:false,json:async()=>({status:'error',message:'Rejected read'})});
    assert.equal(await pending,false); assert.equal(f.context.document.activeElement.id,'retry');
});
test('outside focus is preserved after a pending read succeeds or fails',async()=>{
    for(const ok of [true,false]) { let settle; const f=fixture(()=>new Promise(resolve=>settle=resolve)); f.node('sd-refresh').focus(); const pending=f.load(); f.node('outside').focus(); settle(ok?response(success()):{ok:false,json:async()=>({})}); await pending; assert.equal(f.context.document.activeElement.id,'outside'); }
});
test('a removed panel action transfers focus to progress and current recovery',async()=>{
    let settle;const f=fixture(()=>new Promise(resolve=>settle=resolve));const action=f.node('old-action');action.attrs.panelOwned=true;action.focus();
    const pending=f.load();assert.equal(f.context.document.activeElement.id,'progress');settle({ok:false,json:async()=>({})});
    assert.equal(await pending,false);assert.equal(f.context.document.activeElement.id,'retry');
});
test('local filter render calls cannot replace a failed read with false empty records',async()=>{
    const f=fixture(async()=>{throw Error('offline');});await f.load();const unavailable=f.node('sd-session-history-body').innerHTML;
    vm.runInContext('sdRenderSessionHistory();sdRenderInventory();sdRenderTransactions();',f.context);
    assert.equal(f.node('sd-session-history-body').innerHTML,unavailable);assert.equal(f.node('sd-inventory-count').textContent,'Unavailable');
});
for(const key of ['day_sessions','officers','inventory','recent_transactions']) {
    test(`missing or malformed ${key} remains unavailable instead of a false empty result`,async()=>{
        for(const value of [undefined,{},[null]]) { const data=success();data[key]=value;const f=fixture(async()=>response(data)); assert.equal(await f.load(),false);assert.equal(f.node('sd-sales-total').textContent,'Unavailable');assert.match(f.node('sd-load-feedback').innerHTML,/Retry/); }
    });
}
test('missing store, summary or current-day record remains unavailable',async()=>{
    for(const key of ['store','summary','day_session']) { const data=success();delete data[key];const f=fixture(async()=>response(data));assert.equal(await f.load(),false); }
});
test('confirmed empty details have neutral state, zero totals and no empty pagers',async()=>{
    const f=fixture(async()=>response(success())); assert.equal(await f.load(),true);
    assert.equal(f.node('sd-txn-count').textContent,'0'); assert.match(f.node('sd-inventory-body').innerHTML,/No products/);
    assert.equal(f.node('sd-inventory-pager').innerHTML,'');assert.equal(f.node('sd-transactions-pager').innerHTML,'');
    assert.equal(f.node('sd-load-feedback').innerHTML,''); assert.equal(f.node('sd-session-filter').disabled,false);
});
test('single-page records show their range without disabled pagination actions',async()=>{
    const data=success();data.inventory=[{name:'Product',stock_qty:2,price:10}];const f=fixture(async()=>response(data));
    assert.equal(await f.load(),true);assert.match(f.node('sd-inventory-pager').innerHTML,/1–1 of 1/);assert.doesNotMatch(f.node('sd-inventory-pager').innerHTML,/<button/);
});
test('navigation abort or detached page prevents late success and failure from changing the new page',async()=>{
    for(const fail of [false,true]) for(const detached of [false,true]) {
        const controller=new AbortController(); let settle;const f=fixture((_url,options)=>{assert.equal(options.signal,controller.signal);return new Promise((resolve,reject)=>settle=fail?reject:resolve);},controller.signal);
        const pending=f.load(); if(detached) f.root.isConnected=false;else controller.abort(); f.node('sd-sales-total').textContent='new page';settle(fail?Error('late'):response(success()));
        assert.equal(await pending,false);assert.equal(f.node('sd-sales-total').textContent,'new page');assert.equal(f.node('sd-refresh').disabled,true,'Departed replies do not release new page controls');
    }
});
test('late request failure cannot clear a newer successful store refresh',async()=>{
    let rejectFirst,calls=0;const f=fixture(()=>++calls===1?new Promise((_,reject)=>rejectFirst=reject):Promise.resolve(response(success())));
    const first=f.load();assert.equal(await f.load(),true);rejectFirst(Error('late'));assert.equal(await first,false);
    assert.equal(f.node('sd-sales-total').textContent,'PHP 42.00');assert.equal(f.node('sd-store-name').textContent,'A & B');assert.equal(f.node('sd-session-filter').disabled,false);
});
