const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/admin-store-details.js','utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const response=(data,ok=true)=>({ok,json:async()=>data});
function fixture({fetch,confirm,prompt,refresh=async()=>true,signal,soundThrows=false}={}) {
    let context;const nodes=new Map(),requests=[],sounds=[],prompts=[];
    class Node {
        constructor(id){this.id=id;this.disabled=false;this.isConnected=true;this.visible=true;this.attrs={};this.children=[];this.classes=new Set();this.handlers={};this.textContent='';this.innerHTML='';this.value='';this.classList={add:name=>this.classes.add(name),remove:name=>this.classes.delete(name),contains:name=>this.classes.has(name),toggle:(name,on)=>on?this.classes.add(name):this.classes.delete(name)};}
        append(child){child.parent=this;this.children.push(child);return child;}
        getClientRects(){return this.visible?[{}]:[];}
        getAttribute(name){return this.attrs[name]??null;}
        setAttribute(name,value){this.attrs[name]=String(value);}
        removeAttribute(name){delete this.attrs[name];}
        contains(child){return child===this||this.children.some(node=>node.contains(child));}
        closest(selector){if(selector==='form')return this.isForm?this:this.parent?.closest(selector)||null;if(selector==='.sd-stale-day-modal')return this.id==='sd-stale-day-modal'?this:this.parent?.closest(selector)||null;return null;}
        querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
        querySelectorAll(selector){const all=this.children.flatMap(child=>[child,...child.querySelectorAll('*')]);if(selector==='*')return all;return all.filter(child=>selector==='.sd-case-feedback'?child.id==='case-feedback':selector==='.admin-modal-card'?child.id==='card':selector==='button[type="submit"]'?child.isButton&&child.attrs.type==='submit':selector.includes('button')?child.isControl:false);}
        focus(){context.document.activeElement=this;}
        scrollIntoView(){this.scrolled=true;}
        reportValidity(){return !this.invalid;}
        reset(){this.children.filter(child=>child.name).forEach(child=>child.value='');}
        addEventListener(type,handler){this.handlers[type]=handler;}
    }
    const node=id=>{if(!nodes.has(id))nodes.set(id,new Node(id));return nodes.get(id);};
    const root=node('root');root.attrs={'data-store-id':'900001','data-review-url-prefix':'/store-admin/store-day-sessions'};
    const body=node('body');body.append(root);
    const modal=root.append(node('sd-stale-day-modal')),card=modal.append(node('card')),form=card.append(node('sd-stale-day-form'));form.isForm=true;form.attrs['data-stale-session-id']='900002';
    const makeControl=(parent,id,name,value,button=false)=>{const control=parent.append(node(id));control.isControl=true;control.isButton=button;control.name=name;control.value=value;control.innerHTML=id;return control;};
    makeControl(form,'cash','counted_cash','100.00');makeControl(form,'ecash','counted_ecash','20.00');makeControl(form,'reason','reason','Fictional independent count');
    const save=makeControl(card,'save',null,null,true);save.attrs.type='submit';makeControl(card,'sd-stale-day-close',null,null,true);makeControl(card,'sd-stale-day-cancel',null,null,true);
    card.append(node('sd-stale-result'));root.append(node('sd-action-feedback'));root.append(node('sd-load-feedback'));makeControl(root,'sd-refresh',null,null,true);makeControl(root,'protected',null,null,true).disabled=true;
    const caseForm=root.append(node('case-form'));caseForm.isForm=true;caseForm.attrs['data-case-id']='900003';makeControl(caseForm,'reviewer','to_user_id','900004');makeControl(caseForm,'note','note','Fictional handoff');caseForm.append(node('case-feedback'));const caseSave=makeControl(caseForm,'case-save',null,null,true);caseSave.attrs.type='submit';
    const trigger=makeControl(root,'trigger',null,null,true);
    class FormData {constructor(form){this.values=form.children.filter(child=>child.name&&!child.disabled).map(child=>[child.name,child.value]);}entries(){return this.values[Symbol.iterator]();}}
    const document={body,activeElement:save,getElementById:node,querySelector:()=>root,addEventListener:(type,handler)=>{document.handlers[type]=handler;},handlers:{}};
    context=vm.createContext({document,FormData,window:{location:{pathname:'/store-admin/stores/900001'},IbemsPortalNavigation:{currentSignal:signal},IbemsDialog:{confirm:confirm||(async()=>true),prompt:async(text,options)=>{prompts.push(options);return prompt?prompt(text,options):' Fictional evidence checked ';},alert:async()=>true},IbemsFeedbackSound:{play:type=>{sounds.push(type);if(soundThrows)throw Error('audio');}}},fetch:async(url,options)=>{requests.push({url,options});return fetch?fetch(url,options):response({status:'success'});},loadStoreDetails:refresh});
    vm.runInContext(source.slice(0,source.indexOf('function sdRenderDaySession('))+source.slice(source.indexOf('function sdOpenStaleDayModal('),source.indexOf('function sdRenderOfficers(')),context);
    vm.runInContext('sdDetailsState="ready";sdDaySessions=[{id:900002,business_date:"2026-10-01",expected_cash:100,expected_ecash:20,variance_status:"shortage",variance_cash:-5,variance_case:{attachments:[{id:1}]}}];',context);
    return {context,node,root,modal,form,caseForm,save,trigger,requests,sounds,prompts,resolve:()=>vm.runInContext('sdResolveStaleDay(document.getElementById("sd-stale-day-form"))',context),review:()=>vm.runInContext('sdReviewVariance(900002,"approve_shortage",document.getElementById("trigger"))',context),handoff:()=>vm.runInContext('sdSubmitCaseForm(document.getElementById("case-form"),"handoff")',context),ack:()=>vm.runInContext('sdAcknowledgeCase(900003,document.getElementById("trigger"))',context)};
}

