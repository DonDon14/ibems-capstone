const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/accounting-debts.part2.js','utf8');
const loader=source.slice(source.indexOf('let accountingDataRequest ='),source.indexOf('async function loadSettlementRuns('));
function fixture(fetch, initial=[{name:'Previous'}]){
    const messages=[];
    const elements=new Map();
    const element=id=>{if(!elements.has(id))elements.set(id,{innerHTML:'Loading',textContent:''});return elements.get(id);};
    const context=vm.createContext({window:{},URLSearchParams,fetch,acctRows:initial,
        document:{getElementById:element},aDataState:(title,detail)=>`${title} ${detail}`,
        acctAdvanceRows:[],acctOperatorRows:[],acctDataSummary:null,
        applyMainFiltersAndRender(){},loadDailySummary:async()=>{},loadScheduledSalaryQueue:async()=>{},
        setAcctResult:(message,type)=>messages.push({message,type})});
    vm.runInContext(loader,context);
    return {context,messages,element,load:()=>vm.runInContext('loadData()',context)};
}
test('first Accounting load failure replaces Loading with an alert and retry',async()=>{
    const f=fixture(async()=>{throw new Error('Offline');},[]);
    assert.equal(await f.load(),false);
    assert.match(f.element('acct-body').innerHTML,/role="alert"/);
    assert.match(f.element('acct-body').innerHTML,/Refresh/);
    assert.doesNotMatch(f.element('acct-body').innerHTML,/Loading/);
    assert.equal(f.element('acct-count-text').textContent,'Records unavailable');
});
for(const failure of ['network','http'])test(`Accounting ${failure} refresh failure preserves prior records and offers retry`,async()=>{
    const f=fixture(async()=>{if(failure==='network')throw new Error('Offline');return{ok:false,json:async()=>({status:'success',data:[]})};});
    assert.equal(await f.load(),false);
    assert.equal(f.context.acctRows[0].name,'Previous');
    assert.equal(f.messages.at(-1).type,'error');
    assert.match(f.messages.at(-1).message,/outdated.*Refresh/);
});
test('older Accounting refresh cannot overwrite newer employee records',async()=>{
    const pending=[];
    const f=fixture(()=>new Promise(resolve=>pending.push(resolve)));
    const older=f.load(),newer=f.load();
    const reply=name=>({ok:true,json:async()=>({status:'success',data:[{name}]})});
    pending[1](reply('Current'));assert.equal(await newer,true);
    pending[0](reply('Stale'));assert.equal(await older,false);
    assert.equal(f.context.acctRows[0].name,'Current');
});
