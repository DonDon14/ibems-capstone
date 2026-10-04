const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-accounting-debts.js', 'utf8');
function setup() {
    const classes = new Set(['is-hidden']);
    const handlers = {};
    const modal = {classList:{add:n=>classes.add(n),remove:n=>classes.delete(n)},setAttribute:()=>{},addEventListener(type,handler){handlers[type]=handler;}};
    const content = {innerHTML:'',isConnected:true,attributes:{},setAttribute(k,v){this.attributes[k]=v;},focusCount:0,focus(){this.focusCount++;context.document.activeElement=this;},contains(element){return Boolean(element?.inHistory);}};
    const pending=[],rendered=[];
    const context=vm.createContext({window:{IbemsPortalNavigation:{currentSignal:{aborted:false}}},
        document:{getElementById:id=>id==='ad-account-modal'?modal:id==='ad-account-modal-close'?{focus:()=>{}}:content,body:{classList:{add:()=>{},remove:()=>{}}}},
        fetch:()=>new Promise((resolve,reject)=>pending.push({resolve,reject})),
        encodeURIComponent,URLSearchParams,aEscape:String,adRenderAccountDetail:data=>rendered.push(data),adDebtHistoryMarkup:data=>data.label});
    vm.runInContext('let adAccountModalTrigger=null,adAccountUserId=0,adAccountRequestId=0; const adHistoryRequests=new WeakMap();'+
        source.slice(source.indexOf('async function adOpenAccountDetail'),source.indexOf('async function loadAdminDebtOverview'))+
        source.slice(source.indexOf('async function adLoadDebtHistory'),source.indexOf('function adRenderAccountDetail')),context);
    return {context,pending,rendered,content,classes,handlers};
}

for (const oldFailure of [false, true]) test(`invalid date filter survives earlier history ${oldFailure ? 'failure' : 'success'}`, async () => {
    const f = setup();
    const from = {value:'2026-10-04',focus(){f.context.document.activeElement=this;}};
    const to = {value:'2026-10-03'};
    const card = {dataset:{adStoreCard:'1'},querySelector(selector){return {
        '[data-ad-store-toggle]':{dataset:{adStoreName:'Fixture Store'}},
        '[data-ad-store-date-from]':from,
        '[data-ad-store-date-to]':to,
    }[selector];}};
    const button = {closest:()=>card,matches:()=>false};
    vm.runInContext(source.slice(source.indexOf('document.getElementById("ad-account-modal")?.addEventListener("click"'),source.indexOf('document.addEventListener("keydown"')),f.context);
    vm.runInContext('adAccountUserId=1',f.context);
    const old = vm.runInContext('adLoadDebtHistory(1,1,"store","Fixture Store","ad-store-history-1")',f.context);
    f.handlers.click({target:{closest:selector=>selector.startsWith('[data-ad-store-filter-apply]') ? button : null}});
    assert.match(f.content.innerHTML,/start date must be on or before/);
    assert.equal(f.context.document.activeElement,from);
    const warning = f.content.innerHTML;
    if (oldFailure) f.pending[0].reject(new Error('earlier request failed'));
    else f.pending[0].resolve({ok:true,json:async()=>({status:'success',data:{debt:{label:'earlier history'}}})});
    await old;
    assert.equal(f.content.innerHTML,warning);
    assert.equal(f.content.attributes['aria-busy'],'false');
});

for(const fails of [false,true])test(`history paging retains focus and settles busy state after ${fails?'failure':'success'}`,async()=>{
    const f=setup();vm.runInContext('adAccountUserId=1',f.context);
    f.context.document.activeElement={inHistory:true};
    const pending=vm.runInContext('adLoadDebtHistory(2)',f.context);
    assert.equal(f.content.focusCount,1);assert.equal(f.context.document.activeElement,f.content);assert.equal(f.content.attributes['aria-busy'],'true');
    f.pending[0].resolve({ok:!fails,json:async()=>({status:'success',data:{debt:{label:'page2'}}})});await pending;
    assert.equal(f.content.attributes['aria-busy'],'false');assert.equal(f.context.document.activeElement,f.content);
    if(fails)assert.match(f.content.innerHTML,/role="alert"/);
});

test('stale history response cannot clear the current request busy state',async()=>{
    const f=setup();vm.runInContext('adAccountUserId=1',f.context);
    const old=vm.runInContext('adLoadDebtHistory(1)',f.context),current=vm.runInContext('adLoadDebtHistory(2)',f.context);
    f.pending[0].resolve({ok:true,json:async()=>({status:'success',data:{debt:{label:'old'}}})});await old;
    assert.equal(f.content.attributes['aria-busy'],'true');
    f.pending[1].resolve({ok:true,json:async()=>({status:'success',data:{debt:{label:'current'}}})});await current;
    assert.equal(f.content.attributes['aria-busy'],'false');assert.equal(f.content.focusCount,0);
});
for(const oldFailure of [true,false]) test(`older account ${oldFailure?'failure':'success'} cannot replace current details`,async()=>{
    const f=setup();
    const first=vm.runInContext('adOpenAccountDetail(1)',f.context);
    const second=vm.runInContext('adOpenAccountDetail(2)',f.context);
    f.pending[1].resolve({ok:true,json:async()=>({status:'success',data:{id:2}})});await second;
    if(oldFailure) f.pending[0].reject(new Error('old error'));
    else f.pending[0].resolve({ok:true,json:async()=>({status:'success',data:{id:1}})});
    await first;
    assert.equal(f.rendered.length,1);assert.equal(f.rendered[0].id,2);
    assert.ok(!f.content.innerHTML.includes('old error'));
});
test('closing account invalidates in-flight detail and history',async()=>{
    const f=setup();const detail=vm.runInContext('adOpenAccountDetail(1)',f.context);
    const history=vm.runInContext('adLoadDebtHistory(1)',f.context);
    vm.runInContext('adCloseAccountDetail()',f.context);
    const loading=f.content.innerHTML;
    for(const pending of f.pending) pending.resolve({ok:true,json:async()=>({status:'success',data:{debt:{label:'late'}}})});
    await Promise.all([detail,history]);
    assert.equal(f.content.innerHTML,loading);assert.equal(f.rendered.length,0);assert.ok(f.classes.has('is-hidden'));
});
test('newer history request wins over older response',async()=>{
    const f=setup();vm.runInContext('adAccountUserId=1',f.context);
    const first=vm.runInContext('adLoadDebtHistory(1)',f.context),second=vm.runInContext('adLoadDebtHistory(2)',f.context);
    f.pending[1].resolve({ok:true,json:async()=>({status:'success',data:{debt:{label:'page2'}}})});await second;
    f.pending[0].resolve({ok:true,json:async()=>({status:'success',data:{debt:{label:'page1'}}})});await first;
    assert.equal(f.content.innerHTML,'page2');
});
