const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/store-history.js','utf8');
const start=source.indexOf('async function openReceipt(');
const functions=source.slice(start,source.indexOf('document.getElementById("history-clear-filters")',start));
function setup(){
 const elements=new Map(),requests=[],rendered=[],printed=[];
 const element=id=>{if(!elements.has(id))elements.set(id,{style:{},innerHTML:'',focus(){}});return elements.get(id);};
 const ctx=vm.createContext({historyReceiptSequence:0,historyReceiptTrigger:null,selectedReceipt:null,historyActiveStoreId:3,document:{activeElement:null,getElementById:element,addEventListener(){}},window:{location:{origin:'http://qa.test'},IbemsReceipt:{renderReceipt:(id,tx)=>rendered.push(tx.transactionId),printReceipt:tx=>{printed.push(tx.transactionId);return true;}}},hEscape:v=>String(v).replace(/</g,'&lt;'),setHistoryResult(){},fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject}))});
 vm.runInContext(functions,ctx);return {ctx,requests,rendered,printed,element,open:id=>vm.runInContext(`openReceipt(${id})`,ctx)};
}
for(const outcome of ['success','failure'])test(`late History receipt ${outcome} cannot render or print another receipt`,async()=>{
 const qa=setup(),old=vm.runInContext('printReceiptById(1)',qa.ctx);
 vm.runInContext('closeReceipt()',qa.ctx);const current=qa.open(2);
 qa.requests[1].resolve({ok:true,json:async()=>({status:'success',transaction:{id:2}})});await current;
 if(outcome==='failure')qa.requests[0].reject(new Error('Old failure'));else qa.requests[0].resolve({ok:true,json:async()=>({status:'success',transaction:{id:1}})});
 await old;assert.deepEqual(qa.rendered,[2]);assert.deepEqual(qa.printed,[]);
 assert.equal(qa.ctx.selectedReceipt.lookupUrl,'http://qa.test/store/receipt/2?store_id=3');
});
test('History HTTP failure leaves controls disabled and displays escaped recovery',async()=>{
 const qa=setup(),pending=qa.open(1);assert.equal(qa.element('history-receipt-print').disabled,true);
 qa.requests[0].resolve({ok:false,json:async()=>({status:'success',transaction:{id:1},message:'<failure>'})});assert.equal(await pending,false);
 assert.deepEqual(qa.rendered,[]);assert.equal(qa.element('history-receipt-view').disabled,true);
 assert.match(qa.element('history-receipt-content').innerHTML,/&lt;failure>/);assert.equal(qa.ctx.selectedReceipt,null);
});
