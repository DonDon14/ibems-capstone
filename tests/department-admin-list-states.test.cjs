const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm'), fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/department-debt-pages.js', 'utf8');
const listSource = source.slice(source.indexOf('    function matches(department)'), source.indexOf('    function syncDepartmentReason()'));
const apiSource = source.slice(source.indexOf('async function api('), source.indexOf('function jsonOptions('));
function fixture() {
    const elements = {}, requests = [], messages = [], signal = new AbortController();
    const doc = {body:{},activeElement:null}; doc.activeElement = doc.body;
    function element(id) {
        return elements[id] ||= {
            value:'', disabled:false, attrs:{}, classes:new Set(), html:'', children:{},
            get innerHTML() {return this.html;}, set innerHTML(value) {if(this.contains(doc.activeElement))doc.activeElement=doc.body;this.html=value;this.children={};},
            contains(node) {return node?.owner===id || node===this;}, focus() {doc.activeElement=this;},
            setAttribute(name,value) {this.attrs[name]=value;}, removeAttribute(name) {delete this.attrs[name];},
            querySelector(selector) {const exists=selector==='[data-department-retry]'?this.html.includes('data-department-retry'):selector==='[data-edit-department]'?this.html.includes('data-edit-department'):this.html.includes('data-state');return exists?(this.children[selector] ||= {owner:id,focus(options) {this.focusOptions=options;doc.activeElement=this;}}):null;}
        };
    }
    doc.getElementById=id=>{const el=element(id);el.classList={toggle(name,on) {on?el.classes.add(name):el.classes.delete(name);}};return el;};
    const context = vm.createContext({document:doc,pageSignal:signal.signal,
        fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),
        escapeHtml:value=>String(value??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'),
        setResult:(...args)=>messages.push(args)});
    vm.runInContext('let loadRequestId=0,departmentLoadState="ready",departments=[],people=[];'+apiSource+listSource,context);
    const reply = (departments=[])=>({ok:true,status:200,json:async()=>({status:'success',departments,people:[]})});
    const row={id:7,name:'Fictional laboratory',code:'LAB',is_active:true,head_user_id:2,head_name:'Fictional Head'};
    return {element,doc,requests,messages,signal,reply,row,load:()=>vm.runInContext('load()',context),render:()=>vm.runInContext('render()',context),state:()=>vm.runInContext('departmentLoadState',context)};
}
test('older department failure cannot clear newer progress or re-enable Refresh',async()=>{
    const f=fixture();const first=f.load(),second=f.load();f.requests[0].reject(Error('old error'));await first;
    assert.equal(f.element('department-total').textContent,'Loading…');assert.equal(f.element('department-admin-body').attrs['aria-busy'],'true');assert.equal(f.element('department-refresh').disabled,true);assert.equal(f.element('department-add').disabled,true);
    f.requests[1].resolve(f.reply([f.row]));await second;assert.equal(f.element('department-total').textContent,'1');assert.equal(f.element('department-admin-body').attrs['aria-busy'],undefined);assert.equal(f.element('department-refresh').disabled,false);assert.equal(f.element('department-add').disabled,false);
});
test('network error exposes bounded retry and keeps active filters',async()=>{
    const f=fixture();f.element('department-search').value='LAB';f.element('department-status').value='active';const pending=f.load();f.requests[0].reject(TypeError('Failed to fetch'));await pending;
    const body=f.element('department-admin-body');assert.match(body.innerHTML,/Departments unavailable.*Retry departments/);assert.doesNotMatch(body.innerHTML,/Failed to fetch/);assert.equal(f.element('department-total').textContent,'Unavailable');assert.equal(f.messages.at(-1)[1],'');
    assert.equal(f.element('department-admin-table-region').classes.has('is-department-list-state'),true);assert.equal(f.element('department-admin-table-region').attrs.tabindex,undefined);assert.equal(f.element('department-add').disabled,true);
    const retry=f.load();f.requests[1].resolve(f.reply([f.row]));await retry;assert.equal(f.element('department-search').value,'LAB');assert.equal(f.element('department-status').value,'active');assert.match(body.innerHTML,/Fictional laboratory/);assert.equal(f.element('department-admin-table-region').attrs.tabindex,'0');
});
test('HTTP rejection escapes server detail in one atomic list alert',async()=>{
    const f=fixture(),pending=f.load();f.requests[0].resolve({ok:false,status:422,json:async()=>({status:'error',message:'Rejected <unsafe> & reference'})});await pending;
    const html=f.element('department-admin-body').innerHTML;assert.match(html,/Rejected &lt;unsafe&gt; &amp; reference/);assert.match(html,/role="alert" aria-live="assertive" aria-atomic="true"/);assert.equal(f.messages.at(-1)[1],'');
});
for (const malformed of ['parser','missing records']) test(`unreadable department ${malformed} response remains unavailable`,async()=>{
    const f=fixture(),pending=f.load();f.requests[0].resolve({ok:true,status:200,json:async()=>{if(malformed==='parser')throw SyntaxError('Raw parser details');return {status:'success',departments:{},people:[]};}});await pending;
    assert.equal(f.state(),'error');assert.match(f.element('department-admin-body').innerHTML,/could not be loaded/);assert.doesNotMatch(f.element('department-admin-body').innerHTML,/Raw parser details/);
});
test('empty departments and filtered departments have distinct bounded states',async()=>{
    const f=fixture(),pending=f.load();f.requests[0].resolve(f.reply());await pending;assert.match(f.element('department-admin-body').innerHTML,/No departments have been created/);assert.equal(f.element('department-total').textContent,'0');
    f.element('department-search').value='missing';const reload=f.load();f.requests[1].resolve(f.reply([f.row]));await reload;assert.match(f.element('department-admin-body').innerHTML,/current filters/);assert.equal(f.element('department-total').textContent,'1');assert.equal(f.element('department-admin-table-region').attrs.tabindex,undefined);
});
test('department retry owns progress then restores Retry on failure and Edit on success',async()=>{
    const f=fixture(),pending=f.load();f.requests[0].reject(Error('offline'));await pending;
    const body=f.element('department-admin-body');body.querySelector('[data-department-retry]').focus();const retry=f.load();assert.equal(f.doc.activeElement,body.querySelector('.data-state'));f.requests[1].reject(Error('offline'));await retry;assert.equal(f.doc.activeElement,body.querySelector('[data-department-retry]'));
    const success=f.load();f.requests[2].resolve(f.reply([f.row]));await success;assert.equal(f.doc.activeElement,body.querySelector('[data-edit-department]'));assert.equal(f.doc.activeElement.focusOptions.preventScroll,false);
});
test('department retry completion preserves outside focus',async()=>{
    const f=fixture();f.element('department-admin-body').innerHTML='data-department-retry';f.element('department-admin-body').querySelector('[data-department-retry]').focus();const pending=f.load();f.element('department-search').focus();f.requests[0].resolve(f.reply([f.row]));await pending;assert.equal(f.doc.activeElement,f.element('department-search'));
});
test('departed department page cannot render failure or restore controls',async()=>{
    const f=fixture(),pending=f.load();f.signal.abort();f.requests[0].reject(Error('offline'));await pending;assert.match(f.element('department-admin-body').innerHTML,/Loading departments/);assert.equal(f.element('department-refresh').disabled,true);assert.equal(f.element('department-admin-body').attrs['aria-busy'],'true');
});
