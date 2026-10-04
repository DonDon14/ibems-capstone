const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/store-history.js','utf8');
const loader=source.slice(source.indexOf('async function loadTransactions('),source.indexOf('async function openReceipt('));
function setup(){
 const requests=[],rendered=[],nodes=new Map();
 const element=id=>{if(!nodes.has(id))nodes.set(id,{value:'',textContent:'',innerHTML:''});return nodes.get(id);};
 const context=vm.createContext({historyRequestSequence:0,historyActiveStoreId:3,historyPage:1,historyFilters:{},URLSearchParams,document:{getElementById:element},fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject})),hDataState:(type,message)=>`${type}:${message}`,setHistoryResult:(message)=>rendered.push(['result',message]),clearHistorySummary:label=>{element('history-summary-total').textContent=label;},renderTransactions:(rows)=>{rendered.push(['rows',rows[0].id]);element('history-summary-total').textContent='PHP 330.00';}});
 vm.runInContext(loader,context);return {requests,rendered,element,start:()=>vm.runInContext('loadTransactions()',context)};
}
const success=id=>({ok:true,json:async()=>({status:'success',transactions:[{id}],pagination:{page:1,total_pages:2,total:30}})});
for(const outcome of ['success','network failure','API failure'])test(`obsolete History ${outcome} cannot replace current rows or pager`,async()=>{
 const qa=setup(),old=qa.start(),current=qa.start();qa.requests[1].resolve(success(2));await current;
 const before=qa.rendered.slice(),pager=qa.element('history-pager').innerHTML;
 if(outcome==='network failure')qa.requests[0].reject(new Error('Old failure'));else if(outcome==='API failure')qa.requests[0].resolve({ok:true,json:async()=>({status:'error',message:'Old failure'})});else qa.requests[0].resolve(success(1));
 await old;assert.deepEqual(qa.rendered,before);assert.equal(qa.element('history-pager').innerHTML,pager);assert.equal(qa.element('history-summary-total').textContent,'PHP 330.00');
});
test('current HTTP failure clears totals and obsolete page controls',async()=>{
 const qa=setup();qa.element('history-pager').innerHTML='Next';const pending=qa.start();
 assert.equal(qa.element('history-pager').innerHTML,'');assert.equal(qa.element('history-summary-total').textContent,'Loading…');
 qa.requests[0].resolve({ok:false,json:async()=>({status:'success',transactions:[{id:1}]})});await pending;
 assert.equal(qa.element('history-summary-total').textContent,'Unavailable');assert.equal(qa.element('history-pager').innerHTML,'');
 assert.match(qa.element('history-body').innerHTML,/^error:/);assert.equal(qa.rendered.some(r=>r[0]==='rows'),false);
});
