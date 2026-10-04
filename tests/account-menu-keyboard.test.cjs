const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/account-menu.js','utf8');
test('rapid close prevents scheduled focus from entering a hidden account dialog',()=>{
    let callback,focusCount=0;
    class HTMLElement {getClientRects(){return [{}];}focus(){focusCount++;}}
    const opener=new HTMLElement(),close=new HTMLElement();
    const menu={hidden:true};
    const context=vm.createContext({HTMLElement,menu,trigger:null,document:{activeElement:opener},
        body:{classList:{toggle(){}}},getToggles:()=>[{setAttribute(){}}],syncThemeAction(){},isOpen:()=>!menu.hidden,
        panel:{querySelector:()=>close},window:{requestAnimationFrame:fn=>callback=fn}});
    const start=source.indexOf('    function setOpen(');
    vm.runInContext(source.slice(start,source.indexOf('    closeButtons.forEach',start)),context);
    vm.runInContext('setOpen(true); setOpen(false);',context);
    assert.equal(focusCount,1);
    callback();
    assert.equal(focusCount,1);
    assert.equal(menu.hidden,true);
});
test('account dialog traps both Tab boundaries without a viewport condition',()=>{
    let handler,focused,prevented=0;
    class HTMLElement {hidden=false;offsetParent={};focus(){focused=this;}}
    const first=new HTMLElement(),last=new HTMLElement();
    const document={activeElement:last};
    const context=vm.createContext({HTMLElement,document,focusableSelector:'button',panel:{querySelectorAll:()=>[first,last]},menu:{addEventListener:(_,fn)=>handler=fn},setOpen(){}});
    const start=source.indexOf('    menu.addEventListener("keydown"');
    vm.runInContext(source.slice(start,source.indexOf('    document.addEventListener("click"',start)),context);
    handler({key:'Tab',shiftKey:false,preventDefault(){prevented++;}});
    assert.equal(focused,first);
    document.activeElement=first;
    handler({key:'Tab',shiftKey:true,preventDefault(){prevented++;}});
    assert.equal(focused,last);assert.equal(prevented,2);
});
