const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('app/Views/user/receipt.php', 'utf8');
const loader = source.slice(source.indexOf('(async function ()'), source.indexOf('</script>', source.indexOf('(async function ()'))).replace(/<\?=.*?\?>/g, '271');
for (const scenario of ['http', 'aborted']) test(`standalone receipt ${scenario} never enables Print`, async () => {
    let resolve, rendered = false;
    const signal = {aborted: false};
    const nodes = new Map();
    const node = id => {if (!nodes.has(id)) nodes.set(id, {innerHTML:'loading', attributes:{}, setAttribute(k,v){this.attributes[k]=v;}, disabled: false, querySelector: () => ({textContent:''}), addEventListener(){}});return nodes.get(id);};
    const context = vm.createContext({window:{IbemsUserNavigation:{currentSignal:signal},IbemsReceipt:{renderReceipt(){rendered=true;}}},document:{getElementById:node},fetch:()=>new Promise(r=>{resolve=r;})});
    const pending = vm.runInContext(loader, context);
    if (scenario === 'aborted') signal.aborted = true;
    resolve({ok: scenario !== 'http', json: async () => ({status:'success',transaction:{id:271}})});
    await pending;
    assert.equal(rendered, false);
    assert.equal(node('user-receipt-page-print').disabled, true);
    if (scenario === 'http') {
        assert.equal(node('user-receipt-page-result').className,'data-state data-state--error');
        assert.equal(node('user-receipt-page-result').attributes.role,'alert');
        assert.equal(node('user-receipt-page-result').attributes['aria-atomic'],'true');
    }
});

test('standalone User receipt preserves split payments and cash change',async()=>{
    let rendered;
    const payments=[{payment_method:'cash',amount:15,cash_received:20,change_due:5},{payment_method:'debt',amount:15}];
    const nodes=new Map();
    const node=id=>{if(!nodes.has(id))nodes.set(id,{disabled:true,addEventListener(){}});return nodes.get(id);};
    const context=vm.createContext({window:{IbemsUserNavigation:{currentSignal:{aborted:false}},location:{origin:'http://qa.test'},IbemsReceipt:{renderReceipt(id,receipt){rendered=receipt;}}},document:{getElementById:node},fetch:async()=>({ok:true,json:async()=>({status:'success',transaction:{id:271,payment_method:'split',amount:30,payments}})})});
    await vm.runInContext(loader,context);
    assert.deepEqual(rendered.payments,payments);assert.equal(node('user-receipt-page-print').disabled,false);
});
