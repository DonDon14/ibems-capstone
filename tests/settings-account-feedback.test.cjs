const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const main = fs.readFileSync('public/assets/js/store-settings.js', 'utf8');
const source = fs.readFileSync('public/assets/js/store-settings.part2.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
const success = data => ({ok:true,json:async()=>({status:'success',...data})});
function setup() {
    const elements = new Map(), requests = [], messages = [];
    const abort = new AbortController();
    let active, confirmation, refreshFailed = false, refreshes = 0;
    function node(id) {
        if (!elements.has(id)) {
            const classes = new Set();
            elements.set(id, {id, value:'', files:[], disabled:false, innerHTML:id, textContent:'', isConnected:true, attrs:{}, listeners:{},
                classList:{add:x=>classes.add(x),remove:x=>classes.delete(x),contains:x=>classes.has(x),toggle(x,on){if(on)classes.add(x);else classes.delete(x);}},
                focus(){active=id;},setAttribute(key,value){this.attrs[key]=value;},
                querySelector:()=>node('card'),querySelectorAll:()=>[...elements.values()].filter(x=>x.id!=='card'),
                addEventListener(type,cb){this.listeners[type]=cb;}
            });
        }
        return elements.get(id);
    }
    for (const id of ['payment-method-modal','payment-method-modal-close','account-name','account-number','account-qr','add-account-btn','save-method-btn','edit-method-label','edit-method-image','modal-account-form','settings-account-result','payment-account-list','show-account-form','cancel-account-form']) node(id);
    node('account-name').value='Fictional destination';node('account-number').value='DEMO-001';node('edit-method-label').disabled=true;
    const context = vm.createContext({URL,FormData,settingsPageSignal:abort.signal,settingsStoreId:5,settingsPaymentAccounts:[],settingsPaymentMethods:[],editingPaymentMethodId:7,editingPaymentAccountId:null,editingPaymentMethodImageUrl:'',paymentMethodSavePending:false,paymentAccountSavePending:false,
        settingsEscape:value=>String(value),settingsAnnounceResult(el,message,error){el.textContent=message;el.classList.toggle('is-error',error);messages.push({message,error});},
        loadPaymentAccounts:async()=>{refreshes++;if(refreshFailed)throw new Error('Refresh failed');},loadPaymentMethods:async()=>{refreshes++;if(refreshFailed)throw new Error('Refresh failed');},renderPaymentAccounts(){},settingsSetMethodResult(message){messages.push({message,outside:true});},
        fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),
        window:{IbemsPortalNavigation:{currentSignal:abort.signal},IbemsDialog:{confirm:()=>new Promise(resolve=>{confirmation=resolve;})}},
        document:{getElementById:node,body:{classList:{remove(){},add(){}}},get activeElement(){return node(active||'show-account-form');}}
    });
    const resultStart=main.indexOf('function setAccountResult(');
    vm.runInContext(main.slice(resultStart,main.indexOf('function renderPaymentAccounts(',resultStart)),context);
    vm.runInContext(source.slice(0,source.indexOf('async function createCategory(')),context);
    const addStart=source.indexOf('document.getElementById("add-account-btn")?.addEventListener');
    vm.runInContext(source.slice(addStart,source.indexOf('document.getElementById("payment-method-modal-close")?.addEventListener',addStart)),context);
    const removeStart=source.indexOf('document.getElementById("payment-account-list")?.addEventListener');
    vm.runInContext(source.slice(removeStart,source.indexOf('\n(async () =>',removeStart)),context);
    return {context,node,requests,messages,abort,active:()=>active,failRefresh(){refreshFailed=true;},refreshes:()=>refreshes,
        keys() {
            const close=node('payment-method-modal-close'),body=node('details'),save=node('save-method-btn');
            for(const control of [close,body,save]) {control.tabIndex=0;control.getClientRects=()=>[{}];}
            node('payment-method-modal').querySelectorAll=selector=>selector.includes('[tabindex]')?[close,body,save]:[close,save];
            const start=source.indexOf('const settingsModalRoot =');
            vm.runInContext(source.slice(start,source.indexOf('if (settingsModalRoot &&',start)),context);
            return event=>node('payment-method-modal').listeners.keydown(event);
        },
        save:()=>node('add-account-btn').listeners.click(),
        remove:()=>node('payment-account-list').listeners.click({target:{closest:selector=>selector==='[data-account-remove]'?Object.assign(node('remove'),{dataset:{accountRemove:'11'}}):null}}),
        confirm:value=>confirmation(value)
    };
}

test('receiving-account pending guard freezes drafts, blocks repeated saves/close, then restores protected controls',async()=>{
    const qa=setup();const pending=qa.save();await qa.save();
    assert.equal(qa.requests.length,1);assert.equal(qa.node('account-name').disabled,true);assert.equal(qa.node('payment-method-modal-close').disabled,false);
    assert.equal(qa.active(),'payment-method-modal-close');assert.equal(qa.node('card').attrs['aria-busy'],'true');
    qa.context.closePaymentMethodModal({type:'click'});assert.equal(qa.node('payment-method-modal').classList.contains('is-hidden'),false);
    qa.requests[0].reject(new Error('Save rejected'));await pending;
    assert.equal(qa.node('account-name').disabled,false);assert.equal(qa.node('edit-method-label').disabled,true);assert.equal(qa.node('card').attrs['aria-busy'],'false');
    assert.equal(qa.active(),'settings-account-result');assert.match(qa.node('settings-account-result').textContent,/Save rejected/);
});

test('empty account draft is corrected locally with focused alert and no request',async()=>{
    const qa=setup();qa.node('account-name').value=' ';await qa.save();
    assert.equal(qa.requests.length,0);assert.equal(qa.active(),'settings-account-result');assert.equal(qa.node('settings-account-result').classList.contains('is-error'),true);
});