test('reconciliation freezes controls before confirmation, suppresses duplicate actions and preserves the original packet',async()=>{
    let settle;const f=fixture({fetch:()=>new Promise(resolve=>settle=resolve)});const pending=f.resolve();await tick();
    assert.equal(f.requests.length,1);assert.deepEqual(JSON.parse(f.requests[0].options.body),{counted_cash:'100.00',counted_ecash:'20.00',reason:'Fictional independent count'});assert.match(f.requests[0].url,/store-admin\/store-day-sessions\/900002\/resolve-stale$/);
    assert.equal(f.node('cash').disabled,true);assert.equal(f.node('sd-stale-day-close').disabled,true);assert.equal(f.form.attrs['aria-busy'],'true');
    assert.equal(f.context.document.activeElement.id,'sd-stale-result');await f.resolve();await f.ack();assert.equal(f.requests.length,1);
    vm.runInContext('sdCloseStaleDayModal();sdOpenStaleDayModal(900002,null);',f.context);assert.equal(f.modal.classes.has('is-hidden'),false);assert.equal(f.node('cash').value,'100.00');
    settle(response({status:'error',message:'Rejected count'}));await pending;
    assert.equal(f.node('sd-stale-result').textContent,'Rejected count');assert.equal(f.node('sd-stale-result').attrs.role,'alert');assert.equal(f.node('cash').disabled,false);assert.equal(f.node('protected').disabled,true);assert.equal(f.form.attrs['aria-busy'],undefined);assert.deepEqual(f.sounds,['error']);
});
test('cancelled reconciliation confirmation returns its original focus without posting or clearing the draft',async()=>{
    const f=fixture({confirm:async()=>false});await f.resolve();assert.equal(f.requests.length,0);assert.equal(f.node('cash').value,'100.00');assert.equal(f.context.document.activeElement.id,'save');assert.equal(f.save.disabled,false);assert.equal(f.node('sd-stale-result').textContent,'');
});
for(const kind of ['network','parser','missing','contradictory'])test(`${kind} outcome is unconfirmed, retains the reconciliation and emits no tone`,async()=>{
    const f=fixture({fetch:async()=>{if(kind==='network')throw Error('offline');if(kind==='parser')return {ok:true,json:async()=>{throw Error('json');}};return response(kind==='missing'?{}:{status:'success'},kind!=='contradictory');}});await f.resolve();
    assert.match(f.node('sd-stale-result').textContent,/could not be confirmed.*Check/);assert.equal(f.node('sd-stale-result').attrs.role,'status');assert.equal(f.node('sd-stale-result').classes.has('is-warning'),true);assert.equal(f.modal.classes.has('is-hidden'),false);assert.equal(f.node('cash').value,'100.00');assert.deepEqual(f.sounds,[]);
});
for(const fails of ['false','throw'])test(`confirmed reconciliation remains confirmed when refresh ${fails}`,async()=>{
    const f=fixture({refresh:async()=>{if(fails==='throw')throw Error('read');return false;}});await f.resolve();assert.equal(f.modal.classes.has('is-hidden'),true);assert.match(f.node('sd-action-feedback').textContent,/Previous store day resolved.*Retry store details/);assert.equal(f.node('sd-action-feedback').classes.has('is-warning'),true);assert.deepEqual(f.sounds,['success']);
});
test('handoff freezes enhanced and original controls, retains selection/note after rejection and uses the existing JSON endpoint',async()=>{
    const f=fixture({fetch:async()=>response({status:'error',message:'Handoff rejected'})});f.node('case-save').focus();await f.handoff();
    assert.match(f.requests[0].url,/store-admin\/variance-cases\/900003\/handoff$/);assert.deepEqual(JSON.parse(f.requests[0].options.body),{to_user_id:'900004',note:'Fictional handoff'});assert.equal(f.node('reviewer').value,'900004');assert.equal(f.node('note').value,'Fictional handoff');assert.equal(f.context.document.activeElement.id,'case-feedback');assert.equal(f.node('reviewer').disabled,false);
});
test('evidence uses the original multipart fields and preserves the selected file after an unconfirmed response',async()=>{
    const f=fixture({fetch:async()=>{throw Error('offline');}});f.node('reviewer').name='evidence_file';f.node('reviewer').value='fictional-file';f.node('note').name='description';f.node('case-save').focus();
    await vm.runInContext('sdSubmitCaseForm(document.getElementById("case-form"),"attachments")',f.context);
    assert.deepEqual([...f.requests[0].options.body.entries()],[['evidence_file','fictional-file'],['description','Fictional handoff']]);assert.equal(f.requests[0].options.headers,undefined);assert.equal(f.node('reviewer').value,'fictional-file');assert.match(f.node('case-feedback').textContent,/could not be confirmed/);
});
test('a rejected variance review preserves its note for a later explicitly confirmed attempt',async()=>{
    let calls=0;const f=fixture({fetch:async()=>response(++calls===1?{status:'error',message:'Review rejected'}:{status:'success'})});f.trigger.focus();await f.review();
    assert.deepEqual(JSON.parse(f.requests[0].options.body),{action:'approve_shortage',review_note:'Fictional evidence checked'});assert.equal(vm.runInContext('sdReviewDrafts.get("900002:approve_shortage")',f.context),'Fictional evidence checked');
    f.trigger.focus();await f.review();assert.equal(f.prompts[1].defaultValue,'Fictional evidence checked');assert.equal(vm.runInContext('sdReviewDrafts.size',f.context),0);assert.equal(f.node('sd-action-feedback').textContent,'Variance review saved.');
});
test('evidence gate and cancelled review note do not post decisions',async()=>{
    const missing=fixture();vm.runInContext('sdDaySessions[0].variance_case.attachments=[]',missing.context);missing.trigger.focus();await missing.review();assert.equal(missing.requests.length,0);assert.equal(missing.trigger.disabled,false);
    const cancelled=fixture({prompt:async()=>null});cancelled.trigger.focus();await cancelled.review();assert.equal(cancelled.requests.length,0);assert.equal(cancelled.context.document.activeElement.id,'trigger');
});
test('navigation during confirmation never posts, and navigation during posting ignores late outcomes',async()=>{
    for(const phase of ['confirm','post']) {
        const controller=new AbortController();let settle;
        const f=fixture({signal:controller.signal,confirm:phase==='confirm'?()=>new Promise(resolve=>settle=resolve):undefined,fetch:()=>new Promise(resolve=>settle=resolve)});
        const pending=f.resolve();await tick();controller.abort();f.node('sd-action-feedback').textContent='new page';settle(phase==='confirm'?true:response({status:'success'}));await pending;
        assert.equal(f.requests.length,phase==='confirm'?0:1);assert.equal(f.node('sd-action-feedback').textContent,'new page');assert.deepEqual(f.sounds,[]);
    }
});
test('outside focus survives rejection and success',async()=>{
    for(const success of [false,true]) {let settle;const f=fixture({fetch:()=>new Promise(resolve=>settle=resolve)});const pending=f.resolve();await tick();f.node('outside').focus();settle(response({status:success?'success':'error',message:success?'Resolved':'Rejected'}));await pending;assert.equal(f.context.document.activeElement.id,'outside');}
});
test('optional sound failures cannot turn a confirmed acknowledgment into an unknown outcome',async()=>{
    const f=fixture({soundThrows:true});f.trigger.focus();await f.ack();assert.equal(f.node('sd-action-feedback').textContent,'Handoff acknowledged.');assert.match(f.requests[0].url,/acknowledge$/);assert.equal(f.requests[0].options.body,undefined);
});

