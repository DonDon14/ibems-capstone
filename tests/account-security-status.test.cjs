const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/account-security.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function setup() {
    let document;
    class Element {
        hidden = false; textContent = ''; attributes = {}; listeners = {}; classes = new Set();
        classList = {add: name => this.classes.add(name), remove: name => this.classes.delete(name), contains: name => this.classes.has(name), toggle: (name, on) => on ? this.classes.add(name) : this.classes.delete(name)};
        setAttribute(name, value) {this.attributes[name] = value;}
        addEventListener(name, handler) {this.listeners[name] = handler;}
        reset() {this.resets=(this.resets||0)+1;} focus() {document.activeElement=this;} querySelectorAll() {return [];}
    }
    const elements = new Map();
    const get = id => {if (!elements.has(id)) elements.set(id, new Element()); return elements.get(id);};
    const opener = get('opener');
    const requests = [];
    const controller = new AbortController();
    document={getElementById: get, querySelector: () => opener, querySelectorAll: () => [], body: get('body'), activeElement: opener};
    vm.runInNewContext(source, {
        HTMLElement: Element,
        document,
        window: {IbemsPortalNavigation: {currentSignal: controller.signal}, requestAnimationFrame: callback => callback(), addEventListener() {}},
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({url, options, resolve, reject})),
    });
    return {get, requests, document, retry:()=>get('account-security-retry').listeners.click(), open: () => opener.listeners.click(), close: () => get('account-security-modal').listeners.keydown({key:'Escape', preventDefault(){}}), abort: () => controller.abort()};
}
const response = (hasPin = true) => ({ok:true, json: async () => ({status:'success', has_debt_pin:hasPin, can_manage_debt_pin:true})});
test('security status loading is explicit and current success reveals the PIN form', async () => {
    const qa = setup(); qa.open();
    assert.equal(qa.get('account-pin-form').hidden, true);
    assert.match(qa.get('account-password-result').textContent, /Loading account security/);
    assert.equal(qa.get('account-password-result').attributes.role, 'status');
    assert.equal(qa.get('account-password-result').classes.has('is-success'), false);
    qa.requests[0].resolve(response()); await flush();
    assert.equal(qa.get('account-pin-form').hidden, false);
    assert.equal(qa.get('account-pin-current-password').required, true);
    assert.equal(qa.get('account-password-result').textContent, '');
});
test('reopened security ignores obsolete status success and failure', async () => {
    for (const outcome of ['success', 'failure']) {
        const qa = setup(); qa.open(); qa.close(); qa.open();
        qa.requests[1].resolve(response(false)); await flush();
        if (outcome === 'success') qa.requests[0].resolve(response(true));
        else qa.requests[0].reject(new Error('Obsolete failure'));
        await flush();
        assert.equal(qa.get('account-pin-current-password').required, false);
        assert.equal(qa.get('account-password-result').textContent, '');
    }
});
test('closed or navigation-aborted security ignores late status', async () => {
    for (const stop of ['close', 'abort']) {
        const qa = setup(); qa.open(); qa[stop]();
        qa.requests[0].resolve(response()); await flush();
        assert.equal(qa.get('account-pin-form').hidden, true);
    }
});
test('current HTTP failure remains an alert and keeps PIN controls unavailable', async () => {
    const qa = setup(); qa.open();
    qa.requests[0].resolve({ok:false,json:async()=>({status:'success',message:'Unable to load'})}); await flush();
    assert.equal(qa.get('account-pin-form').hidden, true);
    assert.equal(qa.get('account-password-result').attributes.role, 'alert');
    assert.equal(qa.get('account-password-result').attributes['aria-live'], 'assertive');
});

