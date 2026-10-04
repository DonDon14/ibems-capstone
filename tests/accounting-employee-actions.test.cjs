const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/accounting-employee-actions.js','utf8');
const part2=fs.readFileSync('public/assets/js/accounting-debts.part2.js','utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const reply=(data,ok=true)=>({ok,json:async()=>data});
const success={status:'success',new_base_salary:12000,new_credit_limit:3000};
function fixture({fetch=async()=>reply({status:'error',message:'Rejected'}),confirm=async()=>true,read=async()=>true,soundThrows=false}={}) {
    const nodes=new Map(),requests=[],sounds=[],confirmations=[],reads=[];
    class Node {
        constructor(id,control=false){this.id=id;this.control=control;this.children=[];this.disabled=false;this.isConnected=true;this.visible=true;this.style={display:'grid',visibility:'visible'};this.attrs={};this.dataset={};this.events={};this.value='';this.innerHTML='Save';this.textContent='';this.className='';this.classList={contains:name=>this.className.split(' ').includes(name)};this.tabIndex=0;}
        append(child){this.children.push(child);child.parent=this;}
        contains(child){return child===this||this.children.some(node=>node.contains(child));}
        getClientRects(){return this.visible?[{}]:[];}
        getAttribute(k){return this.attrs[k]??null;}
        setAttribute(k,v){this.attrs[k]=v;}
        removeAttribute(k){delete this.attrs[k];}
        addEventListener(k,v){this.events[k]=v;}
        querySelectorAll(){return this.children.filter(child=>child.control);}
        querySelector(){return null;}
        focus(){document.activeElement=this;}
        scrollIntoView(){this.scrolled=true;}
    }
    const document={body:new Node('body'),activeElement:null,getElementById:id=>nodes.get(id)};
    const modal=new Node('employee-modal');nodes.set(modal.id,modal);
    for(const id of ['employee-limit-save','employee-employment-type','employee-salary-schedule','employee-salary-grade','employee-salary-step','employee-salary-effective-date','employee-salary-value','employee-credit-percentage','employee-limit-reason','employee-limit-value','close-employee-modal','activation','employee-action-feedback']){
        const node=new Node(id,id!=='employee-action-feedback');nodes.set(id,node);modal.append(node);
    }
    const values={'employee-employment-type':'cos','employee-salary-schedule':'7','employee-salary-grade':'1','employee-salary-step':'1','employee-salary-effective-date':'2026-10-01','employee-salary-value':'12000.00','employee-credit-percentage':'25','employee-limit-reason':'Fictional approved salary','employee-limit-value':'PHP 3000.00'};
    Object.entries(values).forEach(([id,value])=>nodes.get(id).value=value);
    nodes.get('activation').dataset.activateSalaryId='900002';
    document.activeElement=nodes.get('employee-limit-save');
    const signal={aborted:false};
    const context=vm.createContext({document,getComputedStyle:e=>e.style,employeeModalUserId:900001,employeeModalProfile:{id:900001},employeeModalRequest:3,employeeModalReturnFocus:null,employeeReadRelease:()=>{},employeeModalLoading:false,aMoney:String,
        window:{IbemsPortalNavigation:{currentSignal:signal},IbemsDialog:{confirm:async(...args)=>{confirmations.push(args);return confirm(...args);}},IbemsFeedbackSound:{play:type=>{sounds.push(type);if(soundThrows)throw Error('Audio unavailable');}}},
        fetch:async(url,options)=>{requests.push({url,options});return fetch(url,options);},
        openEmployeeModal:async(id,options)=>{reads.push({id,options});context.employeeModalRequest++;if(options.focus)nodes.get('close-employee-modal').focus();return read('employee');},loadData:()=>read('accounting')});
    vm.runInContext(source,context);
    vm.runInContext(part2.slice(part2.indexOf('function closeEmployeeModal()'),part2.indexOf('document.getElementById("employee-read-retry").addEventListener')),context);
    const actions=context.window.IbemsEmployeeActions;
    return {nodes,document,modal,context,signal,requests,sounds,confirmations,reads,actions,result:nodes.get('employee-action-feedback'),save:()=>actions.save(),activate:()=>actions.activate(nodes.get('activation')),key(key,defaultPrevented=false){let prevented=false;modal.events.keydown({key,currentTarget:modal,defaultPrevented,preventDefault(){prevented=true;}});return prevented;}};
}
test('salary confirmation freezes original fields, protects cross-actions and posts the reviewed employee packet',async()=>{
    let approve,release;
    const f=fixture({confirm:()=>new Promise(resolve=>approve=resolve),fetch:()=>new Promise(resolve=>release=resolve)});
    f.nodes.get('employee-limit-value').disabled=true;
    const saving=f.save();await tick();
    assert.equal(f.modal.getAttribute('aria-busy'),'true');assert.equal(f.actions.busy,true);assert.equal(f.nodes.get('employee-salary-value').disabled,true);
    await f.save();await f.activate();assert.equal(f.confirmations.length,1);
    vm.runInContext('closeEmployeeModal()',f.context);assert.equal(f.modal.style.display,'grid');
    assert.equal(f.key('Tab'),true);assert.equal(f.key('Escape'),true);assert.equal(f.document.activeElement,f.result);
    approve(true);await tick();
    assert.equal(f.requests.length,1);assert.equal(f.requests[0].url,'/accounting/debts/financial-profile');
    assert.deepEqual(JSON.parse(f.requests[0].options.body),{user_id:900001,employment_type:'cos',salary_schedule_id:7,salary_grade:'1',salary_step:'1',salary_effective_date:'2026-10-01',monthly_salary:'12000.00',credit_percentage:25,reason:'Fictional approved salary'});
    assert.equal(f.requests[0].options.signal,f.signal);
    release(reply({status:'error',message:'Server rejection <literal>'}));await saving;
    assert.equal(f.result.textContent,'Server rejection <literal>');assert.equal(f.result.getAttribute('role'),'alert');assert.equal(f.actions.busy,false);assert.equal(f.nodes.get('employee-limit-save').innerHTML,'Save');
    assert.equal(f.nodes.get('employee-limit-value').disabled,true);assert.equal(f.nodes.get('employee-salary-value').disabled,false);assert.equal(f.nodes.get('employee-salary-value').value,'12000.00');assert.equal(f.modal.getAttribute('aria-busy'),null);
    assert.deepEqual(f.sounds,['error']);assert.equal(f.reads.length,0);
});
test('cancelled salary confirmation restores Save and its draft without a POST or tone',async()=>{
    const f=fixture({confirm:async()=>false});await f.save();assert.equal(f.requests.length,0);assert.equal(f.document.activeElement,f.nodes.get('employee-limit-save'));assert.equal(f.result.textContent,'');assert.equal(f.actions.busy,false);assert.deepEqual(f.sounds,[]);
});
for(const outcome of ['network','parser','missing status','missing amounts','contradictory HTTP'])test(`salary ${outcome} is unconfirmed, retains draft and requests no tone`,async()=>{
    const f=fixture({fetch:async()=>{if(outcome==='network')throw Error('offline');if(outcome==='parser')return {ok:true,json:async()=>{throw Error('Invalid JSON')}};return reply(outcome==='missing status'?{}:outcome==='missing amounts'?{status:'success'}:success,outcome!=='contradictory HTTP');}});
    await f.save();assert.match(f.result.textContent,/could not be confirmed.*Check the employee/);assert.equal(f.result.getAttribute('role'),'status');assert.match(f.result.className,/is-warning/);assert.equal(f.nodes.get('employee-limit-reason').value,'Fictional approved salary');assert.deepEqual(f.sounds,[]);assert.equal(f.reads.length,0);
});
for(const failure of ['false','throw'])test(`confirmed salary plus ${failure} refresh keeps confirmation and one success tone`,async()=>{
    const f=fixture({fetch:async()=>reply(success),read:async()=>{if(failure==='throw')throw Error('Read failed');return false;}});
    await f.save();assert.match(f.result.textContent,/Financial profile updated.*could not be refreshed/);assert.match(f.result.className,/is-warning/);assert.deepEqual(f.sounds,['success']);assert.equal(f.reads[0].id,900001);assert.equal(f.reads[0].options.preserveFeedback,true);
});
test('future salary scheduling keeps its distinct confirmation and preserves current salary wording',async()=>{
    const f=fixture({fetch:async()=>reply({status:'scheduled',effective_date:'2099-01-01',revision:2})});f.nodes.get('employee-salary-effective-date').value='2099-01-01';await f.save();
    assert.equal(f.confirmations[0][1].title,'Schedule salary profile?');assert.match(f.result.textContent,/scheduled for 2099-01-01.*revision 2.*Current salary and credit remain unchanged/);assert.equal(JSON.parse(f.requests[0].options.body).salary_effective_date,'2099-01-01');assert.deepEqual(f.sounds,['success']);
});
test('activation uses original no-body POST and independently announced confirmed outcome',async()=>{
    const f=fixture({fetch:async()=>reply({status:'success',monthly_salary:13000,credit_limit:3250}),soundThrows:true});f.document.activeElement=f.nodes.get('activation');await f.activate();
    assert.equal(f.requests[0].url,'/accounting/debts/scheduled-salary/900002/activate');assert.equal(f.requests[0].options.method,'POST');assert.equal(f.requests[0].options.body,undefined);assert.match(f.result.textContent,/Salary profile activated.*13000.*3250/);assert.match(f.result.className,/is-success/);
});
test('changed employee or modal request during confirmation cannot post reviewed values to a newer target',async()=>{
    for(const changed of ['employee','request']){let approve;const f=fixture({confirm:()=>new Promise(resolve=>approve=resolve)});const saving=f.save();if(changed==='employee')f.context.employeeModalUserId=900003;else f.context.employeeModalRequest++;approve(true);await saving;assert.equal(f.requests.length,0);}
});
for(const departure of ['abort','detach','closed'])test(`late salary response after ${departure} cannot announce, refresh or request sound`,async()=>{
    let release;const f=fixture({fetch:()=>new Promise(resolve=>release=resolve)});const saving=f.save();await tick();
    const prior=f.result.textContent;if(departure==='abort')f.signal.aborted=true;else if(departure==='detach')f.modal.isConnected=false;else f.modal.style.display='none';
    release(reply(success));await saving;assert.equal(f.result.textContent,prior);assert.deepEqual(f.sounds,[]);assert.equal(f.reads.length,0);
});
test('outside focus survives both rejected salary outcomes and confirmed refresh reads',async()=>{
    for(const accepted of [false,true]){let release;const f=fixture({fetch:()=>new Promise(resolve=>release=resolve)});const saving=f.save();await tick();const outside={isConnected:true};f.document.activeElement=outside;release(reply(accepted?success:{status:'error'}));await saving;assert.equal(f.document.activeElement,outside);if(accepted)assert.equal(f.reads[0].options.focus,false);}
});
test('nested confirmation Escape and already handled keys cannot close the employee modal',()=>{
    const f=fixture();f.document.body.className='has-app-dialog';assert.equal(f.key('Escape'),false);assert.equal(f.modal.style.display,'grid');f.document.body.className='';assert.equal(f.key('Escape',true),false);assert.equal(f.modal.style.display,'grid');
});
test('invalid draft and hidden editor issue no POST',async()=>{
    const f=fixture();f.nodes.get('employee-limit-reason').value='';await f.save();assert.match(f.result.textContent,/required/);assert.equal(f.confirmations.length,0);f.nodes.get('employee-limit-save').visible=false;f.nodes.get('employee-limit-reason').value='Reason';await f.save();assert.equal(f.requests.length,0);
});
test('unavailable confirmation restores controls and offers local recovery without an action',async()=>{
    const f=fixture({confirm:async()=>{throw Error('Dialog unavailable');}});await f.save();assert.match(f.result.textContent,/confirmation could not be opened/);assert.equal(f.actions.busy,false);assert.equal(f.nodes.get('employee-limit-save').disabled,false);assert.equal(f.requests.length,0);assert.deepEqual(f.sounds,[]);
});
