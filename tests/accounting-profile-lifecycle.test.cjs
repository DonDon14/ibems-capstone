const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/accounting-debts.part2.js', 'utf8');
const lifecycle = source.slice(source.indexOf('let employeeModalReturnFocus;'), source.indexOf('document.getElementById("employee-modal").addEventListener("keydown"'));
const historyLoader = source.slice(source.indexOf('async function loadHistory('), source.indexOf('function buildEmployeeProfileHtml('));
test('pending financial action cannot reopen or switch the current employee',async()=>{
    const context=vm.createContext({window:{IbemsEmployeeActions:{busy:true}},employeeModalUserId:506});
    vm.runInContext(lifecycle,context);vm.runInContext('employeeModalRequest=3',context);assert.equal(await vm.runInContext('openEmployeeModal(13)',context),false);assert.equal(context.employeeModalUserId,506);assert.equal(vm.runInContext('employeeModalRequest',context),3);
});
test('a contradictory HTTP failure cannot masquerade as a refreshed employee profile',async()=>{
    const profileLoader=source.slice(source.indexOf('async function loadProfile('),source.indexOf('async function loadHistory('));
    const context=vm.createContext({window:{},fetch:async()=>({ok:false,json:async()=>({status:'success',data:{id:506,name:'Fictional'}})})});
    vm.runInContext(profileLoader,context);assert.equal(await vm.runInContext('loadProfile(506)',context),null);
});
test('import feedback resets assertive error semantics for progress and success',()=>{
    const {fixture}=require('./helpers/accounting-import-fixture.cjs');
    const {context,result}=fixture();
    vm.runInContext('setImportCsvResult("Choose file", "error")',context);
    assert.equal(result.attrs.role,'alert');
    vm.runInContext('setImportCsvResult("Checking CSV...")',context);
    assert.equal(result.attrs.role,'status');
    assert.equal(result.attrs['aria-live'],'polite');
    vm.runInContext('setImportCsvResult("Preview ready", "success")',context);
    assert.equal(result.textContent,'Preview ready');
    assert.match(result.className,/is-success/);
    vm.runInContext('setImportCsvResult("")',context);
    assert.equal(result.textContent,'');
    assert.equal(result.attrs.role,'status');
});
for (const closed of [false,true]) test(`profile network rejection ${closed?'after close stays quiet':'offers recovery'}`, async()=>{
    let rejectProfile;
    const elements = new Map();
    const element = id=>{
        if(!elements.has(id)) elements.set(id,{style:{display:'none'},classList:{add(){}},innerHTML:'',setAttribute(){},focus(){},scrollIntoView(){}});
        return elements.get(id);
    };
    const context = vm.createContext({window:{},employeeModalProfile:null,
        document:{getElementById:element,activeElement:null},getComputedStyle:e=>e.style,
        aDataState:(title,detail)=>`${title} ${detail}`,loadAcctSalarySchedules:async()=>{},
        loadProfile:()=>new Promise((resolve,reject)=>rejectProfile=reject)});
    vm.runInContext(lifecycle,context);
    const opening=vm.runInContext('openEmployeeModal(506)',context);
    await new Promise(resolve=>setImmediate(resolve));
    if(closed) vm.runInContext('closeEmployeeModal()',context);
    rejectProfile(new Error('Offline'));
    await opening;
    const markup=element('employee-modal-profile').innerHTML;
    if(closed) assert.match(markup,/Loading employee profile/);
    else {
        assert.match(element('employee-read-feedback').textContent,/Retry employee details/);
        assert.equal(element('employee-read-retry').hidden,false);
        assert.doesNotMatch(markup,/Loading employee profile/);
    }
});
for (const scenario of ['empty','http','application','malformed']) test(`employee history distinguishes ${scenario} result`, async()=>{
    const context = vm.createContext({window:{},fetch:async()=>({ok:scenario!=='http',json:async()=>({status:scenario==='application'?'error':'success',data:scenario==='malformed'?null:[]})})});
    vm.runInContext(historyLoader,context);
    const request = vm.runInContext('loadHistory(506)',context);
    if(scenario==='empty') assert.equal((await request).length,0);
    else await assert.rejects(request,/unavailable/);
});
for (const stage of ['schedule', 'profile']) test(`closing during ${stage} load ignores late employee response`, async () => {
    let release; let calls = 0;
    const pending = new Promise(resolve => release = resolve);
    const elements = new Map();
    const element = id => {
        if (!elements.has(id)) elements.set(id, {style:{display:'none'}, classList:{add(){}}, innerHTML:'', setAttribute(){},focus(){},scrollIntoView(){}});
        return elements.get(id);
    };
    const context = vm.createContext({window:{}, employeeModalUserId:null, employeeModalProfile:null,
        document:{getElementById:element,activeElement:null},getComputedStyle:e=>e.style,
        aDataState:title=>title, loadAcctSalarySchedules:()=>stage==='schedule'?pending:Promise.resolve(),
        loadProfile:()=>{calls++;return pending;}, buildEmployeeProfileHtml:()=>{throw new Error('Stale profile rendered');}});
    vm.runInContext(lifecycle, context);
    const opening = vm.runInContext('openEmployeeModal(506)', context);
    await new Promise(resolve=>setImmediate(resolve));
    vm.runInContext('closeEmployeeModal()', context);
    release({name:'Alyssa'});
    await opening;
    assert.equal(calls,stage==='schedule'?0:1);
    assert.equal(context.employeeModalProfile,null);
    assert.equal(element('employee-modal').style.display,'none');
});

