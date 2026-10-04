const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const base=fs.readFileSync('public/assets/js/admin-stores.js','utf8');
const part=fs.readFileSync('public/assets/js/admin-stores.part2.js','utf8');
const code=base.slice(base.indexOf('function setStoresResult('),base.indexOf('function formatRoleLabel('))+'\n'+part.slice(part.indexOf('let storeModalOpener'),part.indexOf('document.getElementById("store-search-btn").addEventListener'));
const good={ok:true,json:async()=>({status:'success'})};
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};};
function fixture(){
    const elements={},requests=[],frames=[],signal=new AbortController();let refresh=true,loads=0;
    const doc={body:{classList:{add(){},remove(){}}},activeElement:null};doc.activeElement=doc.body;
    function el(id){
        if(!elements[id]){
            const classes=new Set();elements[id]={id,value:'',files:[],disabled:false,isConnected:true,style:{},textContent:'',dataset:{},attrs:{},
                classList:{add:s=>classes.add(s),contains:s=>classes.has(s),toggle:(s,on)=>on?classes.add(s):classes.delete(s)},
                setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];},getAttribute(k){return this.attrs[k]??null;},
                focus(){if(!this.disabled)doc.activeElement=this;},scrollIntoView(options){this.scroll=options;},addEventListener(type,fn){this[type]=fn;},
                contains(n){return n===this||n?.parent===this;},querySelectorAll(){return[];}};
        }return elements[id];
    }
    doc.getElementById=el;doc.querySelector=()=>null;
    const modal=el('store-modal');modal.style.display='grid';
    const ids=['store-save-btn','store-name','store-officer-id','store-logo-url','store-logo-file','store-active','store-deactivation-reason','store-officer-search','store-supervisor-search','store-officer-suggestions','store-supervisor-suggestions','close-store-modal','cancel-store-modal','modern-select','assignment-change','protected'];
    const controls=ids.map(id=>el(id));controls.forEach(e=>e.parent=modal);el('store-modal-result').parent=modal;
    modal.querySelectorAll=()=>controls;
    el('store-name').value='Fictional Store';el('store-officer-id').value='900101';el('store-active').value='1';
    el('store-save-btn').dataset.idleLabel='Save Changes';el('store-save-btn').textContent='Save Changes';el('protected').disabled=true;
    const context=vm.createContext({document:doc,window:{IbemsPortalNavigation:{currentSignal:signal.signal},confirm:()=>true},URL:{revokeObjectURL(){}},requestAnimationFrame:fn=>frames.push(fn),canManageStores:true,storeStaffState:'ready',editingStoreId:900001,editingInitialIsActive:true,selectedSupervisorIds:[900102],editingCurrentLogoUrl:'/fictional-logo.png',logoInputMode:'upload',storeLogoPreviewObjectUrl:null,officerEditorOpen:false,supervisorEditorOpen:false,
        setSuggestionBoxOpen(){},updateAssignmentEditors(){},
        FormData:class{constructor(){this.entries=[];}append(...entry){this.entries.push(entry);}},
        fetch:(url,options)=>{const pending=deferred();requests.push({url,options,...pending});return pending.promise;},
        loadStores:async()=>{loads++;if(refresh instanceof Error)throw refresh;return refresh;}});
    vm.runInContext(code,context);
    return{doc,el,modal,controls,requests,signal,context,frames,loads:()=>loads,refresh:v=>refresh=v,run:s=>vm.runInContext(s,context),save:()=>vm.runInContext('saveStore()',context)};
}

test('pending store save freezes native and enhanced controls and captures the unchanged update packet',async()=>{
    const f=fixture();const pending=f.save();
    assert.equal(f.el('store-modal').attrs['aria-busy'],'true');assert.ok(f.controls.every(e=>e.disabled));assert.equal(f.doc.activeElement,f.el('store-modal-result'));
    assert.ok(f.el('store-modal-result').classList.contains('is-pending'));assert.match(f.el('store-modal-result').textContent,/Saving store/);
    assert.equal(f.requests[0].url,'/admin/stores/update');assert.equal(f.requests[0].options.signal,f.signal.signal);
    assert.deepEqual(f.requests[0].options.body.entries,[['store_name','Fictional Store'],['officer_id','900101'],['supervisor_ids[]','900102'],['logo_url','/fictional-logo.png'],['is_active','1'],['store_id','900001']]);
    await f.save();f.run('closeStoreModal();openStoreModal("create");');assert.equal(f.requests.length,1);assert.equal(f.modal.style.display,'grid');assert.equal(f.context.editingStoreId,900001);
    f.requests[0].resolve(good);await pending;assert.equal(f.modal.style.display,'none');assert.equal(f.el('protected').disabled,true);assert.equal(f.el('store-name').disabled,false);assert.equal(f.el('store-modal').attrs['aria-busy'],undefined);
});

