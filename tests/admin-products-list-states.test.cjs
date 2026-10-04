const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/admin-products.js','utf8');
function fixture() {
    const elements = {}, requests = [], signal = new AbortController();
    const doc = {body:{},activeElement:null}; doc.activeElement=doc.body;
    const element = id => elements[id] ||= {
        value: id==='ap-page-size'?'25':id==='ap-sort'?'store:asc':'', checked:false, attrs:{}, classes:new Set(), children:{}, style:{}, html:'',
        get innerHTML() {return this.html;}, set innerHTML(value) {if(this.contains(doc.activeElement))doc.activeElement=doc.body;this.html=value;this.children={};},
        contains(node) {return node?.owner===id || node===this;},
        setAttribute(name,value) {this.attrs[name]=value;},removeAttribute(name) {delete this.attrs[name];},
        getAttribute(name) {return this.attrs[name]??null;},focus() {doc.activeElement=this;},
        querySelector(selector) {const exists=selector==='[data-ap-retry]'?this.html.includes('data-ap-retry'):selector==='.ap-row'?this.html.includes('class="ap-row"'):this.html.includes('data-state');if(!exists)return null;return this.children[selector] ||= {owner:id,focus() {doc.activeElement=this;}};},
        querySelectorAll() {return [];}
    };
    doc.getElementById = id => {const el=element(id);el.classList={toggle(name,on) {on?el.classes.add(name):el.classes.delete(name);}};return el;};
    const context=vm.createContext({window:{IbemsPortalNavigation:{currentSignal:signal.signal}},document:doc,URLSearchParams,
        fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),CSS:{escape:value=>value}});
    vm.runInContext(source.slice(0,source.indexOf('document.addEventListener("error"')) +
        source.slice(source.indexOf('function apRenderSummary'),source.indexOf('let apModalOpener')),context);
    vm.runInContext('apRenderFilters=()=>{};',context);
    const good = {ok:true,json:async()=>({status:'success',data:[{id:1,name:'Fictional notebook',is_active:true}],summary:{visible_products:1},pagination:{total:1,page:1,total_pages:1}})};
    return {doc,element,requests,signal,good,load:()=>vm.runInContext('apLoad()',context),rows:()=>vm.runInContext('apRows',context)};
}
test('new scope clears previous totals and exposes bounded busy loading state',async()=>{
    const f=fixture();f.element('ap-visible-products').textContent='987';const pending=f.load();
    assert.equal(f.element('ap-visible-products').textContent,'Loading…');assert.equal(f.element('ap-body').attrs['aria-busy'],'true');
    assert.equal(f.element('ap-table-region').classes.has('is-ap-list-state'),true);assert.equal(f.element('ap-table-region').attrs.tabindex,undefined);
    f.requests[0].resolve(f.good);await pending;assert.equal(f.element('ap-visible-products').textContent,'1');assert.equal(f.element('ap-table-region').attrs.tabindex,'0');
});
test('network failure shows plain recovery and preserves the requested scope',async()=>{
    const f=fixture();f.element('ap-search').value='notebook';f.element('ap-store-filter').value='7';const pending=f.load();f.requests[0].reject(new TypeError('Failed to fetch'));await pending;
    assert.match(f.element('ap-body').innerHTML,/data-ap-retry/);assert.doesNotMatch(f.element('ap-body').innerHTML,/Failed to fetch/);
    assert.equal(f.element('ap-search').value,'notebook');assert.equal(f.element('ap-store-filter').value,'7');assert.equal(f.element('ap-visible-products').textContent,'Unavailable');
    const retry=f.load();assert.match(f.requests[1].url,/q=notebook/);assert.match(f.requests[1].url,/store_id=7/);f.requests[1].resolve(f.good);await retry;
});
test('unreadable reply uses recovery guidance without exposing parser errors',async()=>{
    const f=fixture(),pending=f.load();f.requests[0].resolve({ok:true,json:async()=>{throw new SyntaxError('Unexpected token <');}});await pending;
    assert.match(f.element('ap-body').innerHTML,/Retry to refresh it/);assert.doesNotMatch(f.element('ap-body').innerHTML,/Unexpected token/);
});
test('server rejection stays escaped and atomic in the list alert',async()=>{
    const f=fixture(),pending=f.load();f.requests[0].resolve({ok:false,json:async()=>({status:'error',message:'Rejected <unsafe> & reference'})});await pending;
    const html=f.element('ap-body').innerHTML;assert.match(html,/Rejected &lt;unsafe&gt; &amp; reference/);assert.match(html,/role="alert"/);assert.match(html,/aria-atomic="true"/);
});
test('empty successful reply shows zero summaries without a scrolling target',async()=>{
    const f=fixture(),pending=f.load();f.requests[0].resolve({ok:true,json:async()=>({status:'success',data:[],summary:{},pagination:{total:0}})});await pending;
    assert.match(f.element('ap-body').innerHTML,/data-state--empty/);assert.equal(f.element('ap-visible-products').textContent,'0');assert.equal(f.element('ap-pager').innerHTML,'');assert.equal(f.element('ap-table-region').attrs.tabindex,undefined);
});
test('retry owns progress then returns focus to the restored retry on rejection',async()=>{
    const f=fixture(),first=f.load();f.requests[0].reject(Error('offline'));await first;
    f.element('ap-body').querySelector('[data-ap-retry]').focus();const retry=f.load();assert.equal(f.doc.activeElement,f.element('ap-body').querySelector('.data-state'));
    f.requests[1].reject(Error('offline'));await retry;assert.equal(f.doc.activeElement,f.element('ap-body').querySelector('[data-ap-retry]'));
});
test('successful retry focuses its first row and preserves departed outside focus',async()=>{
    const f=fixture(),first=f.load();f.requests[0].reject(Error('offline'));await first;
    f.element('ap-body').querySelector('[data-ap-retry]').focus();const retry=f.load();f.requests[1].resolve(f.good);await retry;assert.equal(f.doc.activeElement,f.element('ap-body').querySelector('.ap-row'));
    const newer=f.load();const outside=f.element('outside');outside.focus();f.requests[2].reject(Error('offline'));await newer;assert.equal(f.doc.activeElement,outside);
});
test('older rejection cannot clear a newer pending state',async()=>{
    const f=fixture(),first=f.load(),second=f.load();f.requests[0].reject(Error('offline'));await first;assert.equal(f.element('ap-body').attrs['aria-busy'],'true');assert.match(f.element('ap-body').innerHTML,/Loading products/);f.requests[1].resolve(f.good);await second;assert.equal(f.rows()[0].id,1);
});
test('departed page ignores a late server rejection',async()=>{
    const f=fixture(),pending=f.load();f.signal.abort();f.requests[0].reject(Error('offline'));await pending;assert.match(f.element('ap-body').innerHTML,/Loading products/);assert.doesNotMatch(f.element('ap-body').innerHTML,/data-ap-retry/);
});
