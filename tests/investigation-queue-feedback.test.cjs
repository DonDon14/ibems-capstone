const test=require('node:test'),assert=require('node:assert/strict');
const {fixture,row,reply}=require('./helpers/investigation-read-fixture.cjs');
test('queue clears stale cards, ignores superseded reads and suppresses duplicate manual retries',async()=>{
    const f=fixture();f.node('investigation-list').innerHTML='old cards';const old=f.queue();assert.equal(f.node('investigation-list').innerHTML,'');assert.equal(f.node('investigation-list').attrs['aria-busy'],'true');
    const current=f.queue();assert.equal(await f.queue({manual:true}),false);assert.equal(f.requests.length,2);
    f.pending[1].resolve(reply({status:'success',investigations:[row()]}));await current;f.pending[0].reject(Error('Old failure'));await old;
    assert.equal(f.node('investigation-list').innerHTML,'current records');assert.equal(f.node('investigation-refresh').disabled,false);
});
for(const failure of ['network','http','parser','application','missing records','invalid row','invalid status','missing recommendation'])test(`queue ${failure} remains unavailable and offers one local retry`,async()=>{
    const f=fixture();f.node('investigation-refresh').focus();const loading=f.queue({manual:true});assert.equal(f.document.activeElement,f.node('investigation-queue-feedback'));
    const data={status:'success',investigations:[row()]};if(failure==='application')data.status='error';if(failure==='missing records')delete data.investigations;if(failure==='invalid row')data.investigations=[null];if(failure==='invalid status')data.investigations=[row({status:'unknown'})];if(failure==='missing recommendation')data.investigations=[row({status:'recommended'})];
    if(failure==='network')f.pending[0].reject(Error('Offline'));else if(failure==='parser')f.pending[0].resolve({ok:true,json:async()=>{throw Error('Parser');}});else f.pending[0].resolve(reply(data,failure!=='http'));
    assert.equal(await loading,false);assert.equal(f.node('investigation-list').innerHTML,'');assert.equal(f.node('investigation-queue-feedback').attrs.role,'alert');assert.equal(f.node('investigation-queue-retry').hidden,false);assert.equal(f.document.activeElement,f.node('investigation-queue-retry'));
    const retry=f.queue({manual:true});f.pending[1].resolve(reply({status:'success',investigations:[]}));assert.equal(await retry,true);assert.match(f.node('investigation-queue-feedback').textContent,/No debt investigations/);assert.equal(f.node('investigation-queue-feedback').attrs.role,'status');assert.equal(f.node('investigation-queue-retry').hidden,true);
});
test('queue draft survives failed retry and only returns to the same unchanged investigation',async()=>{
    const f=fixture();let loading=f.queue();f.pending[0].resolve(reply({status:'success',investigations:[row()]}));await loading;
    f.card().fields['.investigation-findings'].value='Fictional retained findings';f.card().fields['.investigation-action'].value='partial_reversal';
    loading=f.queue({manual:true});f.pending[1].reject(Error('Offline'));await loading;loading=f.queue({manual:true});f.pending[2].resolve(reply({status:'success',investigations:[row()]}));await loading;
    assert.equal(f.card().fields['.investigation-findings'].value,'Fictional retained findings');assert.equal(f.card().fields['.investigation-action'].value,'partial_reversal');
    loading=f.queue({manual:true});f.pending[3].resolve(reply({status:'success',investigations:[row({updated_at:'2026-10-04 19:00:00'})]}));await loading;assert.equal(f.card().fields['.investigation-findings'].value,'');
});
test('queue refresh restores enabled Refresh; retry focuses records and outside focus survives',async()=>{
    for(const origin of ['refresh','retry','outside']){const f=fixture();f.node(origin==='refresh'?'investigation-refresh':origin==='retry'?'investigation-queue-retry':'outside').focus();const loading=f.queue();if(origin==='outside')f.node('outside').focus();f.pending[0].resolve(reply({status:'success',investigations:[row()]}));await loading;assert.equal(f.document.activeElement,f.node(origin==='refresh'?'investigation-refresh':origin==='retry'?'investigation-list':'outside'));}
});
for(const departure of ['close','abort','detach','page'])test(`queue late ${departure} response cannot render or change recovery controls`,async()=>{
    const f=fixture(),loading=f.queue();if(departure==='close'){f.reads.reset();f.node('debt-investigations-modal').style.display='none';}if(departure==='abort')f.signal.aborted=true;if(departure==='detach')f.node('debt-investigations-modal').isConnected=false;if(departure==='page')f.context.window.IbemsPortalNavigation.currentSignal={aborted:false};
    const feedback=f.node('investigation-queue-feedback').textContent;f.pending[0].resolve(reply({status:'success',investigations:[row()]}));await loading;assert.equal(f.node('investigation-list').innerHTML,'');assert.equal(f.node('investigation-queue-feedback').textContent,feedback);
});
test('production cards retain independent approval gates, escaped identity and draft revision',()=>{
    const fs=require('node:fs'),vm=require('node:vm'),source=fs.readFileSync('public/assets/js/accounting-debts.part3.js','utf8');
    const container={},modal={dataset:{currentRole:'ACCOUNTING_OFFICE',currentUser:'900099'}};
    const context=vm.createContext({document:{getElementById:id=>id==='investigation-list'?container:modal},aEscape:v=>String(v??'').replaceAll('<','&lt;').replaceAll('"','&quot;'),aCategory:String,aMoney:String});
    vm.runInContext(source.slice(source.indexOf('function investigationLabel'),source.indexOf('async function loadInvestigationTransactions')),context);
    context.rows=[row({name:'Fictional <script>',status:'recommended',recommended_by:900099,recommended_action:'no_change',recommended_amount:0})];vm.runInContext('renderInvestigations(rows)',context);assert.doesNotMatch(container.innerHTML,/investigation-approve/);assert.match(container.innerHTML,/Fictional &lt;script>/);assert.match(container.innerHTML,/data-investigation-revision="900001:recommended:2026-10-04 18:00:00"/);
    context.rows[0].recommended_by=900098;vm.runInContext('renderInvestigations(rows)',context);assert.match(container.innerHTML,/investigation-approve/);
});
test('Tab from the final nonsequential feedback stays inside the investigation dialog',()=>{
    const vm=require('node:vm'),source=require('node:fs').readFileSync('public/assets/js/accounting-debts.part3.js','utf8');let handler,active,prevented=false;
    const make=()=>({disabled:false,tabIndex:0,getClientRects:()=>[{}],focus(){active=this;},compareDocumentPosition:()=>4});
    const close=make(),refresh=make(),feedback={};const modal={addEventListener:(name,callback)=>handler=callback,contains:()=>true,querySelectorAll:()=>[close,refresh]};
    const context=vm.createContext({window:{},document:{getElementById:()=>modal,body:{classList:{contains:()=>false}},get activeElement(){return active;}},getComputedStyle:()=>({visibility:'visible'}),Node:{DOCUMENT_POSITION_PRECEDING:2,DOCUMENT_POSITION_FOLLOWING:4}});
    vm.runInContext(source.slice(source.indexOf('document.getElementById("debt-investigations-modal").addEventListener("keydown"'),source.indexOf('async function openInvestigation()')),context);
    active=feedback;handler({key:'Tab',shiftKey:false,currentTarget:modal,preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(active,close);
});
test('read interlock reports only current pending reads and suppresses queue refresh during an action',async()=>{
    const f=fixture();const loading=f.queue();assert.equal(f.reads.busy,true);f.signal.aborted=true;assert.equal(f.reads.busy,false);f.signal.aborted=false;f.pending[0].resolve(reply({status:'success',investigations:[]}));await loading;assert.equal(f.reads.busy,false);
    f.context.window.IbemsInvestigationActions={busy:true};assert.equal(await f.queue({manual:true}),false);assert.equal(f.requests.length,1);
});