test('security retry uses GET, ignores duplicate activation and preserves drafts',async()=>{
    const qa=setup();qa.open();qa.requests[0].reject(new Error('Unavailable'));await flush();
    assert.equal(qa.get('account-security-status-actions').hidden,false);
    qa.get('account-new-password').value='unchanged draft';
    qa.get('account-security-retry').focus();qa.retry();qa.retry();
    assert.equal(qa.requests.length,2);
    assert.equal(qa.requests[1].url,'/profile/security');
    assert.equal(qa.requests[1].options.method,undefined);
    assert.equal(qa.requests[1].options.signal.aborted,false);
    assert.equal(qa.get('account-security-retry').disabled,true);
    assert.equal(qa.get('account-security-status-actions').hidden,true);
    assert.equal(qa.get('account-password-form').attributes['aria-busy'],'true');
    assert.equal(qa.get('account-password-result').attributes.role,'status');
    assert.equal(qa.document.activeElement,qa.get('account-password-result'));
    qa.requests[1].resolve(response(false));await flush();
    assert.equal(qa.get('account-new-password').value,'unchanged draft');
    assert.equal(qa.get('account-password-form').resets,1);
    assert.equal(qa.get('account-security-retry').disabled,false);
    assert.equal(qa.get('account-password-form').attributes['aria-busy'],'false');
    assert.equal(qa.get('account-pin-current-wrap').hidden,true);
    assert.equal(qa.document.activeElement,qa.get('account-current-password'));
});
test('failed security retry retains its result focus, exposes retry and restores busy state',async()=>{
    const qa=setup();qa.open();qa.requests[0].reject(new Error('Unavailable'));await flush();qa.retry();
    qa.requests[1].resolve({ok:false,json:async()=>({status:'error',message:'Current failure'})});await flush();
    assert.equal(qa.document.activeElement,qa.get('account-password-result'));
    assert.equal(qa.get('account-security-status-actions').hidden,false);
    assert.equal(qa.get('account-password-form').attributes['aria-busy'],'false');
    assert.equal(qa.get('account-password-result').textContent,'Current failure');
    assert.equal(qa.get('account-pin-form').hidden,true);
});
test('malformed PIN status never exposes an unconfirmed purchase PIN form',async()=>{
    for(const data of [{status:'success'},{status:'success',has_debt_pin:false,can_manage_debt_pin:'false'},{status:'success',has_debt_pin:'true',can_manage_debt_pin:true}]){
        const qa=setup();qa.open();qa.requests[0].resolve({ok:true,json:async()=>data});await flush();
        assert.equal(qa.get('account-pin-form').hidden,true);
        assert.equal(qa.get('account-security-status-actions').hidden,false);
        assert.equal(qa.get('account-password-result').attributes.role,'alert');
        assert.match(qa.get('account-password-result').textContent,/not confirmed/);
    }
});
test('closed security cannot retry and late retry cannot reopen controls or steal outside focus',async()=>{
    for(const outcome of ['success','failure']){
        const qa=setup();qa.open();qa.requests[0].reject(new Error('Unavailable'));await flush();qa.retry();qa.close();
        const count=qa.requests.length;qa.retry();assert.equal(qa.requests.length,count);
        qa.document.activeElement=qa.get('outside');
        if(outcome==='success')qa.requests[1].resolve(response());else qa.requests[1].reject(new Error('Late failure'));
        await flush();
        assert.equal(qa.get('account-security-modal').classes.has('is-hidden'),true);
        assert.equal(qa.get('account-pin-form').hidden,true);
        assert.equal(qa.document.activeElement,qa.get('outside'));
        assert.equal(qa.get('account-password-form').attributes['aria-busy'],'false');
    }
});
test('successful status leaves a departed focus intact and hides PIN for ineligible accounts',async()=>{
    const qa=setup();qa.open();qa.requests[0].reject(new Error('Unavailable'));await flush();qa.retry();qa.document.activeElement=qa.get('outside');
    qa.requests[1].resolve({ok:true,json:async()=>({status:'success',has_debt_pin:false,can_manage_debt_pin:false})});await flush();
    assert.equal(qa.get('account-pin-form').hidden,true);
    assert.equal(qa.get('account-security-status-actions').hidden,true);
    assert.equal(qa.document.activeElement,qa.get('outside'));
});