test('confirmed account survives failed refresh with warning, cleared draft and no second POST',async()=>{
    const qa=setup();qa.failRefresh();const pending=qa.save();qa.requests[0].resolve(success({}));await pending;
    assert.equal(qa.requests.length,1);assert.equal(qa.refreshes(),2);assert.equal(qa.node('account-name').value,'');
    assert.equal(qa.node('modal-account-form').classList.contains('is-hidden'),true);
    assert.match(qa.node('settings-account-result').textContent,/^Receiving account added\./);
    assert.equal(qa.node('settings-account-result').classList.contains('is-warning'),true);assert.equal(qa.node('settings-account-result').classList.contains('is-error'),false);
});

test('late save after departure cannot clear new draft or refresh settings',async()=>{
    const qa=setup();const pending=qa.save();qa.abort.abort();qa.requests[0].resolve(success({}));await pending;
    assert.equal(qa.node('account-name').value,'Fictional destination');assert.equal(qa.refreshes(),0);
});

test('QR upload cannot continue saving into another editor/store',async()=>{
    const qa=setup();qa.node('account-qr').files=[new Blob(['fictional'])];const pending=qa.save();
    assert.match(qa.requests[0].url,/upload-qr/);qa.context.settingsStoreId=6;qa.requests[0].resolve(success({image_url:'/demo.png'}));await pending;
    assert.equal(qa.requests.length,1);assert.equal(qa.node('account-name').value,'Fictional destination');
});

test('deactivation confirmation is exclusive and cannot act after the editor changes',async()=>{
    const qa=setup();const pending=qa.remove();await qa.remove();qa.context.settingsStoreId=6;qa.confirm(true);await pending;
    assert.equal(qa.requests.length,0);assert.equal(qa.node('account-name').disabled,false);
});

test('confirmed deactivation and failed refresh remain an accurate warning',async()=>{
    const qa=setup();qa.failRefresh();const pending=qa.remove();qa.confirm(true);await flush();
    assert.match(qa.requests[0].url,/deactivate/);assert.deepEqual(JSON.parse(qa.requests[0].options.body),{store_id:5,account_id:11});
    qa.requests[0].resolve(success({}));await pending;
    assert.match(qa.node('settings-account-result').textContent,/^Receiving account deactivated\./);assert.equal(qa.node('settings-account-result').classList.contains('is-warning'),true);
});

test('cancelled deactivation restores original controls and its opener without writes',async()=>{
    const qa=setup();const pending=qa.remove();qa.confirm(false);await pending;
    assert.equal(qa.requests.length,0);assert.equal(qa.node('edit-method-label').disabled,true);assert.equal(qa.active(),'remove');
});

test('new method and confirmed first account are never cleaned up merely because lists failed to refresh',async()=>{
    const qa=setup();qa.context.editingPaymentMethodId=null;qa.node('edit-method-label').value='Fictional method';qa.failRefresh();
    const pending=qa.context.savePaymentMethodChanges();await flush();qa.requests[0].resolve(success({method:{id:19}}));await flush();
    assert.match(qa.requests[1].url,/payment-accounts\/save/);qa.requests[1].resolve(success({}));await pending;
    assert.equal(qa.requests.length,2);assert.ok(qa.requests.every(request=>!request.url.endsWith('/delete')));
    assert.match(qa.messages.find(item=>item.outside).message,/now available in POS.*could not be refreshed/);
});

test('confirmed method update with failed refresh closes with accurate saved guidance',async()=>{
    const qa=setup();qa.context.settingsPaymentMethods=[{id:7,code:'wallet'}];qa.node('edit-method-label').value='Fictional renamed method';qa.failRefresh();
    const pending=qa.context.savePaymentMethodChanges();await flush();qa.requests[0].resolve(success({}));await pending;
    assert.equal(qa.requests.length,1);assert.match(qa.messages.find(item=>item.outside).message,/updated.*could not be refreshed/);
    assert.equal(qa.node('payment-method-modal').classList.contains('is-hidden'),true);
});

test('newly rendered account controls stay disabled during pending refresh then regain original availability',()=>{
    const qa=setup();qa.context.setPaymentAccountSavePending(true);
    const late=qa.node('late-account-edit');qa.context.syncPaymentEditorPending(null,'');
    assert.equal(late.disabled,true);qa.context.setPaymentAccountSavePending(false);assert.equal(late.disabled,false);
    assert.equal(qa.node('edit-method-label').disabled,true);
});

test('focused result Tab enters the dialog controls and boundary Tab stays contained',()=>{
    const qa=setup(),key=qa.keys();let prevented=0;const tab={key:'Tab',shiftKey:false,preventDefault(){prevented++;}};
    qa.node('settings-account-result').focus();key(tab);assert.equal(qa.active(),'payment-method-modal-close');
    qa.node('save-method-btn').focus();key(tab);assert.equal(qa.active(),'payment-method-modal-close');
    key({...tab,shiftKey:true});assert.equal(qa.active(),'save-method-btn');assert.equal(prevented,3);
});

test('pending Escape retains editor while keyboard details region remains reachable',()=>{
    const qa=setup(),key=qa.keys();qa.context.setPaymentAccountSavePending(true);
    key({key:'Escape',preventDefault(){}});assert.equal(qa.node('payment-method-modal').classList.contains('is-hidden'),false);
    let prevented=false;
    key({key:'Tab',shiftKey:false,preventDefault(){prevented=true;}});
    assert.equal(prevented,false);assert.equal(qa.node('details').disabled,false);
});
