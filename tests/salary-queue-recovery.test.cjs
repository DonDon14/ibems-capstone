const test = require('node:test');
const assert = require('node:assert/strict');
const {fixture,data,response} = require('./helpers/salary-queue-fixture.cjs');
test('queue starts truthful loading, clears old review controls and rejects duplicate manual reads', async () => {
    const f=fixture(); const old=new f.Node('review'); f.body.appendChild(old); f.document.activeElement=old;
    const pending=f.run(2,true);
    assert.equal(f.body.childElementCount,0); assert.equal(f.count.textContent,'Loading…');
    assert.equal(f.body.attrs['aria-busy'],'true'); assert.equal(f.refresh.disabled,true); assert.equal(f.document.activeElement,f.feedback);
    assert.equal(await f.run(2,true),false); assert.equal(f.requests.length,1);
    assert.equal(f.requests[0].url,'/accounting/debts/scheduled-salary?page=2'); assert.equal(f.requests[0].options.signal,f.signal);
    f.pending[0](response(data({page:2,has_more:false}))); assert.equal(await pending,true);
    assert.equal(f.refresh.disabled,false); assert.equal(f.body.attrs['aria-busy'],undefined); assert.equal(f.document.activeElement,f.body);
});
for(const kind of ['server','network','parser','missing rows','invalid row','invalid totals','wrong page']) test(`salary ${kind} failure remains unavailable and Retry retains its requested page`,async()=>{
    let attempts=0;
    const f=fixture(async()=>{
        if(++attempts>1)return response(data({page:2,has_more:false}));
        if(kind==='network')throw Error('offline');
        if(kind==='parser')return {ok:true,json:async()=>{throw Error('Unreadable')}};
        if(kind==='server')return response({status:'error',message:'Rejected <script>reference</script>'},false);
        if(kind==='missing rows')return response(data({rows:undefined}));
        if(kind==='invalid row')return response(data({page:2,rows:[null]}));
        if(kind==='invalid totals')return response(data({page:2,total_pending:'invalid'}));
        return response(data({page:1}));
    });
    f.document.activeElement=f.refresh;
    assert.equal(await f.run(2,true),false); assert.equal(f.count.textContent,'Unavailable'); assert.equal(f.feedback.attrs.role,'alert');
    assert.doesNotMatch(f.feedback.innerHTML,/<script>/); assert.match(f.feedback.innerHTML,/Retry scheduled salaries/);
    assert.equal(f.document.activeElement,f.feedback.querySelector('button'));
    assert.equal(await f.feedback.querySelector('button').events.click(),true);
    assert.equal(f.requests[1].url,'/accounting/debts/scheduled-salary?page=2'); assert.equal(f.feedback.attrs.role,'status'); assert.equal(f.feedback.textContent,'');
    assert.equal(f.document.activeElement,f.body);
});
test('confirmed empty queue is neutral; an emptied later page offers first-page recovery',async()=>{
    const f=fixture(async()=>response(data({page:3,rows:[],total_pending:2,has_more:false})));
    f.document.activeElement=f.refresh; assert.equal(await f.run(3,true),true);
    assert.match(f.feedback.textContent,/Return to the first page/); assert.equal(f.feedback.attrs.role,'status');
    assert.equal(f.document.activeElement,f.refresh);
    const empty=fixture(async()=>response(data({rows:[],total_pending:0,due_count:0,has_more:false})));
    assert.equal(await empty.run(),true); assert.match(empty.feedback.textContent,/No scheduled/); assert.equal(empty.count.textContent,'0 due · 0 pending');
});
test('newer salary reply owns data and busy restoration while an older reply is ignored',async()=>{
    const f=fixture(); const first=f.run(1),second=f.run(2);
    f.pending[0](response(data())); assert.equal(await first,false); assert.equal(f.refresh.disabled,true); assert.equal(f.count.textContent,'Loading…');
    f.pending[1](response(data({page:2,has_more:false}))); assert.equal(await second,true); assert.equal(f.refresh.disabled,false);
});
for(const departure of ['abort','detach'])test(`${departure} ignores late salary feedback and prevents a new read`,async()=>{
    const f=fixture(); const pending=f.run(); if(departure==='abort')f.signal.aborted=true;else f.body.isConnected=false;
    f.pending[0](response(data())); assert.equal(await pending,false); assert.equal(f.count.textContent,'Loading…');
    assert.equal(await f.run(),false); assert.equal(f.requests.length,1);
});
test('salary completion preserves a control focused outside the queue during its request',async()=>{
    const f=fixture();f.document.activeElement=f.refresh;const pending=f.run();const outside=new f.Node('outside');f.document.activeElement=outside;
    f.pending[0](response(data()));await pending;assert.equal(f.document.activeElement,outside);
});
