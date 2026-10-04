const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/store-admin-dashboard.js','utf8');
function fixture(fetch){
 const nodes={}; const node=id=>nodes[id]??=( {textContent:'old',innerHTML:'old'} );
 const context=vm.createContext({fetch,URL,window:{location:{origin:'http://localhost'},IbemsDashboardPeriod:{get:()=> 'day',setMeta(){}}},document:{getElementById:node},sadRoot:()=>({getAttribute:()=>'/data'}),sadSetText:(id,value)=>node(id).textContent=value,sadDataState:(type)=>type,sadMoney:String,sadRenderReviews(){},sadRenderStores(){}});
 vm.runInContext(source.slice(source.indexOf('let sadDashboardSequence'),source.indexOf('async function sadReviewVariance')),context);
 return {node,load:()=>vm.runInContext('sadLoadDashboard()',context)};
}
test('network failure marks totals unavailable and gives panel recovery',async()=>{
 const f=fixture(async()=>{throw Error('offline');});await f.load();
 assert.equal(f.node('sad-today-sales').textContent,'Unavailable');assert.equal(f.node('sad-store-list').innerHTML,'error');
});
test('late same-period failure cannot replace newer dashboard totals',async()=>{
 let reject;let calls=0;const f=fixture(()=>++calls===1?new Promise((_,r)=>reject=r):Promise.resolve({ok:true,json:async()=>({status:'success',summary:{today_sales_total:99}})}));
 const old=f.load();await f.load();reject(Error('late'));await old;assert.equal(f.node('sad-today-sales').textContent,'99');
});
test('dashboard states announce errors assertively and escape recovery text',()=>{
 const helper=source.slice(source.indexOf('function sadDataState('),source.indexOf('function sadRenderStores('));
 const context=vm.createContext({sadEscape:value=>String(value).replace(/</g,'&lt;')});vm.runInContext(helper,context);
 const error=vm.runInContext('sadDataState("error","<failure>","Reload")',context);
 assert.match(error,/aria-live="assertive" aria-atomic="true"/);assert.match(error,/&lt;failure>/);
 assert.match(vm.runInContext('sadDataState("loading","Loading")',context),/role="status" aria-live="polite"/);
});
