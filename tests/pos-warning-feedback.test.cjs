const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/store-pos.js','utf8');
const toast=source.slice(source.indexOf('function showToast('),source.indexOf('async function requestJson('));
const start=source.indexOf('function setResult(');
const result=source.slice(start,source.indexOf('\nfunction ',start+1));

test('store-day opening and closing errors expose persistent assertive feedback', () => {
 const part2=fs.readFileSync('public/assets/js/store-pos.part2.js','utf8');
 const closeStart=part2.indexOf('function setStoreDayCloseResult(');
 const opening=source.slice(source.indexOf('function setOpeningBalanceResult('),source.indexOf('function setPosTransactionEnabled('));
 const closing=part2.slice(closeStart,part2.indexOf('\nfunction ',closeStart+1));
 const nodes=new Map();
 for(const id of ['opening-balance-result','store-day-close-result']) nodes.set(id,{attrs:{},dataset:{},style:{},setAttribute(k,v){this.attrs[k]=v;}});
 const ctx=vm.createContext({document:{getElementById:id=>nodes.get(id)},showToast(){}});
 vm.runInContext(opening+closing,ctx);
 ctx.setOpeningBalanceResult('Counted cash is required.','error');
 ctx.setStoreDayCloseResult('Enter a closing reason.','error');
 for(const node of nodes.values()) {assert.equal(node.attrs.role,'alert');assert.equal(node.attrs['aria-live'],'assertive');assert.equal(node.attrs['aria-atomic'],'true');assert.ok(node.textContent);}
 ctx.setStoreDayCloseResult('Store day closed.','ok');
 assert.equal(nodes.get('store-day-close-result').attrs.role,'status');
 assert.equal(nodes.get('store-day-close-result').attrs['aria-live'],'polite');
});
test('POS warning uses real toast path without an error tone and exposes normal live feedback',()=>{
 const tones=[],toasts=[],attrs={};const resultNode={dataset:{},setAttribute:(key,value)=>attrs[key]=value};
 const ctx=vm.createContext({ensureToastHost:()=>({appendChild:node=>toasts.push(node)}),document:{getElementById:()=>resultNode,createElement:()=>({setAttribute(){},remove(){}})},window:{IbemsFeedbackSound:{play:type=>tones.push(type)},setTimeout(){}}});
 vm.runInContext(toast+result,ctx);vm.runInContext('setResult("Payment recorded; reload balances.","warning")',ctx);
 assert.deepEqual(tones,[]);assert.equal(toasts[0].className,'toast-item is-warning');assert.equal(resultNode.dataset.tone,'warning');assert.equal(attrs.role,'status');assert.equal(attrs['aria-live'],'polite');
 vm.runInContext('setResult("Rejected payment","error")',ctx);assert.deepEqual(tones,['error']);assert.equal(attrs.role,'alert');assert.equal(attrs['aria-live'],'assertive');
});
