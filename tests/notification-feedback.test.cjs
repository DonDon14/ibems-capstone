const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/notifications.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function setup() {
    let doc;
    const elements = new Map();
    const get = id => {
        if (!elements.has(id)) elements.set(id, {id,hidden:true, contains:()=>false, attributes:{}, listeners:{}, setAttribute(k,v){this.attributes[k]=v;}, getAttribute(k){return this.attributes[k];}, addEventListener(k,v){this.listeners[k]=v;}, focus(){doc.activeElement=this;}});
        return elements.get(id);
    };
    const requests = [];
    let refresh;
    const events={};
    doc={getElementById:get,activeElement:null,addEventListener(name,handler){events[name]=handler;}};
    vm.runInNewContext(source, {document:doc, window:{location:{origin:'http://localhost',pathname:'/user/dashboard'},addEventListener(name,handler){if(name==='focus')refresh=handler;},setInterval(){}}, fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,resolve,reject,options})), Date});
    return {get,requests,refresh,events,doc};
}
const response = count => ({ok:true,json:async()=>({unread_count:count,notifications:[{id:1,title:'Current notice',message:'Recorded',created_at:'2026-10-02',link_url:'#'}]})});
test('keyboard focus leaving notification center dismisses without moving focus',()=>{
    const qa=setup(), panel=qa.get('notification-panel');
    panel.hidden=false;
    qa.events.focusin({target:{closest:()=>({})}});
    assert.equal(panel.hidden,false);
    qa.events.focusin({target:{closest:()=>null}});
    assert.equal(panel.hidden,true);
    assert.equal(qa.get('notification-toggle').attributes['aria-expanded'],'false');
});

test('background refresh defers while focus is inside the open panel', async()=>{
    const qa=setup(); qa.requests[0].resolve(response(2)); await flush();
    const panel=qa.get('notification-panel'); panel.hidden=false; panel.contains=()=>true;
    const before=qa.get('notification-list').innerHTML;
    qa.refresh();
    assert.equal(qa.requests.length,1);
    assert.equal(qa.get('notification-list').innerHTML,before);
    panel.contains=()=>false;
    qa.refresh();
    assert.equal(qa.requests.length,2);
    assert.match(qa.get('notification-list').innerHTML,/Loading notifications/);
});
test('notification refresh clears old cards and ignores obsolete success and failure',async()=>{
    for(const outcome of ['success','failure']) {
        const qa=setup(); qa.refresh();
        assert.match(qa.get('notification-list').innerHTML,/Loading notifications/);
        assert.equal(qa.get('notification-read-all').disabled,true);
        qa.requests[1].resolve(response(2)); await flush();
        const current=qa.get('notification-list').innerHTML;
        if(outcome==='success')qa.requests[0].resolve(response(7));
        else qa.requests[0].reject(new Error('Old error'));
        await flush();
        assert.equal(qa.get('notification-list').innerHTML,current);
        assert.equal(qa.get('notification-summary').textContent,'2 unread');
    }
});
test('current notification HTTP failure is an alert with a recovery hint',async()=>{
    const qa=setup(); qa.requests[0].resolve({ok:false}); await flush();
    assert.match(qa.get('notification-list').innerHTML,/role="alert"/);
    assert.match(qa.get('notification-list').innerHTML,/data-notification-retry/);
    assert.equal(qa.get('notification-summary').textContent,'Unavailable');
    assert.equal(qa.get('notification-read-all').disabled,true);
});