test('departed roots or removed action forms ignore late replies and tones',async()=>{
    for(const removed of ['root','form']) {let settle;const f=fixture({fetch:()=>new Promise(resolve=>settle=resolve)});const pending=f.resolve();await tick();f[removed].isConnected=false;f.node('sd-action-feedback').textContent='new page';settle(response({status:'success'}));await pending;assert.equal(f.node('sd-action-feedback').textContent,'new page');assert.deepEqual(f.sounds,[]);}
});
test('invalid, hidden or unavailable reconciliation contexts never post',async()=>{
    for(const state of ['invalid','hidden','loading']) {const f=fixture();if(state==='invalid')f.form.invalid=true;if(state==='hidden')f.modal.classes.add('is-hidden');if(state==='loading')vm.runInContext('sdDetailsState="loading"',f.context);await f.resolve();assert.equal(f.requests.length,0);assert.equal(f.save.disabled,false);}
});
test('reconciliation keyboard trap includes the named scroll region and keeps pending Tab on feedback',async()=>{
    let settle;const f=fixture({fetch:()=>new Promise(resolve=>settle=resolve)});const start=source.indexOf('document.getElementById("sd-stale-day-modal")?.addEventListener("keydown"');vm.runInContext(source.slice(start,source.lastIndexOf('loadStoreDetails();')),f.context);
    const region=f.node('scroll-region');region.attrs.tabindex='0';region.tabIndex=0;region.focus();
    const oldQuery=f.modal.querySelectorAll.bind(f.modal);f.modal.querySelectorAll=selector=>{const controls=oldQuery(selector);controls.forEach(control=>control.tabIndex=0);return selector.includes('[tabindex="0"]')?[controls[0],region,...controls.slice(1)]:controls;};
    let prevented=false;f.modal.handlers.keydown({key:'Tab',currentTarget:f.modal,preventDefault:()=>prevented=true});assert.equal(prevented,false,'Tab can advance naturally from the scroll region');
    f.save.focus();const pending=f.resolve();await tick();f.modal.handlers.keydown({key:'Tab',currentTarget:f.modal,preventDefault:()=>prevented=true});assert.equal(prevented,true);assert.equal(f.context.document.activeElement.id,'sd-stale-result');settle(response({status:'error',message:'Rejected'}));await pending;
});
test('Escape in a nested app dialog cannot dismiss the reconciliation behind it',()=>{
    const f=fixture();const start=source.indexOf('document.getElementById("sd-stale-day-modal")?.addEventListener("keydown"');vm.runInContext(source.slice(start,source.lastIndexOf('loadStoreDetails();')),f.context);
    f.context.document.body.classes.add('has-app-dialog');f.context.document.handlers.keydown({key:'Escape',defaultPrevented:false});assert.equal(f.modal.classes.has('is-hidden'),false);
    f.context.document.body.classes.delete('has-app-dialog');f.context.document.handlers.keydown({key:'Escape',defaultPrevented:true});assert.equal(f.modal.classes.has('is-hidden'),false);
});
