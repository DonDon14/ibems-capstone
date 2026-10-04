const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {fixture:queueFixture,data:queueData,response:queueResponse}=require('./helpers/salary-queue-fixture.cjs');
const source=fs.readFileSync('public/assets/js/accounting-debts.part2.js','utf8');
const loaders=source.slice(0,source.indexOf('function getMainFilteredRows()'));
function fixture(fetch){
    const elements=new Map();
    const element=id=>{
        if(!elements.has(id))elements.set(id,{textContent:'Previous',attributes:{},setAttribute(k,v){this.attributes[k]=v;}});
        return elements.get(id);
    };
    const signal={aborted:false};
    const context=vm.createContext({window:{IbemsPortalNavigation:{currentSignal:signal}},document:{getElementById:element},fetch,aMoney:String});
    vm.runInContext(loaders,context);
    return{context,element,signal};
}
test('scheduled queue failure announces retry and later empty success restores status',async()=>{
    let fail=true;
    const f=queueFixture(async()=>{if(fail)throw new Error('Offline');return queueResponse(queueData({rows:[],total_pending:0,due_count:0,has_more:false}));});
    assert.equal(await vm.runInContext('loadScheduledSalaryQueue()',f.context),false);
    const body=f.feedback;
    assert.equal(body.attrs.role,'alert');assert.match(body.innerHTML,/Retry scheduled salaries/);
    fail=false;
    assert.equal(await vm.runInContext('loadScheduledSalaryQueue()',f.context),true);
    assert.equal(body.attrs.role,'status');assert.match(body.textContent,/No scheduled/);
});
test('daily summary ignores an older response and responses after page abort',async()=>{
    const pending=[];
    const f=fixture(()=>new Promise(resolve=>pending.push(resolve)));
    const first=vm.runInContext('loadDailySummary()',f.context);
    const second=vm.runInContext('loadDailySummary()',f.context);
    const reply=n=>({ok:true,json:async()=>({status:'success',deduction_count:n,deducted_amount:n})});
    pending[1](reply(2));await second;
    pending[0](reply(1));await first;
    assert.equal(f.element('acct-today-count').textContent,'2');
    const third=vm.runInContext('loadDailySummary()',f.context);
    f.signal.aborted=true;pending[2](reply(3));await third;
    assert.equal(f.element('acct-today-count').textContent,'2');
});
