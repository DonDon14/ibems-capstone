const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/user-stores.js', 'utf8');
const loader = source.slice(source.indexOf('async function usLoad()'), source.indexOf('document.querySelectorAll("#us-store'));
for (const scenario of ['failure', 'stale', 'departed']) test(`catalog load handles ${scenario} without stale totals`, async () => {
    const elements = {}, requests = [], rendered = [];
    const element = id => elements[id] ||= {value: id === 'us-sort' ? 'store:asc' : '', textContent: '97', attrs: {}, contains: () => false, querySelectorAll: () => [], setAttribute(k,v) {this.attrs[k]=v;}};
    const signal = {aborted:false};
    const context = vm.createContext({usPageSignal:signal, usRequestSequence:0, usPage:1, usOptionsLoaded:true, usRows:[{id:9}], URLSearchParams,
        document:{getElementById:element, body:{}}, usUpdateResetFilters(){}, usState:(type,message)=>`${type}:${message}`,
        usRenderProducts: rows=>rendered.push(rows), usRenderPager(){},
        fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject}))});
    vm.runInContext(loader,context);
    const first = vm.runInContext('usLoad()',context);
    assert.equal(element('us-product-count').textContent,'Loading…');
    if(scenario === 'stale') {
        const second = vm.runInContext('usLoad()',context);
        requests[1].resolve({ok:true,json:async()=>({status:'success',data:[{id:2,availability:'available'}],pagination:{total:1}})});
        await second;
    }
    if(scenario === 'departed') signal.aborted=true;
    if(scenario === 'failure') requests[0].reject(Error('offline'));
    else requests[0].resolve({ok:true,json:async()=>({status:'success',data:[{id:1}],pagination:{total:30}})});
    await first;
    if(scenario === 'failure') {
        assert.equal(element('us-product-count').textContent,'Unavailable');
        assert.equal(element('us-available-count').textContent,'Unavailable');
        assert.equal(element('us-products').attrs['aria-busy'],'false');
        assert.equal(element('us-pager').innerHTML,'');
        assert.equal(context.usRows.length,0);
    } else if(scenario === 'stale') {
        assert.equal(element('us-product-count').textContent,'1');
        assert.equal(rendered.length,1); assert.equal(rendered[0][0].id,2);
    } else assert.equal(rendered.length,0);
});
