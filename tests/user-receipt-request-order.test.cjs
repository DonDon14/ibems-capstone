const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/user-history.js','utf8');
const functions=source.slice(source.indexOf('async function openReceipt('),source.indexOf('function printReceipt('));
for(const outcome of ['success','failure']) test(`late receipt ${outcome} cannot overwrite another receipt`,async()=>{
 const elements=new Map(),requests=[],rendered=[];
 const element=id=>{if(!elements.has(id))elements.set(id,{classList:{add(){},remove(){}},focus(){},innerHTML:''});return elements.get(id);};
 const ctx=vm.createContext({uhReceiptRequestId:0,uhSelectedReceipt:null,uhReceiptTrigger:null,uhPageSignal:{aborted:false},HTMLElement:class{},document:{activeElement:null,getElementById:element},window:{location:{origin:'http://qa.test'},IbemsReceipt:{renderReceipt:(id,tx)=>rendered.push(tx.transactionId)}},uhEscape:v=>v,fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject}))});
 vm.runInContext(functions,ctx);
 const old=vm.runInContext('openReceipt(1)',ctx);
 vm.runInContext('closeReceipt()',ctx);
 const latest=vm.runInContext('openReceipt(2)',ctx);
 requests[1].resolve({ok:true,json:async()=>({status:'success',transaction:{id:2}})});await latest;
 if(outcome==='failure')requests[0].reject(new Error('Old failure'));
 else requests[0].resolve({ok:true,json:async()=>({status:'success',transaction:{id:1}})});
 await old;
 assert.deepEqual(rendered,[2]);assert.equal(ctx.uhSelectedReceipt.transactionId,2);assert.doesNotMatch(element('uh-receipt-content').innerHTML,/Old failure/);
});

function setupReceipt() {
 const elements=new Map(),requests=[],rendered=[];
 const element=id=>{if(!elements.has(id))elements.set(id,{classList:{add(){},remove(){}},focus(){},innerHTML:''});return elements.get(id);};
 const signal={aborted:false};
 const ctx=vm.createContext({uhReceiptRequestId:0,uhSelectedReceipt:null,uhReceiptTrigger:null,uhPageSignal:signal,HTMLElement:class{},document:{activeElement:null,getElementById:element},window:{location:{origin:'http://qa.test'},IbemsReceipt:{renderReceipt:(id,tx)=>rendered.push(tx)}},uhEscape:v=>v,fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject}))});
 vm.runInContext(functions,ctx);
 return {ctx,signal,requests,rendered,elements,element,open:()=>vm.runInContext('openReceipt(1)',ctx)};
}

test('departed User History cannot open a receipt',async()=>{
 const qa=setupReceipt();qa.signal.aborted=true;await qa.open();
 assert.equal(qa.requests.length,0);assert.equal(qa.elements.size,0);assert.equal(qa.ctx.uhReceiptRequestId,0);
});

test('receipt resolving after navigation cannot render or enable Print',async()=>{
 const qa=setupReceipt(),pending=qa.open();qa.signal.aborted=true;
 qa.requests[0].resolve({ok:true,json:async()=>({status:'success',transaction:{id:1}})});await pending;
 assert.equal(qa.rendered.length,0);assert.equal(qa.element('uh-receipt-print').disabled,true);assert.equal(qa.ctx.uhSelectedReceipt,null);
});

test('User History carries split amounts and tender into the shared receipt renderer',async()=>{
 const qa=setupReceipt(),payments=[{payment_method:'cash',amount:15,cash_received:20,change_due:5},{payment_method:'debt',amount:15,cash_received:null,change_due:null}],pending=qa.open();
 qa.requests[0].resolve({ok:true,json:async()=>({status:'success',transaction:{id:1,payment_method:'split',amount:30,payments}})});await pending;
 assert.deepEqual(qa.rendered[0].payments,payments);assert.equal(qa.element('uh-receipt-print').disabled,false);
});
