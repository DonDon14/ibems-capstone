const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/accounting-investigation-actions.js','utf8');
const part=fs.readFileSync('public/assets/js/accounting-debts.part3.js','utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const reply=(data,ok=true)=>({ok,json:async()=>data});
const record=(overrides={})=>({id:900002,user_id:900001,status:'open',transaction_id:900003,issue_type:'incorrect_amount',summary:'Fictional debt issue',...overrides});
function fixture({fetch=async()=>reply({status:'error',message:'Fictional rejection <literal>'}),confirm=async()=>true,refresh=async()=>true,soundThrows=false}={}){
    const nodes=new Map(),requests=[],sounds=[],reviews=[],reads=[];let document;
    class Node{
        constructor(id,control=false){this.id=id;this.control=control;this.isConnected=true;this.disabled=false;this.visible=true;this.value='';this.textContent='';this.innerHTML='Original action';this.attrs={};this.dataset={};this.children=[];this.fields={};this.style={display:'grid',visibility:'visible'};this.className='';this.tabIndex=0;this.events={};this.classList={contains:name=>this.className.split(' ').includes(name)};}
        append(child){this.children.push(child);child.parent=this;}
        contains(child){return child===this||this.children.some(node=>node.contains(child));}
        querySelectorAll(){return this.children.flatMap(child=>[...(child.control?[child]:[]),...child.querySelectorAll()]);}
        querySelector(selector){return this.fields[selector]||null;}
        closest(){return this.parent;}
        getClientRects(){return this.visible?[{}]:[];}
        getAttribute(k){return this.attrs[k]??null;}setAttribute(k,v){this.attrs[k]=v;}removeAttribute(k){delete this.attrs[k];}
        dispatchEvent(){}addEventListener(k,fn){this.events[k]=fn;}focus(){if(!this.disabled)document.activeElement=this;}scrollIntoView(){this.scrolled=true;}
    }
    const node=(id,control=false)=>{if(!nodes.has(id))nodes.set(id,new Node(id,control));return nodes.get(id);};
    const modal=node('debt-investigations-modal');modal.dataset={currentUser:'900099',currentRole:'ACCOUNTING_OFFICE'};
    for(const id of ['close-debt-investigations','investigation-open','investigation-user','investigation-transaction','investigation-issue','investigation-summary','investigation-evidence','investigation-refresh','investigation-message'])modal.append(node(id,id!=='investigation-message'));
    const card=node('card');modal.append(card);card.dataset={investigationId:'900002',investigationUser:'900001',investigationStatus:'open',investigationRecommender:'900098',investigationAction:'partial_reversal',investigationAmount:'30.00'};
    for(const name of ['findings','result-evidence','action','amount']){const field=node(name,true);card.append(field);card.fields[`.investigation-${name}`]=field;}
    card.append(node('recommend',true));card.append(node('approve',true));
    Object.entries({'investigation-user':'900001','investigation-transaction':'900003','investigation-issue':'incorrect_amount','investigation-summary':' Fictional debt issue ','investigation-evidence':' Fictional source evidence ',findings:' Fictional reviewed findings ','result-evidence':' Fictional reviewed evidence ',action:'partial_reversal',amount:'30.00'}).forEach(([id,value])=>node(id).value=value);
    document={body:node('body'),activeElement:node('investigation-open'),getElementById:node};
    const signal={aborted:false};
    const context=vm.createContext({document,getComputedStyle:n=>n.style,Event:class Event{},Node:{DOCUMENT_POSITION_PRECEDING:2,DOCUMENT_POSITION_FOLLOWING:4},aMoney:String,investigationLabel:String,
        window:{IbemsPortalNavigation:{currentSignal:signal},IbemsInvestigationReads:{busy:false,reset:()=>{}},IbemsDialog:{confirm:async(...args)=>{reviews.push(args);return confirm(...args);}},IbemsFeedbackSound:{play:type=>{sounds.push(type);if(soundThrows)throw Error('Audio unavailable');}}},
        fetch:async(url,options)=>{requests.push({url,options});return fetch(url,options);},
        loadInvestigations:()=>{reads.push('queue');return refresh('queue');},loadData:()=>{reads.push('totals');return refresh('totals');}});
    vm.runInContext(source,context);
    vm.runInContext(part.slice(part.indexOf('function closeInvestigationsModal()'),part.indexOf('async function openInvestigation()')),context);
    const actions=context.window.IbemsInvestigationActions;
    return {actions,context,modal,card,node,document,signal,requests,sounds,reviews,reads,result:node('investigation-message'),open:()=>actions.open(),recommend:()=>actions.recommend(node('recommend')),approve:()=>{card.dataset.investigationStatus='recommended';return actions.approve(node('approve'));},key(key,handled=false){let prevented=false;modal.events.keydown({key,currentTarget:modal,defaultPrevented:handled,preventDefault(){prevented=true;}});return prevented;}};
}
test('pending creation freezes controls, prevents duplicates and dismissal, and preserves exact packet and draft after rejection',async()=>{
    let release;const f=fixture({fetch:()=>new Promise(resolve=>release=resolve)});f.node('amount').disabled=true;const saving=f.open();
    assert.equal(f.actions.busy,true);assert.equal(f.modal.getAttribute('aria-busy'),'true');assert.equal(f.node('investigation-user').disabled,true);
    await f.open();await f.recommend();await f.approve();assert.equal(f.requests.length,1);
    vm.runInContext('closeInvestigationsModal()',f.context);assert.equal(f.modal.style.display,'grid');assert.equal(f.key('Tab'),true);assert.equal(f.key('Escape'),true);assert.equal(f.document.activeElement,f.result);
    assert.deepEqual(JSON.parse(f.requests[0].options.body),{user_id:900001,transaction_id:900003,issue_type:'incorrect_amount',summary:'Fictional debt issue',evidence_summary:'Fictional source evidence'});assert.equal(f.requests[0].options.signal,f.signal);
    release(reply({status:'error',message:'Fictional rejection <literal>'}));await saving;
    assert.equal(f.result.textContent,'Fictional rejection <literal>');assert.equal(f.result.getAttribute('role'),'alert');assert.equal(f.actions.busy,false);assert.equal(f.node('investigation-user').disabled,false);assert.equal(f.node('amount').disabled,true);assert.equal(f.node('investigation-open').innerHTML,'Original action');assert.match(f.node('investigation-summary').value,/Fictional debt issue/);assert.deepEqual(f.sounds,['error']);assert.equal(f.reads.length,0);
});
for(const outcome of ['network','parser','missing status','wrong employee','wrong case state','http'])test(`creation ${outcome} remains unconfirmed, preserves draft and requests no sound`,async()=>{
    const f=fixture({fetch:async()=>{if(outcome==='network')throw Error('Offline');if(outcome==='parser')return {ok:true,json:async()=>{throw Error('Invalid JSON');}};return reply(outcome==='missing status'?{}:{status:'success',investigation:record(outcome==='wrong employee'?{user_id:900004}:outcome==='wrong case state'?{status:'closed'}:{})},outcome!=='http');}});
    await f.open();assert.match(f.result.textContent,/could not be confirmed.*Refresh investigations/);assert.match(f.result.className,/is-warning/);assert.deepEqual(f.sounds,[]);assert.match(f.node('investigation-summary').value,/Fictional/);assert.equal(f.reads.length,0);assert.equal(f.actions.busy,false);
});
test('confirmed creation clears only confirmed open drafts and distinguishes failed queue refresh',async()=>{
    const f=fixture({fetch:async()=>reply({status:'success',investigation:record()}),refresh:async()=>false});await f.open();assert.match(f.result.textContent,/#900002 opened.*Financial records remain unchanged.*could not be refreshed/);assert.equal(f.node('investigation-summary').value,'');assert.equal(f.node('investigation-evidence').value,'');assert.match(f.node('findings').value,/Fictional reviewed/);assert.deepEqual(f.sounds,['success']);assert.deepEqual(f.reads,['queue']);
});
test('recommendation retains original numeric packet and confirms the same case, actor and amount',async()=>{
    const f=fixture({fetch:async()=>reply({status:'success',investigation:record({status:'recommended',recommended_by:900099,recommended_action:'partial_reversal',recommended_amount:30})})});f.document.activeElement=f.node('recommend');await f.recommend();
    assert.equal(f.requests[0].url,'/accounting/debt-investigations/900002/recommend');assert.deepEqual(JSON.parse(f.requests[0].options.body),{findings:'Fictional reviewed findings',evidence_summary:'Fictional reviewed evidence',recommended_action:'partial_reversal',recommended_amount:30});assert.match(f.result.textContent,/#900002.*independent Accounting approval/);assert.deepEqual(f.sounds,['success']);assert.equal(f.node('recommend').disabled,true);
});
test('normalized no-change recommendation accepts the server zero amount without altering the original packet',async()=>{
    const f=fixture({fetch:async()=>reply({status:'success',investigation:record({status:'recommended',recommended_by:900099,recommended_action:'no_change',recommended_amount:'0.00'})})});f.node('action').value='no_change';await f.recommend();assert.equal(JSON.parse(f.requests[0].options.body).recommended_amount,30);assert.deepEqual(f.sounds,['success']);
});
for(const mismatch of ['case','actor','amount'])test(`recommendation ${mismatch} mismatch is unconfirmed and retains findings`,async()=>{
    const row=record({status:'recommended',recommended_by:900099,recommended_action:'partial_reversal',recommended_amount:30});if(mismatch==='case')row.id=900004;if(mismatch==='actor')row.recommended_by=900098;if(mismatch==='amount')row.recommended_amount=45;
    const f=fixture({fetch:async()=>reply({status:'success',investigation:row})});await f.recommend();assert.match(f.result.textContent,/could not be confirmed/);assert.deepEqual(f.sounds,[]);assert.equal(f.node('recommend').disabled,false);assert.match(f.node('findings').value,/Fictional reviewed/);
});
test('cancelled approval review restores exact action focus and sends no POST or tone',async()=>{
    const f=fixture({confirm:async()=>false});f.document.activeElement=f.node('approve');await f.approve();assert.equal(f.requests.length,0);assert.equal(f.reviews[0][1].title,'Approve debt investigation?');assert.match(f.reviews[0][0],/#900002.*30.00.*changes the employee/);assert.equal(f.document.activeElement,f.node('approve'));assert.deepEqual(f.sounds,[]);assert.equal(f.actions.busy,false);
});
test('confirmed approval uses original empty packet, verifies debt change and preserves success across refresh failure',async()=>{
    const f=fixture({fetch:async()=>reply({status:'success',investigation:record({status:'closed',approved_by:900099,recommended_action:'partial_reversal',recommended_amount:30}),debt_before:100,debt_after:70}),refresh:async()=>{throw Error('Refresh failed');},soundThrows:true});await f.approve();
    assert.equal(f.requests[0].url,'/accounting/debt-investigations/900002/approve');assert.equal(f.requests[0].options.body,'{}');assert.match(f.result.textContent,/correction posted.*100.*70.*could not be refreshed/);assert.deepEqual(f.sounds,['success']);assert.deepEqual(f.reads,['queue','totals']);assert.equal(f.node('approve').disabled,true);await f.approve();assert.equal(f.requests.length,1);
});
test('no-change approval states unchanged debt and uses the close confirmation',async()=>{
    const f=fixture({fetch:async()=>reply({status:'success',investigation:record({status:'closed',approved_by:900099,recommended_action:'no_change',recommended_amount:0}),debt_before:100,debt_after:100})});f.card.dataset.investigationAction='no_change';f.card.dataset.investigationAmount='0';await f.approve();assert.equal(f.reviews[0][1].confirmLabel,'Approve and close');assert.match(f.result.textContent,/approved and closed.*debt is unchanged at 100/);assert.deepEqual(f.sounds,['success']);
});
for(const bad of ['same reviewer','wrong role','invalid amount'])test(`approval ${bad} has no review or POST`,async()=>{
    const f=fixture();if(bad==='same reviewer')f.card.dataset.investigationRecommender='900099';if(bad==='wrong role')f.modal.dataset.currentRole='ADMIN';if(bad==='invalid amount')f.card.dataset.investigationAmount='';await f.approve();assert.equal(f.requests.length,0);assert.equal(f.reviews.length,0);
});
test('approval with contradictory debt confirmation remains unconfirmed and requests no tone',async()=>{
    const f=fixture({fetch:async()=>reply({status:'success',investigation:record({status:'closed',approved_by:900099,recommended_action:'partial_reversal',recommended_amount:30}),debt_before:100,debt_after:20})});await f.approve();assert.match(f.result.textContent,/could not be confirmed/);assert.deepEqual(f.sounds,[]);assert.equal(f.reads.length,0);
});
test('reads and invalid form fields cannot submit; unavailable confirmation restores controls',async()=>{
    const f=fixture();f.context.window.IbemsInvestigationReads.busy=true;await f.open();assert.match(f.result.textContent,/finish loading/);assert.equal(f.requests.length,0);f.context.window.IbemsInvestigationReads.busy=false;f.node('investigation-summary').value='short';await f.open();assert.equal(f.requests.length,0);assert.equal(f.result.getAttribute('role'),'alert');
    f.node('findings').value='short';await f.recommend();assert.equal(f.requests.length,0);
    const g=fixture({confirm:async()=>{throw Error('Unavailable');}});await g.approve();assert.match(g.result.textContent,/review could not be opened/);assert.equal(g.actions.busy,false);assert.equal(g.node('approve').disabled,false);assert.deepEqual(g.sounds,[]);
});
for(const departure of ['abort','detach','hide','page'])test(`late action after ${departure} cannot announce, refresh or sound, and releases captured controls`,async()=>{
    let release;const f=fixture({fetch:()=>new Promise(resolve=>release=resolve)});const saving=f.open(),prior=f.result.textContent;if(departure==='abort')f.signal.aborted=true;if(departure==='detach')f.modal.isConnected=false;if(departure==='hide')f.modal.style.display='none';if(departure==='page')f.context.window.IbemsPortalNavigation.currentSignal={aborted:false};release(reply({status:'success',investigation:record()}));await saving;
    assert.equal(f.result.textContent,prior);assert.deepEqual(f.sounds,[]);assert.equal(f.reads.length,0);assert.equal(f.node('investigation-open').disabled,false);assert.equal(f.actions.busy,false);
});
test('departure during approval confirmation cannot post into a newer page',async()=>{
    let answer;const f=fixture({confirm:()=>new Promise(resolve=>answer=resolve)});const saving=f.approve();await tick();f.context.window.IbemsPortalNavigation.currentSignal={aborted:false};answer(true);await saving;assert.equal(f.requests.length,0);assert.equal(f.node('approve').disabled,false);
});
test('outside focus is preserved after rejection and confirmed refresh',async()=>{
    for(const accepted of [false,true]){let release;const f=fixture({fetch:()=>new Promise(resolve=>release=resolve)});const saving=f.open();f.node('outside').focus();release(reply(accepted?{status:'success',investigation:record()}:{status:'error'}));await saving;assert.equal(f.document.activeElement,f.node('outside'));}
});
test('nested approval dialog owns Escape; already handled keys do not move focus',async()=>{
    let answer;const f=fixture({confirm:()=>new Promise(resolve=>answer=resolve)});const saving=f.approve();f.document.body.className='has-app-dialog';assert.equal(f.key('Escape'),false);f.document.body.className='';assert.equal(f.key('Tab',true),false);answer(false);await saving;
});
test('closing and reopening during a confirmed follow-up read invalidates its old feedback',async()=>{
    let finishRead;const f=fixture({fetch:async()=>reply({status:'success',investigation:record()}),refresh:()=>new Promise(resolve=>finishRead=resolve)});
    const saving=f.open();await tick();assert.equal(f.actions.busy,false);assert.match(f.result.textContent,/opened/);
    vm.runInContext('closeInvestigationsModal()',f.context);f.modal.style.display='grid';f.result.textContent='New modal feedback';finishRead(false);await saving;
    assert.equal(f.result.textContent,'New modal feedback');assert.deepEqual(f.sounds,['success']);
});

test('Accounting Head can independently approve under the current permission matrix',async()=>{
 const f=fixture({fetch:async()=>reply({status:'success',investigation:record({status:'closed',approved_by:900099,recommended_action:'partial_reversal',recommended_amount:30}),debt_before:100,debt_after:70})});
 f.modal.dataset.currentRole='ACCOUNTING_HEAD';await f.approve();assert.equal(f.requests.length,1);assert.deepEqual(f.sounds,['success']);
});
