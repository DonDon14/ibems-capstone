const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm');
const source=fs.readFileSync('public/assets/js/admin-audit.js','utf8');
function fixture() {
    const elements={},requests=[],signal=new AbortController();const doc={body:{},activeElement:null};doc.activeElement=doc.body;
    const el=id=>elements[id] ||= {
        value:id==='audit-limit'?'100':id==='audit-sort'?'date:desc':'',attrs:{},style:{},classes:new Set(),children:{},html:'',
        get innerHTML(){return this.html;},set innerHTML(value){if(this.contains(doc.activeElement))doc.activeElement=doc.body;this.html=value;this.children={};},
        contains(node){return node===this||node?.owner===id;},closest(){return null;},getClientRects(){return [{}];},
        setAttribute(name,value){this.attrs[name]=value;},removeAttribute(name){delete this.attrs[name];},focus(){doc.activeElement=this;},scrollIntoView(){this.scrolled=true;},querySelectorAll(){return [];},
        querySelector(selector){const token=selector==='[data-audit-retry]'?'data-audit-retry':selector==='[data-audit-review-dates]'?'data-audit-review-dates':selector==='.audit-row'?'class="audit-row"':selector==='.data-state'?'data-state':null;return token&&this.html.includes(token)?(this.children[selector] ||= {owner:id,focus(){doc.activeElement=this;}}):null;}
    };
    doc.getElementById=id=>{const node=el(id);node.classList={toggle(name,on){on?node.classes.add(name):node.classes.delete(name);}};return node;};
    const context=vm.createContext({document:doc,window:{IbemsPortalNavigation:{currentSignal:signal.signal},IbemsAvatar:{html:()=>'<span></span>'}},URLSearchParams,CSS:{escape:value=>value},fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))});
    vm.runInContext(source.slice(0,source.indexOf('function auditDetailItem')),context);vm.runInContext('auditSetOptions=()=>{};',context);
    const reply=(data=[])=>({ok:true,json:async()=>({status:'success',data,summary:{total_events:data.length},pagination:{total:data.length,page:1,total_pages:1}})});
    return {el,doc,requests,signal,context,reply,load:()=>vm.runInContext('auditLoad()',context),reviewDates:()=>vm.runInContext('auditReviewDates()',context)};
}
test('Audit loading replaces old totals with a bounded busy state',async()=>{
    const f=fixture();f.el('audit-total-events').textContent='987';const pending=f.load();assert.equal(f.el('audit-total-events').textContent,'Loading…');assert.equal(f.el('audit-body').attrs['aria-busy'],'true');assert.equal(f.el('audit-table-region').attrs.tabindex,undefined);
    f.requests[0].resolve(f.reply([{id:1,action:'FICTIONAL'}]));await pending;assert.equal(f.el('audit-total-events').textContent,'1');assert.equal(f.el('audit-table-region').attrs.tabindex,'0');
});
test('Audit retry preserves query, dates, action, entity, sort, page and row limit',async()=>{
    const f=fixture();for(const [id,value] of Object.entries({'audit-search':'fictional','audit-action-filter':'TEST','audit-entity-filter':'demo','audit-date-from':'2026-10-01','audit-date-to':'2026-10-04','audit-limit':'50','audit-sort':'actor:asc'}))f.el(id).value=value;vm.runInContext('auditPage=3',f.context);
    const pending=f.load();f.requests[0].reject(TypeError('Failed to fetch'));await pending;assert.match(f.el('audit-body').innerHTML,/data-audit-retry/);assert.doesNotMatch(f.el('audit-body').innerHTML,/Failed to fetch/);assert.equal(f.el('audit-result').textContent,'');assert.equal(f.el('audit-total-events').textContent,'Unavailable');
    const retry=f.load();assert.equal(f.requests[1].url,f.requests[0].url);for(const piece of ['page=3','q=fictional','action=TEST','entity=demo','date_from=2026-10-01','date_to=2026-10-04','page_size=50','sort_by=actor','sort_dir=asc'])assert.ok(f.requests[1].url.includes(piece));f.requests[1].resolve(f.reply());await retry;
});
test('Audit server rejection is escaped in one atomic list alert',async()=>{
    const f=fixture(),pending=f.load();f.requests[0].resolve({ok:false,json:async()=>({status:'error',message:'Rejected <unsafe> & reference'})});await pending;assert.match(f.el('audit-body').innerHTML,/Rejected &lt;unsafe&gt; &amp; reference/);assert.match(f.el('audit-body').innerHTML,/role="alert" aria-live="assertive" aria-atomic="true"/);assert.equal(f.el('audit-result').attrs.role,'status');
});
for(const mode of ['JSON','missing records'])test(`Audit unreadable ${mode} remains unavailable`,async()=>{
    const f=fixture(),pending=f.load();f.requests[0].resolve({ok:true,json:async()=>{if(mode==='JSON')throw SyntaxError('Raw parse detail');return {status:'success',data:{}};}});await pending;assert.match(f.el('audit-body').innerHTML,/could not be loaded/);assert.equal(f.el('audit-total-events').textContent,'Unavailable');assert.doesNotMatch(f.el('audit-body').innerHTML,/Raw parse detail/);
});
test('Audit empty records remove headers and scrolling target and report actual zero',async()=>{
    const f=fixture(),pending=f.load();f.requests[0].resolve(f.reply());await pending;assert.match(f.el('audit-body').innerHTML,/No audit events found/);assert.equal(f.el('audit-total-events').textContent,'0');assert.equal(f.el('audit-table-region').classes.has('is-audit-list-state'),true);assert.equal(f.el('audit-table-region').attrs.tabindex,undefined);assert.equal(f.el('audit-pager').innerHTML,'');
});
test('Audit reversed dates supersede pending reads without showing old summary or false zero',async()=>{
    const f=fixture(),pending=f.load();f.el('audit-date-from').value='2026-10-04';f.el('audit-date-to').value='2026-10-01';await f.load();assert.equal(f.requests.length,1);assert.equal(f.el('audit-total-events').textContent,'Needs correction');assert.equal(f.el('audit-date-from').attrs['aria-invalid'],'true');assert.match(f.el('audit-body').innerHTML,/data-audit-review-dates/);assert.equal(f.el('audit-result').textContent,'');assert.equal(f.el('audit-body').attrs['aria-busy'],undefined);
    f.requests[0].resolve(f.reply([{id:1}]));await pending;assert.equal(f.el('audit-total-events').textContent,'Needs correction');
    f.el('audit-date-from').value='2026-10-01';const fixed=f.load();assert.equal(f.el('audit-date-from').attrs['aria-invalid'],undefined);f.requests[1].resolve(f.reply());await fixed;
});
test('Audit date review opens closed compact filters and focuses the enhanced date control',()=>{
    const f=fixture();let opened=false;const target=f.el('date-trigger');target.getClientRects=()=>opened?[{}]:[];f.el('audit-date-from').closest=()=>({querySelector:()=>target});f.doc.querySelector=()=>({click(){opened=true;}});f.reviewDates();assert.equal(opened,true);assert.equal(f.doc.activeElement,target);assert.equal(target.scrolled,true);
});
test('Audit Review dates activation cannot dismiss the opened panel through the same document click',()=>{
    let handler,opened=false,dismissed=false;const start=source.indexOf('document.getElementById("audit-body").addEventListener("click"');
    const context=vm.createContext({document:{getElementById:()=>({addEventListener:(_,cb)=>handler=cb})},auditReviewDates:()=>opened=true});
    vm.runInContext(source.slice(start,source.indexOf('document.getElementById("audit-body").addEventListener("keydown"',start)),context);
    const event={stopped:false,target:{closest:selector=>selector==='[data-audit-review-dates]'?{}:null},stopPropagation(){this.stopped=true;}};
    handler(event);if(opened&&!event.stopped)dismissed=true;assert.equal(opened,true);assert.equal(dismissed,false);
});
test('Audit retry restores focus on failure, success and empty without stealing outside focus',async()=>{
    const f=fixture(),pending=f.load();f.requests[0].reject(Error('offline'));await pending;const body=f.el('audit-body');body.querySelector('[data-audit-retry]').focus();const retry=f.load();assert.equal(f.doc.activeElement,body.querySelector('.data-state'));f.requests[1].reject(Error('offline'));await retry;assert.equal(f.doc.activeElement,body.querySelector('[data-audit-retry]'));
    const success=f.load();f.requests[2].resolve(f.reply([{id:1}]));await success;assert.equal(f.doc.activeElement,body.querySelector('.audit-row'));
    const empty=f.load();f.requests[3].resolve(f.reply());await empty;assert.equal(f.doc.activeElement,body.querySelector('.data-state'));
    const newer=f.load();f.el('audit-search').focus();f.requests[4].reject(Error('offline'));await newer;assert.equal(f.doc.activeElement,f.el('audit-search'));
});
test('Audit late failure leaves a newer loading state busy and a departed page untouched',async()=>{
    const f=fixture(),first=f.load(),second=f.load();f.requests[0].reject(Error('old'));await first;assert.equal(f.el('audit-body').attrs['aria-busy'],'true');assert.match(f.el('audit-body').innerHTML,/Loading audit/);f.signal.abort();f.requests[1].reject(Error('departed'));await second;assert.match(f.el('audit-body').innerHTML,/Loading audit/);
});
