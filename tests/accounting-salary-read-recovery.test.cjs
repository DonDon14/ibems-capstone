const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
const main=fs.readFileSync('public/assets/js/accounting-debts.js','utf8');
const part=fs.readFileSync('public/assets/js/accounting-debts.part2.js','utf8');
const loader=main.slice(main.indexOf('async function loadAcctSalarySchedules()'),main.indexOf('function selectedAcctSalarySchedule()'));
const lifecycle=part.slice(part.indexOf('let employeeModalReturnFocus;'),part.indexOf('document.getElementById("employee-modal").addEventListener'));
const recordLoaders=part.slice(part.indexOf('async function loadProfile('),part.indexOf('function buildEmployeeProfileHtml('));
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const valid=()=>({status:'success',default_credit_percentage:30,data:[{id:7,name:'Fictional schedule',code:'QA',effective_from:'2026-01-01',effective_to:null,rates:[{salary_grade:1,salary_step:1,monthly_salary:10000}]}]});
const response=(data,ok=true)=>({ok,json:async()=>data});
for(const failure of ['different employee','missing money','invalid money','invalid pending','malformed object','valid unconfigured']) test(`employee profile read distinguishes ${failure}`,async()=>{
    let profile={user_id:900001,name:'Fictional',financial_profile_configured:true,base_salary:10000,credit_limit:2500,current_debt:0,available_credit:2500,credit_percentage:25,pending_salary_profiles:[]};
    if(failure==='different employee')profile.user_id=900003;
    if(failure==='missing money')delete profile.credit_limit;
    if(failure==='invalid money')profile.current_debt='';
    if(failure==='invalid pending')profile.pending_salary_profiles=[{}];
    if(failure==='malformed object')profile=[];
    if(failure==='valid unconfigured'){profile.financial_profile_configured=false;profile.base_salary=null;}
    const signal={aborted:false},calls=[];const context=vm.createContext({window:{IbemsPortalNavigation:{currentSignal:signal}},fetch:async(...args)=>{calls.push(args);return response({status:'success',data:profile});}});
    vm.runInContext(recordLoaders,context);const result=await vm.runInContext('loadProfile(900001)',context);
    if(failure==='valid unconfigured')assert.equal(result,profile);else assert.equal(result,null);assert.equal(calls[0][1].signal,signal);
});
test('financial history rejects malformed rows while accepting legacy empty payloads',async()=>{
    for(const invalid of [true,false]){const row={id:900003,action:'ACCOUNTING_UPDATE_FINANCIAL_PROFILE',created_at:'2026-10-04 18:00:00',actor_name:'Unknown',payload:[]};
        const context=vm.createContext({window:{},fetch:async()=>response({status:'success',data:invalid?[null]:[row]})});vm.runInContext(recordLoaders,context);const request=vm.runInContext('loadHistory(900001)',context);if(invalid)await assert.rejects(request,/unavailable/);else assert.equal((await request)[0],row);}
});
function catalogFixture(fetch) {
    const requests=[],signal={aborted:false};
    const context=vm.createContext({acctSalarySchedulesPromise:null,acctSalarySchedulesSignal:undefined,acctSalarySchedules:[],acctDefaultCreditPercentage:25,
        window:{IbemsPortalNavigation:{currentSignal:signal}},fetch:async(...args)=>{requests.push(args);return fetch(...args);}});
    vm.runInContext(loader,context);
    return {context,requests,signal,load:()=>vm.runInContext('loadAcctSalarySchedules()',context)};
}
for(const failure of ['network','http','parser','application','empty','no rates','invalid rate','duplicate rate','duplicate schedule','invalid date','reversed dates','invalid percentage']) test(`salary catalog retries ${failure} failure and shares a valid read`,async()=>{
    let count=0;
    const f=catalogFixture(async()=>{
        if(++count>1)return response(valid());
        if(failure==='network')throw Error('Offline');
        if(failure==='parser')return {ok:true,json:async()=>{throw Error('Unreadable');}};
        const data=valid(),schedule=data.data[0];
        if(failure==='application')data.status='error';
        if(failure==='empty')data.data=[];
        if(failure==='no rates')schedule.rates=[];
        if(failure==='invalid rate')schedule.rates[0].monthly_salary='';
        if(failure==='duplicate rate')schedule.rates.push({...schedule.rates[0]});
        if(failure==='duplicate schedule')data.data.push({...schedule});
        if(failure==='invalid date')schedule.effective_from='2026-02-30';
        if(failure==='reversed dates')schedule.effective_to='2025-12-31';
        if(failure==='invalid percentage')data.default_credit_percentage=101;
        return response(data,failure!=='http');
    });
    await assert.rejects(f.load());assert.equal(f.context.acctSalarySchedulesPromise,null);assert.equal(f.context.acctSalarySchedules.length,0);
    const [first,concurrent]=await Promise.all([f.load(),f.load()]);assert.equal(first,concurrent);assert.equal(first[0].id,7);
    await f.load();assert.equal(f.requests.length,2);assert.equal(f.requests[1][0],'/accounting/salary-schedules');assert.equal(f.requests[1][1].signal,f.signal);assert.equal(f.context.acctDefaultCreditPercentage,30);
});
test('a departed catalog cannot commit or clear a replacement page request',async()=>{
    const releases=[];const f=catalogFixture(()=>new Promise(resolve=>releases.push(resolve)));
    const old=f.load();const oldOutcome=assert.rejects(old);await tick();f.signal.aborted=true;
    f.context.window.IbemsPortalNavigation.currentSignal={aborted:false};const current=f.load();await tick();
    releases[0](response(valid()));await oldOutcome;assert.notEqual(f.context.acctSalarySchedulesPromise,null);assert.equal(f.context.acctSalarySchedules.length,0);
    releases[1](response(valid()));await current;assert.equal(f.requests.length,2);assert.equal(f.context.acctSalarySchedules[0].id,7);
});
function employeeFixture({schedule=async()=>[],profile=async id=>({user_id:id,name:'Fictional employee',pending_salary_profiles:[],financial_profile_configured:true}),history=async()=>[]}={}) {
    const nodes=new Map(),calls=[],signal={aborted:false},classes=new Map();let document;
    const node=id=>{
        if(!nodes.has(id))nodes.set(id,{id,isConnected:true,style:{display:id==='employee-modal'?'none':'block'},attrs:{},hidden:false,disabled:false,textContent:'',innerHTML:'',value:'draft',
            setAttribute(k,v){this.attrs[k]=v;},getAttribute(k){return this.attrs[k]??null;},focus(){document.activeElement=this;},scrollIntoView(){this.scrolled=true;},
            classList:{add(k){classes.set(`${id}:${k}`,true);},remove(k){classes.set(`${id}:${k}`,false);}},querySelectorAll(){return id==='employee-modal-actions'?[node('employee-salary-value'),node('employee-limit-reason'),node('employee-open-limit-edit'),node('already-disabled')]:[];}});
        return nodes.get(id);
    };
    document={body:node('body'),activeElement:node('opener'),getElementById:node};node('already-disabled').disabled=true;
    const context=vm.createContext({employeeModalUserId:null,employeeModalProfile:null,acctDefaultCreditPercentage:25,document,getComputedStyle:e=>e.style,
        window:{IbemsPortalNavigation:{currentSignal:signal},IbemsEmployeeActions:{busy:false,clear(){node('employee-action-feedback').textContent='';}}},
        aDataState:(title,detail)=>`${title}: ${detail}`,aBool:Boolean,aCategory:String,aMoney:String,buildEmployeeProfileHtml:p=>`Employee ${p.user_id}`,buildHistoryHtml:rows=>rows.length?'Populated history':'No financial history yet',
        populateAcctSalaryProfile(){calls.push('populate');},loadAcctSalarySchedules:()=>{calls.push('schedule');return schedule();},
        loadProfile:(id,s)=>{calls.push(['profile',id,s]);return profile(id);},loadHistory:(id,s)=>{calls.push(['history',id,s]);return history(id);}});
    vm.runInContext(lifecycle,context);
    const open=(id=900001,options={})=>vm.runInContext(`openEmployeeModal(${id},${JSON.stringify(options)})`,context);
    return {context,node,document,signal,calls,classes,open,retry:()=>vm.runInContext('retryEmployeeHistory()',context),close:()=>vm.runInContext('closeEmployeeModal()',context)};
}
test('local employee retry clears stale profile, suppresses duplicate reads and recovers owned focus',async()=>{
    let reads=0,release;const f=employeeFixture({schedule:()=>++reads===1?Promise.reject(Error('Offline')):new Promise(resolve=>release=resolve)});
    f.context.employeeModalProfile={user_id:1};assert.equal(await f.open(),false);assert.equal(f.context.employeeModalProfile,null);
    assert.match(f.node('employee-read-feedback').textContent,/Salary schedules/);assert.equal(f.node('employee-read-feedback').attrs.role,'alert');assert.equal(f.node('employee-read-retry').hidden,false);
    f.node('employee-read-retry').focus();const retry=f.open(900001,{focus:false,retry:true,preserveFeedback:true});assert.equal(await f.open(),false);
    assert.equal(f.document.activeElement,f.node('employee-read-feedback'));assert.equal(f.node('employee-read-feedback').attrs['aria-live'],'polite');assert.equal(f.node('employee-modal').attrs['aria-busy'],'true');
    release([]);assert.equal(await retry,true);assert.equal(reads,2);assert.equal(f.document.activeElement,f.node('employee-modal-profile'));assert.equal(f.node('employee-read-retry').hidden,true);assert.equal(f.node('employee-modal').attrs['aria-busy'],'false');
    assert.equal(f.classes.get('employee-modal-actions:hidden'),false);assert.equal(f.calls.find(c=>Array.isArray(c)&&c[0]==='profile')[1],900001);
});
test('history retry keeps the profile, editor draft, confirmed action and original disabled states',async()=>{
    let reads=0,release;const f=employeeFixture({history:()=>++reads===1?Promise.reject(Error('Offline')):new Promise(resolve=>release=resolve)});
    await f.open();const profile=f.context.employeeModalProfile;f.node('employee-salary-value').value='12000';f.node('employee-limit-reason').value='Retained reason';f.node('employee-action-feedback').textContent='Financial profile updated';
    assert.equal(f.node('employee-read-retry').textContent,'Retry financial history');f.node('employee-read-retry').focus();const pending=f.retry();assert.equal(await f.retry(),false);
    assert.equal(f.node('employee-salary-value').disabled,true);assert.equal(f.document.activeElement,f.node('employee-read-feedback'));release([]);assert.equal(await pending,true);
    assert.equal(f.context.employeeModalProfile,profile);assert.equal(f.node('employee-salary-value').value,'12000');assert.equal(f.node('employee-limit-reason').value,'Retained reason');assert.equal(f.node('employee-action-feedback').textContent,'Financial profile updated');
    assert.equal(f.node('employee-salary-value').disabled,false);assert.equal(f.node('already-disabled').disabled,true);assert.equal(f.document.activeElement,f.node('employee-modal-history'));assert.equal(f.calls.filter(c=>Array.isArray(c)&&c[0]==='profile').length,1);
});
test('history retry rejection restores one focused local recovery and preserves outside focus',async()=>{
    for(const outside of [false,true]){let reject;const f=employeeFixture({history:()=>Promise.reject(Error('Offline'))});await f.open();f.context.loadHistory=()=>new Promise((resolve,r)=>reject=r);f.node('employee-read-retry').focus();const pending=f.retry();if(outside)f.node('outside').focus();reject(Error('Offline'));await pending;
        assert.equal(f.node('employee-read-retry').hidden,false);assert.equal(f.node('employee-salary-value').disabled,false);assert.equal(f.node('employee-read-feedback').attrs.role,'alert');assert.equal(f.document.activeElement,f.node(outside?'outside':'employee-read-retry'));}
});
test('closing or switching a pending history retry restores its frozen fields and ignores the old reply',async()=>{
    for(const switchEmployee of [false,true]){let release;const f=employeeFixture({history:async()=>{throw Error('Offline');}});await f.open();f.context.loadHistory=()=>new Promise(resolve=>release=resolve);const pending=f.retry();assert.equal(f.node('employee-salary-value').disabled,true);
        if(switchEmployee){f.context.loadHistory=async()=>[];await f.open(900003);}else f.close();assert.equal(f.node('employee-salary-value').disabled,false);const markup=f.node('employee-modal-history').innerHTML;release([{action:'old'}]);await pending;assert.equal(f.node('employee-modal-history').innerHTML,markup);assert.equal(f.context.employeeModalUserId,switchEmployee?900003:null);}
});
test('employee recovery ignores aborted or detached reads and never takes outside focus',async()=>{
    for(const departure of ['abort','detach','outside']){let release;const f=employeeFixture({schedule:()=>new Promise(resolve=>release=resolve)});f.node('employee-read-retry').focus();const pending=f.open(900001,{focus:false,retry:true});if(departure==='abort')f.signal.aborted=true;if(departure==='detach')f.node('employee-modal').isConnected=false;if(departure==='outside')f.node('outside').focus();release([]);await pending;
        if(departure==='outside'){assert.equal(f.document.activeElement,f.node('outside'));assert.equal(f.context.employeeModalProfile.user_id,900001);}else{assert.equal(f.context.employeeModalProfile,null);assert.match(f.node('employee-read-feedback').textContent,/Loading/);assert.equal(f.calls.some(c=>Array.isArray(c)),false);}}
});
test('a fresh page signal can recover an employee whose earlier read was abandoned',async()=>{
    let release;const f=employeeFixture({schedule:()=>new Promise(resolve=>release=resolve)});const abandoned=f.open();f.signal.aborted=true;
    f.context.window.IbemsPortalNavigation.currentSignal={aborted:false};f.context.loadAcctSalarySchedules=async()=>[];
    assert.equal(await f.open(),true);const markup=f.node('employee-modal-profile').innerHTML;release([]);await abandoned;
    assert.equal(f.node('employee-modal-profile').innerHTML,markup);assert.equal(f.context.employeeModalProfile.user_id,900001);assert.equal(f.node('employee-modal').attrs['aria-busy'],'false');
});
