const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/hr-staff.js', 'utf8');
function setup(mode = 'import') {
    const signal = { aborted: false }, requests = [], nodes = {};
    const doc = { body: {}, getElementById: id => nodes[id] };
    const element = id => ({ id, isConnected: true, attrs: {}, dataset: {}, style: {}, listeners: {}, textContent: '',
        innerHTML: mode === 'import' ? 'Import staff' : 'Add employee', disabled: false, resets: 0,
        classList: { toggle() {} }, setAttribute(k,v) { this.attrs[k]=v; }, addEventListener(k,fn) { this.listeners[k]=fn; },
        reset() { this.resets++; }, focus() { doc.activeElement=this; } });
    const fields = {}, unavailable = {};
    for (const kind of ['add','import']) {
        for (const suffix of ['form','submit','result']) { const id='hr-'+kind+'-'+suffix;nodes[id]=element(id); }
        fields[kind]=element(kind+'-field');unavailable[kind]=element(kind+'-protected');unavailable[kind].disabled=true;
        nodes['hr-'+kind+'-form'].querySelectorAll = () => [fields[kind],unavailable[kind],nodes['hr-'+kind+'-submit']];
        nodes['hr-'+kind+'-form'].contains = el => [fields[kind],unavailable[kind],nodes['hr-'+kind+'-submit'],nodes['hr-'+kind+'-result']].includes(el);
    }
    const kind=mode==='import'?'import':'add', form=nodes['hr-'+kind+'-form'], button=nodes['hr-'+kind+'-submit'], result=nodes['hr-'+kind+'-result'];
    doc.activeElement=button;
    vm.runInNewContext(source, { Intl, window: { IbemsPortalNavigation: { currentSignal: signal } }, document: doc,
        FormData: class { constructor(form) { this.form=form;assert.equal(fields[form.id.includes('import')?'import':'add'].disabled,false,'capture before disabling submitted controls'); } },
        fetch: (url,options) => new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),
    });
    const submit = () => form.listeners.submit({preventDefault(){}});
    const finish = (ok = true, data = kind==='import'?{status:'success',created:1,skipped:0,invalid:0}:{status:'success',user_id:21}) =>
        requests.at(-1).resolve({ok,json:async()=>ok?data:{status:'error',message:'Request rejected'}});
    return {nodes,doc,field:fields[kind],unavailable:unavailable[kind],signal,requests,form,button,result,submit,finish};
}

