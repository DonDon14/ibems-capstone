const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/store-reports.js','utf8');
const functions = source.slice(source.indexOf('async function rOpenReceipt('),source.indexOf('async function rLoadStores('));
function setup() {
 const elements = new Map(), requests = [], rendered = [];
 const element = id => {if(!elements.has(id))elements.set(id,{style:{},focus(){},innerHTML:''}); return elements.get(id);};
 const context = vm.createContext({reportsReceiptSequence:0,reportsSelectedReceipt:null,reportsReceiptTrigger:null,reportsActiveStoreId:3,location:{origin:'http://qa.test'},document:{getElementById:element},window:{IbemsReceipt:{renderReceipt:(id,tx)=>rendered.push(tx.transactionId)}},rEscape:v=>String(v).replace(/</g,'&lt;'),fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject}))});
 vm.runInContext(functions,context);
 return {context,element,requests,rendered,open:id=>vm.runInContext(`rOpenReceipt(${id}, null)`,context),close:()=>vm.runInContext('rCloseReceipt()',context)};
}
for(const outcome of ['success','failure']) test(`closed receipt late ${outcome} cannot replace another receipt`,async()=>{
 const qa=setup(),old=qa.open(1); qa.close(); const latest=qa.open(2);
 assert.equal(qa.element('reports-receipt-print').disabled,true);
 assert.equal(qa.element('reports-receipt-view').hidden,true);
 qa.requests[1].resolve({ok:true,json:async()=>({transaction:{id:2}})});await latest;
 if(outcome==='failure')qa.requests[0].reject(new Error('Old failure'));else qa.requests[0].resolve({ok:true,json:async()=>({transaction:{id:1}})});
 await old;
 assert.deepEqual(qa.rendered,[2]); assert.equal(qa.context.reportsSelectedReceipt.transactionId,2);
 assert.equal(qa.element('reports-receipt-view').href,'http://qa.test/store/receipt/2?store_id=3');
 assert.equal(qa.element('reports-receipt-print').disabled,false);
 assert.doesNotMatch(qa.element('reports-receipt-content').innerHTML,/Old failure/);
});
test('HTTP receipt failure keeps export controls unavailable and gives escaped recovery text',async()=>{
 const qa=setup(),pending=qa.open(1);
 qa.requests[0].resolve({ok:false,json:async()=>({transaction:{id:1},message:'<failure>'})});await pending;
 assert.deepEqual(qa.rendered,[]); assert.equal(qa.context.reportsSelectedReceipt,null);
 assert.equal(qa.element('reports-receipt-print').disabled,true); assert.equal(qa.element('reports-receipt-view').hidden,true);
 assert.match(qa.element('reports-receipt-content').innerHTML,/&lt;failure>/);
 assert.match(qa.element('reports-receipt-content').innerHTML,/role="alert"/);
});
