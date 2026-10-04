const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/app-dialog.js','utf8');
function fixture(){
    const frames=[],nodes=new Map(); let root;
    const document={activeElement:null,body:{classList:{add(){},remove(){}},appendChild(){}},createElement(){return root;}};
    function element(id){
        const classes=new Set(); const node={id,tabIndex:0,disabled:false,value:'',handlers:{},
            classList:{add:c=>classes.add(c),remove:c=>classes.delete(c),contains:c=>classes.has(c),toggle(c,on){on?classes.add(c):classes.delete(c);}},
            setAttribute(){},addEventListener:(name,fn)=>node.handlers[name]=fn,
            focus(){document.activeElement=node;},select(){},
            getClientRects(){return classes.has('is-hidden')||((id==='input'||id==='textarea')&&nodes.get('.app-dialog-input-wrap').classList.contains('is-hidden'))?[]:[{}];},
        }; return node;
    }
    for(const selector of ['#app-dialog-title','#app-dialog-message','.app-dialog-icon i','.app-dialog-input-wrap','#app-dialog-input-label','#app-dialog-input','#app-dialog-textarea','#app-dialog-error','[data-dialog-cancel]','[data-dialog-confirm]'])nodes.set(selector,element(selector==='#app-dialog-input'?'input':selector==='#app-dialog-textarea'?'textarea':selector));
    root=element('root');root.classList.add('is-hidden');root.dataset={};
    root.querySelector=s=>nodes.get(s);
    root.querySelectorAll=()=>['#app-dialog-input','#app-dialog-textarea','[data-dialog-cancel]','[data-dialog-confirm]'].map(s=>nodes.get(s));
    const opener=element('opener');opener.focus();
    const window={requestAnimationFrame:fn=>frames.push(fn)};
    vm.runInNewContext(source,{document,window});
    return {window,root,nodes,document,opener,flush(){while(frames.length)frames.shift()();},key(key,shiftKey=false){let prevented=false;root.handlers.keydown({key,shiftKey,target:document.activeElement,preventDefault(){prevented=true;}});return prevented;}};
}
test('confirmation traps keyboard between visible buttons, excluding hidden prompt fields',async()=>{
    const f=fixture(),pending=f.window.IbemsDialog.confirm('Proceed?');f.flush();
    const cancel=f.nodes.get('[data-dialog-cancel]'),confirm=f.nodes.get('[data-dialog-confirm]');
    assert.equal(f.document.activeElement,confirm);
    assert.equal(f.key('Tab'),true);assert.equal(f.document.activeElement,cancel);
    assert.equal(f.key('Tab',true),true);assert.equal(f.document.activeElement,confirm);
    f.key('Escape');assert.equal(await pending,false);assert.equal(f.document.activeElement,f.opener);
});
test('closing before opening animation cannot steal restored opener focus',async()=>{
    const f=fixture(),pending=f.window.IbemsDialog.confirm('Proceed?');
    f.key('Escape');f.flush();
    assert.equal(await pending,false);assert.equal(f.document.activeElement,f.opener);
});
test('prompt trap includes visible multiline field and excludes disabled controls',async()=>{
    const f=fixture(),pending=f.window.IbemsDialog.prompt('Reason?',{multiline:true});f.flush();
    const textarea=f.nodes.get('#app-dialog-textarea'),confirm=f.nodes.get('[data-dialog-confirm]');
    f.nodes.get('[data-dialog-cancel]').disabled=true;
    assert.equal(f.document.activeElement,textarea);
    assert.equal(f.key('Tab',true),true);assert.equal(f.document.activeElement,confirm);
    assert.equal(f.key('Tab'),true);assert.equal(f.document.activeElement,textarea);
    f.key('Escape');assert.equal(await pending,null);
});
