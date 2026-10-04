const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/user-deductions.js','utf8');
const loader=source.slice(source.indexOf('let udRequestId'),source.indexOf('udPeriod?.addEventListener'));
function setup(){
 const requests=[],rendered=[],elements=new Map();const body={innerHTML:'',attrs:{},setAttribute(k,v){this.attrs[k]=v;}};
 const context=vm.createContext({udPageSignal:{aborted:false},udBody:body,udState:(message,type)=>`${type}:${message}`,udRender:data=>rendered.push(data.selected_period_id),document:{getElementById:id=>{if(!elements.has(id))elements.set(id,{textContent:'old amount'});return elements.get(id);}},fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject}))});
 vm.runInContext(loader,context);
 return {requests,rendered,elements,body,signal:context.udPageSignal,start:id=>vm.runInContext(`udLoad(${id})`,context)};
}
for(const late of ['success','failure'])test(`old deduction ${late} does not replace selected period`,async()=>{
 const qa=setup(),old=qa.start(1),latest=qa.start(2);
 qa.requests[1].resolve({ok:true,json:async()=>({status:'success',selected_period_id:2})});await latest;
 const current=qa.body.innerHTML;
 if(late==='failure')qa.requests[0].reject(new Error('Old error'));
 else qa.requests[0].resolve({ok:true,json:async()=>({status:'success',selected_period_id:1})});await old;
 assert.deepEqual(qa.rendered,[2]);assert.equal(qa.body.innerHTML,current);
});
test('loading and failure do not retain previous-period amounts',async()=>{
 const qa=setup(),pending=qa.start(1);
 assert.ok([...qa.elements.values()].every(e=>e.textContent==='Loading…'));
 qa.requests[0].reject(new Error('Unavailable'));await pending;
 assert.match(qa.body.innerHTML,/error:Unable to load deductions.*try again/);
 assert.ok([...qa.elements.values()].every(e=>e.textContent==='Unavailable'));
 assert.equal(qa.body.attrs['aria-busy'],'false');
});
test('older request cannot clear busy while the selected period is loading',async()=>{
 const qa=setup(),old=qa.start(1),latest=qa.start(2);
 qa.requests[0].resolve({ok:true,json:async()=>({status:'success',selected_period_id:1})});await old;
 assert.equal(qa.body.attrs['aria-busy'],'true');
 qa.requests[1].resolve({ok:true,json:async()=>({status:'success',selected_period_id:2})});await latest;
 assert.equal(qa.body.attrs['aria-busy'],'false');assert.deepEqual(qa.rendered,[2]);
});
test('leaving the page prevents a successful response from rendering',async()=>{
 const qa=setup(),pending=qa.start(1);qa.signal.aborted=true;
 qa.requests[0].resolve({ok:true,json:async()=>({status:'success',selected_period_id:1})});await pending;
 assert.deepEqual(qa.rendered,[]);
});
test('error announcement is assertive and escapes server text',()=>{
 const context=vm.createContext({udEscape:value=>String(value).replace(/</g,'&lt;')});
 vm.runInContext(source.slice(source.indexOf('function udState'),source.indexOf('function udRender')),context);
 assert.match(vm.runInContext('udState("<failure>","error")',context),/role="alert" aria-live="assertive" aria-atomic="true"/);
 assert.match(vm.runInContext('udState("<failure>","error")',context),/&lt;failure>/);
});
