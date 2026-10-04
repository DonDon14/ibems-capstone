const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {fixture,transaction,reply}=require('./helpers/investigation-read-fixture.cjs');
for(const oldFailure of [true,false])test(`transaction choices suppress superseded ${oldFailure?'failure':'success'}`,async()=>{
    const f=fixture(),old=f.transactions(900001),current=f.transactions(900002);f.pending[1].resolve(reply({status:'success',transactions:[transaction()]}));await current;
    if(oldFailure)f.pending[0].reject(Error('Offline'));else f.pending[0].resolve(reply({status:'success',transactions:[transaction({client_txn_id:'old'})]}));await old;
    assert.match(f.node('investigation-transaction').innerHTML,/FICTIONAL-TXN/);assert.doesNotMatch(f.node('investigation-transaction').innerHTML,/old/);assert.equal(f.node('investigation-transaction').disabled,false);
});
test('reset invalidates pending choices and restores original Open state',async()=>{
    const f=fixture();f.node('investigation-open').disabled=true;const loading=f.transactions(900001);await f.transactions(0);f.pending[0].resolve(reply({status:'success',transactions:[transaction()]}));await loading;
    assert.equal(f.node('investigation-transaction').innerHTML,'<option value="">General balance investigation</option>');assert.equal(f.node('investigation-transaction').disabled,false);assert.equal(f.node('investigation-open').disabled,true);
});
for(const failure of ['network','http','parser','application','missing records','invalid amount','duplicate row'])test(`transaction ${failure} disables actions and offers employee-scoped retry`,async()=>{
    const f=fixture();f.node('investigation-message').textContent='Confirmed prior action';const loading=f.transactions(900001);const data={status:'success',transactions:[transaction()]};
    if(failure==='application')data.status='error';if(failure==='missing records')delete data.transactions;if(failure==='invalid amount')data.transactions[0].amount='';if(failure==='duplicate row')data.transactions.push(transaction());
    if(failure==='network')f.pending[0].reject(Error('Offline'));else if(failure==='parser')f.pending[0].resolve({ok:true,json:async()=>{throw Error('Parser');}});else f.pending[0].resolve(reply(data,failure!=='http'));assert.equal(await loading,false);
    assert.equal(f.node('investigation-transaction').disabled,true);assert.equal(f.node('investigation-open').disabled,true);assert.equal(f.node('investigation-transaction-retry').hidden,false);assert.equal(f.node('investigation-message').textContent,'Confirmed prior action');
    const part=fs.readFileSync('public/assets/js/accounting-debts.part3.js','utf8');f.context.setInvestigationMessage=()=>{};vm.runInContext(part.slice(part.indexOf('async function openInvestigation()'),part.indexOf('async function recommendInvestigation')),f.context);await vm.runInContext('openInvestigation()',f.context);assert.equal(f.requests.length,1);
    f.node('investigation-transaction-retry').focus();const retry=f.transactions(900001,{manual:true});assert.equal(await f.transactions(900001,{manual:true}),false);assert.equal(f.requests[1].url,'/accounting/debt-investigations?user_id=900001');assert.equal(f.document.activeElement,f.node('investigation-transaction-feedback'));
    f.pending[1].resolve(reply({status:'success',transactions:[]}));assert.equal(await retry,true);assert.equal(f.node('investigation-open').disabled,false);assert.match(f.node('investigation-transaction-feedback').textContent,/No debt transactions/);assert.equal(f.node('investigation-message').textContent,'Confirmed prior action');
});
test('transaction retry retains selection or warns when its record disappears',async()=>{
    const f=fixture();let loading=f.transactions(900001);f.pending[0].resolve(reply({status:'success',transactions:[transaction()]}));await loading;f.node('investigation-transaction').value='900003';loading=f.transactions(900001);f.pending[1].reject(Error('Offline'));await loading;
    loading=f.transactions(900001,{manual:true});f.pending[2].resolve(reply({status:'success',transactions:[transaction()]}));await loading;assert.equal(f.node('investigation-transaction').value,'900003');
    loading=f.transactions(900001);f.pending[3].resolve(reply({status:'success',transactions:[]}));await loading;assert.equal(f.node('investigation-transaction').value,'');assert.match(f.node('investigation-transaction-feedback').textContent,/previously selected/);assert.equal(f.node('investigation-transaction-feedback').attrs.role,'status');
});
test('outside focus survives and closing a pending read restores Open',async()=>{
    const f=fixture();f.node('investigation-transaction-retry').focus();let loading=f.transactions(900001);f.node('outside').focus();f.pending[0].resolve(reply({status:'success',transactions:[transaction()]}));await loading;assert.equal(f.document.activeElement,f.node('outside'));
    loading=f.transactions(900001);f.reads.reset();f.node('debt-investigations-modal').style.display='none';assert.equal(f.node('investigation-open').disabled,false);f.pending[1].reject(Error('Late failure'));await loading;assert.equal(f.node('investigation-transaction-retry').hidden,true);
});
for(const departure of ['abort','detach'])test(`transaction late ${departure} cannot restore or announce another read`,async()=>{
    const f=fixture(),loading=f.transactions(900001);if(departure==='abort')f.signal.aborted=true;else f.node('debt-investigations-modal').isConnected=false;
    const text=f.node('investigation-transaction-feedback').textContent;f.pending[0].resolve(reply({status:'success',transactions:[transaction()]}));await loading;assert.equal(f.node('investigation-transaction-feedback').textContent,text);assert.equal(f.node('investigation-open').disabled,true);
});
test('a new page request restores the original Open state without committing its abandoned choices',async()=>{
    const f=fixture(),old=f.transactions(900001);f.signal.aborted=true;f.context.window.IbemsPortalNavigation.currentSignal={aborted:false};const current=f.transactions(900001);f.pending[1].resolve(reply({status:'success',transactions:[transaction()]}));await current;f.pending[0].reject(Error('Old read'));await old;assert.equal(f.node('investigation-open').disabled,false);assert.equal(f.node('investigation-transaction').disabled,false);
});
test('transaction read interlock suppresses selection changes during an action and clears after reset',async()=>{
    const f=fixture(),loading=f.transactions(900001);assert.equal(f.reads.busy,true);f.reads.reset();assert.equal(f.reads.busy,false);f.context.window.IbemsInvestigationActions={busy:true};assert.equal(await f.transactions(900002,{manual:true}),false);assert.equal(f.requests.length,1);f.pending[0].reject(Error('Abandoned'));await loading;
});
