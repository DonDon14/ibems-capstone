const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/department-portal.js', 'utf8');
function setup() {
    const elements = new Map(['department-portal-dashboard','department-portal-summary','department-portal-entries'].map(id => [id,{innerHTML:''}]));
    let period = 'day';
    let change;
    const requests = [];
    const context = {AbortController, document: {getElementById:id=>elements.get(id), createElement:()=>({set textContent(v) {this.innerHTML=String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');}})}, window: {IbemsDashboardPeriod:{get:()=>period,setMeta(){}},addEventListener(name,fn){change=fn;}}, fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject}))};
    const controller = new AbortController();
    context.window.IbemsPortalNavigation = {currentSignal:controller.signal};
    vm.runInNewContext(source,context);
    return {elements, requests, abort(){controller.abort();}, changePeriod(value){period=value;change();}};
}
const flush = () => new Promise(resolve=>setImmediate(resolve));
const emptyResponse = () => ({ok:true,json:async()=>({status:'success',assignments:[],recent_entries:[]})});
test('department period refresh clears prior figures while loading',async()=>{
    const qa=setup();
    qa.requests[0].resolve({ok:true,json:async()=>({status:'success',assignments:[{name:'Prior department',period_charges:123}],recent_entries:[]})}); await flush();
    assert.match(qa.elements.get('department-portal-summary').innerHTML,/123\.00/);
    qa.changePeriod('month');
    assert.match(qa.elements.get('department-portal-summary').innerHTML,/Loading department accounts/);
    assert.doesNotMatch(qa.elements.get('department-portal-summary').innerHTML,/123\.00/);
    assert.match(qa.elements.get('department-portal-entries').innerHTML,/Loading recent entries/);
});
test('returning to a period ignores its previous request success and failure',async()=>{
    for(const outcome of ['success','failure']) {
        const qa=setup(); qa.changePeriod('month'); qa.changePeriod('day');
        qa.requests[2].resolve(emptyResponse()); await flush();
        const current=qa.elements.get('department-portal-summary').innerHTML;
        if(outcome==='failure') qa.requests[0].reject(new Error('Obsolete request'));
        else qa.requests[0].resolve({ok:true,json:async()=>({status:'success',assignments:[{name:'Obsolete department'}],recent_entries:[]})});
        await flush(); assert.equal(qa.elements.get('department-portal-summary').innerHTML,current);
    }
});
test('navigation abort prevents late department results from rendering',async()=>{
    const qa=setup(); qa.abort();
    const current=qa.elements.get('department-portal-summary').innerHTML;
    qa.requests[0].resolve(emptyResponse()); await flush();
    assert.equal(qa.elements.get('department-portal-summary').innerHTML,current);
});
test('department dashboard failure is an escaped error alert',async()=>{
    const qa=setup(); qa.requests[0].reject(new Error('<unavailable>')); await flush();
    for(const id of ['department-portal-summary','department-portal-entries']) {
        const html=qa.elements.get(id).innerHTML;
        assert.match(html,/data-state--error/); assert.match(html,/role="alert"/); assert.match(html,/&lt;unavailable&gt;/);
    }
});
test('late old-period failure cannot overwrite current department results',async()=>{
    const qa=setup(); qa.changePeriod('month');
    qa.requests[1].resolve({ok:true,json:async()=>({status:'success',assignments:[],recent_entries:[]})}); await flush();
    const current=qa.elements.get('department-portal-summary').innerHTML;
    assert.match(current,/No active department accounts/);
    qa.requests[0].reject(new Error('Old request failed')); await flush();
    assert.equal(qa.elements.get('department-portal-summary').innerHTML,current);
    assert.doesNotMatch(qa.elements.get('department-portal-entries').innerHTML,/Old request failed/);
});
