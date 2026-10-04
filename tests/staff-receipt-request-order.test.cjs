const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/store-staff-records.part2.js','utf8');
const fn=source.slice(source.indexOf('async function srOpenReceipt('),source.indexOf('function srPrintReceipt('));
function setup(){
 const nodes=new Map(),requests=[],rendered=[],locks=new Set();
 const node=id=>{if(!nodes.has(id)){const classes=new Set(['is-hidden']);nodes.set(id,{style:{},classList:{add:v=>classes.add(v),remove:v=>classes.delete(v),contains:v=>classes.has(v)},focus(){},innerHTML:''});}return nodes.get(id);};
 const ctx=vm.createContext({srReceiptSequence:0,srReceiptTrigger:null,srSelectedReceipt:null,srStoreId:3,document:{activeElement:null,getElementById:node,body:{classList:{add:v=>locks.add(v),remove:v=>locks.delete(v)}}},window:{location:{origin:'http://qa.test'},IbemsReceipt:{renderReceipt:(id,tx)=>rendered.push(tx.transactionId)}},srEscape:v=>String(v).replace(/</g,'&lt;'),fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject}))});vm.runInContext(fn,ctx);
 return {ctx,node,requests,rendered,locks,open:id=>vm.runInContext(`srOpenReceipt(${id})`,ctx),close:()=>vm.runInContext('srCloseReceipt()',ctx)};
}
for(const outcome of ['success','failure'])test(`obsolete staff receipt ${outcome} cannot overwrite current receipt`,async()=>{
 const qa=setup(),old=qa.open(1);qa.close();const current=qa.open(2);
 assert.equal(qa.node('staff-receipt-view').classList.contains('is-hidden'),true);
 qa.requests[1].resolve({ok:true,json:async()=>({status:'success',transaction:{id:2}})});await current;
 if(outcome==='success')qa.requests[0].resolve({ok:true,json:async()=>({status:'success',transaction:{id:1}})});else qa.requests[0].reject(new Error('Old failure'));await old;
 assert.deepEqual(qa.rendered,[2]);assert.equal(qa.node('staff-receipt-view').href,'http://qa.test/store/receipt/2?store_id=3');assert.equal(qa.node('staff-receipt-print').disabled,false);
 assert.equal(qa.node('staff-receipt-view').classList.contains('is-hidden'),false);
});
test('closing staff receipt retains underlying employee modal scroll lock',async()=>{
 const qa=setup();qa.node('staff-employee-modal').classList.remove('is-hidden');const pending=qa.open(1);qa.close();
 assert.equal(qa.locks.has('app-modal-open'),true);assert.equal(qa.ctx.srSelectedReceipt,null);
 qa.requests[0].reject(new Error('Canceled'));await pending;assert.deepEqual(qa.rendered,[]);
 qa.node('staff-employee-modal').classList.add('is-hidden');qa.close();assert.equal(qa.locks.has('app-modal-open'),false);
});
