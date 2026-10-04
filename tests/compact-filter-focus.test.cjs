const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/app-layout.js', 'utf8').replace(/\r\n/g, '\n');
test('Reset all restores fields instead of delegating to Refresh',()=>{
    const begin=source.indexOf('panel.querySelector("[data-compact-filter-reset]").addEventListener');
    const end=source.indexOf('\n\n        advancedControls.forEach',begin);
    let handler, refreshes=0, changes=0;
    const sort={type:'select-one',value:'name:desc',dataset:{compactDefault:'name:asc'},dispatchEvent(){changes++;}};
    const search={value:'query',dispatchEvent(){changes++;}};
    vm.runInNewContext(source.slice(begin,end),{
        panel:{querySelector:()=>({addEventListener:(name,fn)=>handler=fn})},
        originalButtons:[{id:'acct-refresh-btn',textContent:'Refresh',click(){refreshes++;}}],
        advancedControls:[sort],searchInput:search,Event:function(){},window:{setTimeout:fn=>fn()},updateCounts(){}
    });
    handler();
    assert.equal(sort.value,'name:asc');
    assert.equal(search.value,'');
    assert.equal(refreshes,0);
    assert.equal(changes,2);
});
const start = source.indexOf('bar.addEventListener("keydown", (event) => {');
const handlerSource = source.slice(start, source.indexOf('        updateCounts();', start));
function fixture(mobile = true) {
    let handler;
    let closed = 0;
    const document = {activeElement: null};
    const controls = [0, 1, 2].map(() => ({disabled:false,tabIndex:0,getClientRects:()=>[{}],focus(){document.activeElement=this;}}));
    const panel = {hidden:false,dataset:{mode:'filter'},querySelectorAll:()=>controls,contains:element=>controls.includes(element)};
    const trigger = {focus(){document.activeElement=this;}};
    vm.runInNewContext(handlerSource, {bar:{addEventListener:(_,fn)=>handler=fn},panel,document,window:{matchMedia:()=>({matches:mobile})},sortToggle:trigger,filterToggle:trigger,setOpen(){closed++;panel.hidden=true;}});
    return {document,controls,panel,trigger,event:values=>handler({key:'Tab',defaultPrevented:false,preventDefault(){this.defaultPrevented=true;},...values}),closed:()=>closed};
}
test('mobile filter boundaries wrap around visible enabled controls',()=>{
    const f=fixture();f.document.activeElement=f.controls[2];f.event({});assert.equal(f.document.activeElement,f.controls[0]);
    f.event({shiftKey:true});assert.equal(f.document.activeElement,f.controls[2]);
});
test('handled child Escape leaves the parent open; next Escape restores opener',()=>{
    const f=fixture();f.event({key:'Escape',defaultPrevented:true});assert.equal(f.closed(),0);
    f.event({key:'Escape'});assert.equal(f.closed(),1);assert.equal(f.document.activeElement,f.trigger);
});
test('desktop filter does not trap normal Tab navigation',()=>{
    const f=fixture(false);f.document.activeElement=f.controls[2];f.event({});assert.equal(f.document.activeElement,f.controls[2]);
});
test('portaled calendar and select interactions retain parent; outside click closes it',()=>{
    const start = source.indexOf('document.addEventListener("click", (event) => {\n            if (panel.hidden || bar.contains(event.target)) return;');
    const end = source.indexOf('        bar.addEventListener("keydown"', start);
    let handler; let closed = 0;
    vm.runInNewContext(source.slice(start,end),{
        document:{addEventListener:(_,fn)=>handler=fn},panel:{hidden:false,dataset:{mode:'filter'}},
        bar:{contains:()=>false},pageSignal:null,setOpen:()=>closed++,
    });
    // A navigation click removes its button, but retains the original event path.
    handler({target:{closest:()=>null},composedPath:()=>[{}, {matches:()=>true}]});
    assert.equal(closed,0);
    handler({target:{closest:()=>null},composedPath:()=>[{}, {matches:()=>false}]});
    assert.equal(closed,1);
});