const retryEvent = () => ({stopped:false,preventDefault(){},stopPropagation(){this.stopped=true;},target:{closest:selector=>selector==='[data-notification-retry]'?{}:null}});
test('notification retry uses GET, retains feed focus and ignores duplicate pending retry',async()=>{
    const qa=setup(),list=qa.get('notification-list');
    qa.requests[0].reject(new Error('Unavailable')); await flush();
    const event=retryEvent();
    const retry=list.listeners.click(event);
    assert.equal(event.stopped,true);
    assert.equal(qa.requests.length,2);
    assert.equal(qa.requests[1].url,'http://localhost/notifications?limit=12');
    assert.equal(qa.requests[1].options.method,undefined);
    assert.equal(list.attributes['aria-busy'],'true');
    assert.match(list.innerHTML,/Loading notifications/);
    assert.equal(qa.doc.activeElement,list);
    await list.listeners.click(retryEvent());
    assert.equal(qa.requests.length,2);
    qa.requests[1].resolve(response(2)); await retry;
    assert.equal(list.attributes['aria-busy'],'false');
    assert.equal(qa.doc.activeElement,list);
    assert.match(list.innerHTML,/Current notice/);
    assert.equal(qa.get('notification-read-all').disabled,false);
});
test('obsolete feed completion cannot clear the current busy state',async()=>{
    const qa=setup(),list=qa.get('notification-list'); qa.refresh();
    qa.requests[0].resolve(response(4)); await flush();
    assert.equal(list.attributes['aria-busy'],'true');
    qa.requests[1].resolve(response(2)); await flush();
    assert.equal(list.attributes['aria-busy'],'false');
    assert.equal(qa.get('notification-badge').textContent,'2');
});
test('malformed notification payloads remain unavailable and expose a retry',async()=>{
    for(const data of [null,{notifications:[],unread_count:null},{notifications:[],unread_count:''},{notifications:[],unread_count:-1},{notifications:[],unread_count:1.5},{notifications:[],unread_count:true},{notifications:{},unread_count:2}]){
        const qa=setup();qa.requests[0].resolve({ok:true,json:async()=>data});await flush();
        assert.match(qa.get('notification-list').innerHTML,/data-notification-retry/);
        assert.equal(qa.get('notification-list').attributes['aria-busy'],'false');
        assert.equal(qa.get('notification-summary').textContent,'Unavailable');
        assert.equal(qa.get('notification-read-all').disabled,true);
    }
});
test('mark-all progress retains keyboard context, defers refresh, and retry only reads the feed',async()=>{
    const qa=setup(),list=qa.get('notification-list'),readAll=qa.get('notification-read-all'),panel=qa.get('notification-panel');
    let disabled=false;
    Object.defineProperty(readAll,'disabled',{get:()=>disabled,set(value){disabled=value;if(value&&qa.doc.activeElement===readAll)qa.doc.activeElement={id:'body'};}});
    qa.requests[0].resolve(response(2));await flush();
    panel.hidden=false;panel.contains=element=>element===readAll||element===list;qa.doc.activeElement=readAll;
    const pending=readAll.listeners.click();
    assert.equal(qa.doc.activeElement,list);
    assert.match(list.innerHTML,/Marking notifications as read/);
    assert.equal(list.attributes['aria-busy'],'true');
    qa.refresh();qa.get('notification-toggle').listeners.click();qa.get('notification-toggle').listeners.click();
    assert.equal(qa.requests.length,2);
    qa.doc.activeElement={id:'outside'};
    qa.requests[1].reject(new Error('Unconfirmed'));await pending;
    assert.equal(qa.doc.activeElement.id,'outside');
    assert.equal(list.attributes['aria-busy'],'false');
    assert.equal(qa.get('notification-badge').textContent,'2');
    const retry=list.listeners.click(retryEvent());
    assert.equal(qa.requests[2].options.method,undefined);
    assert.equal(qa.requests.filter(request=>request.options.method==='POST').length,1);
    qa.requests[2].resolve(response(0));await retry;
    assert.equal(qa.get('notification-badge').hidden,true);
    assert.equal(readAll.disabled,true);
});
test('mark all read failure keeps prior count and prevents duplicate submissions',async()=>{
    const qa=setup(); qa.requests[0].resolve(response(2)); await flush();
    const first=qa.get('notification-read-all').listeners.click();
    await qa.get('notification-read-all').listeners.click();
    assert.equal(qa.requests.length,2);
    qa.requests[1].resolve({ok:false,json:async()=>({success:true})}); await first;
    assert.equal(qa.get('notification-badge').textContent,'2');
    assert.match(qa.get('notification-list').innerHTML,/could not be marked as read/);
    assert.equal(qa.get('notification-read-all').disabled,true);
});
test('confirmed mark all read refreshes the feed before enabling another action',async()=>{
    const qa=setup(); qa.requests[0].resolve(response(2)); await flush();
    const pending=qa.get('notification-read-all').listeners.click();
    qa.requests[1].resolve({ok:true,json:async()=>({success:true})}); await flush();
    assert.equal(qa.get('notification-read-all').disabled,true);
    qa.requests[2].resolve(response(0)); await pending;
    assert.equal(qa.get('notification-badge').hidden,true);
    assert.equal(qa.get('notification-read-all').disabled,true);
});
