const test=require('node:test');
const assert=require('node:assert/strict');
const {fixture,data,response}=require('./helpers/salary-queue-fixture.cjs');
for(const outcome of ['populated','empty','error'])test(`salary pager restores meaningful focus after ${outcome} results replace its button`,async()=>{
    const f=fixture(),button=new f.Node('pager');f.body.appendChild(button);f.document.activeElement=button;
    const pending=f.run(2);
    assert.equal(f.document.activeElement,f.feedback);
    f.pending[0](outcome==='error'?response({status:'error'},false):response(data({page:2,rows:outcome==='populated'?data().rows:[],total_pending:outcome==='populated'?2:0,due_count:outcome==='populated'?1:0,has_more:false})));
    await pending;
    assert.equal(f.document.activeElement,outcome==='populated'?f.body:outcome==='empty'?f.feedback:f.feedback.querySelector('button'));
});
test('salary queue does not steal focus moved elsewhere during its request',async()=>{
    const f=fixture(),button=new f.Node('pager');f.body.appendChild(button);f.document.activeElement=button;
    const pending=f.run(2),outside=new f.Node('other-control');f.document.activeElement=outside;
    f.pending[0](response(data({page:2})));await pending;assert.equal(f.document.activeElement,outside);
});
