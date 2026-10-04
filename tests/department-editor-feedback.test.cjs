const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/department-debt-pages.js','utf8');
const submitStart = source.indexOf('    document.getElementById("department-form").addEventListener("submit"');
const submitSource = source.slice(submitStart,source.indexOf('    load();',submitStart));
const dialogSource = source.slice(source.indexOf('    function syncDepartmentReason()'),source.indexOf('    document.getElementById("department-add").addEventListener'));
const apiSource = source.slice(source.indexOf('async function api('),source.indexOf('function dataState('));
function fixture() {
    const elements={},requests=[],signal=new AbortController();let loads=0,closed=0,refresh=true;
    const doc={body:{},activeElement:null};doc.activeElement=doc.body;
    function el(id) {
        if(!elements[id]) {
            const classes=new Set();
            elements[id]={id,value:'',disabled:false,innerHTML:'',textContent:'',attrs:{},listeners:{},isConnected:true,tabIndex:0,
                classList:{add:name=>classes.add(name),remove:name=>classes.delete(name),contains:name=>classes.has(name),toggle(name,on){on?classes.add(name):classes.delete(name);}},
                addEventListener(type,handler){this.listeners[type]=handler;},setAttribute(name,value){this.attrs[name]=value;},removeAttribute(name){delete this.attrs[name];},
                focus(){doc.activeElement=this;},scrollIntoView(options){this.scrollOptions=options;},setCustomValidity(value){this.validity=value;},reportValidity(){this.reported=true;},dispatchEvent(){},getClientRects(){return [{}];},
                querySelectorAll(){return Object.values(elements).filter(e=>e.id!=='department-modal'&&e.id!=='department-form'&&e.id!=='department-modal-result');}};
        }
        return elements[id];
    }
    doc.getElementById=el;
    const modal=el('department-modal'),form=el('department-form');el('department-save').innerHTML='<i></i> Save Department';
    for(const id of ['department-id','department-code','department-name','department-head','department-head-search','department-active-input','department-status-reason','department-modal-result','department-close','department-cancel','assignment','protected','outside']) el(id);
    el('department-id').value='7';el('department-code').value='LAB';el('department-name').value='Fictional laboratory';el('department-head').value='9';el('department-active-input').value='1';el('protected').disabled=true;
    form.querySelectorAll=()=>Object.values(elements).filter(e=>['department-id','department-code','department-name','department-head','department-head-search','department-active-input','department-status-reason','department-close','department-cancel','department-save','assignment','protected'].includes(e.id));
    const context=vm.createContext({document:doc,pageSignal:signal.signal,departmentModal:modal,departmentLoadState:'ready',departmentSaving:false,modalOpener:el('outside'),headEditorOpen:false,Event:function(){},
        setHeadSuggestionsOpen(){},renderHeadSummary(){},requestAnimationFrame:cb=>cb(),
        setDepartmentModalOpen(node,on){node.classList.toggle('is-hidden',!on);if(!on)closed++;},
        load:async()=>{loads++;return refresh;},fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))});
    vm.runInContext(apiSource+dialogSource+submitSource,context);
    const submit=()=>form.listeners.submit({preventDefault(){}});
    const good={ok:true,status:200,json:async()=>({status:'success'})};
    return {el,doc,requests,signal,modal,form,good,submit,close:()=>vm.runInContext('closeModal()',context),open:()=>vm.runInContext('openModal()',context),loads:()=>loads,closed:()=>closed,refresh:value=>refresh=value};
}
test('pending department save freezes native and enhanced controls, prevents duplicates and dismissal',async()=>{
    const f=fixture();const pending=f.submit();assert.equal(f.requests.length,1);assert.equal(f.form.attrs['aria-busy'],'true');assert.equal(f.doc.activeElement,f.el('department-modal-result'));assert.match(f.el('department-modal-result').textContent,/Saving department/);
    for(const el of f.form.querySelectorAll())assert.equal(el.disabled,true);
    const packet=JSON.parse(f.requests[0].options.body);assert.deepEqual(packet,{id:7,code:'LAB',name:'Fictional laboratory',head_user_id:9,is_active:true,status_reason:''});assert.equal(f.requests[0].options.method,'POST');
    await f.submit();f.close();f.open();assert.equal(f.closed(),0);assert.equal(f.requests.length,1);assert.equal(f.el('department-code').value,'LAB');
    let prevented=false;f.modal.listeners.keydown({key:'Tab',preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(f.doc.activeElement,f.el('department-modal-result'));
    f.requests[0].resolve(f.good);await pending;assert.equal(f.closed(),1);assert.equal(f.loads(),1);assert.equal(f.el('department-save').disabled,false);assert.equal(f.el('protected').disabled,true);assert.equal(f.form.attrs['aria-busy'],undefined);assert.equal(f.modal.classList.contains('is-saving'),false);assert.match(f.el('department-save').innerHTML,/Save Department/);
});
test('server rejection preserves drafts and restores original disabled state with a focused alert',async()=>{
    const f=fixture(),pending=f.submit();f.requests[0].resolve({ok:false,status:422,json:async()=>({status:'error',message:'Department code <duplicate>'})});await pending;
    assert.equal(f.closed(),0);assert.equal(f.loads(),0);assert.equal(f.el('department-code').value,'LAB');assert.equal(f.el('department-name').value,'Fictional laboratory');assert.equal(f.el('department-modal-result').attrs.role,'alert');assert.equal(f.doc.activeElement,f.el('department-modal-result'));assert.equal(f.el('department-modal-result').scrollOptions.block,'nearest');assert.equal(f.el('protected').disabled,true);assert.equal(f.el('department-close').disabled,false);
});
for(const mode of ['network','json','missing confirmation'])test(`unconfirmed department ${mode} reply retains draft and warns without closing`,async()=>{
    const f=fixture(),pending=f.submit();if(mode==='network')f.requests[0].reject(TypeError('Failed to fetch'));else f.requests[0].resolve({ok:true,status:200,json:async()=>{if(mode==='json')throw SyntaxError('Raw parser detail');return {};}});await pending;
    assert.equal(f.closed(),0);assert.equal(f.loads(),0);assert.equal(f.el('department-code').value,'LAB');assert.equal(f.el('department-modal-result').className,'department-result is-warning');assert.match(f.el('department-modal-result').textContent,/save could not be confirmed/);assert.doesNotMatch(f.el('department-modal-result').textContent,/Failed to fetch|Raw parser/);
});
test('confirmed save survives failed list refresh as a warning on the page',async()=>{
    const f=fixture();f.refresh(false);const pending=f.submit();f.requests[0].resolve(f.good);await pending;assert.equal(f.closed(),1);assert.match(f.el('department-admin-result').textContent,/^Department saved\..*Retry departments/);assert.equal(f.el('department-admin-result').className,'department-result is-warning');
});
test('departed editor ignores late confirmation and leaves the new page untouched',async()=>{
    const f=fixture(),pending=f.submit();f.signal.abort();f.el('outside').focus();f.requests[0].resolve(f.good);await pending;assert.equal(f.closed(),0);assert.equal(f.loads(),0);assert.equal(f.doc.activeElement,f.el('outside'));assert.match(f.el('department-modal-result').textContent,/Saving department/);assert.equal(f.el('department-save').disabled,true);
});
test('server rejection preserves outside focus when a user moves away',async()=>{
    const f=fixture(),pending=f.submit();f.el('outside').focus();f.requests[0].resolve({ok:false,status:409,json:async()=>({status:'error',message:'Department name already used'})});await pending;assert.equal(f.doc.activeElement,f.el('outside'));assert.equal(f.el('department-code').disabled,false);
});
test('hidden department editor and departed page cannot submit',async()=>{
    const f=fixture();f.modal.classList.add('is-hidden');await f.submit();assert.equal(f.requests.length,0);f.modal.classList.remove('is-hidden');f.signal.abort();await f.submit();assert.equal(f.requests.length,0);
});