for (const mode of ['add','import']) {
    test('pending HR '+mode+' captures payload, announces neutral progress, freezes drafts and blocks duplicate submit',async()=>{
        const ui=setup(mode), first=ui.submit();await ui.submit();
        assert.equal(ui.requests.length,1);assert.equal(ui.form.attrs['aria-busy'],'true');assert.equal(ui.field.disabled,true);
        assert.match(ui.button.innerHTML,/Saving/);assert.equal(ui.result.attrs.role,'status');assert.equal(ui.result.style.color,'var(--text-soft)');
        assert.equal(ui.doc.activeElement,ui.result);assert.equal(ui.requests[0].options.body.form,ui.form);assert.equal(ui.requests[0].options.signal,ui.signal);
        assert.equal(ui.requests[0].options.method,'POST');assert.equal(ui.requests[0].url,mode==='add'?'/hr/staff/create':'/hr/staff/import-csv');
        ui.finish();await first;
        assert.equal(ui.form.attrs['aria-busy'],'false');assert.equal(ui.form.resets,1);assert.equal(ui.result.dataset.type,'success');
        assert.equal(ui.field.disabled,false);assert.equal(ui.unavailable.disabled,true);assert.equal(ui.doc.activeElement,ui.result);
        assert.equal(ui.button.innerHTML,mode==='import'?'Import staff':'Add employee');
    });
    test('failed HR '+mode+' restores controls, preserves drafts and gives a local alert',async()=>{
        const ui=setup(mode), pending=ui.submit();ui.finish(false);await pending;
        assert.equal(ui.field.disabled,false);assert.equal(ui.unavailable.disabled,true);assert.equal(ui.form.resets,0);
        assert.equal(ui.result.attrs.role,'alert');assert.equal(ui.result.attrs['aria-live'],'assertive');assert.equal(ui.result.attrs['aria-atomic'],'true');
        assert.equal(ui.result.textContent,'Request rejected');assert.equal(ui.doc.activeElement,ui.result);
    });
    test('late HR '+mode+' completion cannot reset or announce into an aborted/disconnected/replaced form',async()=>{
        for(const stale of ['aborted','disconnected','replaced']) {
            const ui=setup(mode), pending=ui.submit();
            if(stale==='aborted')ui.signal.aborted=true;
            if(stale==='disconnected')ui.form.isConnected=false;
            if(stale==='replaced')ui.nodes[ui.form.id]={...ui.form};
            ui.result.textContent='New page feedback';ui.doc.activeElement=ui.doc.body;
            ui.finish();await pending;
            assert.equal(ui.form.resets,0);assert.equal(ui.result.textContent,'New page feedback');assert.equal(ui.doc.activeElement,ui.doc.body);
        }
    });
    test('HR '+mode+' transport/JSON failures explain unconfirmed save and do not reset or retry',async()=>{
        for(const failure of ['network','json']) {
            const ui=setup(mode), pending=ui.submit();
            if(failure==='network')ui.requests[0].reject(Error('offline'));
            else ui.requests[0].resolve({ok:true,json:async()=>{throw Error('invalid JSON');}});
            await pending;assert.equal(ui.requests.length,1);assert.equal(ui.form.resets,0);
            assert.match(ui.result.textContent,/not confirmed.*Check existing staff/);assert.equal(ui.result.dataset.type,'error');
        }
    });
    test('HR '+mode+' ignores success-looking JSON on HTTP rejection and preserves outside focus',async()=>{
        const ui=setup(mode), pending=ui.submit(),outside={};ui.doc.activeElement=outside;
        ui.requests[0].resolve({ok:false,json:async()=>({status:'success',user_id:21,created:1,skipped:0,invalid:0})});
        await pending;assert.equal(ui.form.resets,0);assert.equal(ui.result.dataset.type,'error');assert.equal(ui.doc.activeElement,outside);
    });
}

test('confirmed HR creation without returned ID avoids NaN without falsely reporting failure',async()=>{
    const ui=setup('add'),pending=ui.submit();ui.finish(true,{status:'success'});await pending;
    assert.equal(ui.result.textContent,'Employee account created.');assert.equal(ui.form.resets,1);assert.equal(ui.result.dataset.type,'success');
});
test('confirmed HR import with missing or invalid totals keeps success and warns to review counts',async()=>{
    for(const counts of [{},{created:-1,skipped:0,invalid:0},{created:1.5,skipped:0,invalid:0},{created:null,skipped:0,invalid:0}]) {
        const ui=setup(),pending=ui.submit();ui.finish(true,{status:'success',...counts});await pending;
        assert.match(ui.result.textContent,/Staff import completed.*review the counts/);assert.equal(ui.result.dataset.type,'warning');
        assert.equal(ui.result.attrs.role,'status');assert.equal(ui.result.style.color,'var(--feedback-warning)');assert.equal(ui.form.resets,1);
    }
});
test('settled HR save only restores a blurred submit focus when the original form still owns it',async()=>{
    const ui=setup(),pending=ui.submit();ui.doc.activeElement=ui.doc.body;ui.finish();await pending;assert.equal(ui.doc.activeElement,ui.button);
    const outside=setup(),saving=outside.submit(),other={};outside.doc.activeElement=other;outside.finish();await saving;assert.equal(outside.doc.activeElement,other);
});
test('unavailable HR forms cannot start requests or clear current feedback',async()=>{
    for(const unavailable of ['aborted','disconnected','replaced']) {
        const ui=setup();
        if(unavailable==='aborted')ui.signal.aborted=true;
        if(unavailable==='disconnected')ui.form.isConnected=false;
        if(unavailable==='replaced')ui.nodes[ui.form.id]={...ui.form};
        ui.result.textContent='Existing feedback';await ui.submit();assert.equal(ui.requests.length,0);assert.equal(ui.result.textContent,'Existing feedback');
    }
});
