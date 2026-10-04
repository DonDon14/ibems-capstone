const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const base = fs.readFileSync('public/assets/js/admin-stores.js', 'utf8');
const part = fs.readFileSync('public/assets/js/admin-stores.part2.js', 'utf8');
const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => {resolve=a;reject=b;}); return {promise,resolve,reject}; };
const reply = (data, ok=true) => ({ok,json:async()=>data});
const rows = Array.from({length:12},(_,i)=>({id:i+1,store_name:`Fictional Store ${String(i+1).padStart(2,'0')}`,is_active:1,officer_id:1,supervisors:[{name:'Fictional Supervisor'}]}));
function fixture(fetch, manage=true) {
    let document;
    const focusCalls=[];
    class Node {
        constructor(id='',attrs={}) { this.id=id;this.attrs=attrs;this.value='';this.disabled=false;this.children=[];this.hidden=false;this.style={};this.classList={add:name=>{this.attrs.class=((this.attrs.class||'')+' '+name).trim();},toggle:(name,on)=>{const names=new Set((this.attrs.class||'').split(' ').filter(Boolean));on?names.add(name):names.delete(name);this.attrs.class=[...names].join(' ');}}; }
        set innerHTML(html) {
            if(this.contains(document.activeElement)) document.activeElement=document.body;
            this.html=html;this.children=[];
            for(const tag of html.matchAll(/<(button|a|div|select)\b([^>]*)>/g)) {
                const attrs={};for(const item of tag[2].matchAll(/([\w-]+)(?:="([^"]*)")?/g)) attrs[item[1]]=item[2]??'';
                const child=new Node(attrs.id||'',attrs);child.parent=this;child.disabled='disabled' in attrs;this.children.push(child);
            }
        }
        get innerHTML(){return this.html||'';}
        contains(node){return node===this||this.children.includes(node);}
        setAttribute(k,v){this.attrs[k]=v;}
        getAttribute(k){return this.attrs[k]??null;}
        removeAttribute(k){delete this.attrs[k];}
        matches(selector){
            if(selector.startsWith('.'))return (this.attrs.class||'').split(' ').includes(selector.slice(1));
            if(selector.startsWith('#'))return this.id===selector.slice(1);
            const match=selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
            return match?match[1] in this.attrs&&(match[2]===undefined||this.attrs[match[1]]===match[2]):false;
        }
        querySelectorAll(selector){return this.children.filter(c=>c.matches(selector));}
        querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
        closest(){return null;}
        addEventListener(type,fn){this[type]=fn;}
        focus(options){if(!this.disabled){document.activeElement=this;focusCalls.push({node:this,options});}}
    }
    const nodes={};for(const id of ['stores-gallery','store-staff-result','store-search','store-status-filter','store-sort','store-refresh-btn','open-store-modal','stores-result','store-modal','store-modal-result'])nodes[id]=new Node(id);
    const shell=new Node('',{'data-can-manage-stores':manage?'1':'0','data-stores-data-url':'/fixture/stores','data-store-detail-prefix':'/fixture/stores'});
    document={body:new Node('body'),getElementById:id=>nodes[id]||nodes['stores-gallery'].querySelector('#'+id),querySelector:s=>s==='.admin-stores-shell'?shell:s==='#stores-gallery .store-card-title'?nodes['stores-gallery'].querySelector('.store-card-title'):null,querySelectorAll:s=>nodes['stores-gallery'].querySelectorAll(s)};
    document.activeElement=document.body;
    const signal={aborted:false};const requests=[];
    const frames=[];
    const context=vm.createContext({document,URLSearchParams,requestAnimationFrame:fn=>frames.push(fn),window:{IbemsPortalNavigation:{currentSignal:signal}},fetch:async(url,options)=>{requests.push({url,options});return fetch(url,options);}});
    vm.runInContext(base+'\n'+part.slice(0,part.indexOf('let storeModalOpener')),context);
    return {nodes,document,context,signal,requests,focusCalls,frames,run:code=>vm.runInContext(code,context),load:options=>vm.runInContext(`loadStores(${options?JSON.stringify(options):''})`,context),staff:()=>vm.runInContext('loadOfficers()',context)};
}

test('initial store and staff reads run independently; editing waits for confirmed staff choices',async()=>{
    const staff=deferred();const f=fixture(url=>url.includes('officers')?staff.promise:reply({status:'success',data:rows}));
    f.run('if (canManageStores) loadOfficers(); loadStores();');
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(f.requests.length,2);assert.match(f.nodes['stores-gallery'].innerHTML,/Fictional Store/);
    assert.equal(f.nodes['open-store-modal'].disabled,true);assert.ok(f.nodes['stores-gallery'].querySelectorAll('[data-edit-store]').every(n=>n.disabled));
    staff.resolve(reply({status:'success',data:[{id:1,name:'Fictional Cashier'}]}));await new Promise(resolve=>setImmediate(resolve));
    assert.equal(f.nodes['open-store-modal'].disabled,false);assert.equal(f.nodes['store-staff-result'].hidden,true);
    assert.ok(f.nodes['stores-gallery'].querySelectorAll('[data-edit-store]').every(n=>!n.disabled));
});

test('staff HTTP, malformed, and connection failures leave confirmed stores readable',async()=>{
    for(const result of [reply({status:'error',message:'<b>Staff offline</b>'},false),reply({status:'success',data:{}}),null]){
        const f=fixture(url=>url.includes('officers')?(result||Promise.reject(Error('raw offline'))):reply({status:'success',data:rows}));
        await Promise.all([f.staff(),f.load()]);
        assert.match(f.nodes['stores-gallery'].innerHTML,/Fictional Store/);assert.match(f.nodes['store-staff-result'].innerHTML,/Retry store staff/);
        assert.equal(f.nodes['open-store-modal'].disabled,true);assert.equal(f.nodes['store-sort'].disabled,false);
        assert.equal(f.nodes['store-staff-result'].getAttribute('aria-busy'),null);assert.equal(f.nodes['stores-result'].textContent,'');
        assert.doesNotMatch(f.nodes['store-staff-result'].innerHTML,/<b>/);
    }
});

test('staff retry owns progress and recovery focus without reloading records',async()=>{
    let next=reply({status:'error'});const f=fixture(()=>next);
    await f.staff();f.nodes['store-staff-result'].querySelector('[data-store-staff-retry]').focus();
    const pending=deferred();next=pending.promise;const load=f.staff();
    assert.equal(f.document.activeElement,f.nodes['store-staff-result'].querySelector('.data-state'));
    pending.resolve(reply({status:'success',data:[]}));await load;
    assert.equal(f.document.activeElement,f.nodes['open-store-modal']);assert.equal(f.requests.filter(r=>!r.url.includes('officers')).length,0);
});

test('list loading and failures clear stale records, expose one alert, and restore refresh',async()=>{
    for(const mode of ['server','http','network','parser']){
        const f=fixture(()=>mode==='network'?Promise.reject(Error('raw offline')):mode==='parser'?{ok:true,json:async()=>{throw Error('raw JSON');}}:reply({status:'error',message:'<img onerror="bad">'},mode!=='http'));
        f.run('storesData=[{id:999}];');const load=f.load();
        assert.equal(f.nodes['stores-gallery'].getAttribute('aria-busy'),'true');assert.equal(f.nodes['store-refresh-btn'].disabled,true);
        assert.match(f.nodes['stores-gallery'].innerHTML,/Loading stores/);await load;
        assert.equal(f.nodes['stores-gallery'].getAttribute('aria-busy'),null);assert.equal(f.nodes['store-refresh-btn'].disabled,false);
        assert.equal(f.nodes['store-refresh-btn'].textContent,'Refresh');assert.equal(f.nodes['store-sort'].disabled,true);
        assert.equal((f.nodes['stores-gallery'].innerHTML.match(/role="alert"/g)||[]).length,1);
        assert.match(f.nodes['stores-gallery'].innerHTML,/Retry stores/);assert.doesNotMatch(f.nodes['stores-gallery'].innerHTML,/<img|raw offline|raw JSON/);
        assert.equal(f.nodes['stores-result'].textContent,'');assert.equal(f.run('storesData.length'),0);
    }
});

test('non-array successful payloads remain unavailable while a real empty array stays neutral',async()=>{
    for(const data of [undefined,null,{},'bad']){
        const f=fixture(()=>reply({status:'success',data}));assert.equal(await f.load(),false);
        assert.match(f.nodes['stores-gallery'].innerHTML,/Stores unavailable/);assert.doesNotMatch(f.nodes['stores-gallery'].innerHTML,/No stores found/);
    }
    const f=fixture(()=>reply({status:'success',data:[]}));assert.equal(await f.load(),true);
    assert.match(f.nodes['stores-gallery'].innerHTML,/No stores found/);assert.doesNotMatch(f.nodes['stores-gallery'].innerHTML,/role="alert"/);
    assert.equal(f.nodes['store-sort'].disabled,false);
});

test('retry retains search, status, local page size, sort, and a valid local page',async()=>{
    const f=fixture(()=>reply({status:'success',data:rows}));
    f.nodes['store-search'].value='Fictional Store';f.nodes['store-status-filter'].value='active';f.nodes['store-sort'].value='name_desc';
    f.run('storePage=2;storePageSize=10;');await f.load({resetPage:false});
    assert.equal(f.requests[0].url,'/fixture/stores?q=Fictional+Store&status=active');assert.equal(f.run('storePage'),2);
    assert.equal(f.run('storePageSize'),10);assert.equal(f.nodes['store-sort'].value,'name_desc');assert.match(f.nodes['stores-gallery'].innerHTML,/Page 2 of 2/);
    assert.equal(f.requests[0].options.signal,f.signal);
});

test('list retry focus goes from progress to retry, a visible record, or the empty status',async()=>{
    let next=reply({status:'error'});const f=fixture(()=>next);await f.load();
    for(const data of [{status:'error'},{status:'success',data:rows},{status:'success',data:[]}]){
        f.nodes['stores-gallery'].innerHTML='<button data-stores-retry>Retry</button>';
        f.nodes['stores-gallery'].querySelector('[data-stores-retry]').focus();
        const pending=deferred();next=pending.promise;const load=f.load({resetPage:false});
        assert.equal(f.document.activeElement,f.nodes['stores-gallery'].querySelector('.data-state'));
        pending.resolve(reply(data));await load;
        assert.equal(f.document.activeElement,f.nodes['stores-gallery'].querySelector(data.status==='error'?'[data-stores-retry]':data.data.length?'.store-card-title':'.data-state'));
    }
});

test('retry replies preserve an outside focus choice',async()=>{
    const pending=deferred();const f=fixture(()=>pending.promise);f.nodes['stores-gallery'].innerHTML='<button data-stores-retry>Retry</button>';
    f.nodes['stores-gallery'].querySelector('[data-stores-retry]').focus();const load=f.load();f.nodes['store-search'].focus();
    pending.resolve(reply({status:'success',data:rows}));await load;assert.equal(f.document.activeElement,f.nodes['store-search']);
});

test('obsolete and aborted list replies cannot clear the newer busy state or render cards',async()=>{
    const old=deferred(),current=deferred();let count=0;const f=fixture(()=>++count===1?old.promise:current.promise);
    const a=f.load(),b=f.load();old.reject(Error('obsolete'));await a;
    assert.equal(f.nodes['stores-gallery'].getAttribute('aria-busy'),'true');assert.equal(f.nodes['store-refresh-btn'].disabled,true);
    f.signal.aborted=true;current.resolve(reply({status:'success',data:rows}));await b;
    assert.match(f.nodes['stores-gallery'].innerHTML,/Loading stores/);assert.equal(f.run('storesData.length'),0);
});

test('obsolete and departed staff replies cannot enable editing or replace the current status',async()=>{
    const old=deferred(),current=deferred();let count=0;const f=fixture(()=>++count===1?old.promise:current.promise);
    const a=f.staff(),b=f.staff();old.reject(Error('obsolete'));await a;
    assert.match(f.nodes['store-staff-result'].innerHTML,/Loading store staff/);assert.equal(f.nodes['open-store-modal'].disabled,true);
    f.signal.aborted=true;current.resolve(reply({status:'success',data:[]}));await b;
    assert.equal(f.nodes['open-store-modal'].disabled,true);assert.equal(f.run('officersData.length'),0);
});

test('supervision does not fetch staff choices or create editing actions',async()=>{
    const f=fixture(()=>reply({status:'success',data:rows}),false);await f.staff();await f.load();
    assert.equal(f.requests.length,1);assert.doesNotMatch(f.nodes['stores-gallery'].innerHTML,/data-edit-store/);
    assert.match(f.nodes['stores-gallery'].innerHTML,/\/fixture\/stores\/1/);
});

test('pagination retains an enabled control and lets native focus bring it into view',async()=>{
    const f=fixture(()=>reply({status:'success',data:rows}));await f.load();
    f.run(part.slice(part.indexOf('document.getElementById("stores-gallery").addEventListener("click"'),part.indexOf('document.getElementById("stores-gallery").addEventListener("change"')));
    const next=f.nodes['stores-gallery'].querySelector('[data-store-page="next"]');next.focus();
    await f.nodes['stores-gallery'].click({target:{closest:s=>s==='[data-store-page]'?next:null}});
    assert.equal(f.run('storePage'),2);assert.equal(f.document.activeElement.getAttribute('data-store-page'),'previous');
    assert.equal(f.document.activeElement.disabled,false);assert.equal(f.focusCalls.at(-1).options,undefined,'Native focus can scroll the recovered pager into view');
});

test('page-size changes focus the first record when the larger size removes pagination',async()=>{
    const f=fixture(()=>reply({status:'success',data:rows}));await f.load();
    f.run(part.slice(part.indexOf('document.getElementById("stores-gallery").addEventListener("change"'),part.indexOf('document.addEventListener("click"')));
    const select=f.document.getElementById('store-page-size');select.value='25';select.focus();
    f.nodes['stores-gallery'].change({target:select});f.frames.shift()();
    assert.equal(f.run('storePageSize'),25);assert.equal(f.run('storePage'),1);
    assert.equal(f.document.activeElement,f.nodes['stores-gallery'].querySelector('.store-card-title'));
    assert.match(f.nodes['stores-gallery'].innerHTML,/Showing all 12 stores/);
});