test('pending store Tab stays on progress and Escape cannot dismiss the dialog',async()=>{
    const f=fixture(),pending=f.save();
    const start=part.indexOf('document.getElementById("store-modal")?.addEventListener("keydown"');
    f.run(part.slice(start,part.indexOf('document.getElementById("store-save-btn")?.addEventListener',start)));
    for(const key of ['Tab','Escape']){let prevented=false;f.modal.keydown({key,defaultPrevented:false,preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(f.modal.style.display,'grid');assert.equal(f.doc.activeElement,f.el('store-modal-result'));}
    f.requests[0].resolve(good);await pending;
});

test('server rejection retains store and staffing drafts, restores exact control states, and focuses the alert',async()=>{
    const f=fixture(),pending=f.save();f.requests[0].resolve({ok:false,json:async()=>({status:'error',message:'Fictional store name already exists'})});await pending;
    assert.equal(f.modal.style.display,'grid');assert.equal(f.loads(),0);assert.equal(f.el('store-name').value,'Fictional Store');assert.deepEqual(f.context.selectedSupervisorIds,[900102]);
    assert.equal(f.el('protected').disabled,true);assert.equal(f.el('close-store-modal').disabled,false);assert.equal(f.doc.activeElement,f.el('store-modal-result'));
    assert.equal(f.el('store-modal-result').attrs.role,'alert');assert.ok(f.el('store-modal-result').classList.contains('is-error'));assert.equal(f.el('store-modal-result').scroll.block,'nearest');
    assert.equal(f.el('store-save-btn').textContent,'Save Changes');assert.equal(f.run('storeSaving'),false);
});

for(const mode of ['network','JSON','missing confirmation'])test(`unconfirmed store ${mode} preserves the draft and gives check-before-retry guidance`,async()=>{
    const f=fixture(),pending=f.save();if(mode==='network')f.requests[0].reject(TypeError('raw transport'));else f.requests[0].resolve({ok:true,json:async()=>{if(mode==='JSON')throw SyntaxError('raw parser');return{};}});await pending;
    assert.equal(f.modal.style.display,'grid');assert.equal(f.loads(),0);assert.equal(f.el('store-name').value,'Fictional Store');assert.ok(f.el('store-modal-result').classList.contains('is-warning'));
    assert.match(f.el('store-modal-result').textContent,/could not be confirmed.*Check the store list before trying again/);assert.doesNotMatch(f.el('store-modal-result').textContent,/raw/);
});

test('confirmed store creation retains confirmation when list refresh fails',async()=>{
    const f=fixture();f.context.editingStoreId=null;f.context.editingInitialIsActive=false;f.el('store-active').value='0';f.context.selectedSupervisorIds=[];f.el('store-officer-id').value='';f.refresh(false);
    const pending=f.save();assert.equal(f.requests[0].url,'/admin/stores/create');assert.equal(f.requests[0].options.body.entries.some(([key])=>key==='store_id'),false);
    f.requests[0].resolve(good);await pending;assert.equal(f.modal.style.display,'none');assert.match(f.el('stores-result').textContent,/^Store created\..*Retry stores/);assert.ok(f.el('stores-result').classList.contains('is-warning'));
});

test('late replies after navigation or hidden editor cannot resurface feedback or change new controls',async()=>{
    for(const mode of ['aborted','hidden','disconnected']){
        const f=fixture(),pending=f.save();if(mode==='aborted')f.signal.abort();else if(mode==='hidden')f.modal.style.display='none';else f.modal.isConnected=false;
        f.el('outside').focus();f.requests[0].resolve(good);await pending;
        assert.equal(f.loads(),0);assert.equal(f.doc.activeElement,f.el('outside'));assert.match(f.el('store-modal-result').textContent,/Saving store/);assert.equal(f.el('store-save-btn').disabled,true);
    }
});

test('hidden or departed store editor cannot send a save',async()=>{
    const f=fixture();f.modal.style.display='none';await f.save();assert.equal(f.requests.length,0);f.modal.style.display='grid';f.signal.abort();await f.save();assert.equal(f.requests.length,0);
});

test('local activation validation opens and focuses the missing staff picker',async()=>{
    for(const kind of ['cashier','supervisor']){
        const f=fixture();if(kind==='cashier')f.el('store-officer-id').value='';else f.context.selectedSupervisorIds=[];
        await f.save();assert.equal(f.requests.length,0);assert.equal(f.doc.activeElement,f.el(kind==='cashier'?'store-officer-search':'store-supervisor-search'));
        assert.equal(f.context[kind==='cashier'?'officerEditorOpen':'supervisorEditorOpen'],true);
    }
});

test('server feedback preserves an outside focus choice',async()=>{
    const f=fixture(),pending=f.save();f.el('outside').focus();f.requests[0].resolve({ok:false,json:async()=>({status:'error',message:'Fictional rejection'})});await pending;assert.equal(f.doc.activeElement,f.el('outside'));
});

test('old confirmed refresh cannot overwrite a newer save outcome or release its controls',async()=>{
    const f=fixture(),refresh=deferred();f.refresh(refresh.promise);const pending=f.save();f.requests[0].resolve(good);
    await new Promise(resolve=>setImmediate(resolve));assert.equal(f.modal.style.display,'none');assert.equal(f.run('storeSaving'),false);
    f.modal.style.display='grid';f.context.editingStoreId=900003;f.context.selectedSupervisorIds=[900102];const newer=f.save();assert.equal(f.requests.length,2);
    refresh.resolve(true);await pending;assert.equal(f.el('stores-result').textContent,'');assert.equal(f.run('storeSaving'),true);assert.equal(f.el('store-name').disabled,true);
    f.requests[1].resolve({ok:false,json:async()=>({status:'error',message:'Newer rejection'})});await newer;assert.equal(f.el('store-modal-result').textContent,'Newer rejection');
});

test('confirmed refresh failure keeps its recovery control visible after the confirmation appears',async()=>{
    const f=fixture(),retry=f.el('retry');retry.closest=selector=>selector==='#stores-gallery'?{}:null;
    let messageWhenScrolled='';retry.scrollIntoView=options=>{retry.scroll=options;messageWhenScrolled=f.el('stores-result').textContent;};
    f.context.loadStores=async()=>{retry.focus();return false;};const pending=f.save();f.requests[0].resolve(good);await pending;
    assert.equal(f.doc.activeElement,retry);assert.equal(retry.scroll.block,'nearest');assert.match(messageWhenScrolled,/^Store updated\..*Retry stores/);
});
