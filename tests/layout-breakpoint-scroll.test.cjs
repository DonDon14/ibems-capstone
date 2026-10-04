const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/app-layout.js','utf8');
const handler=source.slice(source.indexOf('    let hadDesktopScroll ='),source.indexOf('    const modalSelectors ='));
test('only mobile-to-desktop breakpoint crossings reset obsolete outer scrolling',()=>{
    let desktop=false,resize;
    const resets=[];
    vm.runInNewContext(handler,{canCollapse:()=>desktop,applyResponsiveState(){},window:{addEventListener:(name,fn)=>resize=fn,scrollTo:options=>resets.push(options)}});
    resize();assert.equal(resets.length,0);
    desktop=true;resize();assert.equal(resets.length,1);
    assert.equal(resets[0].top,0);assert.equal(resets[0].behavior,'instant');
    resize();assert.equal(resets.length,1);
    desktop=false;resize();assert.equal(resets.length,1);
    desktop=true;resize();assert.equal(resets.length,2);
});
