const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/store-staff-records.part2.js','utf8');
const loader=source.slice(source.indexOf('async function srLoadDebtRecords('),source.indexOf('async function srLoadStaffTransactions('));
function setup(){
 const nodes=new Map(),requests=[],rendered=[];
 const element=id=>{if(!nodes.has(id))nodes.set(id,{value:'',textContent:'',innerHTML:''});return nodes.get(id);};element('debt-sort').value='name:asc';element('debt-page-size').value='25';
 const ctx=vm.createContext({srDebtRequestSequence:0,srDebtPage:1,URLSearchParams,document:{getElementById:element},fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject})),srDebtDataState:(type,message)=>`${type}:${message}`,srSetResult(){},srRenderDebts:rows=>rendered.push(rows[0].id),srRenderPager(){}});vm.runInContext(loader,ctx);
 return {element,requests,rendered,start:()=>vm.runInContext('srLoadDebtRecords()',ctx)};
}
test('staff HTTP failure is unavailable instead of zero matching records',async()=>{
 const qa=setup();qa.element('debt-pager').innerHTML='Next';const pending=qa.start();assert.equal(qa.element('debt-pager').innerHTML,'');
 qa.requests[0].resolve({ok:false,json:async()=>({status:'success',customers:[{id:1}]})});await pending;
 assert.deepEqual(qa.rendered,[]);assert.equal(qa.element('staff-count-badge').textContent,'Unavailable');assert.equal(qa.element('staff-count-text').textContent,'Employee records unavailable');assert.equal(qa.element('debt-search-btn').disabled,false);
});
test('obsolete staff failure cannot clear newer successful count',async()=>{
 const qa=setup(),old=qa.start(),current=qa.start();qa.requests[1].resolve({ok:true,json:async()=>({status:'success',customers:[{id:2}],pagination:{total:9,page:1}})});await current;
 qa.requests[0].reject(new Error('Old failure'));await old;assert.deepEqual(qa.rendered,[2]);assert.equal(qa.element('staff-count-badge').textContent,'9 employees');
});
