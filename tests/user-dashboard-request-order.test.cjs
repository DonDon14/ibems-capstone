const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/user-dashboard.js','utf8');
const loader=source.slice(source.indexOf('let uDashboardRequestId'),source.indexOf('uPageSignal.addEventListener("abort"'));
function setup() {
 const requests=[],states=[],totals=new Map();
 const context=vm.createContext({window:{IbemsDashboardPeriod:{get:()=> 'year',setMeta(){}}},uPageSignal:{aborted:false},fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject})),document:{getElementById(id){if(!totals.has(id))totals.set(id,{style:{},classList:{remove(){}}});return totals.get(id);}},uMoney:v=>String(v),uApplyDebtStatus:(id,s)=>states.push(s.debt_status_label||'loaded'),uRenderCreditMeter(){},uSetTrendState:(type)=>states.push(type),uRenderTrend(){},uRenderRecentCashbook(){},uRenderRecentTransactions(){},uDataState:()=> 'error'});
 vm.runInContext('let uTrendChart=null,uTrendRows=[];',context);
 vm.runInContext(loader,context);
 return {requests,states,totals,run:code=>vm.runInContext(code,context),start:()=>vm.runInContext('loadUserDashboard()',context)};
}
for(const late of ['failure','success']) test(`obsolete dashboard ${late} cannot replace newer result`,async()=>{
 const qa=setup(),old=qa.start(),current=qa.start();
 qa.requests[1].resolve({ok:true,json:async()=>({status:'success',summary:{total_spent:330}})}); await current;
 const before=qa.states.slice();
 if(late==='failure')qa.requests[0].reject(new Error('Old failure'));
 else qa.requests[0].resolve({ok:true,json:async()=>({status:'success',summary:{total_spent:999}})});
 await old;
 assert.deepEqual(qa.states,before); assert.equal(qa.totals.get('u-total-spent').textContent,'330');
});
test('loading destroys old chart and removes rows that theme changes could reuse',async()=>{
 const qa=setup();
 qa.run('let destroyed=0;uTrendChart={destroy(){destroyed++;}};uTrendRows=[{total:999}];');
 const pending=qa.start();
 assert.equal(qa.run('destroyed'),1);
 assert.equal(qa.run('uTrendChart'),null);
 assert.equal(qa.run('uTrendRows.length'),0);
 qa.requests[0].reject(new Error('Offline'));await pending;
 assert.equal(qa.run('uTrendRows.length'),0);
});
test('HTTP failure cannot display successful dashboard payload',async()=>{
 const qa=setup(),pending=qa.start();
 assert.equal(qa.totals.get('u-total-spent').textContent,'Loading…');
 assert.equal(qa.totals.get('u-credit-used-percent').textContent,'Loading…');
 qa.requests[0].resolve({ok:false,json:async()=>({status:'success',summary:{total_spent:999}})});await pending;
 assert.ok(qa.states.includes('Unavailable')); assert.ok(qa.states.includes('error'));
 assert.equal(qa.totals.get('u-total-spent').textContent,'Unavailable');
 assert.equal(qa.totals.get('u-credit-used-percent').textContent,'Unavailable');
 assert.equal(qa.totals.get('u-credit-meter-fill').style.width,'0%');
});