test('a replaced page ignores a delayed schedule response', async () => {
    let release; let profileCalls = 0;
    const signal = {aborted:false};
    const elements = new Map();
    const element = id => {
        if (!elements.has(id)) elements.set(id,{style:{display:'none'},classList:{add(){}},innerHTML:'',setAttribute(){},focus(){},scrollIntoView(){}});
        return elements.get(id);
    };
    const context = vm.createContext({window:{IbemsPortalNavigation:{currentSignal:signal}},
        document:{getElementById:element,activeElement:null},getComputedStyle:e=>e.style,
        aDataState:title=>title,loadAcctSalarySchedules:()=>new Promise(resolve=>release=resolve),
        loadProfile:()=>{profileCalls++;}});
    vm.runInContext(lifecycle,context);
    const opening = vm.runInContext('openEmployeeModal(506)',context);
    signal.aborted = true;
    release();
    await opening;
    assert.equal(profileCalls,0);
});

test('a newer employee selection supersedes an earlier pending profile', async () => {
    const releases = new Map();
    const requested = [];
    const elements = new Map();
    const element = id => {
        if (!elements.has(id)) elements.set(id,{style:{display:'none'},classList:{add(){}},innerHTML:'',setAttribute(){},focus(){},scrollIntoView(){}});
        return elements.get(id);
    };
    const context = vm.createContext({window:{},employeeModalProfile:null,
        document:{getElementById:element,activeElement:null},getComputedStyle:e=>e.style,
        aDataState:title=>title,loadAcctSalarySchedules:async()=>{},
        loadProfile:id=>{requested.push(id);return new Promise(resolve=>releases.set(id,resolve));},
        buildEmployeeProfileHtml:()=>{throw new Error('Superseded profile rendered');}});
    vm.runInContext(lifecycle,context);
    const first = vm.runInContext('openEmployeeModal(506)',context);
    await new Promise(resolve=>setImmediate(resolve));
    const second = vm.runInContext('openEmployeeModal(13)',context);
    await new Promise(resolve=>setImmediate(resolve));
    releases.get(506)({name:'Alyssa'});
    await first;
    assert.equal(context.employeeModalProfile,null);
    assert.deepEqual(requested,[506,13]);
    releases.get(13)(null);
    await second;
    assert.match(element('employee-modal-profile').innerHTML,/Unable to load/);
});
